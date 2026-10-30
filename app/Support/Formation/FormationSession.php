<?php

namespace App\Support\Formation;

use Illuminate\Support\Str;

/**
 * Holds a proposal between "here is what it would do" and "yes, do it".
 *
 * This is what makes preview-before-save a guarantee rather than a habit. A
 * preview lives only in the session, never in the groups table, so abandoning
 * it, regenerating it or closing the tab leaves nothing behind. And confirming
 * writes what is held here, not something recomputed from the request, so what
 * the lecturer approved is exactly what is saved.
 *
 * Each entry is bound to the subject and lecturer it was made for, and is
 * refused for anyone else. The session is already per-user; checking again
 * costs nothing and means a leaked or guessed token is useless on its own.
 */
class FormationSession
{
    /** Proposals kept per kind. Older ones are dropped as new ones arrive. */
    protected const KEEP = 6;

    public function put(string $kind, int $subjectId, array $payload): string
    {
        $token = Str::random(24);

        session()->put($this->key($kind, $token), $payload + [
            '_subject_id' => $subjectId,
            '_lecturer_id' => auth()->id(),
        ]);

        $index = session()->get($this->indexKey($kind), []);
        $index[] = $token;

        while (count($index) > self::KEEP) {
            session()->forget($this->key($kind, array_shift($index)));
        }

        session()->put($this->indexKey($kind), $index);

        return $token;
    }

    /** @return array<string, mixed>|null */
    public function get(string $kind, string $token, int $subjectId): ?array
    {
        $held = session()->get($this->key($kind, $token));

        if (! is_array($held)
            || ($held['_subject_id'] ?? null) !== $subjectId
            || ($held['_lecturer_id'] ?? null) !== auth()->id()) {
            return null;
        }

        return $held;
    }

    public function replace(string $kind, string $token, array $payload): void
    {
        $held = session()->get($this->key($kind, $token));

        session()->put($this->key($kind, $token), $payload + [
            '_subject_id' => $held['_subject_id'] ?? null,
            '_lecturer_id' => $held['_lecturer_id'] ?? null,
        ]);
    }

    public function forget(string $kind, string $token): void
    {
        session()->forget($this->key($kind, $token));

        session()->put(
            $this->indexKey($kind),
            array_values(array_diff(session()->get($this->indexKey($kind), []), [$token])),
        );
    }

    protected function key(string $kind, string $token): string
    {
        return "formation.{$kind}.{$token}";
    }

    protected function indexKey(string $kind): string
    {
        return "formation_index.{$kind}";
    }
}
