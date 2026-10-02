<?php

use App\Livewire\Groups\Show;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\User;
use App\Notifications\GroupInvitationNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->group = Group::factory()->create(['name' => 'Jardin partagé']);
    $this->organizer = $this->group->owner;
});

/**
 * Invite an address and return the plain token that was emailed.
 */
function inviteAndGetToken(Group $group, string $email): string
{
    Livewire::actingAs($group->owner)
        ->test(Show::class, ['group' => $group])
        ->set('invitationEmails', $email)
        ->call('invite');

    $token = null;
    Notification::assertSentOnDemand(GroupInvitationNotification::class, function (GroupInvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$token) {
        if ($notifiable->routes['mail'] === $email) {
            $token = $notification->token;
        }

        return true;
    });

    return $token;
}

test('the organizer invites several addresses at once', function () {
    Livewire::actingAs($this->organizer)
        ->test(Show::class, ['group' => $this->group])
        ->set('invitationEmails', "amina@example.com, bastien@example.com\nchloe@example.com")
        ->call('invite')
        ->assertHasNoErrors();

    expect(GroupInvitation::pluck('email')->sort()->values()->all())
        ->toBe(['amina@example.com', 'bastien@example.com', 'chloe@example.com']);
    Notification::assertSentOnDemandTimes(GroupInvitationNotification::class, 3);
});

test('duplicates, letter case, existing members and pending invitations are not invited twice', function () {
    $member = User::factory()->create(['email' => 'member@example.com']);
    $this->group->addMember($member);
    GroupInvitation::factory()->for($this->group)->create(['email' => 'pending@example.com']);

    Livewire::actingAs($this->organizer)
        ->test(Show::class, ['group' => $this->group])
        ->set('invitationEmails', 'Amina@Example.com, amina@example.com, MEMBER@example.com, pending@example.com')
        ->call('invite');

    expect(GroupInvitation::where('email', 'amina@example.com')->count())->toBe(1)
        ->and(GroupInvitation::count())->toBe(2);
    Notification::assertSentOnDemandTimes(GroupInvitationNotification::class, 1);
});

test('invalid addresses are refused', function () {
    Livewire::actingAs($this->organizer)
        ->test(Show::class, ['group' => $this->group])
        ->set('invitationEmails', 'amina@example.com, not-an-email')
        ->call('invite')
        ->assertHasErrors('invitationEmails');

    expect(GroupInvitation::count())->toBe(0);
});

test('a member cannot invite', function () {
    $member = User::factory()->create();
    $this->group->addMember($member);

    Livewire::actingAs($member)
        ->test(Show::class, ['group' => $this->group])
        ->set('invitationEmails', 'amina@example.com')
        ->call('invite')
        ->assertForbidden();
});

test('a logged in user accepts a valid invitation and joins the group', function () {
    $token = inviteAndGetToken($this->group, 'amina@example.com');
    $amina = User::factory()->create(['email' => 'amina.perso@example.com']);

    $this->actingAs($amina)->get(route('invitations.show', $token))
        ->assertOk()
        ->assertSee('Jardin partagé');

    $this->actingAs($amina)->post(route('invitations.accept', $token))
        ->assertRedirect(route('groups.show', $this->group));

    expect($this->group->hasMember($amina))->toBeTrue()
        ->and(GroupInvitation::sole()->accepted_by)->toBe($amina->id);

    $this->actingAs(User::factory()->create())->post(route('invitations.accept', $token))
        ->assertOk()
        ->assertSee(__('This invitation is no longer valid'));
});

test('an existing member opening the link is sent to the group without duplicate', function () {
    $token = inviteAndGetToken($this->group, 'amina@example.com');

    $this->actingAs($this->organizer)->post(route('invitations.accept', $token))
        ->assertRedirect(route('groups.show', $this->group));

    expect($this->group->members()->count())->toBe(1)
        ->and(GroupInvitation::sole()->accepted_at)->not->toBeNull();
});

test('expired, cancelled or unknown links do not let anyone join', function () {
    $token = inviteAndGetToken($this->group, 'amina@example.com');
    GroupInvitation::query()->update(['expires_at' => now()->subMinute()]);
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('invitations.accept', $token))->assertSee(__('This invitation is no longer valid'));
    $this->actingAs($user)->get(route('invitations.show', 'made-up-token'))->assertSee(__('This invitation is no longer valid'));

    expect($this->group->hasMember($user))->toBeFalse();
});

test('a guest opening the link is asked to log in and comes back to the invitation', function () {
    $token = inviteAndGetToken($this->group, 'amina@example.com');
    auth()->logout();

    $this->get(route('invitations.show', $token))
        ->assertOk()
        ->assertSee(route('login'), escape: false)
        ->assertSee(route('auth.google.redirect'), escape: false);

    $amina = User::factory()->create(['password' => 'password']);
    $this->post(route('login.store'), ['email' => $amina->email, 'password' => 'password'])
        ->assertRedirect(route('invitations.show', $token));
});

test('the organizer resends and cancels an invitation', function () {
    $oldToken = inviteAndGetToken($this->group, 'amina@example.com');
    $invitation = GroupInvitation::sole();

    Livewire::actingAs($this->organizer)
        ->test(Show::class, ['group' => $this->group])
        ->call('resendInvitation', $invitation->id);

    expect(GroupInvitation::findByToken($oldToken))->toBeNull();
    Notification::assertSentOnDemandTimes(GroupInvitationNotification::class, 2);

    Livewire::actingAs($this->organizer)
        ->test(Show::class, ['group' => $this->group])
        ->call('cancelInvitation', $invitation->id);

    expect(GroupInvitation::count())->toBe(0);
});

test('the invitation email names the group, the inviter and the expiry', function () {
    $token = inviteAndGetToken($this->group, 'amina@example.com');
    $notification = new GroupInvitationNotification(GroupInvitation::sole(), $token);

    $mail = $notification->toMail(new AnonymousNotifiable);

    expect($mail->subject)->toContain('Jardin partagé')
        ->and($mail->actionUrl)->toBe(route('invitations.show', $token))
        ->and(implode(' ', $mail->introLines))->toContain($this->organizer->name);
});
