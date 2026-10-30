@props([
    'group',            // needs: subject, members (names), members_count, tasks_* counts
    'href' => null,     // where the group name links
    'formation' => true,
])

{{--
    The four cells that describe a group wherever groups are listed (Groups, the
    dashboard overview, Monitor progress): name over how it was formed, subject
    code over subject name, the member stack with its status, and task progress.
    Four <td>s, so use it inside a row.
--}}
<td>
    <x-table.stack :href="$href ?? route('lecturer.groups.show', $group)">
        <x-slot:primary>{{ $group->name }}</x-slot:primary>
        @if ($formation)
            <span class="inline-flex items-center gap-1.5" title="{{ $group->formationLabel() }}">
                <x-icon :name="['balanced' => 'chart', 'imported' => 'file'][$group->formation_method] ?? 'edit'" size="12" />
                {{ ucfirst($group->formation_method) }}
            </span>
        @endif
    </x-table.stack>
</td>

<td>
    <x-table.stack>
        <x-slot:primary>
            <x-badge tone="info">{{ $group->subject->code }}</x-badge>
        </x-slot:primary>
        <span class="truncate">{{ $group->subject->name }}</span>
    </x-table.stack>
</td>

<td>
    <x-table.capacity :names="$group->members->pluck('name')"
                      :count="$group->members_count"
                      :size="$group->group_size" />
</td>

<td>
    <x-table.progress :done="$group->tasks_done"
                      :total="$group->tasks_total"
                      :overdue="$group->tasks_overdue" />
</td>
