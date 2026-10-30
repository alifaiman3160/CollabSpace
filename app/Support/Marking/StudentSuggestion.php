<?php

namespace App\Support\Marking;

/**
 * What came of generating suggestions for one student, for the page that is
 * working through the group.
 *
 *   done          finished (rule-based always; the AI too if it was asked and answered)
 *   rate_limited  the provider said to slow down: try this student again after retryAfter seconds
 *   failed        the provider could not answer; the rule-based suggestion stands
 *   disabled      no provider is set up; rule-based only
 *   limited       today's AI allowance is used; rule-based only
 *   skipped       the student's marks are approved, so nothing was changed
 */
final class StudentSuggestion
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
        public readonly ?int $retryAfter = null,
        public readonly ?SuggestionReport $report = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
            'retry_after' => $this->retryAfter,
            'counts' => $this->report ? [
                'ai' => $this->report->fromAi,
                'rule_based' => $this->report->fromBaseline,
                'insufficient' => $this->report->insufficient,
                'needs_input' => $this->report->needsInput,
                'discarded' => $this->report->discarded,
                'flagged' => $this->report->flagged,
            ] : null,
        ];
    }
}