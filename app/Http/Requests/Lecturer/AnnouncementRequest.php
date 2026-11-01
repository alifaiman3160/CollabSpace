<?php

namespace App\Http\Requests\Lecturer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Ownership is checked by the controller; this only checks what was typed. */
class AnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:2000'],
            // Empty means every section; otherwise a section of this very subject.
            'section_id' => ['nullable', 'integer', Rule::exists('sections', 'id')->where('subject_id', $this->route('subject')?->id)],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Give the announcement a title.',
            'title.max' => 'Keep the title to 150 characters.',
            'body.required' => 'Write the message.',
            'section_id.exists' => 'That section does not belong to this subject.',
            'body.max' => 'Keep the message to 2000 characters.',
        ];
    }
}
