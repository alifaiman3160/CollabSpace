<?php

namespace App\Support\Marking;

/**
 * What recorded group activity a system-suggested criterion draws on.
 *
 * A criterion with no evidence type, or "none", is never given a suggestion: the
 * lecturer decides it unaided, and the model is never asked about it.
 *
 * This class also holds the advice shown beside the control: what each type
 * measures, a default guessed from the wording, and a hint when the chosen type
 * does not seem to fit the wording. The hint is advice only. The same rules are
 * handed to the browser (hintConfig) so it can update as the selection changes,
 * and are the single source for both.
 */
final class EvidenceType
{
    public const TASK_COMPLETION = 'task_completion';

    public const RELIABILITY = 'reliability';

    public const COMMUNICATION = 'communication';

    public const COLLABORATION = 'collaboration';

    public const NONE = 'none';

    public const ALL = [
        self::TASK_COMPLETION,
        self::RELIABILITY,
        self::COMMUNICATION,
        self::COLLABORATION,
        self::NONE,
    ];

    /**
     * Wording about attitude or quality. Counts of tasks, messages and edits say
     * nothing about these, so such a criterion belongs with the lecturer.
     */
    public const ATTITUDE_WORDS = [
        'respect', 'support', 'idea', 'quality', 'attitude', 'creativ',
        'professional', 'empath', 'behaviour', 'behavior',
    ];

    /** Wording about when work is done, which only reliability measures. */
    public const RELIABILITY_WORDS = ['deadline', 'on time', 'punctual', 'reliab', 'timely', 'timeliness', 'tepat masa'];

    /**
     * Wording that points to each type, in the order they are tried. Reliability
     * is first because "meeting deadlines on tasks" is about when work was done,
     * not whether it was.
     */
    public const KEYWORDS = [
        self::RELIABILITY => self::RELIABILITY_WORDS,
        self::TASK_COMPLETION => ['task', 'complet', 'deliver', 'assigned', 'tugas'],
        self::COMMUNICATION => ['communicat', 'discuss', 'meeting', 'informed', 'respond', 'komunikasi'],
        self::COLLABORATION => ['collaborat', 'cooperat', 'co-operat', 'shared work', 'help', 'kerjasama'],
    ];

    public static function label(?string $type): string
    {
        return match (self::normalise($type)) {
            self::TASK_COMPLETION => 'Task completion',
            self::RELIABILITY => 'Reliability (deadlines)',
            self::COMMUNICATION => 'Communication',
            self::COLLABORATION => 'Collaboration',
            default => 'None (lecturer input only)',
        };
    }

    /** One line saying exactly what the type measures. Null and blank read as none. */
    public static function description(?string $type): string
    {
        return match (self::normalise($type)) {
            self::TASK_COMPLETION => 'Share of assigned tasks that are completed',
            self::RELIABILITY => 'Share of assigned tasks completed on time (late and overdue tasks do not count as on time)',
            self::COMMUNICATION => 'Messages sent compared with the group average',
            self::COLLABORATION => 'Document edits and file uploads compared with the group average',
            default => 'No suggestion is made; you choose the level yourself',
        };
    }

    /** Null, blank and anything unrecognised all mean "none". */
    public static function normalise(?string $type): string
    {
        return in_array($type, self::ALL, true) ? $type : self::NONE;
    }

    /**
     * A starting suggestion from the criterion's wording, or null when nothing
     * is obvious. Only a default: the lecturer sees it as one and can change it.
     *
     * A criterion about attitude or quality gets no default at all, because the
     * honest answer for it is Manual.
     */
    public static function guess(string $criterionName): ?string
    {
        $text = mb_strtolower($criterionName);

        if (self::mentions($text, self::ATTITUDE_WORDS)) {
            return null;
        }

        foreach (self::KEYWORDS as $type => $words) {
            if (self::mentions($text, $words)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Advice when the evidence chosen does not seem to fit the criterion, or null
     * when there is nothing to say. Never an error: the lecturer may choose
     * whatever they think is right.
     *
     * Only a criterion the system may suggest for can be mismatched; one set to
     * Manual is already the lecturer's alone. "None" is treated like Manual
     * for attitude and quality (nothing would be suggested), but is still
     * advised against for a criterion that plainly is about deadlines.
     */
    public static function hint(string $criterionName, string $mode, ?string $evidence): ?string
    {
        if ($mode !== 'suggested') {
            return null;
        }

        $type = self::normalise($evidence);
        $text = mb_strtolower($criterionName);

        if (self::mentions($text, self::ATTITUDE_WORDS)) {
            return $type === self::NONE ? null : self::messages()['attitude'];
        }

        if (self::mentions($text, self::RELIABILITY_WORDS)) {
            return $type === self::RELIABILITY ? null : self::messages()['reliability'];
        }

        $guess = self::guess($criterionName);

        if ($guess !== null && $type !== self::NONE && $type !== $guess) {
            return str_replace(':label', self::label($guess), self::messages()['mismatch']);
        }

        return null;
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'attitude' => 'This looks like a judgement about attitude or quality, which recorded activity cannot measure. Consider setting the criterion to Manual.',
            'reliability' => 'The wording mentions deadlines or reliability. Reliability (deadlines) is probably the better evidence type.',
            'mismatch' => 'The wording looks more like :label. Check this is the evidence type you want.',
        ];
    }

    /**
     * The same rules, for the browser to apply as the selection changes.
     *
     * @return array<string, mixed>
     */
    public static function hintConfig(): array
    {
        return [
            'attitude' => self::ATTITUDE_WORDS,
            'reliability' => self::RELIABILITY_WORDS,
            'keywords' => self::KEYWORDS,
            'labels' => array_combine(self::ALL, array_map(self::label(...), self::ALL)),
            'messages' => self::messages(),
            // Keyed by the select's values, so "" (not set) reads as none.
            'descriptions' => ['' => self::description(null)] + array_combine(self::ALL, array_map(self::description(...), self::ALL)),
        ];
    }

    private static function mentions(string $text, array $words): bool
    {
        foreach ($words as $word) {
            if (str_contains($text, $word)) {
                return true;
            }
        }

        return false;
    }
}
