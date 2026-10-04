<?php

use App\Actions\Meetings\BuildGoogleCalendarUrl;
use App\Models\Group;
use App\Models\Meeting;
use App\Notifications\MeetingConfirmedNotification;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->group = Group::factory()->create();
    $this->meeting = Meeting::factory()->for($this->group)
        ->confirmed(CarbonImmutable::parse('2026-11-07 17:00:00', 'UTC'))
        ->create(['title' => 'Assemblée & rentrée', 'location' => 'Salle des fêtes']);
});

test('the link opens a prefilled Google Calendar event in UTC', function () {
    $url = (new BuildGoogleCalendarUrl)($this->meeting);

    expect($url)->toStartWith('https://calendar.google.com/calendar/render?');

    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    expect($query)->toMatchArray([
        'action' => 'TEMPLATE',
        'text' => 'Assemblée & rentrée',
        'dates' => '20261107T170000Z/20261107T190000Z',
        'location' => 'Salle des fêtes',
        'details' => route('meetings.show', $this->meeting),
    ]);
});

test('the location is left out when there is none', function () {
    $this->meeting->update(['location' => null]);

    parse_str(parse_url((new BuildGoogleCalendarUrl)($this->meeting), PHP_URL_QUERY), $query);

    expect($query)->not->toHaveKey('location');
});

test('the confirmed meeting page offers Google Calendar next to the calendar file', function () {
    $this->actingAs($this->group->owner)->get(route('meetings.show', $this->meeting))
        ->assertSee(route('meetings.calendar', $this->meeting))
        ->assertSee(__('Add to Google Calendar'))
        ->assertSee('calendar.google.com/calendar/render', false);
});

test('the confirmation email links to Google Calendar', function () {
    $mail = (new MeetingConfirmedNotification($this->meeting))->toMail($this->group->owner);

    expect($mail->render()->toHtml())->toContain('calendar.google.com/calendar/render');
});
