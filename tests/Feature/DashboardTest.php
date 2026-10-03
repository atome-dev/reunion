<?php

use App\Livewire\Dashboard;
use App\Models\AvailabilityDay;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\MeetingSlot;
use App\Models\SlotVote;
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
    Meeting::factory()->for($group)->create(['title' => 'Assemblée à répondre']);
    $answered = Meeting::factory()->for($group)->create(['title' => 'Réunion déjà répondue']);
    AvailabilityDay::factory()->for($answered)->for($user)->cells(str_repeat('p', 28))->create();
    Meeting::factory()->for($group)->confirmed(now()->subWeek())->create(['title' => 'Réunion passée']);
    Meeting::factory()->create(['title' => 'Réunion d\'un autre groupe']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSee('Jardin partagé')
        ->assertSee('Assemblée à répondre')
        ->assertDontSee('Réunion déjà répondue')
        ->assertDontSee('Réunion passée')
        ->assertDontSee('Réunion d\'un autre groupe');
});

test('pending answers include grids to fill and votes to complete, confirmed meetings are listed', function () {
    $user = User::factory()->create();
    $group = Group::factory()->create();
    $group->addMember($user);
    Meeting::factory()->for($group)->create(['title' => 'Grille à remplir']);
    $voting = Meeting::factory()->for($group)->voting()->create(['title' => 'Vote à faire']);
    foreach ([1, 2] as $day) {
        MeetingSlot::factory()->for($voting)->create(['starts_at' => now()->addDays($day)->setTime(18, 30)]);
    }
    $voted = Meeting::factory()->for($group)->voting()->create(['title' => 'Vote déjà fait']);
    foreach ([1, 2] as $day) {
        $slot = MeetingSlot::factory()->for($voted)->create(['starts_at' => now()->addDays($day)->setTime(18, 30)]);
        SlotVote::factory()->for($slot, 'slot')->for($user)->create();
    }
    Meeting::factory()->for($group)->confirmed(now()->addWeek())->create(['title' => 'Réunion confirmée']);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertSee('Grille à remplir')
        ->assertSee('Vote à faire')
        ->assertDontSee('Vote déjà fait')
        ->assertSee('Réunion confirmée');
});

test('a member who joined during the collection is asked for their availability', function () {
    $group = Group::factory()->create();
    Meeting::factory()->for($group)->create(['title' => 'Demande en cours']);
    $newcomer = User::factory()->create();
    $group->addMember($newcomer);

    $this->actingAs($newcomer)->get(route('dashboard'))->assertSee('Demande en cours');
});
