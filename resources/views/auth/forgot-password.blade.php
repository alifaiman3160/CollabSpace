<x-guest-layout title="Reset your password — CollabSpace">

    <div class="flex flex-col gap-1.5">
        <h1 class="text-[34px] font-extrabold leading-[1.08] tracking-stat">Forgot password</h1>
        <p class="text-nav leading-[1.55] text-ink-400">
            Give us the email on your account and we will send a link to choose a new password.
        </p>
    </div>

    <x-auth-session-status :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-4">
        @csrf

        <x-auth-field
            name="email"
            label="Email address"
            icon="mail"
            type="email"
            :value="old('email')"
            placeholder="you@student.collabspace.test"
            required
            autofocus
            autocomplete="username" />

        <x-primary-button class="mt-1 w-full">
            <span>Email password reset link</span>
            <x-icon name="arrow-right" size="17" class="[stroke-width:2]" />
        </x-primary-button>
    </form>

    <p class="text-meta text-ink-400">
        Remembered it?
        <a href="{{ route('login') }}" class="font-bold text-periwinkle-link hover:underline">Back to sign in</a>
    </p>

</x-guest-layout>
