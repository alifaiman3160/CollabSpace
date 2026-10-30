<?php

namespace App\Support\Ai;

use App\Support\Ai\Drivers\AbstractDriver;
use App\Support\Ai\Drivers\AnthropicDriver;
use App\Support\Ai\Drivers\FakeDriver;
use App\Support\Ai\Drivers\GeminiDriver;
use App\Support\Ai\Drivers\OpenAiCompatibleDriver;
use App\Support\Ai\Drivers\OpenAiDriver;

/**
 * Turns AI_DRIVER and config/ai.php into a provider, or into a reason there is
 * not one.
 *
 * The rest of the application asks two questions of this class: is a provider
 * available, and if not, why not in words a lecturer can read. It never has to
 * handle a half-configured provider, because a missing key or model is reported
 * here as "not available", not discovered as an error on the first request.
 */
class AiManager
{
    /** Driver name => [class, label, config key]. */
    private const DRIVERS = [
        'anthropic' => [AnthropicDriver::class, 'Anthropic', 'anthropic'],
        'openai' => [OpenAiDriver::class, 'OpenAI', 'openai'],
        'gemini' => [GeminiDriver::class, 'Google Gemini', 'gemini'],
        'openai_compatible' => [OpenAiCompatibleDriver::class, 'the OpenAI-compatible server', 'openai_compatible'],
        // Presets of the above with their usual address filled in.
        'openrouter' => [OpenAiCompatibleDriver::class, 'OpenRouter', 'openai_compatible'],
        'groq' => [OpenAiCompatibleDriver::class, 'Groq', 'openai_compatible'],
        'ollama' => [OpenAiCompatibleDriver::class, 'Ollama', 'openai_compatible'],
    ];

    private ?AiProvider $instance = null;

    /** The driver name as configured, lower-cased; 'none' when unset. */
    public function driverName(): string
    {
        $name = strtolower(trim((string) config('ai.driver', 'none')));

        return $name === '' ? 'none' : $name;
    }

    public function enabled(): bool
    {
        return $this->reason() === null;
    }

    /**
     * Why suggestions from a provider are unavailable, or null when they are.
     * Written to be shown as is: neutral, no key, no stack trace.
     */
    public function reason(): ?string
    {
        $name = $this->driverName();

        if ($name === 'none') {
            return 'AI suggestions are switched off. Rule-based suggestions are shown instead.';
        }

        if ($name === 'fake') {
            return null;
        }

        if (! isset(self::DRIVERS[$name])) {
            return 'AI suggestions are unavailable: “'.$name.'” is not a supported provider. Rule-based suggestions are shown instead.';
        }

        [, $label] = self::DRIVERS[$name];
        $config = $this->configFor($name);

        if (blank($config['model'] ?? null)) {
            return "AI suggestions are unavailable: no model is set for {$label}. Rule-based suggestions are shown instead.";
        }

        if (in_array($name, ['openai_compatible', 'openrouter', 'groq', 'ollama'], true) && blank($config['base_url'] ?? null)) {
            return "AI suggestions are unavailable: no server address is set for {$label}. Rule-based suggestions are shown instead.";
        }

        if (blank($config['key'] ?? null) && $this->keyRequired($name, $config)) {
            return "AI suggestions are unavailable: no API key is set for {$label}. Rule-based suggestions are shown instead.";
        }

        return null;
    }

    /**
     * @return array{provider: string, label: string, model: string}
     *
     * For display and for the audit record. There is deliberately no key here.
     */
    public function current(): array
    {
        $name = $this->driverName();

        if ($name === 'fake') {
            return ['provider' => 'fake', 'label' => 'Test provider', 'model' => 'fake-model'];
        }

        [, $label] = self::DRIVERS[$name] ?? [null, ucfirst($name)];

        return [
            'provider' => $name,
            'label' => $label,
            'model' => (string) ($this->configFor($name)['model'] ?? ''),
        ];
    }

    /** @throws AiException  when no provider is available, with the reason */
    public function provider(): AiProvider
    {
        if ($reason = $this->reason()) {
            throw AiException::notConfigured($reason);
        }

        return $this->instance ??= $this->build($this->driverName());
    }

    /** Forget the built provider, for tests that change config between calls. */
    public function flush(): void
    {
        $this->instance = null;
    }

    private function build(string $name): AiProvider
    {
        if ($name === 'fake') {
            return new FakeDriver;
        }

        [$class] = self::DRIVERS[$name];

        /** @var AbstractDriver $driver */
        $driver = new $class($name, $this->configFor($name));

        return $driver;
    }

    /** @return array<string, mixed> */
    private function configFor(string $name): array
    {
        $key = self::DRIVERS[$name][2] ?? $name;
        $config = (array) config("ai.drivers.{$key}", []);

        if (blank($config['base_url'] ?? null)) {
            $config['base_url'] = config("ai.presets.{$name}");
        }

        return $config;
    }

    /** A local Ollama needs no key; anything else does unless told otherwise. */
    private function keyRequired(string $name, array $config): bool
    {
        if ($name === 'ollama') {
            return false;
        }

        if ($name === 'openai_compatible') {
            return (bool) ($config['key_required'] ?? true);
        }

        return true;
    }
}
