<?php

namespace App\Actions\Meetings;

use App\Models\Meeting;

/**
 * Link that opens Google Calendar with a confirmed meeting prefilled; the user only has to save it.
 */
class BuildGoogleCalendarUrl
{
    public function __invoke(Meeting $meeting): string
    {
        $query = array_filter([
            'action' => 'TEMPLATE',
            'text' => $meeting->title,
            'dates' => $meeting->confirmed_starts_at->copy()->utc()->format('Ymd\THis\Z')
                .'/'.$meeting->confirmed_ends_at->copy()->utc()->format('Ymd\THis\Z'),
            'location' => $meeting->location,
            'details' => route('meetings.show', $meeting),
        ]);

        return 'https://calendar.google.com/calendar/render?'.http_build_query($query, encoding_type: PHP_QUERY_RFC3986);
    }
}
