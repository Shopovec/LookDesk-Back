<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentTranslation;
use Illuminate\Support\Facades\DB;

class DocumentService
{
    /**
     * Create document with translations
     */
    public function create(array $data, $userId)
    {
        return DB::transaction(function () use ($data, $userId) {

            $doc = Document::create([
                'category_id' => $data['category_id'] ?? null,
                'is_favorite' => $data['is_favorite'] ?? false,
                'is_archived' => $data['is_archived'] ?? false,
                'file_path'   => $data['file_path'] ?? null,
                'type'        => $data['type'] ?? 'manual',
                'meta'        => $data['meta'] ?? [],
                'created_by'  => $userId,
                'updated_by'  => $userId,
            ]);

            foreach ($data['translations'] as $tr) {
                DocumentTranslation::create([
                    'document_id' => $doc->id,
                    'lang'        => $tr['lang'],
                    'title'       => $tr['title'],
                    'content'     => $tr['content'],
                    'summary'     => $tr['summary'] ?? null,
                ]);
            }

            return $doc->load('translations');
        });
    }

    /**
     * Update document + translations
     */
    public function update(Document $doc, array $data, $userId)
    {
        return DB::transaction(function () use ($doc, $data, $userId) {

            $doc->update([
                'category_id' => $data['category_id'] ?? $doc->category_id,
                'is_favorite' => $data['is_favorite'] ?? $doc->is_favorite,
                'is_archived' => $data['is_archived'] ?? $doc->is_archived,
                'file_path'   => $data['file_path'] ?? $doc->file_path,
                'type'        => $data['type'] ?? $doc->type,
                'meta'        => $data['meta'] ?? $doc->meta,
                'updated_by'  => $userId,
            ]);

            // update/create translations
            if (isset($data['translations'])) {
                foreach ($data['translations'] as $tr) {
                    DocumentTranslation::updateOrCreate(
                        [
                            'document_id' => $doc->id,
                            'lang'        => $tr['lang']
                        ],
                        [
                            'title'    => $tr['title'],
                            'content'  => $tr['content'],
                            'summary'  => $tr['summary'] ?? null,
                        ]
                    );
                }
            }

            return $doc->load('translations');
        });
    }

    /**
     * Delete document (soft-delete possible)
     */
    public function delete(Document $doc)
    {
        return $doc->delete();
    }
}
