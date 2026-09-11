<x-guest-layout title="Confirm password — CollabSpace">

    <div class="flex flex-col gap-1.5">
        <h1 class="text-[34px] font-extrabold leading-[1.08] tracking-stat">Confirm password</h1>
        <p class="text-nav leading-[1.55] text-ink-400">
            This is a secure area. Please confirm your password before continuing.
        </p>
    </div>

    <form method="POST" action="{{ route('password.confirm') }}" class="flex flex-col gap-4">
        @csrf

        <x-auth-field
            name="password"
            label="Password"
            icon="lock"
            reveal
            placeholder="Enter your password"
            required
            autofocus
            autocomplete="current-password" />

        <x-primary-button class="mt-1 w-full">
            <span>Confirm</span>
            <x-icon name="arrow-right" size="17" class="[stroke-width:2]" />
        </x-primary-button>
    </form>

</x-guest-layout>
