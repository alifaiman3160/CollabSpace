<x-layouts.app title="Admin dashboard — CollabSpace"
               heading="Administration"
               :subtitle="'Managing '.$counts->total.' '.Str::plural('account', $counts->total).' across CollabSpace.'">

    <x-slot:actions>
        <x-button variant="icon" aria-label="Notifications">
            <x-icon name="bell" />
        </x-button>
        <x-button :href="route('admin.users.create')" icon="plus">New user</x-button>
    </x-slot:actions>

    <div class="flex flex-col gap-[18px]">

        <x-flash />

        {{-- Stat row, per the dashboard designs: compact cards, 34px icon chip. --}}
        <section class="flex flex-wrap gap-3.5" aria-label="Account totals">
            <x-stat-card size="sm" tone="periwinkle" icon="users" icon-tone="neutral"
                         label="Total users" :value="$counts->total"
                         :meta="$counts->admins.' '.Str::plural('administrator', $counts->admins)" />

            <x-stat-card size="sm" icon="graduation" icon-tone="info"
                         label="Students" :value="$counts->students"
                         :meta="$counts->total ? round($counts->students / $counts->total * 100).'% of accounts' : 'None yet'" />

            <x-stat-card size="sm" icon="book" icon-tone="info"
                         label="Lecturers" :value="$counts->lecturers"
                         :meta="$counts->lecturers ? 'Teaching staff' : 'None yet'" />

            <x-stat-card size="sm" icon="check" icon-tone="success"
                         label="Active" :value="$counts->active"
                         :meta="$counts->pending ? $counts->pending.' not signed in yet' : 'All signed in'" />

            <x-stat-card size="sm" icon="alert" icon-tone="danger"
                         label="Inactive" :value="$counts->inactive"
                         :meta="$counts->inactive ? 'Cannot sign in' : 'Nobody suspended'" />
        </section>

        {{-- Recent users --}}
        <section class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-h3 font-extrabold tracking-title">Recently added</h2>
                <x-button variant="secondary" :href="route('admin.users')">Manage user accounts</x-button>
            </div>

            <x-data-table :headings="['Name', 'Role', 'Matric no.', 'Status', 'Added']"
                          empty="No accounts have been created yet.">
                @foreach ($recentUsers as $user)
                    <tr>
                        <td>
                            <x-table.person :name="$user->name" :href="route('admin.users.edit', $user)">
                                <span class="truncate">{{ $user->email }}</span>
                            </x-table.person>
                        </td>
                        <td><x-role-badge :role="$user->role" /></td>
                        <td class="text-meta font-semibold text-ink-400">{{ $user->matric_no ?? '—' }}</td>
                        <td>
                            <span class="flex flex-wrap gap-1.5">
                                <x-status-badge :active="$user->is_active" :pending="$user->must_change_password" />
                            </span>
                        </td>
                        <td class="whitespace-nowrap text-meta text-ink-400" title="{{ $user->created_at->format('j F Y, g:ia') }}">
                            {{ $user->created_at->diffForHumans() }}
                        </td>
                    </tr>
                @endforeach
            </x-data-table>
        </section>
    </div>

</x-layouts.app>
