<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

final readonly class ValidationResult
{
    /** @param list<ValidationIssue> $issues */
    public function __construct(
        public bool $valid,
        public array $issues,
    ) {
    }

    /** @return list<string> identifiants des produits en défaut */
    public function productIdsInError(): array
    {
        return array_values(array_filter(array_map(
            static fn (ValidationIssue $issue): ?string => $issue->productId,
            $this->issues,
        )));
    }
}
