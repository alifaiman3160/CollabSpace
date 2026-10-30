<?php

/*
|--------------------------------------------------------------------------
| AI provider layer
|--------------------------------------------------------------------------
|
| One place for everything provider-specific. Nothing outside
| App\Support\Ai\Drivers knows which vendor is behind the interface, and
| nothing outside this file knows a model name, an endpoint or a key.
|
| Keys come from .env only. They are never written to the database, never put
| in a log line (error text is scrubbed of them first) and never reach a view.
|
| With no driver set, or a driver whose key is missing, the feature that asks
| for a suggestion carries on without one and says so. See AiManager.
|
*/

return [

    /*
    | Which provider answers: anthropic | openai | gemini | openai_compatible,
    | or one of the presets openrouter | groq | ollama (an OpenAI-compatible
    | server with its usual address filled in). "none" switches suggestions off.
    | "fake" is for the test suite.
    */
    'driver' => env('AI_DRIVER', 'none'),

    /*
    | Requests one lecturer may make per day, counted across every screen that
    | asks. A cap, not a target: it exists so a stuck loop or a very large
    | cohort cannot run up a bill unnoticed. 0 means no suggestions at all.
    */
    'daily_limit' => (int) env('AI_DAILY_LIMIT', 200),

    'drivers' => [

        'anthropic' => [
            'key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
            'timeout' => (int) env('ANTHROPIC_TIMEOUT', 30),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
            'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 1024),
        ],

        'openai' => [
            'key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'timeout' => (int) env('OPENAI_TIMEOUT', 30),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        ],

        'gemini' => [
            'key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
            'timeout' => (int) env('GEMINI_TIMEOUT', 30),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        ],

        /*
        | Any server that speaks the OpenAI chat API: OpenRouter, Groq, Ollama,
        | LM Studio, a company gateway. The key is optional because a local
        | Ollama needs none.
        */
        'openai_compatible' => [
            'key' => env('AI_COMPAT_API_KEY'),
            'model' => env('AI_COMPAT_MODEL'),
            'timeout' => (int) env('AI_COMPAT_TIMEOUT', 60),
            'base_url' => env('AI_COMPAT_BASE_URL'),
            'key_required' => (bool) env('AI_COMPAT_KEY_REQUIRED', true),
        ],
    ],

    /*
    | Addresses the presets fill in when AI_COMPAT_BASE_URL is left empty.
    */
    'presets' => [
        'openrouter' => 'https://openrouter.ai/api/v1',
        'groq' => 'https://api.groq.com/openai/v1',
        'ollama' => 'http://localhost:11434/v1',
    ],
];
