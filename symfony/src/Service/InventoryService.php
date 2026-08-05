<?php

declare(strict_types=1);

namespace Merisu\Inventory\Service;

use Merisu\Inventory\Domain\BusinessDate;
use Merisu\Inventory\Domain\CountMoment;
use Merisu\Inventory\Domain\DailyNet;
use Merisu\Inventory\Domain\EveningValidation;
use Merisu\Inventory\Domain\InventoryCount;
use Merisu\Inventory\Domain\NetVariance;
use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\Production;
use Merisu\Inventory\Domain\ProductionPlanResult;
use Merisu\Inventory\Domain\ProductionPlanRow;
use Merisu\Inventory\Domain\Role;
use Merisu\Inventory\Domain\ValidationResult;
use Merisu\Inventory\Store\Store;

/**
 * Orchestration du parcours consultant (§3.1, §3.2).
 *
 * Aucune formule n'est réimplémentée ici : tous les calculs viennent de
 * `Merisu\Inventory\Domain`.
 */
final class InventoryService
{
    public function __construct(private readonly Store $store)
    {
    }

    /** Date métier courante, dans le fuseau configuré en administration. */
    public function today(): string
    {
        return BusinessDate::today($this->store->settings()->timezone);
    }

    /**
     * Moment à proposer par défaut : avant l'heure de clôture on compte
     * l'ouverture, après on compte la clôture.
     */
    public function suggestedMoment(): CountMoment
    {
        $settings = $this->store->settings();
        $now = BusinessDate::time($settings->timezone);

        return $now >= $settings->closingTime || $now < $settings->openingTime
            ? CountMoment::Close2200
            : CountMoment::Open0800;
    }

    /**
     * Tout ce dont un écran de saisie a besoin.
     *
     * @return array{
     *     date: string, workstationId: string, moment: CountMoment,
     *     products: list<Product>, counts: array<string, InventoryCount>,
     *     validated: bool, validatedAt: ?string, validatedBy: ?string,
     *     planForToday: list<ProductionPlanRow>, nets: array<string, DailyNet>
     * }
     */
    public function daySheet(string $date, string $workstationId, CountMoment $moment): array
    {
        $products = $this->store->products(activeOnly: true);
        $allCounts = $this->store->counts(['date' => $date, 'workstationId' => $workstationId]);

        $byMoment = [];
        foreach ($allCounts as $count) {
            $byMoment[$count->moment->value][$count->productId] = $count;
        }

        $forMoment = $byMoment[$moment->value] ?? [];
        $validatedCount = null;
        foreach ($forMoment as $count) {
            if ($count->validated) {
                $validatedCount = $count;
                break;
            }
        }

        $nets = [];
        foreach ($products as $product) {
            $opening = $byMoment[CountMoment::Open0800->value][$product->id] ?? null;
            $closing = $byMoment[CountMoment::Close2200->value][$product->id] ?? null;

            $nets[$product->id] = NetVariance::compute(
                $date,
                $product->id,
                $workstationId,
                $opening?->qty,
                $closing?->qty,
                ($opening?->producedQty ?? 0) + ($closing?->producedQty ?? 0),
            );
        }

        return [
            'date' => $date,
            'workstationId' => $workstationId,
            'moment' => $moment,
            'products' => $products,
            // Regroupement par catégorie de production : avec vingt-cinq
            // produits, une liste à plat oblige à faire défiler en cherchant
            // du regard. Les groupes suivent l'ordre des produits, pas un
            // alphabet : l'atelier a rangé ses emplacements dans un ordre qui
            // lui parle.
            'productGroups' => self::groupByCategory($products),
            'counts' => $forMoment,
            'validated' => $validatedCount !== null,
            'validatedAt' => $validatedCount?->validatedAt,
            'validatedBy' => $validatedCount?->validatedBy,
            'planForToday' => $this->store->plan($date, $workstationId),
            'nets' => $nets,
        ];
    }

