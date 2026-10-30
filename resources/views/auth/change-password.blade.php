<x-guest-layout title="Choose a new password — CollabSpace">

    <div class="flex flex-col gap-1.5">
        <h1 class="text-[34px] font-extrabold leading-[1.08] tracking-stat">Set your password</h1>
        <p class="text-nav leading-[1.55] text-ink-400">
            Your account is still on the temporary password issued when it was created.
            Choose your own before you continue.
        </p>
    </div>

    <x-auth-session-status :status="session('status')" />

    <form method="POST" action="{{ route('password.change.update') }}" class="flex flex-col gap-4">
        @csrf
        @method('PUT')

        <x-auth-field
            name="current_password"
            label="Temporary password"
            icon="lock"
            reveal
            placeholder="The password you were given"
            required
            autofocus
            autocomplete="current-password" />

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
            <span>Update password and continue</span>
            <x-icon name="arrow-right" size="17" class="[stroke-width:2]" />
        </x-primary-button>
    </form>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="interactive text-meta font-bold text-ink-400 hover:text-ink hover:underline">
            Sign out instead
        </button>
    </form>

</x-guest-layout>
