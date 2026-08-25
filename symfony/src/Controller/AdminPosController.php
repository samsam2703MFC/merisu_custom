<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Adapter\PosServiceInterface;
use Merisu\Inventory\Adapter\PosUnavailable;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Service\PosImportService;
use Merisu\Inventory\Service\SecretBox;
use Merisu\Inventory\Service\ShopPos;
use Merisu\Inventory\Store\PosCredentialStore;
use Merisu\Inventory\Store\ShopStore;
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
        private readonly PosImportService $import,
        private readonly SecretBox $box,
        private readonly ShopStore $shops,
        private readonly ShopPos $shopPos,
    ) {
    }

    #[Route('', name: 'admin_cash', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->currentUser->requireAdmin();

        $vue = [
            'configured' => $this->pos->isConfigured(),
            'shopName' => null,
            // Les organisations que la paire ouvre RÉELLEMENT. C'est la seule
            // façon de savoir si un secret unique peut tenir tout le réseau :
            // les identifiants sont liés à une organisation à leur création,
            // mais `/api/v3/me` en rend une LISTE.
            'organizations' => [],
            'categories' => [],
            'items' => [],
            'error' => null,
            // Ce que la caisse a RÉPONDU. Sans lui, « refusé » n'apprend rien :
            // on ne sait ni à quelle étape, ni pourquoi.
            'errorDetail' => '',
            // On ne va chercher QUE si on le demande : ouvrir l'onglet ne doit
            // pas déclencher deux cents appels chez la caisse.
            'probed' => $request->query->getBoolean('tester'),
        ];

        if ($vue['configured'] && $vue['probed']) {
            try {
                $vue['shopName'] = $this->pos->ping();
                $vue['organizations'] = $this->pos->organizations();
                $vue['categories'] = $this->pos->categories();
                $vue['items'] = $this->pos->items();
            } catch (PosUnavailable $e) {
                $vue['error'] = $e->getMessage();
                $vue['errorDetail'] = $e->detail;
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
            // Les boutiques enregistrées, et d'où chacune tire ses
            // identifiants : les siens, ou ceux du réseau.
            'shops' => $this->shopSummaries(),
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
     * La règle vit dans `PosImportService`, partagée avec `merisu:caisse` :
     * un import de quarante articles se fait aussi bien par SSH, et deux
     * copies de la règle auraient divergé au premier ajustement.
     */
    #[Route('/categories', name: 'admin_cash_import_categories', methods: ['POST'])]
    public function importCategories(): Response
    {
        $admin = $this->currentUser->requireAdmin();

        try {
            $this->import->importCategories($admin->id, $admin->role->value);
        } catch (PosUnavailable $e) {
            return $this->echec($e);
        }

        $this->addFlash('success', 'admin.pos.categoriesImported');

        return $this->redirectToRoute('admin_cash', ['tester' => 1]);
    }

    /**
     * Rattache les fiches produits aux articles de la caisse.
     *
     * Voir `PosImportService` : l'import AJOUTE, il n'écrase pas. Unité,
     * facteur de perte, arrondi, rythme de comptage et traductions restent
     * tels quels — ce sont des réglages d'inventaire, que la caisse ne
     * connaît pas.
     */
    #[Route('/produits', name: 'admin_cash_import_items', methods: ['POST'])]
    public function importItems(): Response
    {
        $admin = $this->currentUser->requireAdmin();

        try {
            $this->import->importItems($admin->id, $admin->role->value);
        } catch (PosUnavailable $e) {
            return $this->echec($e);
        }

        $this->addFlash('success', 'admin.pos.itemsImported');

        return $this->redirectToRoute('admin_cash', ['tester' => 1]);
    }

    /**
     * Le refus de la caisse, porté à l'écran — avec ce qu'elle a DIT.
     *
     * Deux bandeaux et non un seul : le premier nomme le geste qui répare, le
     * second cite la caisse mot pour mot. Sans le second, on ne saurait pas
     * distinguer un identifiant erroné d'une organisation non accordée.
     */
    private function echec(PosUnavailable $e): Response
    {
        $this->addFlash('error', ['key' => $e->getMessage(), 'params' => []]);

        if ($e->detail !== '') {
            $this->addFlash('error', ['key' => 'admin.pos.hostSaid', 'params' => ['%detail%' => $e->detail]]);
        }

        return $this->redirectToRoute('admin_cash');
    }

    /**
     * Chaque boutique, et d'où elle tire ses identifiants de caisse.
     *
     * @return list<array{shop: \Merisu\Inventory\Domain\Shop, source: string}>
     */
    private function shopSummaries(): array
    {
        return array_map(
            fn (\Merisu\Inventory\Domain\Shop $b): array => [
                'shop' => $b,
                'source' => $this->shopPos->sourceFor($b),
            ],
            $this->shops->all(),
        );
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
