<?php

namespace App\Notifications;

use App\Models\Meeting;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AvailabilityReminderNotification extends Notification
{
    public function __construct(public Meeting $meeting) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Reminder: give your availability for :title', ['title' => $this->meeting->title]))
            ->line(__('Answers for :title are expected by tomorrow.', ['title' => $this->meeting->title]))
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
