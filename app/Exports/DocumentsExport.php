<?php
namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class DocumentsExport implements FromCollection, WithHeadings
{
    protected $documents;

    public function __construct($documents)
    {
        $this->documents = $documents;
    }

    public function collection()
    {
        return $this->documents->map(function ($doc) {
            return [
                'ID' => $doc->id,
                'Title' => $doc->translated['title'] ?? '',
                'Content' => $doc->translations[0]['content'] ?? '',
                'Views (30d)' => $doc->views_last_30_days,
                'AI Searches (30d)' => $doc->ai_searches_last_30_days,
                'Created At' => $doc->created_at,
            ];
        });
    }

    public function headings(): array
    {
        return [
            'ID',
            'Title',
            'Content',
            'Views Last 30 Days',
            'AI Searches Last 30 Days',
            'Created At',
        ];
    }
}