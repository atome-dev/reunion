<?php

namespace App\Enums;

enum AvailabilityStatus: string
{
    case OnSite = 'on_site';
    case Remote = 'remote';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::OnSite => __('On site'),
            self::Remote => __('Remote'),
            self::Unavailable => __('Unavailable'),
        };
    }

    public function isPresent(): bool
    {
        return $this !== self::Unavailable;
    }
}
