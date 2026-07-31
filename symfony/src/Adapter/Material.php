<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

use Merisu\Inventory\Domain\Locale;

/** Matière première — provient du module « Recette technique » existant. */
final readonly class Material
{
    /** @param array<string,string> $name libellé par langue */
    public function __construct(
        public string $id,
        public array $name,
        public string $unit,
    ) {
    }

    public function label(Locale $locale, Locale $default = Locale::Fr): string
    {
        foreach ([$locale->value, $default->value] as $candidate) {
            $value = trim($this->name[$candidate] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        return $this->id;
    }
}
