<x-layouts.app title="New user — CollabSpace"
               heading="Create user account"
               subtitle="A temporary password is generated on save and shown to you once.">

    <form method="POST" action="{{ route('admin.users.store') }}" class="flex flex-col gap-7">
        @csrf

        @include('admin.users._form', ['user' => null])

        <div class="flex max-w-xl items-start gap-2.5 rounded-card border border-surface-line bg-surface-soft px-4 py-3.5">
            <x-icon name="lock" size="17" class="mt-0.5 shrink-0 text-ink-300" />
            <p class="text-body leading-[1.55] text-ink-400">
                CollabSpace has no self-registration. This account will be issued a temporary
                password that you pass on directly, and the holder must change it before they
                can use anything else.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <x-button type="submit">Create account</x-button>
            <x-button variant="secondary" :href="route('admin.users')">Cancel</x-button>
        </div>
    </form>

</x-layouts.app>
