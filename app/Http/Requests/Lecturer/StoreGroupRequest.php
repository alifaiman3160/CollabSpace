<?php

namespace App\Http\Requests\Lecturer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isLecturer() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject_id' => [
                'required', 'integer',
                // Scoped to the signed-in lecturer, so posting another
                // lecturer's subject id fails validation rather than creating
                // a group under their subject.
                Rule::exists('subjects', 'id')->where('lecturer_id', $this->user()->id),
            ],
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('groups', 'name')->where('subject_id', $this->input('subject_id')),
            ],
            'group_size' => ['required', 'integer', 'min:2', 'max:10'],
            // Optional: left out, the first member added decides it. Must be a section of the chosen subject.
            'section_id' => ['nullable', 'integer', Rule::exists('sections', 'id')->where('subject_id', $this->input('subject_id'))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'subject_id.exists' => 'That subject is not one of yours.',
            'name.unique' => 'That subject already has a group with this name.',
            'section_id.exists' => 'That section does not belong to the chosen subject.',
            'group_size.min' => 'A group needs at least 2 members.',
            'group_size.max' => 'Groups are capped at 10 members.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
        ]);
    }
}
