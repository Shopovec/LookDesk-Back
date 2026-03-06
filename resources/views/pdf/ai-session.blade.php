<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>AI Session #{{ $session->id }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        .h1 { font-size: 18px; font-weight: 700; margin-bottom: 6px; }
        .muted { color: #666; }
        .meta { margin-bottom: 14px; }
        .msg { margin: 10px 0; padding: 10px; border: 1px solid #ddd; border-radius: 6px; }
        .role { font-weight: 700; margin-bottom: 6px; text-transform: uppercase; font-size: 11px; }
        .content { white-space: pre-wrap; }
        .fb { margin-top: 6px; font-size: 11px; }
    </style>
</head>
<body>
    <div class="h1">AI Chat Session #{{ $session->id }}</div>

    <div class="meta muted">
        User: {{ $user->email ?? ('ID ' . $user->id) }}<br>
        Query: {{ $session->search_query?->query }}<br>
        Lang: {{ $session->search_query?->lang }}<br>
        Created: {{ optional($session->created_at)->toDateTimeString() }}
    </div>

    @foreach($session->messages->sortBy('id') as $m)
        <div class="msg">
            <div class="role">{{ $m->role }}</div>
            <div class="content">{{ $m->content }}</div>

            @if($m->feedback)
                <div class="fb muted">
                    Feedback: {{ $m->feedback->is_useful ? 'useful' : 'not useful' }}
                    @if($m->feedback->comment)
                        — {{ $m->feedback->comment }}
                    @endif
                </div>
            @endif
        </div>
    @endforeach
</body>
</html>