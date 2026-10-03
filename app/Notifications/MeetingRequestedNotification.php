<?php

namespace App\Notifications;

use App\Models\Meeting;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MeetingRequestedNotification extends Notification
{
    public function __construct(public Meeting $meeting) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('When are you available for :title?', ['title' => $this->meeting->title]))
            ->line(__(':group is organising :title between :start and :end.', [
                'group' => $this->meeting->group->name,
                'title' => $this->meeting->title,
                'start' => $this->meeting->range_start->translatedFormat('j F'),
                'end' => $this->meeting->range_end->translatedFormat('j F'),
            ]))
            ->line(__('Tell us when you are available, on site or remotely, before :date.', ['date' => $this->meeting->deadline->translatedFormat('l j F')]))
            ->action(__('Give my availability'), route('meetings.show', $this->meeting));
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }
}
