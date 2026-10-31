<?php

namespace App\Support\Ai;

use RuntimeException;

/**
 * Anything that stops a suggestion arriving. The message is what the lecturer
 * reads: short, plain, and the same whichever provider is behind it. It never
 * carries an API key, a prompt or a raw error body. What went wrong in technical
 * terms (an HTTP status, say) is kept apart in $detail, for the log and for the
 * code that needs to recognise a rate limit; it is never shown on a screen.
 */
class AiException extends RuntimeException
{
    public string $detail = '';

    /** The provider is not set up at all, or is missing a key or model. */
    public static function notConfigured(string $reason): self
    {
        return new self($reason);
    }

    /** The request did not complete: network, timeout, or an HTTP error. */
    public static function requestFailed(string $detail): self
    {
        return self::withDetail('The AI could not answer just now. You still see the suggestion from the numbers.', $detail);
    }

    /** It answered, but not with anything that fits what was asked for. */
    public static function invalidResponse(string $detail): self
    {
        return self::withDetail("The AI's answer could not be used. You still see the suggestion from the numbers.", $detail);
    }

    /** The lecturer has used today's allowance. */
    public static function limitReached(int $limit): self
    {
        return new self("You have reached today's limit of {$limit} AI requests. You still see the suggestions from the numbers. The limit starts again tomorrow.");
    }

    private static function withDetail(string $message, string $detail): self
    {
        $e = new self($message);
        $e->detail = $detail;

        return $e;
    }
}
