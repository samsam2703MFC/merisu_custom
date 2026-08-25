<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Domain\Shop;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Service\SecretBox;
use Merisu\Inventory\Store\ShopStore;
use Merisu\Inventory\Store\Store;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin ▸ Boutiques — le réseau, boutique par boutique.
 *
 * ── Ce qui est propre à une boutique, et ce qui ne l'est pas
 *
 * Propre : l'adresse, les coordonnées — deux boutiques à trois cents
 * kilomètres n'ont pas la même semaine —, l'organisation de caisse, les
 * équipes, les comptages, les seuils.
 *
 * Commun : le catalogue et les compositions. Une enseigne vend la même gamme
 * partout ; un catalogue par boutique aurait obligé à saisir trois fois le même
 * tiramisu, et le premier renommage en aurait laissé deux en arrière. Là où les
 * boutiques diffèrent, c'est sur les QUANTITÉS, et celles-là sont déjà par
 * boutique.
 *
 * ── Le code ne se saisit pas
 *
 * Il se fabrique, comme celui des produits. Un administrateur pressé aurait
 * saisi deux fois le même, et deux boutiques auraient partagé leurs comptages.
 */
#[Route('/admin/boutiques')]
final class AdminShopController extends AbstractController
{
    public function __construct(
        private readonly Store $store,
        private readonly ShopStore $shops,
        private readonly CurrentUser $currentUser,
        private readonly SecretBox $box,
    ) {
    }

    #[Route('', name: 'admin_shops', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->currentUser->requireAdmin();

        $slot = $this->shops->nextSlot();

