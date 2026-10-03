<?php

namespace App\Enums;

enum MeetingStatus: string
{
    case Collecting = 'collecting';
    case Voting = 'voting';
    case Confirmed = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::Collecting => __('Collecting availabilities'),
            self::Voting => __('Vote in progress'),
            self::Confirmed => __('Confirmed'),
        };
    }
}
