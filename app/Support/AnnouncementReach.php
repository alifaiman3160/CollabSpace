<?php

namespace App\Support;

use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\GroupMember;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How far announcements have reached: the students of each subject (the "Y") and how many of them opened each
 * announcement (the "X"). Two grouped queries whatever the number of subjects or announcements. Only active students
 * who are in a group of that subject count, on both sides, so X can never be bigger than Y.
 */
class AnnouncementReach
{
    /**
     * @param  array<int, int>  $subjectIds
     * @return Collection<int, int>  subject id => active students in its groups
     */
    public function audience(array $subjectIds): Collection
    {
        return GroupMember::query()
            ->join('groups', 'groups.id', '=', 'group_members.group_id')
            ->join('users', 'users.id', '=', 'group_members.user_id')
            ->whereIn('groups.subject_id', $subjectIds)
            ->where('users.is_active', true)
            ->groupBy('groups.subject_id')
            ->selectRaw('groups.subject_id as subject_id, count(distinct group_members.user_id) as total')
            ->pluck('total', 'subject_id');
    }

    /**
     * @param  iterable<int>  $announcementIds
     * @return Collection<int, int>  announcement id => students of its subject who opened it
     */
    public function opened(iterable $announcementIds): Collection
    {
        return AnnouncementRead::query()
            ->join('announcements', 'announcements.id', '=', 'announcement_reads.announcement_id')
            ->whereIn('announcement_reads.announcement_id', collect($announcementIds)->all())
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('group_members')
                    ->join('groups', 'groups.id', '=', 'group_members.group_id')
                    ->join('users', 'users.id', '=', 'group_members.user_id')
                    ->whereColumn('group_members.user_id', 'announcement_reads.user_id')
                    ->whereColumn('groups.subject_id', 'announcements.subject_id')
                    ->where('users.is_active', true);
            })
            ->groupBy('announcement_reads.announcement_id')
            ->selectRaw('announcement_reads.announcement_id as announcement_id, count(distinct announcement_reads.user_id) as total')
            ->pluck('total', 'announcement_id');
    }
}