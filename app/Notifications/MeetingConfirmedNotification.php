<?php

namespace App\Notifications;

use App\Actions\Meetings\BuildGoogleCalendarUrl;
use App\Actions\Meetings\BuildIcsCalendar;
use App\Models\Meeting;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MeetingConfirmedNotification extends Notification
{
    public function __construct(public Meeting $meeting) {}

    public function toMail(object $notifiable): MailMessage
    {
        $startsAt = $this->meeting->confirmed_starts_at->copy()->setTimezone(config('app.display_timezone'));
        $endsAt = $this->meeting->confirmed_ends_at->copy()->setTimezone(config('app.display_timezone'));

        $mail = (new MailMessage)
            ->subject(__(':title is confirmed', ['title' => $this->meeting->title]))
            ->line(__(':title will take place on :date, from :start to :end.', [
                'title' => $this->meeting->title,
                'date' => $startsAt->translatedFormat('l j F Y'),
                'start' => $startsAt->format('H\hi'),
                'end' => $endsAt->format('H\hi'),
            ]));

        if ($this->meeting->location) {
            $mail->line(__('Location: :location', ['location' => $this->meeting->location]));
        }

        return $mail
            ->action(__('See the meeting'), route('meetings.show', $this->meeting))
            ->line(__('Using Google Calendar? [Add it in one click](:url). The attached file works with other calendars.', ['url' => (new BuildGoogleCalendarUrl)($this->meeting)]))
            ->attachData((new BuildIcsCalendar)($this->meeting), 'reunion.ics', ['mime' => 'text/calendar']);
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }
}
