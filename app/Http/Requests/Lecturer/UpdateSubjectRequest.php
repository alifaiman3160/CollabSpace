<?php

namespace App\Http\Requests\Lecturer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('subjects', 'code')
                    ->where('lecturer_id', $this->user()->id)
                    ->ignore($this->route('subject')->id),
            ],
            'name' => ['required', 'string', 'max:150'],
            'group_size' => ['required', 'integer', 'min:2', 'max:10'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.unique' => 'You already have another subject with that code.',
            'group_size.min' => 'A group needs at least 2 members.',
            'group_size.max' => 'Groups are capped at 10 members.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => is_string($this->code) ? strtoupper(trim($this->code)) : $this->code,
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
        ]);
    }
}
