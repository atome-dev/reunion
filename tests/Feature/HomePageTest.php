<?php

use App\View\Components\DemoPoll;

test('home page is in french and shows the interactive demo poll', function () {
    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertSee('<html lang="fr"', escape: false)
        ->assertSee('Réunir tout le monde, sans le casse-tête')
        ->assertSee('data-you="0"', escape: false)
        ->assertSee('Exemple fictif');
});

test('demo poll picks the date gathering the most members', function () {
    $poll = new DemoPoll;

    expect($poll->best)->toBe(4)
        ->and($poll->tallies[4])->toBe(['onSite' => 6, 'remote' => 2]);
});

test('demo poll prefers more people on site when totals are tied', function () {
    $poll = new DemoPoll;
    $poll->tallies = [
        ['onSite' => 3, 'remote' => 4],
        ['onSite' => 5, 'remote' => 2],
    ];

    expect($poll->bestDateIndex())->toBe(1);
});

test('auth pages use the branded layout with the compact demo poll', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee(__('Log in to your account'))
        ->assertSee('Jardin partagé des Lilas')
        ->assertDontSee('data-you="0"', escape: false);
});

test('the home page explains how it works step by step before the closing call to action', function () {
    $response = $this->get(route('home'))->assertOk();

    $response->assertSeeInOrder([
        'Comment ça marche, en 30 secondes',
        'Créez votre groupe',
        'Invitez les membres',
        'Proposez des dates',
        'Chacun répond',
        'La date se dégage',
        'Votre prochaine réunion commence ici.',
    ]);
});
