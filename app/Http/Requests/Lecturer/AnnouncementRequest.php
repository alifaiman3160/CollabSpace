<?php

namespace App\Http\Requests\Lecturer;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Give the announcement a title.',
            'title.max' => 'Keep the title to 150 characters.',
            'body.required' => 'Write the message.',
            'body.max' => 'Keep the message to 2000 characters.',
        ];
    }
}
