<?php

namespace App\Services;

use App\Models\ChatSession;
use App\Models\Document;
use App\Models\DocumentEmbedding;
use App\Models\SearchQuery;
use App\Models\AiDocumentStat;

class AISearchService
{
    public static function make(): self
    {
        return new self();
    }

    /* ===================== EMBEDDING ===================== */

    public function embedQuery(SearchQuery $q): SearchQuery
    {
        if (!$q->embedding) {
            $q->embedding = OpenAIClient::embed($q->query);
            $q->save();
        }

        return $q;
    }

    /* ===================== SEARCH ===================== */

    public function findTopDocuments(array $queryVector, string $lang): array
    {
        $topK = config('ai.top_k');
        $minScore = config('ai.min_score');

        $rows = DocumentEmbedding::where('lang', $lang)->get();
        $stats = AiDocumentStat::all()->keyBy('document_id');

        $scored = [];

        foreach ($rows as $row) {

            $sim = $this->cosine($queryVector, $row->embedding ?? []);
            if ($sim < $minScore) continue;

            $bias = $stats[$row->document_id]->bias ?? 0;

            $final = $sim * (1 + $bias);

            $scored[] = [
                'document_id' => $row->document_id,
                'score' => round($final, 5),
                'similarity' => round($sim, 5),
                'bias' => round($bias, 3),
            ];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        $scored = array_slice($scored, 0, $topK);

        if (!$scored) return [];

        $docs = Document::with('translations')
            ->whereIn('id', array_column($scored, 'document_id'))
            ->get()
            ->keyBy('id');

        $out = [];

        foreach ($scored as $item) {

            $doc = $docs[$item['document_id']] ?? null;
            if (!$doc) continue;

            $tr = $doc->getTranslation($lang);

            $out[] = [
                'document_id' => $doc->id,
                'score' => $item['score'],
                'similarity' => $item['similarity'],
                'bias' => $item['bias'],
                'title' => $tr->title ?? '',
                'snippet' => mb_substr($tr->content ?? '', 0, 1200),
            ];
        }

        return $out;
    }

    /* ===================== CHAT ANSWER ===================== */

    public function answer(ChatSession $session, string $lang): array
    {
        $query = $session->search_query;

        $this->embedQuery($query);

        $docs = $this->findTopDocuments($query->embedding, $lang);

        $system = <<<SYS
You are LookDesk AI assistant.
Answer strictly using provided knowledge base.
If not found — say so clearly.
Keep answers short and structured.
SYS;

        $context = collect($docs)->map(fn($d, $i) =>
            "### Doc ".($i+1)."\nTitle: {$d['title']}\n{$d['snippet']}"
        )->implode("\n\n");

        $history = $session->messages()
            ->orderBy('id')
            ->get(['role','content'])
            ->map(fn($m) => ['role'=>$m->role,'content'=>$m->content])
            ->toArray();

        $messages = array_merge(
            [['role'=>'system','content'=>$system."\n\nCONTEXT:\n".($context ?: 'No docs found')]],
            $history,
            [['role'=>'user','content'=>$query->query]]
        );

        $text = OpenAIClient::chat($messages);

        return [
            'text' => $text,
            'meta' => [
                'documents' => $docs,
                'lang' => $lang,
            ]
        ];
    }

    /* ===================== VECTOR MATH ===================== */

    private function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if (!$n) return 0;

        $dot = $na = $nb = 0;

        for ($i=0;$i<$n;$i++){
            $dot += $a[$i]*$b[$i];
            $na += $a[$i]**2;
            $nb += $b[$i]**2;
        }

        return ($na && $nb) ? $dot / (sqrt($na)*sqrt($nb)) : 0;
    }
}