        return $this->render('admin/shops.html.twig', [
            'shops' => $this->shops->all(),
            'blank' => new Shop($slot['id'], $slot['code'], '', sortOrder: $slot['sortOrder']),
            'open' => (string) $request->query->get('ouvrir', ''),
            // Sans chiffrement, on n'accepte pas de secret de caisse : mieux
            // vaut un écran qui refuse et l'explique qu'un secret posé en clair
            // dans une base qu'on sauvegarde toutes les nuits.
            'canStoreSecret' => $this->box->isAvailable(),
            'timezones' => self::TIMEZONES,
        ]);
    }

    #[Route('/nouvelle', name: 'admin_shop_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $slot = $this->shops->nextSlot();
        $boutique = $this->read($request, new Shop($slot['id'], $slot['code'], '', sortOrder: $slot['sortOrder']));

        // Sans nom, la fiche n'est identifiable par personne : le code ne se
        // montre pas au vendeur, et une boutique « BOUTIQUE_3 » dans une liste
        // de trois n'aide en rien.
        if (trim($boutique->name) === '') {
            $this->addFlash('error', 'admin.shops.nameRequired');

            return $this->redirectToRoute('admin_shops');
        }

        $this->shops->save($boutique);
        $this->store->audit($admin->id, $admin->role->value, 'SHOP_CREATED', null, null, [
            'shopId' => $boutique->id,
            'code' => $boutique->code,
        ]);

        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_shops', ['ouvrir' => $boutique->id]);
    }

    #[Route('/{id}', name: 'admin_shop_save', methods: ['POST'])]
    public function save(Request $request, string $id): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $boutique = $this->shops->find($id) ?? throw $this->createNotFoundException('SHOP_NOT_FOUND');
        $modifiee = $this->read($request, $boutique);

        if (trim($modifiee->name) === '') {
            $this->addFlash('error', 'admin.shops.nameRequired');

            return $this->redirectToRoute('admin_shops', ['ouvrir' => $id]);
        }

        $this->shops->save($modifiee);
        $this->store->audit($admin->id, $admin->role->value, 'SHOP_UPDATED', null, null, [
            'shopId' => $id,
            'code' => $modifiee->code,
        ]);

        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_shops', ['ouvrir' => $id]);
    }

    #[Route('/{id}/supprimer', name: 'admin_shop_delete', methods: ['POST'])]
    public function delete(string $id): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $boutique = $this->shops->find($id) ?? throw $this->createNotFoundException('SHOP_NOT_FOUND');

        $this->shops->delete($id);
        $this->store->audit($admin->id, $admin->role->value, 'SHOP_DELETED', null, null, [
            'shopId' => $id,
            'code' => $boutique->code,
        ]);

        // La fiche part, PAS ce qu'elle a produit : comptages, plans et
        // historique gardent son code.
        $this->addFlash('success', 'admin.shops.deleted');

        return $this->redirectToRoute('admin_shops');
    }

    /**
     * Les fuseaux proposés.
     *
     * Une liste courte plutôt que les quelque six cents identifiants IANA :
     * une enseigne ouvre dans quelques pays, et dérouler six cents lignes pour
     * en trouver un est un obstacle, pas un choix. La saisie reste libre côté
     * base — c'est l'écran qui est court, pas le domaine.
     */
    private const TIMEZONES = [
        'Europe/Warsaw', 'Europe/Paris', 'Europe/Rome', 'Europe/Madrid',
        'Europe/Berlin', 'Europe/Brussels', 'Europe/Lisbon', 'Europe/London',
        'Europe/Prague', 'Europe/Vienna', 'Europe/Zurich', 'UTC',
    ];

    /** Lit le formulaire par-dessus une fiche existante. */
    private function read(Request $request, Shop $base): Shop
    {
        return $base->with(
            name: mb_substr(trim((string) $request->request->get('name', '')), 0, 190),
            address: mb_substr(trim((string) $request->request->get('address', '')), 0, 190),
            postalCode: mb_substr(trim((string) $request->request->get('postalCode', '')), 0, 16),
            city: mb_substr(trim((string) $request->request->get('city', '')), 0, 120),
            latitude: self::coordinate($request->request->get('latitude')),
            longitude: self::coordinate($request->request->get('longitude')),
            posOrganizationId: mb_substr(trim((string) $request->request->get('posOrganizationId', '')), 0, 64),
            posClientId: mb_substr(trim((string) $request->request->get('posClientId', '')), 0, 190),
            // Vide = on GARDE celui qui est posé. Le secret ne se relit pas ;
            // l'effacer parce qu'on a corrigé une adresse serait sans retour.
            posClientSecret: trim((string) $request->request->get('posClientSecret', '')) !== ''
                ? (string) $request->request->get('posClientSecret')
                : null,
            openingTime: self::time($request->request->get('openingTime'), $base->openingTime),
            closingTime: self::time($request->request->get('closingTime'), $base->closingTime),
            timezone: in_array($request->request->get('timezone'), self::TIMEZONES, true)
                ? (string) $request->request->get('timezone')
                : $base->timezone,
            photoRequired: $request->request->getBoolean('photoRequired'),
            photoPerProduct: $request->request->getBoolean('photoPerProduct'),
            // Bornée : au-delà de 100 % de tolérance, le delta ne signale plus
            // rien, et une valeur négative signalerait tout.
            deltaTolerance: max(0.0, min(1.0, (float) str_replace(
                ',', '.', (string) $request->request->get('deltaTolerance', '0.05'),
            ))),
            monthlyTarget: max(0, (int) $request->request->get('monthlyTarget', 0)),
            active: $request->request->getBoolean('active'),
        );
    }

    /**
     * Une heure au format HH:MM, ou celle qui était là.
     *
     * Refuser en silence vaut mieux qu'enregistrer « 25:99 » : l'heure décide
     * de quel comptage s'ouvre, et une valeur impossible aurait fermé la
     * saisie du matin sans que rien ne l'explique.
     */
    private static function time(mixed $value, string $fallback): string
    {
        $texte = is_scalar($value) ? trim((string) $value) : '';

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $texte) === 1 ? $texte : $fallback;
    }

    /**
     * Une coordonnée saisie au clavier.
     *
     * La virgule est acceptée : « 51,11 » est ce que tape un clavier français
     * ou polonais, et le refuser sans rien dire aurait donné une latitude de
     * 51 — cent kilomètres plus au nord.
     *
     * Hors du globe, on ramène à zéro plutôt que d'enregistrer : zéro se lit
     * « pas de coordonnées », une latitude de 999 se lirait comme une adresse.
     */
    private static function coordinate(mixed $value): float
    {
        if (!is_scalar($value)) {
            return 0.0;
        }

        $nombre = (float) str_replace(',', '.', trim((string) $value));

        return abs($nombre) > 180.0 ? 0.0 : $nombre;
    }
}
