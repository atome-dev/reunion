<?php

use App\Livewire\Groups\Show;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\MeetingSlot;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->group = Group::factory()->create(['name' => 'Jardin partagé']);
    $this->organizer = $this->group->owner;
    $this->member = User::factory()->create(['name' => 'Bastien']);
    $this->group->addMember($this->member);
});

test('members see the group, its members and its meetings', function () {
    $meeting = Meeting::factory()->for($this->group)->create(['title' => 'Assemblée de rentrée']);
    MeetingSlot::factory()->for($meeting)->create(['starts_at' => now()->addWeek()]);

    $this->actingAs($this->member)
        ->get(route('groups.show', $this->group))
        ->assertOk()
        ->assertSee('Jardin partagé')
        ->assertSee('Bastien')
        ->assertSee('Assemblée de rentrée');
});

test('outsiders get a 404', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('groups.show', $this->group))
        ->assertNotFound();
});

test('the organizer renames and deletes the group', function () {
    Livewire::actingAs($this->organizer)
        ->test(Show::class, ['group' => $this->group])
        ->set('name', 'Jardin des Lilas')
        ->call('rename')
        ->assertHasNoErrors();

    expect($this->group->fresh()->name)->toBe('Jardin des Lilas');

    Livewire::actingAs($this->organizer)
        ->test(Show::class, ['group' => $this->group])
        ->call('deleteGroup')
        ->assertRedirect(route('dashboard'));

    expect(Group::count())->toBe(0);
});

test('the organizer removes a member', function () {
    Livewire::actingAs($this->organizer)
        ->test(Show::class, ['group' => $this->group])
        ->call('removeMember', $this->member->id);

    expect($this->group->hasMember($this->member))->toBeFalse();
});

test('the organizer cannot remove themselves', function () {
    Livewire::actingAs($this->organizer)
        ->test(Show::class, ['group' => $this->group])
        ->call('removeMember', $this->organizer->id)
        ->assertForbidden();
});

test('a member cannot manage the group', function (string $action, array $arguments) {
    Livewire::actingAs($this->member)
        ->test(Show::class, ['group' => $this->group])
        ->set('name', 'Piraté')
        ->call($action, ...$arguments)
        ->assertForbidden();
})->with([
    'rename' => ['rename', []],
    'delete' => ['deleteGroup', []],
    'remove a member' => ['removeMember', [1]],
]);

test('a member leaves the group but the organizer cannot', function () {
    Livewire::actingAs($this->member)
        ->test(Show::class, ['group' => $this->group])
        ->call('leave')
        ->assertRedirect(route('dashboard'));

    expect($this->group->hasMember($this->member))->toBeFalse();

    Livewire::actingAs($this->organizer)
        ->test(Show::class, ['group' => $this->group])
        ->call('leave')
        ->assertForbidden();
});
