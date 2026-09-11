<x-guest-layout title="Sign in — CollabSpace">

    <div class="flex flex-col gap-1.5">
        <h1 class="text-[34px] font-extrabold leading-[1.08] tracking-stat">Sign in</h1>
        <p class="text-nav text-ink-400">Welcome back — your groups have been busy.</p>
    </div>

    <x-auth-session-status :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="flex flex-col gap-4">
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

        <x-auth-field
            name="password"
            label="Password"
            icon="lock"
            reveal
            placeholder="Enter your password"
            required
            autocomplete="current-password">
            <x-slot:aside>
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}"
                       class="text-meta font-bold text-periwinkle-link hover:underline">Forgot?</a>
                @endif
            </x-slot:aside>
        </x-auth-field>

        {{-- Keep me signed in --}}
        <label class="inline-flex cursor-pointer items-center gap-[9px]" x-data="{ on: false }">
            <input type="checkbox" name="remember" x-model="on" class="peer sr-only">
            <span class="flex size-5 shrink-0 items-center justify-center rounded-[6px] transition-colors"
                  :class="on ? 'bg-ink' : 'bg-surface-muted'">
                <span x-show="on" x-cloak><x-icon name="check" size="13" class="text-white [stroke-width:3]" /></span>
            </span>
            <span class="text-meta font-semibold text-ink-600">Keep me signed in</span>
        </label>

        <x-primary-button class="mt-1 w-full">
            <span>Sign in</span>
            <x-icon name="arrow-right" size="17" class="[stroke-width:2]" />
        </x-primary-button>
    </form>

    {{-- Accounts are issued by the Administrator; there is no self-registration. --}}
    <p class="text-meta leading-[1.5] text-ink-400">
        No account yet? Your faculty administrator creates it and sends you a temporary password.
    </p>

</x-guest-layout>
