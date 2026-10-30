<?php

namespace App\Support\Ai;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The one door features go through to ask for a suggestion.
 *
 * It checks a provider exists, takes one request from the lecturer's daily
 * allowance, makes the call, and turns every way that can go wrong into an
 * AiOutcome. It never throws, and what it logs is the provider, the model and a
 * scrubbed reason: never the key, never the instructions, never the payload.
 */
class AiGateway
{
    public function __construct(
        private readonly AiManager $manager,
        private readonly AiQuota $quota,
    ) {}

    /** Is a provider available, and why not if it is not. */
    public function status(): array
    {
        return ['enabled' => $this->manager->enabled(), 'reason' => $this->manager->reason()] + $this->manager->current();
    }

    /**
     * @param  array<string, mixed>|string  $payload
     * @param  array<string, mixed>  $schema
     */
    public function suggest(User $lecturer, string $system, array|string $payload, array $schema): AiOutcome
    {
        $current = $this->manager->current();

        if ($reason = $this->manager->reason()) {
            return new AiOutcome(AiOutcome::DISABLED, message: $reason, provider: $current['provider'], model: $current['model']);
        }

        if (! $this->quota->take($lecturer)) {
            return new AiOutcome(
                AiOutcome::LIMITED,
                message: AiException::limitReached($this->quota->limit())->getMessage(),
                provider: $current['provider'],
                model: $current['model'],
            );
        }

        $started = hrtime(true);

        try {
            $data = $this->manager->provider()->complete($system, $payload, $schema);
        } catch (\Throwable $e) {
            // An AiException's text is already safe to show. Anything else is a
            // bug in the provider layer; the class is logged, its message (which
            // could carry a URL or header) is not, and the reader gets the same
            // neutral fallback.
            $safe = $e instanceof AiException
                ? $e->getMessage()
                : AiException::requestFailed('unexpected '.class_basename($e))->getMessage();

            Log::warning('AI request failed', [
                'provider' => $current['provider'],
                'model' => $current['model'],
                'reason' => $safe,
            ]);

            return new AiOutcome(
                AiOutcome::FAILED,
                message: $safe,
                provider: $current['provider'],
                model: $current['model'],
                latencyMs: $this->since($started),
            );
        }

        return new AiOutcome(
            AiOutcome::OK,
            data: $data,
            provider: $current['provider'],
            model: $current['model'],
            latencyMs: $this->since($started),
        );
    }

    private function since(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
