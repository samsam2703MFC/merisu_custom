<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Adapter\PosServiceInterface;
use Merisu\Inventory\Adapter\PosUnavailable;
use Merisu\Inventory\Domain\ProductCategory;
use Merisu\Inventory\Domain\ProductNature;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Service\SecretBox;
use Merisu\Inventory\Store\PosCredentialStore;
use Merisu\Inventory\Store\Store;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin ▸ Caisse — ce que GoPOS connaît, et ce qu'on en reprend.
 *
 * ── Voir AVANT d'importer
 *
 * L'écran commence par montrer ce que la caisse renvoie, sans rien écrire.
 * Un import qui se déclenche au premier clic sur une boutique de trois cents
 * articles est irrattrapable : il faut pouvoir constater que c'est bien LA
 * bonne organisation qui répond, et combien de lignes vont entrer, avant que
 * quoi que ce soit ne touche la base.
 *
 * ── L'import AJOUTE, il n'écrase pas
 *
 * Un produit déjà connu garde son unité, son facteur de perte, son rythme de
 * comptage et ses traductions : ce sont des réglages posés ici, que la caisse
 * ne connaît pas et ne peut donc pas remplacer. Seul le rattachement à la
 * caisse est mis à jour. Sans cette règle, un import aurait remis à zéro le
 * paramétrage de toute la boutique.
 */
