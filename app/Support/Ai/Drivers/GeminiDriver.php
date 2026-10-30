<?php

namespace App\Support\Ai\Drivers;

use App\Support\Ai\AiException;

/**
 * Google Gemini generateContent in JSON mode. The key goes in a header, never in
 * the query string, so it cannot end up in a URL that something logs.
 */
class GeminiDriver extends AbstractDriver
{
    protected function send(string $system, string $user, array $schema): mixed
    {
        $json = $this->post(
            $this->url('models/'.rawurlencode($this->model()).':generateContent'),
            ['x-goog-api-key' => (string) $this->config['key']],
            [
                'systemInstruction' => ['parts' => [['text' => $system."\n\n".$this->schemaInstruction($schema)]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
                'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0],
            ],
        );

        $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (! is_string($text) || $text === '') {
            throw AiException::invalidResponse('the reply was empty or blocked');
        }

        return $text;
    }
}
