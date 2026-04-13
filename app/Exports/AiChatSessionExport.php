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
    public function __construct(private Collection $sessions) {}

    public function collection(): Collection
    {
        return $this->sessions
            ->flatMap(fn ($session) =>
                $session->messages->map(fn ($msg) => [
                    'session' => $session,
                    'msg' => $msg
                ])
            )
            ->values();
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

    public function map($row): array
    {
        $session = $row['session'];
        $msg = $row['msg'];

        return [
            $session->id,
            $session->search_query?->query,
            $session->search_query?->lang,
            $msg->id,
            $msg->role,
            $msg->content,
            optional($msg->created_at)->toDateTimeString(),
            optional($msg->feedback)->is_useful,
            optional($msg->feedback)->comment,
        ];
    }
}