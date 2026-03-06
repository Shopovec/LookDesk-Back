<?php

namespace App\Exports;

use App\Models\ChatSession;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class AiChatSessionExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    public function __construct(private ChatSession $session) {}

    public function collection(): Collection
    {
        // сортируем по id/created_at на всякий
        return $this->session->messages->sortBy('id')->values();
    }

    public function headings(): array
    {
        return [
            'session_id',
            'query',
            'lang',
            'message_id',
            'role',
            'content',
            'created_at',
            'feedback_is_useful',
            'feedback_comment',
        ];
    }

    public function map($msg): array
    {
        return [
            $this->session->id,
            $this->session->search_query?->query,
            $this->session->search_query?->lang,
            $msg->id,
            $msg->role,
            $msg->content,
            optional($msg->created_at)->toDateTimeString(),
            optional($msg->feedback)->is_useful,
            optional($msg->feedback)->comment,
        ];
    }
}