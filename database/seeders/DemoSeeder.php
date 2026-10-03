<?php

namespace Database\Seeders;

use App\Actions\Groups\CreateGroup;
use App\Models\AvailabilityDay;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo group with an availability request, for local screenshots.
 * Run with: php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(CreateGroup $createGroup): void
    {
        $organizer = User::query()->oldest('id')->first() ?? User::factory()->create(['email' => 'test@reunion.test']);
        $group = $createGroup($organizer, 'Jardin partagé des Lilas (démo)');

        $members = collect(['Amina', 'Bastien', 'Chloé', 'David', 'Élise', 'Farid'])
            ->map(fn (string $name) => User::factory()->create(['name' => $name]))
            ->each(fn (User $member) => $group->addMember($member));

        $meeting = new Meeting([
            'title' => 'Assemblée de rentrée',
            'location' => 'Salle des fêtes',
            'range_start' => today()->addDays(3)->toDateString(),
            'range_end' => today()->addDays(16)->toDateString(),
            'deadline' => today()->addDays(2)->toDateString(),
        ]);
        $meeting->group()->associate($group);
        $meeting->creator()->associate($organizer);
        $meeting->save();

        $patterns = ['pppppp', 'dddddd', 'ppdddd', 'pppp00', '00pppp'];

        foreach ($members->take(5) as $index => $member) {
            foreach (array_slice($meeting->rangeDays(), $index % 2, 8) as $offset => $day) {
                $cells = str_repeat('0', 20).$patterns[($index + $offset) % count($patterns)].'00';

                $availability = new AvailabilityDay(['cells' => $cells]);
                $availability->meeting()->associate($meeting);
                $availability->user()->associate($member);
                $availability->day = $day;
                $availability->save();
            }
        }
    }
}