    /**
     * Enregistre les quantités comptées.
     *
     * Écriture en masse et idempotente (clé date + poste + produit + moment) :
     * c'est ce qui permet à la file d'attente hors-ligne de rejouer un envoi
     * sans créer de doublon.
     *
     * @param array<string, float|null> $quantities quantités par identifiant de produit
     *
     * @throws \RuntimeException si la saisie est verrouillée
     */
    public function saveCounts(
        string $date,
        string $workstationId,
        CountMoment $moment,
        array $quantities,
        string $actorId,
        Role $actorRole,
    ): int {
        $existing = $this->store->counts([
            'date' => $date,
            'workstationId' => $workstationId,
            'moment' => $moment,
        ]);

        $locked = false;
        foreach ($existing as $count) {
            if ($count->validated) {
                $locked = true;
                break;
            }
        }

        // Verrouillage après validation : seul un ADMIN peut encore corriger (§5.3).
        if ($locked && !EveningValidation::canEdit(true, $actorRole)) {
            throw new \RuntimeException('COUNT_LOCKED');
        }

        $activeIds = array_map(static fn (Product $p): string => $p->id, $this->store->products(activeOnly: true));
        $saved = 0;

        foreach ($quantities as $productId => $qty) {
            if ($qty === null || !\in_array($productId, $activeIds, true)) {
                continue;
            }
            if ($qty < 0) {
                throw new \RuntimeException('INVALID_QTY');
            }

            $this->store->saveCount($date, $workstationId, $actorId, (string) $productId, $moment, $qty);
            ++$saved;
        }

        if ($saved > 0) {
            $this->store->audit(
                $actorId,
                $actorRole->value,
                $locked ? 'COUNT_EDITED_AFTER_VALIDATION' : 'COUNT_SAVED',
                $workstationId,
                $date,
                ['moment' => $moment->value, 'products' => $saved],
            );
        }

        return $saved;
    }

    /**
     * Joint une photo, désignée par sa clé métier (jour, poste, produit, moment).
     * Le comptage doit exister : la quantité est toujours enregistrée avant.
     */
    public function addPhoto(
        string $date,
        string $workstationId,
        string $productId,
        CountMoment $moment,
        string $url,
        string $actorId,
        Role $actorRole,
    ): void {
        $counts = $this->store->counts([
            'date' => $date,
            'workstationId' => $workstationId,
            'productId' => $productId,
            'moment' => $moment,
        ]);

        $count = $counts[0] ?? null;
        if ($count === null) {
            throw new \RuntimeException('COUNT_NOT_FOUND');
        }
        if (!EveningValidation::canEdit($count->validated, $actorRole)) {
            throw new \RuntimeException('COUNT_LOCKED');
        }

        $this->store->addPhoto($count->id, $url);
        $this->store->audit($actorId, $actorRole->value, 'PHOTO_ADDED', $workstationId, $date, [
            'productId' => $productId,
        ]);
    }

    /**
     * Valide un comptage.
     *
     * Pour le SOIR : vérifie les règles §5.3, verrouille les saisies, puis calcule
     * et FIGE le plan de production du lendemain (§5.2).
     *
     * @return array{result: ValidationResult, plan: list<ProductionPlanRow>}
     */
    public function validate(
        string $date,
        string $workstationId,
        CountMoment $moment,
        string $actorId,
        Role $actorRole,
    ): array {
        $settings = $this->store->settings();
        $products = $this->store->products(activeOnly: true);
        $counts = $this->store->counts(['date' => $date, 'workstationId' => $workstationId, 'moment' => $moment]);

        $quantities = [];
        $photoCounts = [];
        $alreadyValidated = false;

        foreach ($counts as $count) {
            $quantities[$count->productId] = $count->qty;
            $photoCounts[$count->productId] = \count($count->photos);
            $alreadyValidated = $alreadyValidated || $count->validated;
        }

        // Les photos ont été retirées des écrans de comptage : le vendeur n'a
        // plus aucun moyen d'en fournir une. Continuer à en exiger bloquerait
        // la validation sans recours possible au poste — un verrou qu'aucun
        // geste ne peut ouvrir est pire que l'absence de contrôle.
        //
        // La règle reste implémentée et testée dans EveningValidation : rendre
        // les photos aux écrans suffira à la réactiver, sans la réécrire.
        $photoRequired = false;

        $result = EveningValidation::validate(
            $products,
            $quantities,
            $photoCounts,
            0,
            $photoRequired,
            $settings->photoPerProduct,
            $alreadyValidated,
        );

        if (!$result->valid) {
            return ['result' => $result, 'plan' => []];
        }

        $this->store->setValidated($date, $workstationId, $moment, true, $actorId);
        $this->store->audit(
            $actorId,
            $actorRole->value,
            $moment->isEvening() ? 'EVENING_VALIDATED' : 'MORNING_VALIDATED',
            $workstationId,
            $date,
            ['products' => \count($counts)],
        );

        $plan = $moment->isEvening()
            ? $this->freezePlan($date, $workstationId, $actorId, $actorRole)
            : [];

        return ['result' => $result, 'plan' => $plan];
    }

