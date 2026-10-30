<?php

namespace App\Http\Requests\Lecturer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isLecturer() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:20',
                // Unique per lecturer, matching the composite unique index on
                // subjects — two lecturers may each run their own section of
                // the same course code.
                Rule::unique('subjects', 'code')
                    ->where('lecturer_id', $this->user()->id),
            ],
            'name' => ['required', 'string', 'max:150'],
            // The assignment is written after the subject exists more often than
            // not, so neither field is required to create or keep one.
            'brief' => ['nullable', 'string', 'max:5000'],
            'project_deadline' => ['nullable', 'date'],
            'group_size' => ['required', 'integer', 'min:2', 'max:10'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.unique' => 'You already have a subject with that code.',
            'group_size.min' => 'A group needs at least 2 members.',
            'group_size.max' => 'Groups are capped at 10 members.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => is_string($this->code) ? strtoupper(trim($this->code)) : $this->code,
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
            // An emptied textarea or date field arrives as '', which is neither
            // a brief nor a date — it means the field was left blank, so it is
            // stored as null rather than as an empty string that would later
            // read as "there is a brief, and it says nothing".
            'brief' => filled($this->brief) ? trim($this->brief) : null,
            'project_deadline' => filled($this->project_deadline) ? $this->project_deadline : null,
        ]);
    }
}
