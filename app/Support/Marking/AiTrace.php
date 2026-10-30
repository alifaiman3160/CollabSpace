<?php

namespace App\Support\Marking;

use Psr\Http\Message\ResponseInterface;

/**
 * Watches the HTTP replies that come back while a suggestion is being asked for.
 *
 * The AI provider layer returns a validated answer, or a neutral failure
 * message, and deliberately nothing else. Two things the marking feature needs
 * live below that: the provider's raw reply, which is kept for audit, and a 429
 * response's Retry-After header, which decides how long to wait before trying
 * again. Rather than reach into the provider layer for them, this sits on the
 * HTTP client's response middleware and records them while a window is open.
 *
 * Closed, it does nothing, so ordinary requests are untouched. Open, it keeps
 * only the last response's status, Retry-After and body (the body capped), never
 * the request, so no credential can end up in it.
 */
class AiTrace
{
    private const BODY_LIMIT = 200_000;

    private bool $open = false;

    private ?int $status = null;

    private ?string $retryAfter = null;

    private ?string $body = null;

    public function begin(): void
    {
        $this->open = true;
        $this->status = $this->retryAfter = $this->body = null;
    }

    /** @return array{status: ?int, retry_after: ?int, body: ?string} */
    public function end(): array
    {
        $this->open = false;

        return [
            'status' => $this->status,
            'retry_after' => $this->retryAfterSeconds(),
            'body' => $this->body === null ? null : $this->scrub($this->body),
        ];
    }

    /** The Http response middleware. Always returns the response untouched. */
    public function observe(ResponseInterface $response): ResponseInterface
    {
        if ($this->open) {
            $this->status = $response->getStatusCode();
            $this->retryAfter = $response->getHeaderLine('Retry-After') ?: null;

            $body = (string) $response->getBody();
            $response->getBody()->rewind();

            $this->body = mb_strlen($body) > self::BODY_LIMIT ? mb_substr($body, 0, self::BODY_LIMIT) : $body;
        }

        return $response;
    }

    /**
     * The body as it is kept: with every configured API key, and anything shaped
     * like one, replaced. Providers do echo a rejected key back in their error
     * message, and a key must not end up in the audit record.
     */
    private function scrub(string $text): string
    {
        foreach ((array) config('ai.drivers', []) as $driver) {
            $key = (string) ($driver['key'] ?? '');

            if (strlen($key) >= 4) {
                $text = str_replace($key, '[redacted]', $text);
            }
        }

        return preg_replace(
            ['/\b(sk|rk|gsk|or)-[A-Za-z0-9_\-]{8,}/', '/\bAIza[0-9A-Za-z_\-]{20,}/', '/Bearer\s+[A-Za-z0-9._\-]+/i'],
            '[redacted]',
            $text,
        );
    }

    /** Retry-After is either a number of seconds or an HTTP date. */
    private function retryAfterSeconds(): ?int
    {
        if ($this->retryAfter === null) {
            return null;
        }

        if (ctype_digit($this->retryAfter)) {
            return (int) $this->retryAfter;
        }

        $at = strtotime($this->retryAfter);

        return $at === false ? null : max(0, $at - time());
    }
}
