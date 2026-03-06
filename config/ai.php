<?php

return [
    'ollama_host' => env('OLLAMA_HOST', 'http://127.0.0.1:11434'),
    'chat_model'  => env('OLLAMA_CHAT_MODEL', 'llama3.1:8b'),
    'embed_model' => env('OLLAMA_EMBED_MODEL', 'nomic-embed-text'),

    'top_k' => 6,

    // минимальная релевантность FULLTEXT (подбирается экспериментально)
    'min_score_text' => 0.0,

    // лимит контекста в символах (поставь 60-120k)
    'max_context_chars' => 80000,
];