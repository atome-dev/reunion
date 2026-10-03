<?php

namespace App\Http\Controllers;

use App\Actions\Meetings\BuildIcsCalendar;
use App\Enums\MeetingStatus;
use App\Models\Meeting;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class MeetingCalendarController extends Controller
{
    public function __invoke(Meeting $meeting, BuildIcsCalendar $buildIcsCalendar): Response
    {
        Gate::authorize('view', $meeting);

        abort_unless($meeting->status === MeetingStatus::Confirmed, 404);

        return response($buildIcsCalendar($meeting), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="reunion.ics"',
        ]);
    }
}
