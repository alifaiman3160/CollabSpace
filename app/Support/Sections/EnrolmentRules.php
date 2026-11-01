<?php

namespace App\Support\Sections;

use Illuminate\Http\Request;

/**
 * The "Subjects and sections" rows of the admin account form, validated the same way on create and on edit.
 * Rows: enrolments[n][subject_id], enrolments[n][section]. One row per subject; students only.
 */
class EnrolmentRules
{
    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return [
            'enrolments' => ['nullable', 'array', 'max:30'],
            'enrolments.*.subject_id' => ['required', 'integer', 'distinct', 'exists:subjects,id'],
            'enrolments.*.section' => ['required', 'string', 'max:'.Enrolments::NAME_MAX],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'enrolments.*.subject_id.required' => 'Choose a subject for every row, or remove the row.',
            'enrolments.*.subject_id.distinct' => 'Each subject can appear only once: a student has one section per subject.',
            'enrolments.*.subject_id.exists' => 'That subject does not exist.',
            'enrolments.*.section.required' => 'Give the section for every subject, for example 1A.',
            'enrolments.*.section.max' => 'A section name is at most '.Enrolments::NAME_MAX.' characters.',
        ];
    }

    /**
     * Tidies the posted rows: names trimmed, rows left completely empty dropped. Only students have enrolments, so
     * for anybody else the rows are discarded.
     */
    public static function prepare(Request $request): void
    {
        if (! $request->has('enrolments')) {
            return;
        }

        $rows = $request->input('enrolments');

        if ($request->input('role') !== 'student' || ! is_array($rows)) {
            $request->merge(['enrolments' => null]);

            return;
        }

        $rows = collect($rows)
            ->map(fn ($row) => is_array($row) ? [
                'subject_id' => $row['subject_id'] ?? null,
                'section' => Enrolments::clean((string) ($row['section'] ?? '')),
            ] : null)
            ->filter(fn ($row) => $row !== null && (filled($row['subject_id']) || $row['section'] !== ''))
            ->values()
            ->all();

        $request->merge(['enrolments' => $rows]);
    }
}
