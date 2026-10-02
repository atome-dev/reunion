<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\GroupInvitationController;
use App\Livewire\Dashboard;
use App\Livewire\Groups\Show as GroupsShow;
use App\Livewire\Meetings\Form as MeetingsForm;
use App\Livewire\Meetings\Show as MeetingsShow;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware('guest')->group(function () {
    Route::get('auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
});

Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');

Route::middleware('auth')->group(function () {
    Route::get('auth/google/confirm', [GoogleAuthController::class, 'confirm'])->name('auth.google.confirm');
});

Route::get('invitations/{token}', [GroupInvitationController::class, 'show'])->name('invitations.show');
Route::post('invitations/{token}', [GroupInvitationController::class, 'accept'])->middleware('auth')->name('invitations.accept');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', Dashboard::class)->name('dashboard');
    Route::livewire('groups/{group}', GroupsShow::class)->name('groups.show');
    Route::livewire('groups/{group}/meetings/create', MeetingsForm::class)->name('meetings.create');
    Route::livewire('meetings/{meeting}', MeetingsShow::class)->name('meetings.show');
});

require __DIR__.'/settings.php';
