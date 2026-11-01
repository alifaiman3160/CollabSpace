<?php

namespace App\Support\Sections;

use Illuminate\Support\Facades\DB;

/**
 * Gives data that predates sections a place to live. Safe to run any number of times: it only fills what is missing
 * and never moves a student, a group or a section that already has one.
 *
 * For every subject: a default section (DEFAULT_NAME) if it has no section at all; its groups without a section go
 * into the subject's only section (or the default); and every student already in one of its groups, who is not yet
 * enrolled in that subject, is enrolled in the section of the group they are in (first group wins).
 */
class Backfill
{
    public const DEFAULT_NAME = 'Main';

    public static function run(): void
    {
        $now = now();

        foreach (DB::table('subjects')->pluck('id') as $subjectId) {
            $sections = DB::table('sections')->where('subject_id', $subjectId)->orderBy('id')->pluck('id');

            if ($sections->isEmpty()) {
                $sections = collect([DB::table('sections')->insertGetId([
                    'subject_id' => $subjectId, 'name' => self::DEFAULT_NAME, 'created_at' => $now, 'updated_at' => $now,
                ])]);
            }

            $default = $sections->first();

            DB::table('groups')->where('subject_id', $subjectId)->whereNull('section_id')->update(['section_id' => $default]);

            $enrolled = DB::table('enrolments')->where('subject_id', $subjectId)->pluck('user_id')->flip();

            $members = DB::table('group_members')
                ->join('groups', 'groups.id', '=', 'group_members.group_id')
                ->where('groups.subject_id', $subjectId)
                ->orderBy('group_members.id')
                ->get(['group_members.user_id', 'groups.section_id']);

            foreach ($members as $member) {
                if ($enrolled->has($member->user_id)) {
                    continue;
                }

                DB::table('enrolments')->insert([
                    'user_id' => $member->user_id,
                    'subject_id' => $subjectId,
                    'section_id' => $member->section_id ?? $default,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $enrolled->put($member->user_id, true);
            }
        }
    }
}
