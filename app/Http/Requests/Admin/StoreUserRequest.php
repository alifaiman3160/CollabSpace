<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(['admin', 'lecturer', 'student'])],
            // Only students carry a matriculation number, and it must be unique.
            'matric_no' => [
                Rule::requiredIf(fn () => $this->input('role') === 'student'),
                'nullable', 'string', 'max:20', Rule::unique('users', 'matric_no'),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'matric_no.required' => 'A student account needs a matriculation number.',
            'matric_no.unique' => 'That matriculation number already belongs to another account.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->email) ? strtolower(trim($this->email)) : $this->email,
            // A non-student never keeps a matric number, even if one was typed
            // before the role was switched.
            'matric_no' => $this->input('role') === 'student'
                ? (filled($this->matric_no) ? strtoupper(trim($this->matric_no)) : null)
                : null,
        ]);
    }
}
