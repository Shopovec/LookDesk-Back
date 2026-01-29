<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentEmbedding;
use App\Services\OllamaClient;
use Illuminate\Console\Command;

class BuildDocumentEmbeddings extends Command
{
    protected $signature = 'ai:embed-docs {--lang=en} {--force}';
    protected $description = 'Build embeddings for documents translations';

    public function handle(): int
    {
        $lang = (string) $this->option('lang');
        $force = (bool) $this->option('force');

        $ollama = OllamaClient::make();

        $docs = Document::with('translations')->get();
        $count = 0;

        foreach ($docs as $doc) {
            $tr = method_exists($doc, 'getTranslation') ? $doc->getTranslation($lang) : null;
            if (!$tr) continue;

            $text = trim(($tr->title ?? '') . "\n" . ($tr->content ?? $tr->description ?? ''));
            if ($text === '') continue;

            $exists = DocumentEmbedding::where('document_id', $doc->id)->where('lang', $lang)->first();
            if ($exists && !$force) continue;

            $vec = $ollama->embed($text);

            DocumentEmbedding::updateOrCreate(
                ['document_id' => $doc->id, 'lang' => $lang],
                ['embedding' => $vec]
            );

            $count++;
            $this->info("Embedded doc={$doc->id} lang={$lang}");
        }

        $this->info("Done. Embedded: {$count}");
        return self::SUCCESS;
    }
}
