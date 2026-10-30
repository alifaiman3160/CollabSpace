<?php

namespace App\Support\Ai\Drivers;

use App\Support\Ai\AiException;

/**
 * OpenAI Chat Completions with a JSON-schema response format.
 */
class OpenAiDriver extends AbstractDriver
{
    protected function send(string $system, string $user, array $schema): mixed
    {
        $json = $this->post(
            $this->url('chat/completions'),
            $this->headers(),
            [
                'model' => $this->model(),
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => ['name' => 'answer', 'schema' => $schema, 'strict' => false],
                ],
            ],
        );

        return $this->content($json);
    }

    protected function headers(): array
    {
        $key = (string) ($this->config['key'] ?? '');

        return $key === '' ? [] : ['Authorization' => 'Bearer '.$key];
    }

    protected function content(array $json): string
    {
        $message = $json['choices'][0]['message'] ?? [];

        if (! empty($message['refusal'])) {
            throw AiException::invalidResponse('the provider declined to answer');
        }

        $content = $message['content'] ?? null;

        if (! is_string($content) || $content === '') {
            throw AiException::invalidResponse('the reply was empty');
        }

        return $content;
    }
}
