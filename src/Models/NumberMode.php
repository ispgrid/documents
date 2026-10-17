<?php

namespace Ispgrid\Documents\Models;

/**
 * How much of a document's ID number is kept.
 */
enum NumberMode: string
{
    case None = 'none';
    case Full = 'full';
    case LastFour = 'last4';

    public function label(): string
    {
        return match ($this) {
            self::None => __('No ID number'),
            self::Full => __('Full ID number'),
            self::LastFour => __('Last 4 characters only'),
        };
    }
}
