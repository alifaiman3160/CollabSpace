{{-- The three things you can do with a preview. Shown above and below the
     groups, so the choice is never a scroll away. Only the first writes
     anything, and it writes exactly what is on this screen. --}}
<div class="flex flex-wrap items-center gap-3">
    <form method="POST" action="{{ route('lecturer.formation.balanced.confirm', [$subject, $token]) }}">
        @csrf
        <x-button type="submit">
            <x-icon name="check" size="16" />
            Confirm and create {{ count($proposal['groups']) }} {{ Str::plural('group', count($proposal['groups'])) }}
        </x-button>
    </form>

    <form method="POST" action="{{ route('lecturer.formation.balanced.regenerate', [$subject, $token]) }}">
        @csrf
        <x-button variant="secondary" type="submit">
            <x-icon name="restore" size="16" />
            Regenerate
        </x-button>
    </form>

    <x-button variant="secondary" :href="route('lecturer.formation', $subject)">Change who or how many</x-button>
</div>
