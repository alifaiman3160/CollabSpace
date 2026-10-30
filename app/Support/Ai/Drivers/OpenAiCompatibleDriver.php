<?php

namespace App\Support\Ai\Drivers;

/**
 * Any server that speaks the OpenAI chat API: OpenRouter, Groq, Ollama and the
 * like. They differ on whether a schema-constrained mode exists, so this asks
 * for plain JSON mode and states the schema in words; the reply is validated
 * here either way.
 */
class OpenAiCompatibleDriver extends OpenAiDriver
{
    protected function send(string $system, string $user, array $schema): mixed
    {
        $json = $this->post(
            $this->url('chat/completions'),
            $this->headers(),
            [
                'model' => $this->model(),
                'temperature' => 0,
                'messages' => [
                    ['role' => 'system', 'content' => $system."\n\n".$this->schemaInstruction($schema)],
                    ['role' => 'user', 'content' => $user],
                ],
                'response_format' => ['type' => 'json_object'],
            ],
        );

        return $this->content($json);
    }
}
