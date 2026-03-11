<?php

namespace App\Services;

use App\Models\ChatSession;
use App\Models\DocumentTranslation;

class AISearchService
{
    public static function make(): self
    {
        return new self();
    }

    /**
     * Главный метод: LLM сама выбирает релевантные документы (в т.ч. RU->UA),
     * затем мы подгружаем полные тексты и просим LLM ответить строго по ним.
     */
    public function answer(ChatSession $session, ?string $preferredLang = null): array
    {
        $userQuery = collect($session->messages)
    ->where('role','user')
    ->last()['content'] ?? '';

        // 1) Каталог: все переводы (или можно ограничить языком/количеством)
        $catalogLimit = (int) config('ai.catalog_limit', 400);
        $catalog = $this->getCatalog(null, $catalogLimit); // null => по всем языкам

        // 2) Пусть LLM выберет нужные document_id по смыслу
        $pickDocs = (int) config('ai.pick_docs', 6);
        $pickedIds = $this->pickDocIdsByLLM($userQuery, $catalog, $pickDocs);

        // 3) Грузим полные тексты выбранных документов (все языки или только preferredLang)
        $docs = $this->loadFullDocs($pickedIds, $preferredLang);

        // 4) Собираем контекст с лимитом, чтобы не упереться в токены
        $maxChars = (int) config('ai.max_context_chars', 90000);
        $context = $this->buildFullContext($docs, $maxChars);

        $system = <<<SYS
You are LookDesk AI assistant.
Answer strictly using the DOCUMENTS below.
If the answer is not found in the documents, say exactly: "Not found in knowledge base".
Cite sources as (Doc ID: X, lang: Y).
Keep answers short and structured.
SYS;

       $messagesCollection = $session->messages()
    ->orderByDesc('id')
    ->limit(10)
    ->get(['role','content'])
    ->reverse()
    ->values();

    $history = $messagesCollection
    ->slice(0, -1)
    ->map(fn ($m) => [
        'role' => $m->role,
        'content' => $m->content
    ])
    ->toArray();

        $messages = array_merge(
            [['role' => 'system', 'content' => $system . "\n\nDOCUMENTS:\n" . ($context ?: 'No docs found')]],
            $history,
            [['role' => 'user', 'content' => $userQuery]]
        );

        $text = OpenAIClient::chat($messages);

        return [
            'text' => $text,
            'meta' => [
                'picked_ids' => $pickedIds,
                'catalog_count' => count($catalog),
                'context_chars' => mb_strlen($context),
                'preferred_lang' => $preferredLang,
            ],
        ];
    }

    /* ===================== CATALOG ===================== */

    /**
     * Каталог документов для первичного выбора (LLM-retrieval).
     * Даем title + небольшой preview (чтобы модель поняла смысл).
     *
     * @param string|null $lang   если null — берем все языки
     * @param int         $limit  сколько записей catalog отдавать в LLM
     */
    private function getCatalog(?string $lang = null, int $limit = 400): array
    {
        $q = DocumentTranslation::query()
            ->select(['document_id', 'lang', 'title', 'content'])
            ->orderByDesc('updated_at')
            ->limit($limit);

        if ($lang) {
            $q->where('lang', $lang);
        }

        return $q->get()
            ->map(function ($t) {
                return [
                    'document_id' => (int) $t->document_id,
                    'lang'        => (string) $t->lang,
                    'title'       => (string) ($t->title ?? ''),
                    'preview'     => $t->content ?? '',
                ];
            })
            ->toArray();
    }

    /* ===================== LLM PICK DOCS ===================== */

