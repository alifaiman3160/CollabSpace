{{-- One page for every role (student, lecturer, administrator): the same title, left edge, padding and width as the other pages, from the shared layout. --}}
<x-layouts.app title="My account — CollabSpace" heading="My account" :subtitle="$user->email">

    <div class="flex flex-col gap-[18px]">

        <x-flash />

        {{-- Three equal cards of one height at 1280px and wider; two columns (profile across the top) from 900px; one below. --}}
        <div class="grid items-stretch gap-[18px] min-[900px]:grid-cols-2 min-[1280px]:grid-cols-3" data-account-cards>

            {{-- Who you are. Read-only: these are administered, not self-served. A flat band in the role's own soft colour,
                 the avatar half over its edge, the name and chips centred in the space between, and the user's own
                 numbers at the bottom, so the card is full at whatever height the row stretches it to. --}}
            @php
                $band = \App\Support\SubjectPalette::COLOURS[['student' => 0, 'lecturer' => 1, 'admin' => 3][$user->role] ?? 0];
                $facts = collect($stats)->push(['label' => 'Member since', 'value' => $user->created_at?->format('M Y') ?? '—']);
                // Two or three tiles to a row.
                $columns = [1 => 'grid-cols-1', 2 => 'grid-cols-2', 3 => 'grid-cols-3', 4 => 'grid-cols-2'][$facts->count()] ?? 'grid-cols-2';
            @endphp
            <section class="flex flex-col overflow-hidden rounded-panel border border-surface-line bg-surface shadow-subtle min-[900px]:col-span-2 min-[1280px]:col-span-1" data-profile-card>
                <div class="h-24 shrink-0" style="background-color: {{ $band['accent'] }};" aria-hidden="true" data-profile-band></div>

                <div class="flex flex-1 flex-col justify-between gap-6 px-6 pb-6">
                    <div class="flex flex-1 flex-col items-center justify-center gap-3 text-center">
                        <x-avatar :name="$user->name" size="lg" class="relative z-10 -mt-12 !size-24 !text-h1 ring-4 ring-surface" />

                        <span class="break-words text-h2 font-extrabold leading-tight tracking-title">{{ $user->name }}</span>

                        <div class="flex flex-wrap items-center justify-center gap-2">
                            <x-role-badge :role="$user->role" />
                            <x-status-badge :active="$user->is_active" />
                            @if ($user->matric_no)
                                <x-badge tone="neutral">{{ $user->matric_no }}</x-badge>
                            @endif
                        </div>
                    </div>

                    <dl class="grid {{ $columns }} gap-2 border-t border-surface-line pt-4" data-profile-stats>
                        @foreach ($facts as $fact)
                            <div class="flex flex-col items-center gap-0.5 rounded-card bg-surface-soft px-2 py-3 text-center">
                                <dt class="text-label font-bold uppercase tracking-eyebrow text-ink-300">{{ $fact['label'] }}</dt>
                                <dd class="text-h3 font-extrabold leading-tight tracking-stat">{{ $fact['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </section>
            {{-- Details --}}
            <form method="POST" action="{{ route('account.update') }}" class="flex flex-col gap-5 rounded-panel border border-surface-line bg-surface p-6 shadow-subtle">
                @csrf
                @method('PATCH')

                <h2 class="flex items-center gap-2.5 text-h3 font-bold tracking-title">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-[10px] bg-periwinkle-soft text-periwinkle-link"><x-icon name="user" size="16" /></span>
                    Your details
                </h2>

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

                {{-- Set by the administrator, so it can be read here and not changed. --}}
                <div class="flex flex-col gap-2" data-readonly-field>
                    <x-input-label for="readonly-id" :value="$user->isStudent() ? 'Student ID' : 'Role'" />
                    <div class="relative">
                        <input id="readonly-id" type="text" readonly aria-readonly="true"
                               value="{{ $user->isStudent() ? ($user->matric_no ?? '—') : ucfirst($user->role) }}"
                               class="h-[52px] w-full cursor-not-allowed rounded-[16px] border border-surface-line bg-surface-muted px-[18px] pr-11 text-[14px] font-medium text-ink-400 focus:outline-none focus:ring-2 focus:ring-ink/10" />
                        <x-icon name="lock" size="16" class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-ink-300" />
                    </div>
                    <p class="text-label text-ink-400">Set by your administrator.</p>
                </div>

                <div class="mt-auto">
                    <x-button type="submit">Save details</x-button>
                </div>
            </form>

            {{-- Password --}}
            <form method="POST" action="{{ route('account.password') }}" class="flex flex-col gap-5 rounded-panel border border-surface-line bg-surface p-6 shadow-subtle">
                @csrf
                @method('PUT')

                <h2 class="flex items-center gap-2.5 text-h3 font-bold tracking-title">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-[10px] bg-periwinkle-soft text-periwinkle-link"><x-icon name="lock" size="16" /></span>
                    Change password
                </h2>

                <div class="flex flex-col gap-2">
                    <x-input-label for="current_password" value="Current password" />
                    <x-text-input id="current_password" name="current_password" type="password" required autocomplete="current-password" />
                    <x-input-error :messages="$errors->get('current_password')" />
                </div>

                <div class="flex flex-col gap-2">
                    <x-input-label for="password" value="New password" />
                    <x-text-input id="password" name="password" type="password" required autocomplete="new-password" />
                    <x-input-error :messages="$errors->get('password')" />
                    <p class="text-label text-ink-400" data-password-hint>Use at least 8 characters.</p>
                </div>

                <div class="flex flex-col gap-2">
                    <x-input-label for="password_confirmation" value="Confirm new password" />
                    <x-text-input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" />
                    <x-input-error :messages="$errors->get('password_confirmation')" />
                </div>

                <div class="mt-auto">
                    <x-button type="submit">Change password</x-button>
                </div>
            </form>
        </div>

        <p class="flex items-start gap-2.5 rounded-card bg-surface-muted px-4 py-3 text-label leading-[1.5] text-ink-400" data-closing-note>
            <x-icon name="shield" size="15" class="mt-0.5 shrink-0 text-ink-300" />
            <span>
                Need your account closed or suspended? That is done by the administrator — accounts are kept
                so contribution history stays intact for grading.
            </span>
        </p>
    </div>

</x-layouts.app>