<?php

namespace App\Http\Requests\Admin;

use App\Models\Subject;
use App\Support\Sections\Enrolments;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating and editing a subject (administrator only): its code, name, the lecturer who teaches it, and its sections.
 * Two lecturers may each have a subject with the same code, so the code is unique per lecturer, not overall.
 */
class SubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /** The subject being edited, or null when creating. */
    protected function current(): ?Subject
    {
        $subject = $this->route('subject');

        return $subject instanceof Subject ? $subject : null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:150'],
            'lecturer_id' => [
                'required', 'integer',
                Rule::exists('users', 'id')->where('role', 'lecturer'),
                Rule::unique('subjects', 'lecturer_id')
                    ->where('code', $this->input('code'))
                    ->ignore($this->current()?->getKey()),
            ],
            'sections' => ['nullable', 'array', 'max:30'],
            'sections.*.id' => ['nullable', 'integer'],
            'sections.*.name' => ['required', 'string', 'max:'.Enrolments::NAME_MAX],
            'confirm_lecturer' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lecturer_id.required' => 'Choose the lecturer who teaches this subject.',
            'lecturer_id.exists' => 'That account is not a lecturer.',
            'lecturer_id.unique' => 'This lecturer already has a subject with this code.',
            'sections.*.name.required' => 'Give each section a name, for example 1A, or remove the row.',
            'sections.*.name.max' => 'A section name is at most '.Enrolments::NAME_MAX.' characters.',
        ];
    }

    /** Section names must differ within the subject, whatever their capitals. */
    public function after(): array
    {
        return [function ($validator) {
            $seen = [];

            foreach ((array) $this->input('sections', []) as $i => $row) {
                $key = mb_strtolower((string) ($row['name'] ?? ''));

                if ($key === '') {
                    continue;
                }

                if (isset($seen[$key])) {
                    $validator->errors()->add("sections.{$i}.name", "Section {$row['name']} is listed twice: a subject's section names must be different.");
                }

                $seen[$key] = true;
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $rows = collect((array) $this->input('sections', []))
            ->map(fn ($row) => is_array($row) ? ['id' => $row['id'] ?? null, 'name' => Enrolments::clean((string) ($row['name'] ?? ''))] : null)
            ->filter(fn ($row) => $row !== null && ($row['name'] !== '' || filled($row['id'])))
            ->values()
            ->all();

        $this->merge([
            'code' => is_string($this->code) ? strtoupper(trim($this->code)) : $this->code,
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
            'sections' => $rows,
        ]);
    }
}
