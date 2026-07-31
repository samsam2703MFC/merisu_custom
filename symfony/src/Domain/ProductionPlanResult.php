<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

final readonly class ProductionPlanResult
{
    /**
     * @param list<ProductionLine> $lines
     * @param list<string>         $warningsMissingThreshold
     */
    public function __construct(
        public string $forDate,
        public DayOfWeek $forDayOfWeek,
        public string $workstationId,
        public array $lines,
        public array $warningsMissingThreshold,
    ) {
    }
}
