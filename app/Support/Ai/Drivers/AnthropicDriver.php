<?php

namespace App\Support\Ai\Drivers;

use App\Support\Ai\AiException;

/**
 * Anthropic Messages API. Structure comes from a forced tool call: the schema is
 * the tool's input schema, so the reply arrives already as JSON arguments rather
 * than as prose to be parsed.
 */
class AnthropicDriver extends AbstractDriver
{
    protected function send(string $system, string $user, array $schema): mixed
    {
        $json = $this->post(
            $this->url('messages'),
            [
                'x-api-key' => (string) $this->config['key'],
                'anthropic-version' => (string) ($this->config['version'] ?? '2023-06-01'),
            ],
            [
                'model' => $this->model(),
                'max_tokens' => (int) ($this->config['max_tokens'] ?? 1024),
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $user]],
                'tools' => [[
                    'name' => 'respond',
                    'description' => 'Return the answer in the required structure.',
                    'input_schema' => $schema,
                ]],
                'tool_choice' => ['type' => 'tool', 'name' => 'respond'],
            ],
        );

        foreach ($json['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'tool_use') {
                return $block['input'] ?? [];
            }
        }

        throw AiException::invalidResponse('it did not return the structured answer');
    }
}
