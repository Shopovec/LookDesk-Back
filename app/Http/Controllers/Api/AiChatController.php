<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatFeedback;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\SearchQuery;
use App\Services\AISearchService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use App\Models\AiDocumentStat;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\AiChatSessionExport;

class AiChatController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    #[OA\Put(
    path: "/api/ai/sessions/{id}/favorite",
    summary: "Mark/unmark a search query as favorite",
    tags: ["AI Chat"],
    security: [["sanctum" => []]],
    parameters: [
        new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ["is_favorite"],
            properties: [
                new OA\Property(property: "is_favorite", type: "boolean", example: true),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: "Updated search query"),
        new OA\Response(response: 404, description: "Not found"),
    ]
)]

    public function favoriteSearch($id, Request $request)
    {
        $data = $request->validate([
            'is_favorite' => 'required|boolean',
        ]);

        $q = ChatSession::find($id);
        if (!$q) return response()->json(['message' => 'Not found'], 404);

        $q->is_favorite = (bool) $data['is_favorite'];
        $q->save();

        return response()->json($q);
    }

    #[OA\Get(
    path: "/api/ai/sessions/{id}/export/excel",
    summary: "Export AI chat session to Excel (.xlsx)",
    tags: ["AI Chat"],
    security: [["sanctum" => []]],
    parameters: [
        new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
    ],
    responses: [
        new OA\Response(response: 200, description: "XLSX file"),
        new OA\Response(response: 404, description: "Not found"),
    ]
)]
    public function exportExcel($id): BinaryFileResponse
    {
        $session = ChatSession::where('user_id', auth()->id())
        ->with(['search_query', 'messages.feedback'])
        ->find($id);

        if (!$session) {
            abort(404, 'Not found');
        }

        $fileName = 'ai_session_' . $session->id . '_' . now()->format('Ymd_His') . '.xlsx';

        return Excel::download(new AiChatSessionExport($session), $fileName);
    }

#[OA\Get(
    path: "/api/ai/sessions/{id}/export/pdf",
    summary: "Export AI chat session to PDF",
    tags: ["AI Chat"],
    security: [["sanctum" => []]],
    parameters: [
        new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
    ],
    responses: [
        new OA\Response(response: 200, description: "PDF file"),
        new OA\Response(response: 404, description: "Not found"),
    ]
)]
public function exportPdf($id)
{
    $session = ChatSession::where('user_id', auth()->id())
    ->with(['search_query', 'messages.feedback'])
    ->find($id);

    if (!$session) {
        return response()->json(['message' => 'Not found'], 404);
    }

    $fileName = 'ai_session_' . $session->id . '_' . now()->format('Ymd_His') . '.pdf';

    $pdf = Pdf::loadView('pdf.ai-session', [
        'session' => $session,
        'user' => auth()->user(),
    ])->setPaper('a4');

    return $pdf->download($fileName);
}

    #[OA\Post(
