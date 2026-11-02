<?php

namespace App\Support\Assessments;

use Illuminate\Support\Facades\DB;

/**
 * Gives data that predates assessments a place to live. Safe to run any number of times: it only fills what is missing.
 *
 * For every subject without an assessment: one called "Project" (type project, due on the subject's project deadline,
 * no start date). Every rubric of the subject that has no assessment goes into the subject's first one. Marks,
 * approvals, suggestions and frozen snapshots hang off a rubric, so they follow it.
 */
class Backfill
{
    public const DEFAULT_TITLE = 'Project';

    /**
     * Moves what belonged to the subject as a whole into its first assessment: the brief text (only into an assessment
     * that has none yet, so nothing written later is overwritten) and the files that are not assigned to any assessment.
     * Called once, by the migration that adds the columns; repeating it straight away changes nothing. A file added
     * afterwards as "whole subject" is assigned to no assessment on purpose, so this is not run again by anything else.
     */
    public static function briefsAndFiles(): void
    {
        foreach (DB::table('subjects')->get(['id', 'brief']) as $subject) {
            $first = DB::table('assessments')->where('subject_id', $subject->id)->orderBy('position')->orderBy('id')->first(['id', 'brief']);

            if ($first === null) {
                continue;
            }

            if (filled($subject->brief) && blank($first->brief)) {
                DB::table('assessments')->where('id', $first->id)->update(['brief' => $subject->brief]);
            }

            DB::table('subject_files')->where('subject_id', $subject->id)->whereNull('assessment_id')->update(['assessment_id' => $first->id]);
        }
    }

    public static function run(): void
    {
        $now = now();

        foreach (DB::table('subjects')->get(['id', 'project_deadline']) as $subject) {
            $first = DB::table('assessments')->where('subject_id', $subject->id)->orderBy('position')->orderBy('id')->value('id');

            if ($first === null) {
                $first = DB::table('assessments')->insertGetId([
                    'subject_id' => $subject->id,
                    'title' => self::DEFAULT_TITLE,
                    'type' => 'project',
                    'weight' => null,
                    'starts_on' => null,
                    'due_on' => $subject->project_deadline,
                    'position' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('rubrics')->where('subject_id', $subject->id)->whereNull('assessment_id')->update(['assessment_id' => $first]);
        }
    }
}
