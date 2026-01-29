<?php

return [
    'ollama_host' => env('OLLAMA_HOST', 'http://127.0.0.1:11434'),
    'chat_model'  => env('OLLAMA_CHAT_MODEL', 'llama3.1:8b'),
    'embed_model' => env('OLLAMA_EMBED_MODEL', 'nomic-embed-text'),

    'top_k'      => (int) env('AI_TOP_K', 5),
    'min_score'  => (float) env('AI_MIN_SCORE', 0.35),
];