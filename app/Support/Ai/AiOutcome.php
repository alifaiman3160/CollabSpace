<?php

namespace App\Support\Ai;

/**
 * What a request for a suggestion came to. Callers branch on this instead of
 * catching exceptions, so the fallback (show the rule-based suggestion, say why)
 * is one ordinary code path rather than an error handler.
 */
final class AiOutcome
{
    public const OK = 'ok';

    public const DISABLED = 'disabled';   // no provider set up

    public const LIMITED = 'limited';     // today's allowance is used

    public const FAILED = 'failed';       // the provider could not answer usefully

    /**
     * @param  array<string, mixed>  $data  the validated answer; empty unless OK
     */
    public function __construct(
        public readonly string $status,
        public readonly array $data = [],
        public readonly ?string $message = null,
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
        public readonly ?int $latencyMs = null,
        /** What went wrong in technical terms, for the log and for recognising a rate limit. Never shown. */
        public readonly ?string $detail = null,
    ) {}

    public function ok(): bool
    {
        return $this->status === self::OK;
    }
}
