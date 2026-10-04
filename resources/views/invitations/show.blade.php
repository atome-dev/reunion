<x-layouts::auth :title="__('Invitation to join :group', ['group' => $invitation->group->name])">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Join :group', ['group' => $invitation->group->name])"
            :description="__(':inviter invites you to join this group to plan its meetings together.', ['inviter' => $invitation->inviter?->name ?? __('A former member')])"
        />

        @auth
            <form method="POST" action="{{ route('invitations.accept', $token) }}">
                @csrf
                <flux:button variant="primary" type="submit" class="w-full">{{ __('Join the group') }}</flux:button>
            </form>
        @else
            <x-google-button :href="route('auth.google.redirect')" :label="__('Continue with Google')" />
            <flux:button :href="route('login')" class="w-full">{{ __('Log in') }}</flux:button>
            @if (Route::has('register'))
                <flux:button variant="primary" :href="route('register')" class="w-full">{{ __('Create an account') }}</flux:button>
            @endif
        @endauth
    </div>
</x-layouts::auth>
