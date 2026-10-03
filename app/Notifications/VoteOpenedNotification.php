<?php

namespace App\Notifications;

use App\Models\Meeting;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VoteOpenedNotification extends Notification
{
    public function __construct(public Meeting $meeting) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('Vote for the date of :title', ['title' => $this->meeting->title]))
            ->line(__('The organizer of :group proposes these slots:', ['group' => $this->meeting->group->name]));

        foreach ($this->meeting->slots as $slot) {
            $mail->line('• '.ucfirst($slot->startsAtLocal()->translatedFormat('l j F, H\hi')).' – '.$slot->endsAtLocal()->format('H\hi'));
        }

        return $mail->action(__('Vote'), route('meetings.show', $this->meeting));
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }
}
