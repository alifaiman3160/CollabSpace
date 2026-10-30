<?php

namespace App\Support\Ai;

use RuntimeException;

/**
 * Anything that stops a suggestion arriving. The message is safe to show: it
 * never carries an API key, a prompt or a provider's raw error body, and is
 * worded the same way whichever provider is behind it.
 */
class AiException extends RuntimeException
{
    /** The provider is not set up at all, or is missing a key or model. */
    public static function notConfigured(string $reason): self
    {
        return new self($reason);
    }

    /** The request did not complete: network, timeout, or an HTTP error. */
    public static function requestFailed(string $detail): self
    {
        return new self('The AI provider did not respond ('.$detail.'). Rule-based suggestions are shown instead.');
    }

    /** It answered, but not with anything that fits what was asked for. */
    public static function invalidResponse(string $detail): self
    {
        return new self('The AI provider returned an answer that could not be used ('.$detail.'). Rule-based suggestions are shown instead.');
    }

    /** The lecturer has used today's allowance. */
    public static function limitReached(int $limit): self
    {
        return new self('The daily limit of '.$limit.' AI suggestion requests has been reached. Rule-based suggestions are shown instead, and the limit resets tomorrow.');
    }
}
