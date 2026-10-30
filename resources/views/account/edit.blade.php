<x-layouts.app title="My account — CollabSpace"
               heading="My account"
               :subtitle="$user->email">

    {{-- Capped at a readable measure, then split into two columns once there is
         room. A single narrow column left most of a desktop screen empty. --}}
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-[18px]">

        <x-flash />

        {{-- Read-only facts: these are administered, not self-served. --}}
        <section class="flex flex-wrap items-center gap-3 rounded-panel border border-surface-line bg-surface-soft p-5">
            <x-avatar :name="$user->name" size="lg" />
            <div class="flex min-w-0 flex-col gap-1">
                <span class="truncate text-h3 font-bold tracking-title">{{ $user->name }}</span>
                <div class="flex flex-wrap items-center gap-2">
                    <x-role-badge :role="$user->role" />
                    <x-status-badge :active="$user->is_active" />
                    @if ($user->matric_no)
                        <x-badge tone="neutral">{{ $user->matric_no }}</x-badge>
                    @endif
                </div>
            </div>
        </section>

        <div class="grid items-start gap-[18px] lg:grid-cols-2">

        {{-- Details --}}
        <form method="POST" action="{{ route('account.update') }}" class="flex flex-col gap-5 rounded-panel border border-surface-line bg-surface shadow-subtle p-6">
            @csrf
            @method('PATCH')

            <h2 class="text-h3 font-bold tracking-title">Your details</h2>

            <div class="flex flex-col gap-2">
                <x-input-label for="name" value="Full name" />
                <x-text-input id="name" name="name" type="text" required :value="old('name', $user->name)" />
                <x-input-error :messages="$errors->get('name')" />
            </div>

            <div class="flex flex-col gap-2">
                <x-input-label for="email" value="Email address" />
                <x-text-input id="email" name="email" type="email" required :value="old('email', $user->email)" />
                <x-input-error :messages="$errors->get('email')" />
                <p class="text-label text-ink-400">
                    You sign in with this address. Changing it means verifying the new one.
                </p>
            </div>

            <div>
                <x-button type="submit">Save details</x-button>
            </div>
        </form>

        {{-- Password --}}
        <form method="POST" action="{{ route('account.password') }}" class="flex flex-col gap-5 rounded-panel border border-surface-line bg-surface shadow-subtle p-6">
            @csrf
            @method('PUT')

            <h2 class="text-h3 font-bold tracking-title">Change password</h2>

            <div class="flex flex-col gap-2">
                <x-input-label for="current_password" value="Current password" />
                <x-text-input id="current_password" name="current_password" type="password" required autocomplete="current-password" />
                <x-input-error :messages="$errors->get('current_password')" />
            </div>

            <div class="flex flex-col gap-2">
                <x-input-label for="password" value="New password" />
                <x-text-input id="password" name="password" type="password" required autocomplete="new-password" />
                <x-input-error :messages="$errors->get('password')" />
            </div>

            <div class="flex flex-col gap-2">
                <x-input-label for="password_confirmation" value="Confirm new password" />
                <x-text-input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" />
                <x-input-error :messages="$errors->get('password_confirmation')" />
            </div>

            <div>
                <x-button type="submit">Change password</x-button>
            </div>
        </form>

        </div>

        <p class="max-w-xl text-label leading-[1.5] text-ink-400">
            Need your account closed or suspended? That is done by the administrator — accounts are kept
            so contribution history stays intact for grading.
        </p>
    </div>

</x-layouts.app>
