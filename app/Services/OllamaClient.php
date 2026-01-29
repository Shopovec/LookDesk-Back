<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class OllamaClient
{
    public function __construct(
        private readonly string $host,
        private readonly string $chatModel,
        private readonly string $embedModel
    ) {}

    public static function make(): self
    {
        return new self(
            config('ai.ollama_host'),
            config('ai.chat_model'),
            config('ai.embed_model')
        );
    }

    public function embed(string $text): array
    {
        $res = Http::timeout(60)->post($this->host . '/api/embeddings', [
            'model'  => $this->embedModel,
            'prompt' => $text,
        ])->throw()->json();

        // ollama embeddings: ['embedding' => [...]]
        return $res['embedding'] ?? [];
    }

    /**
     * @param array<int, array{role:string, content:string}> $messages
     */
    public function chat(array $messages): string
    {
        $res = Http::timeout(180)->post($this->host . '/api/chat', [
            'model'    => $this->chatModel,
            'messages' => $messages,
            'stream'   => false,
        ])->throw()->json();

        return $res['message']['content'] ?? '';
    }
}