path: "/api/ai/search",
summary: "Create AI chat session from query (Recent Search with AI)",
tags: ["AI Chat"],
security: [["sanctum" => []]],
requestBody: new OA\RequestBody(
    required: true,
    content: new OA\JsonContent(
        required: ["query"],
        properties: [
            new OA\Property(property: "query", type: "string", example: "Where can I find the new GDPR compliance rules?"),
            new OA\Property(property: "lang", type: "string", example: "en")
        ]
    )
),
responses: [
    new OA\Response(response: 200, description: "Chat session with messages"),
]
)]
    public function search(Request $request)
    {
        $request->validate([
            'query' => 'required|string|max:1000',
            'lang'  => 'nullable|string|in:en,ru,uk,de',
        ]);

        $request = (object)$request->all();


        $lang = $request->lang ?? 'en';

        $q = SearchQuery::create([
            'user_id' => auth()->id(),
            'lang'    => $lang,
            'query'   => $request->query,
        ]);





        $session = ChatSession::create([
            'user_id' => auth()->id(),
            'search_query_id' => $q->id,
        ]);



        // user message in chat
        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'user',
            'content' => $request->query,
        ]);



        $ai = AISearchService::make();
        $answer = $ai->answer($session->fresh('messages', 'search_query'), $lang);

        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'assistant',
            'content' => $answer['text'],
            'meta' => $answer['meta'],
        ]);

        return response()->json($session->fresh()->load(['search_query', 'messages.feedback']));
    }

    #[OA\Get(
    path: "/api/ai/sessions",
    summary: "List recent AI chat sessions",
    tags: ["AI Chat"],
    security: [["sanctum" => []]],
    parameters: [
        new OA\Parameter(name: "per_page", in: "query", schema: new OA\Schema(type: "integer", default: 20)),
    ],
    responses: [
        new OA\Response(response: 200, description: "Paginated sessions")
    ]
)]
    public function sessions(Request $request)
    {
        $perPage = (int)($request->get('per_page', 20));

        $items = ChatSession::where('user_id', auth()->id())
        ->with(['search_query', 'messages.feedback'])
        ->orderByDesc('id')
        ->paginate($perPage);

        return response()->json($items);
    }

    #[OA\Get(
    path: "/api/ai/sessions/{id}",
    summary: "Get chat session details with messages",
    tags: ["AI Chat"],
    security: [["sanctum" => []]],
    parameters: [
        new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
    ],
    responses: [
        new OA\Response(response: 200, description: "Session"),
        new OA\Response(response: 404, description: "Not found"),
    ]
)]
    public function showSession($id)
    {
        $session = ChatSession::where('user_id', auth()->id())
        ->with(['search_query', 'messages.feedback'])
        ->find($id);

        if (!$session) return response()->json(['message' => 'Not found'], 404);

        return response()->json($session);
    }

    #[OA\Post(
    path: "/api/ai/sessions/{id}/message",
    summary: "Send a new message in existing session (continue chat)",
    tags: ["AI Chat"],
    security: [["sanctum" => []]],
    parameters: [
        new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ["message"],
            properties: [
                new OA\Property(property: "message", type: "string", example: "Can you summarize the policy in 3 bullet points?"),
                new OA\Property(property: "lang", type: "string", example: "en"),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: "New assistant message"),
        new OA\Response(response: 404, description: "Not found"),
    ]
)]
    public function message($id, Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:3000',
            'lang' => 'nullable|string|in:en,ru,uk,de',
        ]);

        $request = (object)$request->all();

        $lang = $request->lang ?? 'en';

        $session = ChatSession::where('user_id', auth()->id())
        ->with(['search_query', 'messages'])
        ->find($id);

        if (!$session) return response()->json(['message' => 'Not found'], 404);

        // append user message
        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'user',
            'content' => $request->message,
        ]);

        // IMPORTANT: query stays original; but we can temporarily answer based on new message
        // simplest: overwrite query->query for this response only (without saving)
        $session->search_query->query = $request->message;

        $ai = AISearchService::make();
        $answer = $ai->answer($session->fresh('messages'), $lang);

        $assistant = ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'assistant',
            'content' => $answer['text'],
            'meta' => $answer['meta'],
        ]);

        return response()->json($assistant->load('feedback'));
    }

    #[OA\Post(
    path: "/api/ai/messages/{id}/feedback",
    summary: "Leave feedback for assistant message",
    tags: ["AI Chat"],
    security: [["sanctum" => []]],
    parameters: [
        new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ["is_useful"],
            properties: [
                new OA\Property(property: "is_useful", type: "boolean", example: true),
                new OA\Property(property: "comment", type: "string", example: "This answer was helpful because..."),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: "Saved")
    ]
)]
    public function feedback($id, Request $request)
    {
        $request->validate([
            'is_useful' => 'required|boolean',
            'comment' => 'nullable|string|max:255',
        ]);

        $request = (object)$request->all();

        $msg = ChatMessage::find($id);
        if (!$msg) return response()->json(['message' => 'Not found'], 404);

        // security: ensure this message belongs to this user session
        $session = $msg->session;
        if (!$session || $session->user_id !== auth()->id()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $fb = ChatFeedback::updateOrCreate(
            ['chat_message_id' => $msg->id],
            ['is_useful' => (bool)$request->is_useful, 'comment' => $request->comment]
        );

        $docs = $msg->meta['documents'] ?? [];

        foreach ($docs as $d) {

            $stat = AiDocumentStat::firstOrCreate([
                'document_id' => $d['document_id']
            ]);

            if ($request->is_useful) {
                $stat->positive++;
            } else {
                $stat->negative++;
            }

    // Reinforcement formula (простая и эффективная)
            $stat->bias = ($stat->positive - $stat->negative)
            / max(1, $stat->positive + $stat->negative);

            $stat->save();
        }

        return response()->json($fb);
    }
}
