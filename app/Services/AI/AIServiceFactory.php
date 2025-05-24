<?php

namespace App\Services\AI;

class AIServiceFactory
{
    public static function create(string $provider = null): AIServiceInterface
    {
        $provider = $provider ?: config('services.ai.default_provider', 'openai');

        return match ($provider) {
            'openai' => new OpenAIService(),
            'gemini' => new GeminiService(),
            'claude' => new ClaudeService(),
            default => throw new \InvalidArgumentException("Unsupported AI provider: {$provider}"),
        };
    }
}
