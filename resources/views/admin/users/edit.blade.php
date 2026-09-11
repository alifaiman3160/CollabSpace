<x-layouts.app title="Edit user — CollabSpace"
               :heading="$user->name"
               :subtitle="'Joined '.$user->created_at->format('j F Y').' · '.$user->email">

@php
    // Built here, then passed through Js::from below. Blade compiles {{ }}
    // echoes inside a component attribute but NOT @ directives, so @js()
    // there would render literally and break the payload.
    $resetBody = $user->name."'s current password stops working immediately. "
        ."A new temporary password is generated and shown to you once, and they "
        ."must change it at next sign-in.";
    $deactivateBody = $user->name." will be signed out and blocked from signing in "
        ."again until an administrator reactivates the account. Their groups, tasks "
        ."and contribution history are kept.";
@endphp

    <div class="flex flex-col gap-7" x-data="{ confirm: null }">

        <x-flash />

        <div class="flex flex-wrap items-center gap-2">
            <x-role-badge :role="$user->role" />
            <x-status-badge :active="$user->is_active" :pending="$user->must_change_password" />
        </div>

        <form method="POST" action="{{ route('admin.users.update', $user) }}" class="flex flex-col gap-7">
            @csrf
            @method('PUT')

            @include('admin.users._form', ['user' => $user])

            <div class="flex flex-wrap items-center gap-3">
                <x-button type="submit">Save changes</x-button>
                <x-button variant="secondary" :href="route('admin.users')">Cancel</x-button>
            </div>
        </form>

        {{-- Destructive actions, kept away from the save button. --}}
        <section class="flex max-w-xl flex-col gap-4 rounded-panel border border-surface-line bg-surface shadow-subtle p-6">
            <div class="flex flex-col gap-1">
                <h2 class="text-h3 font-bold tracking-title">Account actions</h2>
                <p class="text-body text-ink-400">Both of these ask for confirmation first.</p>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                <x-button variant="secondary"
                    x-on:click="confirm = {
                        url: '{{ route('admin.users.password', $user) }}',
                        title: 'Reset this password?',
                        body: {{ Js::from($resetBody) }},
                        action: 'Reset password',
                        tone: 'danger',
                    }">Reset password</x-button>

                @if ($user->is(auth()->user()))
                    <x-button variant="secondary" disabled title="You cannot deactivate your own account">
                        Deactivate account
                    </x-button>
                @elseif ($user->is_active)
                    <x-button variant="secondary" class="!text-danger-text"
                        x-on:click="confirm = {
                            url: '{{ route('admin.users.status', $user) }}',
                            title: 'Deactivate this account?',
                            body: {{ Js::from($deactivateBody) }},
                            action: 'Deactivate',
                            tone: 'danger',
                        }">Deactivate account</x-button>
                @else
                    <form method="POST" action="{{ route('admin.users.status', $user) }}">
                        @csrf
                        @method('PUT')
                        <x-button variant="secondary" type="submit">Reactivate account</x-button>
                    </form>
                @endif
            </div>
        </section>

        <x-confirm-dialog />
    </div>

</x-layouts.app>
