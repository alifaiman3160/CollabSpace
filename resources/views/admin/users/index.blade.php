<x-layouts.app title="Manage user accounts — CollabSpace"
               heading="User accounts"
               :subtitle="$users->total().' '.Str::plural('account', $users->total()).' match the current filter.'">

    <x-slot:actions>
        <x-button :href="route('admin.users.create')" icon="plus">New user</x-button>
    </x-slot:actions>

    {{-- One Alpine scope wraps the table and the single confirm dialog. --}}
    <div class="flex flex-col gap-[18px]" x-data="{ confirm: null }">

        <x-flash />

        {{-- Search + role filter --}}
        <form method="GET" action="{{ route('admin.users') }}" class="flex flex-wrap items-center gap-3">
            <label class="flex h-11 min-w-[260px] flex-1 items-center gap-2.5 rounded-pill bg-surface-muted px-[18px]">
                <x-icon name="search" size="17" class="text-ink-300" />
                <input type="search"
                       name="search"
                       value="{{ $search }}"
                       placeholder="Search name, email or matric no."
                       class="w-full min-w-0 border-none bg-transparent p-0 text-body text-ink outline-none placeholder:text-ink-300 focus:ring-0">
            </label>

            {{-- Role filter, styled as the design's segmented control --}}
            <div class="flex shrink-0 rounded-pill bg-surface-muted p-[3px]">
                @foreach ([null => 'All', 'admin' => 'Admins', 'lecturer' => 'Lecturers', 'student' => 'Students'] as $value => $label)
                    <button type="submit"
                            name="role"
                            value="{{ $value }}"
                            class="h-8 rounded-pill px-[15px] text-meta font-bold transition-colors
                                   {{ $role === $value ? 'bg-ink text-white' : 'text-ink-600 hover:text-ink' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            @if ($search !== '' || $role)
                <x-button variant="secondary" :href="route('admin.users')">Clear</x-button>
            @endif
        </form>

        <x-data-table :headings="['Name', 'Role', 'Matric no.', 'Status', 'Actions']"
                      :empty="$search !== '' || $role
                          ? 'No accounts match that search.'
                          : 'No accounts have been created yet.'">
            @foreach ($users as $user)
                @php
                    // Built here, then passed through Js::from below. Blade compiles
                    // {{ }} echoes inside a component attribute but NOT @ directives,
                    // so @js() there would render literally and break the payload.
                    $resetBody = $user->name."'s current password stops working immediately. "
                        ."A new temporary password is generated and shown to you once, and they "
                        ."must change it at next sign-in.";
                    $deactivateBody = $user->name." will be signed out and blocked from signing in "
                        ."again until an administrator reactivates the account. Their groups, tasks "
                        ."and contribution history are kept.";
                @endphp
                <tr class="hover:bg-surface-soft">
                    <td>
                        <div class="flex items-center gap-3">
                            <x-avatar :name="$user->name" size="sm" />
                            <span class="flex min-w-0 flex-col">
                                <span class="text-nav font-bold">
                                    {{ $user->name }}
                                    @if ($user->is(auth()->user()))
                                        <span class="text-meta font-semibold text-ink-300">(you)</span>
                                    @endif
                                </span>
                                <span class="truncate text-meta text-ink-400">{{ $user->email }}</span>
                            </span>
                        </div>
                    </td>

                    <td><x-role-badge :role="$user->role" /></td>

                    <td class="text-meta font-semibold text-ink-400">{{ $user->matric_no ?? '—' }}</td>

                    <td>
                        <span class="flex flex-wrap gap-1.5">
                            <x-status-badge :active="$user->is_active" :pending="$user->must_change_password" />
                        </span>
                    </td>

                    <td>
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            <x-button variant="secondary" :href="route('admin.users.edit', $user)"
                                      class="!px-4 !py-2 !text-meta">Edit</x-button>

                            {{-- Reset password: destructive, invalidates the current one. --}}
                            <x-button variant="secondary" class="!px-4 !py-2 !text-meta"
                                x-on:click="confirm = {
                                    url: '{{ route('admin.users.password', $user) }}',
                                    title: 'Reset this password?',
                                    body: {{ Js::from($resetBody) }},
                                    action: 'Reset password',
                                    tone: 'danger',
                                }">Reset password</x-button>

                            @if ($user->is(auth()->user()))
                                {{-- An admin cannot lock themselves out. --}}
                                <x-button variant="secondary" class="!px-4 !py-2 !text-meta" disabled
                                          title="You cannot deactivate your own account">Deactivate</x-button>
                            @elseif ($user->is_active)
                                <x-button variant="secondary" class="!px-4 !py-2 !text-meta !text-danger-text"
                                    x-on:click="confirm = {
                                        url: '{{ route('admin.users.status', $user) }}',
                                        title: 'Deactivate this account?',
                                        body: {{ Js::from($deactivateBody) }},
                                        action: 'Deactivate',
                                        tone: 'danger',
                                    }">Deactivate</x-button>
                            @else
                                {{-- Restorative, so it goes straight through. --}}
                                <form method="POST" action="{{ route('admin.users.status', $user) }}">
                                    @csrf
                                    @method('PUT')
                                    <x-button variant="secondary" type="submit" class="!px-4 !py-2 !text-meta">Activate</x-button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach

            @if ($users->hasPages())
                <x-slot:footer>
                    {{ $users->links() }}
                </x-slot:footer>
            @endif
        </x-data-table>

        <x-confirm-dialog />
    </div>

</x-layouts.app>
