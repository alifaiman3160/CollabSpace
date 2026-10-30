<?php

namespace App\Support\Marking;

/**
 * What a run of suggestions came to, for the person who ran it.
 */
final class SuggestionReport
{
    public int $students = 0;

    /** Requests made to the provider, counting each student once however many retries. */
    public int $requests = 0;

    /** Criteria whose suggestion came from the AI. */
    public int $fromAi = 0;

    /** Criteria that fell back to the rule-based baseline. */
    public int $fromBaseline = 0;

    /** Criteria with no level because the group has recorded too little to compare students. */
    public int $insufficient = 0;

    /** Criteria the AI answered but whose answer was discarded. */
    public int $discarded = 0;

    /** Criteria with nothing to suggest from: no evidence type, or no data. */
    public int $needsInput = 0;

    /** Criteria where the baseline and the AI differ by more than one level. */
    public int $flagged = 0;

    /** Set when no further requests were made, and why. */
    public ?string $stopped = null;

    public bool $rateLimited = false;
}
