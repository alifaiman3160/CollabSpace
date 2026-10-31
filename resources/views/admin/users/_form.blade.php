{{--
    Shared fields for creating and editing an account.
    Expects $user (an existing User, or null when creating).
--}}
@php($user = $user ?? null)

{{-- Editing your own account: the role is shown but cannot be changed, the
     same way "Deactivate" is disabled on your own row. The controller refuses
     it as well — this only keeps the UI honest about what will be accepted. --}}
@php($lockRole = $user !== null && auth()->check() && $user->is(auth()->user()))

<div class="flex max-w-xl flex-col gap-5 rounded-panel border border-surface-line bg-surface p-6 shadow-subtle"
     x-data="{ role: '{{ old('role', $user?->role ?? 'student') }}' }">

    <div class="flex flex-col gap-2">
        <x-input-label for="name" value="Full name" />
        <x-text-input id="name" name="name" type="text" required autofocus
                      :value="old('name', $user?->name)"
                      placeholder="As it appears on the university record" />
        <x-input-error :messages="$errors->get('name')" />
    </div>

    <div class="flex flex-col gap-2">
        <x-input-label for="email" value="Email address" />
        <x-text-input id="email" name="email" type="email" required
                      :value="old('email', $user?->email)"
                      placeholder="name@collabspace.test" />
        <x-input-error :messages="$errors->get('email')" />
    </div>

    <div class="flex flex-col gap-2">
        <x-input-label value="Role" />

        @if ($lockRole)
            {{-- Carries the unchanged role through, so the rest of the form
                 still saves. --}}
            <input type="hidden" name="role" value="{{ $user->role }}">

            <div class="flex flex-wrap gap-2.5">
                @foreach (['student' => 'Student', 'lecturer' => 'Lecturer', 'admin' => 'Administrator'] as $value => $label)
                    <span @class([
                            'inline-flex h-11 items-center rounded-pill px-5 text-nav font-bold',
                            'bg-ink text-white' => $user->role === $value,
                            'bg-surface-muted text-ink-300' => $user->role !== $value,
                          ])
                          title="You cannot change your own role">
                        {{ $label }}
                    </span>
                @endforeach
            </div>

            <p class="text-meta text-ink-400">
                This is your own account. You cannot change your own role — another
                administrator can do it for you.
            </p>
        @else
            <div class="flex flex-wrap gap-2.5">
                @foreach (['student' => 'Student', 'lecturer' => 'Lecturer', 'admin' => 'Administrator'] as $value => $label)
                    <label class="cursor-pointer">
                        <input type="radio" name="role" value="{{ $value }}" x-model="role" class="peer sr-only">
                        <span class="inline-flex h-11 items-center rounded-pill bg-surface-muted px-5 text-nav font-bold
                                     text-ink-600 transition-colors peer-checked:bg-ink peer-checked:text-white
                                     hover:bg-surface-hover peer-checked:hover:bg-ink-hover">
                            {{ $label }}
                        </span>
                    </label>
                @endforeach
            </div>
        @endif

        <x-input-error :messages="$errors->get('role')" />
    </div>

    {{-- Only students carry a matriculation number. --}}
    <div class="flex flex-col gap-2" x-show="role === 'student'" x-cloak x-transition:enter="cs-t-enter" x-transition:enter-start="cs-t-from" x-transition:enter-end="cs-t-to" x-transition:leave="cs-t-leave" x-transition:leave-start="cs-t-to" x-transition:leave-end="cs-t-from">
        <x-input-label for="matric_no" value="Matriculation number" />
        <x-text-input id="matric_no" name="matric_no" type="text"
                      :value="old('matric_no', $user?->matric_no)"
                      placeholder="A21EC0011"
                      class="uppercase" />
        <x-input-error :messages="$errors->get('matric_no')" />
    </div>

    {{-- Only students carry a CGPA. It is typed in here by hand: nothing in
         CollabSpace is connected to an academic system, so the figure is only
         as current as the last time somebody edited this account. It is shown
         to lecturers, for forming balanced groups, and to nobody else. --}}
    <div class="flex flex-col gap-2" x-show="role === 'student'" x-cloak x-transition:enter="cs-t-enter" x-transition:enter-start="cs-t-from" x-transition:enter-end="cs-t-to" x-transition:leave="cs-t-leave" x-transition:leave-start="cs-t-to" x-transition:leave-end="cs-t-from">
        <x-input-label for="cgpa" value="CGPA (optional)" />
        <x-text-input id="cgpa" name="cgpa" type="number" step="0.01" min="0" max="4" inputmode="decimal"
                      :value="old('cgpa', $user?->cgpa)"
                      placeholder="3.47"
                      class="max-w-[140px]" />
        <p class="text-meta leading-[1.5] text-ink-400">
            Entered by hand — CollabSpace does not read this from any academic system, so it is only
            as up to date as you keep it. Lecturers see it when forming balanced groups; students
            never do. Leave it blank if you do not have it.
        </p>
        <x-input-error :messages="$errors->get('cgpa')" />
    </div>
</div>
