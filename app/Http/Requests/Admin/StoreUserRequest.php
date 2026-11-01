<?php

namespace App\Http\Requests\Admin;

use App\Support\Sections\EnrolmentRules;
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

            // Typed in by the administrator, never read from anywhere. Two
            // decimal places at most and inside 0 to 4: a figure outside that
            // range is a typo, and a typo here silently skews every group the
            // balancer builds from it.
            'cgpa' => ['nullable', 'numeric', 'between:0,4', 'decimal:0,2'],

            // The subjects and sections a student is enrolled in (students only).
            ...EnrolmentRules::rules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return EnrolmentRules::messages() + [
            'matric_no.required' => 'A student account needs a matriculation number.',
            'matric_no.unique' => 'That matriculation number already belongs to another account.',
            'cgpa.between' => 'A CGPA runs from 0.00 to 4.00.',
            'cgpa.decimal' => 'Give the CGPA to at most two decimal places, for example 3.47.',
            'cgpa.numeric' => 'The CGPA must be a number, for example 3.47.',
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
            // Only students carry a CGPA, and an emptied field means none rather
            // than zero — a blank must never be stored as 0.00, which would read
            // as the lowest possible ability.
            'cgpa' => $this->input('role') === 'student' && filled($this->cgpa)
                ? str_replace(',', '.', trim((string) $this->cgpa))
                : null,
        ]);

        EnrolmentRules::prepare($this);
    }
}