    /**
     * Просим LLM выбрать document_id по смыслу (включая RU->UA).
     * Возвращаем массив IDs.
     */
    private function pickDocIdsByLLM(string $userQuery, array $catalog, int $pick = 6): array
    {
        $system = <<<SYS
You are a retrieval assistant for a knowledge base.

Select the most relevant documents for the user's query by searching BOTH:
- title
- content/preview

If the query explicitly mentions an exact title (e.g. "Contract EN"), you MUST include that document if present.

Return ONLY valid JSON in this exact format: {"ids":[123,456]}
Return document_id values only. Never return list indexes.
Pick up to {$pick} document_id values.
SYS;

$lines = [];
foreach ($catalog as $d) {
    $lines[] = json_encode([
        'document_id' => (int)($d['document_id'] ?? 0),
      //  'lang'        => (string)($d['lang'] ?? ''),
        'title'       => $this->oneLine($d['title'] ?? ''),
        'preview'     => $this->oneLine($d['preview'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$catalogText = implode("\n", $lines);

$messages = [
    ['role' => 'system', 'content' => $system],
    ['role' => 'user', 'content' =>
        "User query: {$userQuery}\n\nDOCUMENTS (one JSON per line):\n{$catalogText}"
    ],
];

$raw  = OpenAIClient::chat($messages);
$json = $this->extractJsonObject($raw);

        $ids = $json['ids'] ?? [];
        if (!is_array($ids)) $ids = [];

        $ids = array_values(array_unique(array_filter($ids, fn($id) => is_numeric($id))));
        $ids = array_map('intval', $ids);

        return array_slice($ids, 0, $pick);
    }

    /* ===================== LOAD FULL DOCS ===================== */

    /**
     * Загружаем полные переводы выбранных document_id.
     * Если $preferredLang задан — берем только этот язык, иначе берем все языки.
     *
     * Возвращает массив:
     * [
     *   12 => [
     *     ['document_id'=>12,'lang'=>'uk','title'=>'...','content'=>'...'],
     *     ['document_id'=>12,'lang'=>'ru','title'=>'...','content'=>'...'],
     *   ],
     *   ...
     * ]
     */
    private function loadFullDocs(array $ids, ?string $preferredLang = null): array
    {
        if (!$ids) return [];

        $q = DocumentTranslation::query()
            ->whereIn('document_id', $ids)
            ->select(['document_id', 'lang', 'title', 'content']);

        if ($preferredLang) {
            $q->where('lang', $preferredLang);
        }

        $rows = $q->get();

        // Чтобы порядок был как выбрал LLM
        $order = array_flip($ids);

        $grouped = $rows->groupBy('document_id')->toArray();
        uksort($grouped, function($a, $b) use ($order) {
            return ($order[$a] ?? 999999) <=> ($order[$b] ?? 999999);
        });

        // Нормализуем структуру
        $out = [];
        foreach ($grouped as $docId => $items) {
            $out[(int)$docId] = array_map(function($r){
                return [
                    'document_id' => (int) $r['document_id'],
                    'lang'        => (string) $r['lang'],
                    'title'       => (string) ($r['title'] ?? ''),
                    'content'     => (string) ($r['content'] ?? ''),
                ];
            }, $items);
        }

        return $out;
    }

    /* ===================== CONTEXT BUILD ===================== */

    private function buildFullContext(array $docsById, int $maxChars): string
    {
        $used = 0;
        $parts = [];

        foreach ($docsById as $docId => $translations) {
            foreach ($translations as $t) {
                $block =
                    "### Doc ID: {$t['document_id']}\n" .
                    "Lang: {$t['lang']}\n" .
                    "Title: {$t['title']}\n" .
                    "Content:\n{$t['content']}\n";

                $len = mb_strlen($block);
                if ($used + $len > $maxChars) {
                    break 2;
                }

                $parts[] = $block;
                $used += $len;
            }
        }

        return implode("\n\n", $parts);
    }

    /* ===================== HELPERS ===================== */

    private function oneLine(string $s): string
    {
        $s = str_replace(["\r\n", "\n", "\r", "\t"], ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    /**
     * Достаёт первый JSON-объект из ответа модели.
     */
    private function extractJsonObject(string $text): array
    {
        // иногда модель может обрамлять ```json ... ```
        $text = trim($text);
        $text = preg_replace('/^```json\s*/i', '', $text);
        $text = preg_replace('/```$/', '', $text);

        if (preg_match('/\{.*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}