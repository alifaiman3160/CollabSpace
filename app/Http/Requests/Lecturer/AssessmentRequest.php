<?php

namespace App\Http\Requests\Lecturer;

use App\Models\Assessment;
use App\Models\Subject;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding or changing an assessment of a subject (ownership is checked by the controller; this checks what was typed).
 * Two assessments of one subject may not overlap, because the window decides whose activity counts for which.
 */
class AssessmentRequest extends FormRequest
{
    /** Errors are kept apart from the subject's own form on the same page. */
    protected $errorBag = 'assessment';

    public function authorize(): bool
    {
        // Another lecturer's subject is simply not found, before anything about it is validated or revealed.
        abort_unless($this->subject() === null || $this->subject()->lecturer_id === $this->user()?->getKey(), 404);

        return $this->user()?->isLecturer() ?? false;
    }

    protected function subject(): ?Subject
    {
        $subject = $this->route('subject');

        return $subject instanceof Subject ? $subject : null;
    }

    protected function current(): ?Assessment
    {
        $assessment = $this->route('assessment');

        return $assessment instanceof Assessment ? $assessment : null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => [
                'required', 'string', 'max:80',
                Rule::unique('assessments', 'title')->where('subject_id', $this->subject()?->getKey())->ignore($this->current()?->getKey()),
            ],
            'brief' => ['nullable', 'string', 'max:5000'],
            'type' => ['required', Rule::in(array_keys(Assessment::TYPES))],
            'weight' => ['nullable', 'numeric', 'between:0,100'],
            'starts_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'Give the assessment a title, for example Assignment 1.',
            'title.max' => 'Keep the title to 80 characters.',
            'title.unique' => 'This subject already has an assessment with that title.',
            'type.in' => 'Choose Assignment or Project.',
            'weight.between' => 'The weight is a percentage from 0 to 100.',
            'weight.numeric' => 'The weight is a number, for example 20.',
            'due_on.after_or_equal' => 'The due date cannot be before the start date.',
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($validator->errors()->isNotEmpty() || $this->subject() === null) {
                return;
            }

            $others = $this->subject()->assessments()->when($this->current(), fn ($q, $me) => $q->whereKeyNot($me->getKey()))->get();

            if ($this->current() === null && $others->count() >= Assessment::MAX_PER_SUBJECT) {
                $validator->errors()->add('title', 'A subject can have at most '.Assessment::MAX_PER_SUBJECT.' assessments.');

                return;
            }

            $start = filled($this->starts_on) ? Carbon::parse($this->starts_on)->startOfDay() : null;
            $end = filled($this->due_on) ? Carbon::parse($this->due_on)->endOfDay() : null;

            foreach ($others as $other) {
                $otherStart = $other->windowStart();
                $otherEnd = $other->windowEnd();

                $overlaps = ($end === null || $otherStart === null || $otherStart->lte($end))
                    && ($otherEnd === null || $start === null || $otherEnd->gte($start));

                if ($overlaps) {
                    $validator->errors()->add('starts_on', "These dates overlap {$other->title}"
                        .($other->starts_on === null && $other->due_on === null ? ', which has no dates yet: give it dates first.' : ' ('.$this->range($other).'). Two assessments cannot share days.'));

                    return;
                }
            }
        }];
    }

    protected function range(Assessment $assessment): string
    {
        return ($assessment->starts_on?->format('j M Y') ?? 'the start').' to '.($assessment->due_on?->format('j M Y') ?? 'no end');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => is_string($this->title) ? trim($this->title) : $this->title,
            // Plain text like the subject's own brief; a cleared box is no brief, not an empty one.
            'brief' => filled($this->brief) ? trim($this->brief) : null,
            'weight' => filled($this->weight) ? $this->weight : null,
            'starts_on' => filled($this->starts_on) ? $this->starts_on : null,
            'due_on' => filled($this->due_on) ? $this->due_on : null,
        ]);
    }
}
