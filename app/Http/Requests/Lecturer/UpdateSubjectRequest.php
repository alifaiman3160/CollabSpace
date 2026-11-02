<?php

namespace App\Http\Requests\Lecturer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What a lecturer may change on a subject given to them: the project brief, its due date, the semester start date
 * (display only) and the default group size.
 * The code, name, lecturer and sections belong to the administrator, so they are not rules here: validated() never
 * contains them, whatever a hand-made request sends.
 */
class UpdateSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isLecturer() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // The project is written after the subject exists more often than not, so neither field is required.
            'brief' => ['nullable', 'string', 'max:5000'],
            'project_deadline' => ['nullable', 'date'],
            // The first day of Week 1; display only. Empty hides the week.
            'semester_start_date' => ['nullable', 'date'],
            'group_size' => ['required', 'integer', 'min:2', 'max:10'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'group_size.min' => 'A group needs at least 2 members.',
            'group_size.max' => 'Groups are capped at 10 members.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            // An emptied textarea or date field arrives as '', which is neither a brief nor a date: it means the
            // lecturer cleared it, so it is stored as null rather than as an empty string.
            'brief' => filled($this->brief) ? trim($this->brief) : null,
            'project_deadline' => filled($this->project_deadline) ? $this->project_deadline : null,
            'semester_start_date' => filled($this->semester_start_date) ? $this->semester_start_date : null,
        ]);
    }
}