<?php

namespace App\Support\Ai\Drivers;

use App\Support\Ai\AiException;
use App\Support\Ai\AiProvider;
use App\Support\Ai\SchemaValidator;
use Closure;

/**
 * A provider for tests: no network, scripted answers, and a record of exactly
 * what it was sent so a test can look at the payload.
 *
 *   config(['ai.driver' => 'fake']);
 *   FakeDriver::respondWith(['level' => 'L3', 'rationale' => '...']);
 *   // ... exercise the feature ...
 *   FakeDriver::sent();   // [['system' => ..., 'payload' => ..., 'schema' => ...]]
 *
 * respondWith takes an array, a list of arrays (used in order), a Closure that
 * receives the payload, or an AiException/Throwable to be thrown. Answers go
 * through the same schema validation as a real provider's, so a test of a bad
 * answer exercises the real rejection path.
 */
class FakeDriver implements AiProvider
{
    /** @var list<array<string, mixed>|Closure|\Throwable> */
    private static array $queue = [];

    private static mixed $default = null;

    /** @var list<array{system: string, payload: string, schema: array<string, mixed>}> */
    private static array $sent = [];

    public static function respondWith(array|Closure|\Throwable $answer): void
    {
        self::$default = $answer;
        self::$queue = [];
    }

    /** Answers used once each, in order, before falling back to respondWith. */
    public static function queue(array|Closure|\Throwable ...$answers): void
    {
        self::$queue = array_values($answers);
    }

    /** @return list<array{system: string, payload: string, schema: array<string, mixed>}> */
    public static function sent(): array
    {
        return self::$sent;
    }

    public static function reset(): void
    {
        self::$queue = [];
        self::$default = null;
        self::$sent = [];
    }

    public function provider(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-model';
    }

    public function complete(string $system, array|string $payload, array $schema): array
    {
        self::$sent[] = [
            'system' => trim($system)."\n\n".AbstractDriver::STANDING_NOTICE,
            'payload' => is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $payload,
            'schema' => $schema,
        ];

        $answer = array_shift(self::$queue) ?? self::$default;

        if ($answer === null) {
            throw AiException::requestFailed('no scripted answer');
        }

        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        if ($answer instanceof Closure) {
            $answer = $answer($payload);
        }

        SchemaValidator::assert($answer, $schema);

        return $answer;
    }
}