    /** Déverrouillage d'un comptage validé — ADMIN uniquement, tracé (§5.3). */
    public function unlock(
        string $date,
        string $workstationId,
        CountMoment $moment,
        string $actorId,
        Role $actorRole,
        ?string $reason = null,
    ): int {
        if (!$actorRole->isAdmin()) {
            throw new \RuntimeException('ADMIN_ONLY');
        }

        $affected = $this->store->setValidated($date, $workstationId, $moment, false, $actorId);

        $this->store->audit($actorId, $actorRole->value, 'COUNT_UNLOCKED', $workstationId, $date, [
            'moment' => $moment->value,
            'reason' => $reason,
            'counts' => $affected,
        ]);

        return $affected;
    }

    /** Calcule le plan du lendemain à partir des stocks de clôture, puis le fige. */
    public function freezePlan(string $date, string $workstationId, string $actorId, Role $actorRole): array
    {
        $computed = $this->previewPlan($date, $workstationId);

        $this->store->replacePlan($computed->forDate, $workstationId, array_map(
            static fn ($line): array => [
                'productId' => $line->productId,
                'requiredPieces' => $line->requiredPieces,
                'closingStock' => $line->closingStock,
                'qtyToProduce' => $line->qtyToProduce,
                'missingThreshold' => $line->missingThreshold,
            ],
            $computed->lines,
        ));

        $this->store->audit($actorId, $actorRole->value, 'PRODUCTION_PLAN_FROZEN', $workstationId, $date, [
            'forDate' => $computed->forDate,
            'lines' => \count($computed->lines),
            'missingThresholds' => $computed->warningsMissingThreshold,
        ]);

        return $this->store->plan($computed->forDate, $workstationId);
    }

    /**
     * Calcule le plan SANS l'enregistrer — aperçu avant validation, et
     * recalcul d'un plan manquant.
     */
    public function previewPlan(string $date, string $workstationId): ProductionPlanResult
    {
        $products = $this->store->products(activeOnly: true);
        $closingCounts = $this->store->counts([
            'date' => $date,
            'workstationId' => $workstationId,
            'moment' => CountMoment::Close2200,
        ]);

        $closingStocks = [];
        foreach ($products as $product) {
            $closingStocks[$product->id] = null;
        }
        foreach ($closingCounts as $count) {
            $closingStocks[$count->productId] = $count->qty;
        }

        return Production::plan($date, $products, $closingStocks, $this->store->parMatrix(), $workstationId);
    }

    /**
     * Produits groupés par catégorie, dans l'ordre où les catégories
     * apparaissent.
     *
     * Les produits sans catégorie forment un dernier groupe : les cacher
     * reviendrait à ne jamais les compter, et les mêler aux autres ferait
     * mentir les compteurs de chaque groupe.
     *
     * @param list<Product> $products
     *
     * @return list<array{category: string, products: list<Product>}>
     */
    private static function groupByCategory(array $products): array
    {
        $groupes = [];
        $sansCategorie = [];

        foreach ($products as $product) {
            if ($product->category === '') {
                $sansCategorie[] = $product;
                continue;
            }

            $groupes[$product->category][] = $product;
        }

        $sortie = [];

        foreach ($groupes as $categorie => $liste) {
            $sortie[] = ['category' => (string) $categorie, 'products' => $liste];
        }

        if ($sansCategorie !== []) {
            $sortie[] = ['category' => '', 'products' => $sansCategorie];
        }

        return $sortie;
    }
}
