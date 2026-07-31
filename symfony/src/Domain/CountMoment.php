<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/** Moment de comptage dans la journée. */
enum CountMoment: string
{
    case Open0800 = 'OPEN_0800';
    case Close2200 = 'CLOSE_2200';

    public function isEvening(): bool
    {
        return $this === self::Close2200;
    }
}
