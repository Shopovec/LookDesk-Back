<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class OpenAIClient
{
    public static function embed(string $text): array
    {
        $res = Http::withToken(env('OPENAI_API_KEY'))
            ->post('https://api.openai.com/v1/embeddings', [
                'model' => env('OPENAI_EMBED_MODEL'),
                'input' => $text,
            ])->json();

        return $res['data'][0]['embedding'] ?? [];
    }

    public static function chat(array $messages): string
    {
        $res = Http::withToken(env('OPENAI_API_KEY'))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => env('OPENAI_MODEL'),
                'messages' => $messages,
                'temperature' => 0.2,
            ])->json();

        return $res['choices'][0]['message']['content'] ?? '';
    }
}
