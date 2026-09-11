<x-guest-layout title="Verify your email — CollabSpace">

    <div class="flex flex-col gap-1.5">
        <h1 class="text-[34px] font-extrabold leading-[1.08] tracking-stat">Verify email</h1>
        <p class="text-nav leading-[1.55] text-ink-400">
            Thanks for signing up. Please confirm your email address by clicking the link we just
            sent you. If it did not arrive, we will gladly send another.
        </p>
    </div>

    @if (session('status') == 'verification-link-sent')
        <x-auth-session-status status="A fresh verification link has been sent to your email address." />
    @endif

    <div class="flex flex-wrap items-center gap-3">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-primary-button>
                <span>Resend verification email</span>
            </x-primary-button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <x-secondary-button type="submit">
                <x-icon name="logout" size="16" />
                <span>Log out</span>
            </x-secondary-button>
        </form>
    </div>

</x-guest-layout>