#[Route('/admin/caisse')]
final class AdminPosController extends AbstractController
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly PosServiceInterface $pos,
        private readonly Store $store,
        private readonly PosCredentialStore $credentials,
        private readonly SecretBox $box,
    ) {
    }

    #[Route('', name: 'admin_cash', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->currentUser->requireAdmin();

        $vue = [
            'configured' => $this->pos->isConfigured(),
            'shopName' => null,
            'categories' => [],
            'items' => [],
            'error' => null,
            // On ne va chercher QUE si on le demande : ouvrir l'onglet ne doit
            // pas déclencher deux cents appels chez la caisse.
            'probed' => $request->query->getBoolean('tester'),
        ];

        if ($vue['configured'] && $vue['probed']) {
            try {
                $vue['shopName'] = $this->pos->ping();
                $vue['categories'] = $this->pos->categories();
                $vue['items'] = $this->pos->items();
            } catch (PosUnavailable $e) {
                $vue['error'] = $e->getMessage();
            }
        }

        $identifiants = $this->pos->credentials();

        return $this->render('admin/pos.html.twig', $vue + [
            'knownCategories' => $this->store->categoryOrder(),
            'knownRefs' => $this->referencesConnues(),
            // Ce que l'écran a le droit de montrer — le secret n'en fait PAS
            // partie, jamais, même tronqué : « sk-… 4f2a » suffit à confirmer
            // à qui l'a volé qu'il tient le bon.
            'credentials' => $identifiants->display(),
            'fromScreen' => $identifiants->fromScreen,
            'canStore' => $this->box->isAvailable(),
            'defaultBaseUrl' => \Merisu\Inventory\Adapter\GoPosService::DEFAULT_BASE_URL,
        ]);
    }

    /**
     * Enregistre les identifiants saisis à l'écran.
     *
     * ── Le secret est en ÉCRITURE SEULE
     *
     * Il n'est jamais renvoyé au navigateur. Laissé vide, il n'est pas
     * effacé : c'est la contrepartie obligée d'un champ qu'on n'affiche pas —
     * sans cette règle, corriger une faute de frappe dans l'identifiant aurait
     * effacé un secret que personne ne peut relire pour le retaper.
     */
    #[Route('/identifiants', name: 'admin_cash_credentials', methods: ['POST'])]
    public function saveCredentials(Request $request): Response
    {
        $admin = $this->currentUser->requireAdmin();

        // Sans chiffrement possible, on n'écrit RIEN : mieux vaut un écran qui
        // refuse et l'explique qu'un secret de caisse posé en clair dans une
        // base que l'on sauvegarde toutes les nuits.
        if (!$this->box->isAvailable()) {
            $this->addFlash('error', 'admin.pos.errorNoCrypto');

            return $this->redirectToRoute('admin_cash');
        }

        $clientId = mb_substr(trim((string) $request->request->get('clientId', '')), 0, 190);
        $organisation = mb_substr(trim((string) $request->request->get('organizationId', '')), 0, 64);
        $adresse = mb_substr(trim((string) $request->request->get('baseUrl', '')), 0, 190);
        $secret = (string) $request->request->get('clientSecret', '');

        // HTTPS, et rien d'autre. Le message le disait déjà ; le code, lui,
        // acceptait aussi `http://` — la commodité qui sert à essayer en
        // local et qui devient, un mois plus tard, un secret de caisse qui
        // traverse le réseau en clair.
        //
        // La variable d'environnement, elle, n'est pas contrainte : régler un
        // laboratoire depuis le serveur suppose déjà l'accès au serveur.
        if ($adresse !== '' && !str_starts_with($adresse, 'https://')) {
            $this->addFlash('error', 'admin.pos.errorBadUrl');

            return $this->redirectToRoute('admin_cash');
        }

        try {
            $this->credentials->save(
                $clientId,
                $secret,
                $organisation,
                $adresse !== '' ? $adresse : \Merisu\Inventory\Adapter\GoPosService::DEFAULT_BASE_URL,
            );
        } catch (\RuntimeException) {
            $this->addFlash('error', 'admin.pos.errorNoCrypto');

            return $this->redirectToRoute('admin_cash');
        }

        // Ni le secret, ni son empreinte : l'historique se consulte en
        // administration, et n'a pas à en porter la trace.
        $this->store->audit($admin->id, $admin->role->value, 'POS_CREDENTIALS_SAVED', null, null, [
            'organizationId' => $organisation,
            'secretChanged' => trim($secret) !== '',
        ]);

        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_cash');
    }

    /** Efface la saisie d'écran : la configuration du serveur reprend la main. */
    #[Route('/identifiants/effacer', name: 'admin_cash_credentials_clear', methods: ['POST'])]
    public function clearCredentials(): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $this->credentials->clear();

        $this->store->audit($admin->id, $admin->role->value, 'POS_CREDENTIALS_CLEARED');
        $this->addFlash('success', 'admin.pos.credentialsCleared');

        return $this->redirectToRoute('admin_cash');
    }

    /**
     * Reprend les catégories de la caisse dans Admin ▸ Catégories.
     *
     * Les nouvelles seulement : une catégorie déjà présente porte peut-être
     * une nature réglée à la main (matière première, emballage), et la
     * réécrire l'aurait remise en « produit en vente ».
     */
    #[Route('/categories', name: 'admin_cash_import_categories', methods: ['POST'])]
    public function importCategories(): Response
    {
        $admin = $this->currentUser->requireAdmin();

        try {
            $distantes = $this->pos->categories();
        } catch (PosUnavailable $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_cash');
        }

        $connues = array_flip($this->store->categoryOrder());
        $ajoutees = 0;

        foreach ($distantes as $categorie) {
            $nom = ProductCategory::clean($categorie->name);

            if ($nom === '' || isset($connues[$nom])) {
                continue;
            }

            $this->store->addCategory($nom, ProductNature::Sale);
            $connues[$nom] = true;
            ++$ajoutees;
        }

        $this->store->audit($admin->id, $admin->role->value, 'POS_CATEGORIES_IMPORTED', null, null, [
            'seen' => count($distantes),
            'added' => $ajoutees,
        ]);

        $this->addFlash('success', 'admin.pos.categoriesImported');

        return $this->redirectToRoute('admin_cash', ['tester' => 1]);
    }

    /**
     * Rattache les fiches produits aux articles de la caisse.
     *
     * ── Ce que l'import fait, et ce qu'il ne fait pas
     *
     * Il RATTACHE : la fiche locale reçoit la référence de l'article
     * (`recipeRef`), qui est le seul lien durable entre les deux — un nom se
     * retape, un identifiant non. Et il crée les fiches absentes.
     *
     * Il ne touche à RIEN d'autre : unité, facteur de perte, arrondi, rythme
     * de comptage, traductions restent tels quels. Ce sont des réglages
     * d'inventaire, que la caisse ne connaît pas.
     */
    #[Route('/produits', name: 'admin_cash_import_items', methods: ['POST'])]
    public function importItems(): Response
    {
        $admin = $this->currentUser->requireAdmin();

        try {
            $articles = $this->pos->items();
        } catch (PosUnavailable $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_cash');
        }

        $parReference = [];
        $parNom = [];

        foreach ($this->store->products() as $produit) {
            if (($produit->recipeRef ?? '') !== '') {
                $parReference[(string) $produit->recipeRef] = $produit;
            }

            foreach ($produit->name as $libelle) {
                $parNom[mb_strtolower(trim($libelle))] = $produit;
            }
        }

        $crees = 0;
        $rattaches = 0;

        foreach ($articles as $article) {
            if (isset($parReference[$article->externalId])) {
                continue;
            }

            // Rattachement par le NOM, faute de référence : c'est le seul
            // repère disponible au premier import, et il évite de créer un
            // doublon de chaque produit déjà saisi à la main. Une fois
            // rattachée, la fiche ne dépend plus de son nom.
            $existante = $parNom[mb_strtolower($article->name)] ?? null;

            if ($existante !== null) {
                $this->store->saveProduct($existante->with(recipeRef: $article->externalId));
                $parReference[$article->externalId] = $existante;
                ++$rattaches;

                continue;
            }

            $slot = $this->store->nextProductSlot();

            $this->store->saveProduct(new \Merisu\Inventory\Domain\Product(
                $slot['id'],
                $slot['code'],
                // Le nom part dans la langue par défaut : la caisse n'en donne
                // qu'un. Les trois autres se complètent au bouton « Traduire »
                // d'Admin ▸ Produits.
                [$this->store->settings()->defaultLocale->value => $article->name],
                'pcs',
                $article->enabled,
                0.0,
                1.0,
                \Merisu\Inventory\Domain\RoundingMode::Ceil,
                $article->externalId,
                $slot['sortOrder'],
                category: ProductCategory::clean($article->categoryName ?? ''),
            ));

            ++$crees;
        }

        $this->store->audit($admin->id, $admin->role->value, 'POS_ITEMS_IMPORTED', null, null, [
            'seen' => count($articles),
            'created' => $crees,
            'linked' => $rattaches,
        ]);

        $this->addFlash('success', 'admin.pos.itemsImported');

        return $this->redirectToRoute('admin_cash', ['tester' => 1]);
    }

    /**
     * Les références déjà rattachées — pour que l'écran dise, article par
     * article, ce qui entrerait et ce qui est déjà là.
     *
     * @return array<string, true>
     */
    private function referencesConnues(): array
    {
        $refs = [];

        foreach ($this->store->products() as $produit) {
            if (($produit->recipeRef ?? '') !== '') {
                $refs[(string) $produit->recipeRef] = true;
            }
        }

        return $refs;
    }
}
