<?php

use App\Models\AvailabilityDay;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 2)->setTime(10, 0));
});

test('the migration keeps the most recent row per user and day', function () {
    $migration = require database_path('migrations/2026_10_03_200000_make_availability_days_personal.php');
    $migration->down();

    $user = User::factory()->create();
    $other = User::factory()->create();
    $first = Meeting::factory()->create();
    $second = Meeting::factory()->create();

    DB::table('availability_days')->insert([
        ['meeting_id' => $first->id, 'user_id' => $user->id, 'day' => '2026-11-05', 'cells' => str_repeat('p', 28), 'created_at' => now(), 'updated_at' => now()->subDay()],
        ['meeting_id' => $second->id, 'user_id' => $user->id, 'day' => '2026-11-05', 'cells' => str_repeat('d', 28), 'created_at' => now(), 'updated_at' => now()],
        ['meeting_id' => $first->id, 'user_id' => $other->id, 'day' => '2026-11-05', 'cells' => str_repeat('p', 28), 'created_at' => now(), 'updated_at' => now()],
    ]);

    $migration->up();

    expect(Schema::hasColumn('availability_days', 'meeting_id'))->toBeFalse()
        ->and(AvailabilityDay::where('user_id', $user->id)->pluck('cells', 'day')->all())->toBe(['2026-11-05' => str_repeat('d', 28)])
        ->and(AvailabilityDay::where('user_id', $other->id)->count())->toBe(1);
});

test('a meeting reads its current members personal rows within its range', function () {
    $group = Group::factory()->create();
    $member = User::factory()->create();
    $group->addMember($member);
    $meeting = Meeting::factory()->for($group)->create(['range_start' => '2026-11-04', 'range_end' => '2026-11-06', 'deadline' => '2026-11-03']);

    AvailabilityDay::factory()->for($member)->cells(str_repeat('p', 28))->create(['day' => '2026-11-05']);
    AvailabilityDay::factory()->for($member)->cells(str_repeat('d', 28))->create(['day' => '2026-11-09']);

    expect($meeting->cellsByMember())->toBe([$member->id => ['2026-11-05' => str_repeat('p', 28)]])
        ->and($meeting->respondentIds()->all())->toBe([$member->id]);
});

test('a member with rows only outside the range has not answered', function () {
    $group = Group::factory()->create();
    $member = User::factory()->create();
    $group->addMember($member);
    $meeting = Meeting::factory()->for($group)->create(['range_start' => '2026-11-04', 'range_end' => '2026-11-06', 'deadline' => '2026-11-03']);

    AvailabilityDay::factory()->for($member)->cells(str_repeat('p', 28))->create(['day' => '2026-11-20']);

    expect($meeting->respondentIds()->all())->toBe([]);
});

test('a former member keeps their availability but is not counted', function () {
    $group = Group::factory()->create();
    $former = User::factory()->create();
    $group->addMember($former);
    $meeting = Meeting::factory()->for($group)->create(['range_start' => '2026-11-04', 'range_end' => '2026-11-06', 'deadline' => '2026-11-03']);
    AvailabilityDay::factory()->for($former)->cells(str_repeat('p', 28))->create(['day' => '2026-11-05']);

    $group->removeMember($former);

    expect(AvailabilityDay::where('user_id', $former->id)->count())->toBe(1)
        ->and($meeting->fresh()->cellsByMember())->toBe([]);
});

test('the last editable day is three months ahead in Paris time', function () {
    $this->travelTo(now('UTC')->setDate(2026, 11, 30)->setTime(23, 30));

    expect(AvailabilityDay::lastEditableDay()->toDateString())->toBe('2027-03-01');
});
