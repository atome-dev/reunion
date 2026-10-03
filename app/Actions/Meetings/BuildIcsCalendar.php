<?php

namespace App\Actions\Meetings;

use App\Models\Meeting;

/**
 * Build an iCalendar file for a confirmed meeting, so members can add it to their calendar.
 */
class BuildIcsCalendar
{
    public function __invoke(Meeting $meeting): string
    {
        $lines = array_filter([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//'.config('app.name').'//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:meeting-'.$meeting->id.'@'.(parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost'),
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:'.$meeting->confirmed_starts_at->copy()->utc()->format('Ymd\THis\Z'),
            'DTEND:'.$meeting->confirmed_ends_at->copy()->utc()->format('Ymd\THis\Z'),
            'SUMMARY:'.$this->escape($meeting->title),
            $meeting->location ? 'LOCATION:'.$this->escape($meeting->location) : null,
            'DESCRIPTION:'.$this->escape(route('meetings.show', $meeting)),
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        return implode("\r\n", $lines)."\r\n";
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $text);
    }
}
