<x-guest-layout title="Sign in — CollabSpace" :intro="true">

    <div class="flex flex-col gap-1.5">
        <h1 class="text-[34px] font-extrabold leading-[1.08] tracking-stat" data-reveal style="--i:1">Sign in</h1>
        <p class="text-nav text-ink-400" data-reveal style="--i:2">Welcome back — your groups have been busy.</p>
    </div>

    <x-auth-session-status :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="cs-login flex flex-col gap-4">
        @csrf

        <div data-reveal style="--i:3">
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
        </div>

        <div data-reveal style="--i:4">
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
        </div>

        {{-- Keep me signed in --}}
        <label class="inline-flex w-fit cursor-pointer items-center gap-[9px]" x-data="{ on: false }" data-reveal style="--i:5">
            <input type="checkbox" name="remember" x-model="on" class="peer sr-only">
            <span class="flex size-5 shrink-0 items-center justify-center rounded-[6px] transition-colors"
                  :class="on ? 'bg-ink' : 'bg-surface-muted'">
                <span x-show="on" x-cloak><x-icon name="check" size="13" class="text-white [stroke-width:3]" /></span>
            </span>
            <span class="text-meta font-semibold text-ink-600">Keep me signed in</span>
        </label>

        <div class="mt-1" data-reveal style="--i:6">
        <x-primary-button class="w-full">
            <span>Sign in</span>
            <x-icon name="arrow-right" size="17" class="cs-arrow [stroke-width:2]" />
        </x-primary-button>
        </div>
    </form>

    {{-- Accounts are issued by the Administrator; there is no self-registration. --}}
    <p class="text-meta leading-[1.5] text-ink-400" data-reveal style="--i:7">
        No account yet? Your faculty administrator creates it and sends you a temporary password.
    </p>

</x-guest-layout>
