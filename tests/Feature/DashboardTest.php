<?php

use App\Enums\AvailabilityStatus;
use App\Livewire\Dashboard;
use App\Models\Availability;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\MeetingSlot;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('a new user is invited to create their first group', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSee(__('Create my first group'));
});

test('creating a group makes the user its organizer and opens it', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->set('name', 'Bureau de l\'AMAP')
        ->call('createGroup')
        ->assertHasNoErrors()
        ->assertRedirect(route('groups.show', Group::sole()));

    expect(Group::sole()->isOrganizer($user))->toBeTrue();
});

test('a group needs a name', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(Dashboard::class)
        ->set('name', '')
        ->call('createGroup')
        ->assertHasErrors(['name' => 'required']);
});

test('the dashboard lists my groups and only the upcoming meetings I have not answered', function () {
    $user = User::factory()->create();
    $group = Group::factory()->create(['name' => 'Jardin partagé']);
    $group->addMember($user);
    $toAnswer = Meeting::factory()->for($group)->create(['title' => 'Assemblée à répondre']);
    MeetingSlot::factory()->for($toAnswer)->create(['starts_at' => now()->addWeek()]);
    $answered = Meeting::factory()->for($group)->create(['title' => 'Réunion déjà répondue']);
    $slot = MeetingSlot::factory()->for($answered)->create(['starts_at' => now()->addWeek()]);
    Availability::factory()->for($slot, 'slot')->for($user)->create(['status' => AvailabilityStatus::Remote]);
    $past = Meeting::factory()->for($group)->create(['title' => 'Réunion passée']);
    MeetingSlot::factory()->for($past)->create(['starts_at' => now()->subWeek()]);
    $otherGroup = Meeting::factory()->create(['title' => 'Réunion d\'un autre groupe']);
    MeetingSlot::factory()->for($otherGroup)->create(['starts_at' => now()->addWeek()]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSee('Jardin partagé')
        ->assertSee('Assemblée à répondre')
        ->assertDontSee('Réunion déjà répondue')
        ->assertDontSee('Réunion passée')
        ->assertDontSee('Réunion d\'un autre groupe');
});
