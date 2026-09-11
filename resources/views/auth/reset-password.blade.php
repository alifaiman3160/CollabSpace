<x-guest-layout title="Choose a new password — CollabSpace">

    <div class="flex flex-col gap-1.5">
        <h1 class="text-[34px] font-extrabold leading-[1.08] tracking-stat">New password</h1>
        <p class="text-nav text-ink-400">Choose something you have not used on CollabSpace before.</p>
    </div>

    <form method="POST" action="{{ route('password.store') }}" class="flex flex-col gap-4">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-auth-field
            name="email"
            label="Email address"
            icon="mail"
            type="email"
            :value="old('email', $request->email)"
            required
            autofocus
            autocomplete="username" />

        <x-auth-field
            name="password"
            label="New password"
            icon="lock"
            reveal
            placeholder="At least 8 characters"
            required
            autocomplete="new-password" />

        <x-auth-field
            name="password_confirmation"
            label="Confirm new password"
            icon="lock"
            reveal
            placeholder="Type it once more"
            required
            autocomplete="new-password" />

        <x-primary-button class="mt-1 w-full">
            <span>Reset password</span>
            <x-icon name="arrow-right" size="17" class="[stroke-width:2]" />
        </x-primary-button>
    </form>

</x-guest-layout>
