<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        h2 { margin-bottom: 20px; }
        table { width:100%; border-collapse: collapse; }
        th, td {
            border:1px solid #ddd;
            padding:6px;
        }
        th {
            background:#f3f3f3;
        }
    </style>
</head>
<body>

<h2>Documents Report</h2>

<p>
Generated: {{ now()->format('Y-m-d H:i') }}<br>
</p>

<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Title</th>
            <th>Content</th>
            <th>Views (30d)</th>
            <th>AI Searches (30d)</th>
            <th>Created</th>
        </tr>
    </thead>
    <tbody>
        @foreach($documents as $doc)
            <tr style="page-break-inside: avoid;">
                <td>{{ $doc->id }}</td>
                <td>{{ $doc->translated['title'] ?? '' }}</td>
                <td>
    {{ \Illuminate\Support\Str::limit($doc->translations[0]['content'] ?? '', 500) }}
</td>
                <td>{{ $doc->views_last_30_days }}</td>
                <td>{{ $doc->ai_searches_last_30_days }}</td>
                <td>{{ $doc->created_at }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
<style>
    body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 11px;
    }

    h2 {
        margin-bottom: 20px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }

    thead {
        display: table-header-group;
    }

    tfoot {
        display: table-footer-group;
    }

    tr {
        page-break-inside: avoid !important;
        page-break-after: auto;
    }

    th, td {
        border: 1px solid #ddd;
        padding: 6px;
        vertical-align: top;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }

    th {
        background: #f3f3f3;
    }
</style>
</body>
</html>