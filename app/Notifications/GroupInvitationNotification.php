<?php

namespace App\Notifications;

use App\Models\GroupInvitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GroupInvitationNotification extends Notification
{
    public function __construct(public GroupInvitation $invitation, public string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $group = $this->invitation->group;

        return (new MailMessage)
            ->subject(__('Invitation to join :group', ['group' => $group->name]))
            ->line(__(':inviter invites you to join the group :group on :app.', [
                'inviter' => $this->invitation->inviter->name,
                'group' => $group->name,
                'app' => config('app.name'),
            ]))
            ->line(__('You will be able to tell when you are available for the group\'s meetings, on site or remotely.'))
            ->action(__('Join the group'), route('invitations.show', $this->token))
            ->line(__('This invitation expires on :date.', [
                'date' => $this->invitation->expires_at->setTimezone(config('app.display_timezone'))->translatedFormat('j F Y'),
            ]));
    }
}
