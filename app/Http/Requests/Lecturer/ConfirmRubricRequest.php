<?php

namespace App\Http\Requests\Lecturer;

use App\Models\RubricSection;
use App\Support\Marking\EvidenceType;
use App\Support\Rubric\RubricScoring;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A reviewed rubric, as posted from the review screen.
 *
 * The screen is the only place content comes from: nothing is taken from what
 * was parsed out of the file, so what the lecturer saw and corrected is exactly
 * what is saved. Everything is checked again here because the form is only a
 * convenience.
 */
class ConfirmRubricRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'lecturer';
    }

    protected function prepareForValidation(): void
    {
        // A comma as the decimal mark is how many people type 1,5.
        $sections = $this->input('sections');

        if (! is_array($sections)) {
            return;
        }

        foreach ($sections as $s => $section) {
            if (isset($section['declared_total']) && is_string($section['declared_total'])) {
                $sections[$s]['declared_total'] = $this->decimal($section['declared_total']);
            }

            foreach ((array) ($section['criteria'] ?? []) as $c => $criterion) {
                if (isset($criterion['weight']) && is_string($criterion['weight'])) {
                    $sections[$s]['criteria'][$c]['weight'] = $this->decimal($criterion['weight']);
                }

                foreach ((array) ($criterion['levels'] ?? []) as $l => $level) {
                    if (isset($level['marks']) && is_string($level['marks'])) {
                        $sections[$s]['criteria'][$c]['levels'][$l]['marks'] = $this->decimal($level['marks']);
                    }
                }
            }
        }

        $this->merge(['sections' => $sections]);
    }

    public function rules(): array
    {
        $mode = ['required', Rule::in([RubricSection::SUGGESTED, RubricSection::MANUAL])];

        return [
            'name' => ['required', 'string', 'max:120'],

            'sections' => ['required', 'array', 'min:1', 'max:20'],
            'sections.*.title' => ['required', 'string', 'max:150'],
            'sections.*.mode' => $mode,
            'sections.*.declared_total' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'sections.*.scoring' => ['nullable', Rule::in(RubricScoring::FORMULAS)],

            'sections.*.criteria' => ['required', 'array', 'min:1', 'max:60'],
            'sections.*.criteria.*.name' => ['required', 'string', 'max:255'],
            'sections.*.criteria.*.weight' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'sections.*.criteria.*.mode' => $mode,
            'sections.*.criteria.*.evidence_type' => ['nullable', Rule::in(EvidenceType::ALL)],

            'sections.*.criteria.*.levels' => ['required', 'array', 'min:1', 'max:12'],
            'sections.*.criteria.*.levels.*.label' => ['required', 'string', 'max:60'],
            'sections.*.criteria.*.levels.*.marks' => ['required', 'numeric', 'min:0', 'max:1000'],
            'sections.*.criteria.*.levels.*.range' => ['nullable', 'string', 'max:20'],
            'sections.*.criteria.*.levels.*.descriptor' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Rules that need to look across fields.
     *
     * A section the system may suggest for cannot be saved until it is settled
     * how its marks are calculated, because every suggested mark depends on it.
     * And a formula that multiplies by the weight needs a weight on every
     * criterion to multiply by.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ((array) $this->input('sections', []) as $s => $section) {
                if (! is_array($section)) {
                    continue;
                }

                $criteria = (array) ($section['criteria'] ?? []);
                $title = $section['title'] ?? 'this section';
                $suggests = ($section['mode'] ?? null) === RubricSection::SUGGESTED
                    || collect($criteria)->contains(fn ($c) => ($c['mode'] ?? null) === RubricSection::SUGGESTED);
                $formula = $section['scoring'] ?? null;

                if ($suggests && blank($formula)) {
                    $validator->errors()->add("sections.{$s}.scoring", "Choose how marks are calculated for “{$title}” before saving.");
                }

                if ($formula === RubricScoring::LEVEL_X_WEIGHT
                    && collect($criteria)->contains(fn ($c) => RubricScoring::number($c['weight'] ?? null) === null)) {
                    $validator->errors()->add("sections.{$s}.scoring", "“{$title}” multiplies by the weight, so every criterion in it needs a weight.");
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Give the rubric a name.',
            'sections.required' => 'A rubric needs at least one section.',
            'sections.min' => 'A rubric needs at least one section.',
            'sections.*.title.required' => 'Every section needs a title.',
            'sections.*.criteria.required' => 'Every section needs at least one criterion. Delete the section if it is not wanted.',
            'sections.*.criteria.min' => 'Every section needs at least one criterion. Delete the section if it is not wanted.',
            'sections.*.criteria.*.name.required' => 'Every criterion needs a name.',
            'sections.*.criteria.*.weight.numeric' => 'A weight must be a number.',
            'sections.*.criteria.*.levels.required' => 'Every criterion needs at least one performance level.',
            'sections.*.criteria.*.levels.min' => 'Every criterion needs at least one performance level.',
            'sections.*.criteria.*.levels.*.label.required' => 'Every level needs a name.',
            'sections.*.criteria.*.levels.*.marks.required' => 'Every level needs its marks.',
            'sections.*.criteria.*.levels.*.marks.numeric' => 'Marks must be a number.',
            'sections.*.*.mode.in' => 'Choose System-suggested or Manual.',
            'sections.*.criteria.*.evidence_type.in' => 'Choose one of the evidence types listed.',
            'sections.*.scoring.in' => 'Choose how marks are calculated: level × weight, or level only.',
            'sections.*.declared_total.numeric' => 'The total marks must be a number.',
        ];
    }

    private function decimal(string $value): string
    {
        return str_replace(',', '.', trim($value));
    }
}
