<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatThread;
use App\Models\ChatSupportMessage;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class SupportChatController extends Controller
{
	use ApiResponse;

	public function __construct()
	{
		$this->middleware('auth:sanctum');
	}

    #[OA\Post(
	path: "/api/support/chats",
	summary: "Create support chat",
	tags: ["Support Chat"],
	security: [["sanctum" => []]],
	requestBody: new OA\RequestBody(
		required: true,
		content: new OA\JsonContent(
			properties: [
				new OA\Property(property: "subject", type: "string"),
				new OA\Property(property: "message", type: "string")
			],
			required: ["message"]
		)
	),
	responses: [new OA\Response(response: 201, description: "Created")]
)]
    public function create(Request $request)
    {
    	$request->validate([
    		'subject' => 'nullable|string|max:255',
    		'message' => 'required|string'
    	]);

    	$thread = ChatThread::create([
    		'user_id' => auth()->id(),
    		'subject' => $request->subject,
    	]);

    	ChatSupportMessage::create([
    		'chat_thread_id' => $thread->id,
    		'user_id' => auth()->id(),
    		'role' => 'user',
    		'content' => $request->message,
    	]);

    	return $this->success($thread->load('messages'), "Chat created", 201);
    }

    #[OA\Get(
    path: "/api/support/chats",
    summary: "List support chats",
    tags: ["Support Chat"],
    security: [["sanctum" => []]],
    responses: [new OA\Response(response: 200, description: "List")]
)]
    public function index()
    {
    	$user = auth()->user();

    	$q = ChatThread::with('user','admin','messages');

    	if (!in_array($user->role_id, [1,2])) {
    		$q->where('user_id', $user->id);
    	}

    	return $this->success(
    		$q->orderByDesc('updated_at')->get()
    	);
    }

    #[OA\Get(
    path: "/api/support/chats/{id}",
    summary: "Get chat",
    tags: ["Support Chat"],
    security: [["sanctum" => []]],
    parameters: [
    	new OA\Parameter(name: "id", in: "path", required: true)
    ],
    responses: [new OA\Response(response: 200, description: "Chat")]
)]

    public function show($id)
    {
    	$chat = ChatThread::with('messages.user')->findOrFail($id);

    	$user = auth()->user();

    	if ($chat->user_id !== $user->id && !in_array($user->role_id,[1,2])) {
    		abort(403);
    	}

    	return $this->success($chat);
    }

    #[OA\Post(
    path: "/api/support/chats/{id}/message",
    summary: "Send message",
    tags: ["Support Chat"],
    security: [["sanctum" => []]],
    parameters: [
    	new OA\Parameter(name: "id", in: "path", required: true)
    ],
    requestBody: new OA\RequestBody(
    	content: new OA\JsonContent(
    		properties: [
    			new OA\Property(property: "message", type: "string")
    		],
    		required: ["message"]
    	)
    ),
    responses: [new OA\Response(response: 201, description: "Sent")]
)]
    public function sendMessage($id, Request $request)
    {
    	$request->validate([
    		'message' => 'required|string'
    	]);

    	$chat = ChatThread::findOrFail($id);
    	$user = auth()->user();

    	if ($chat->user_id !== $user->id && !in_array($user->role_id,[1,2])) {
    		abort(403);
    	}

    	$isAdmin = in_array($user->role_id,[1,2]);
    	$role = $isAdmin ? 'admin' : 'user';

    // 🔥 АВТО-НАЗНАЧЕНИЕ АДМИНА
    	if ($isAdmin && !$chat->assigned_admin_id) {
    		$chat->update([
    			'assigned_admin_id' => $user->id
    		]);
    	}

    	ChatSupportMessage::create([
    		'chat_thread_id' => $chat->id,
    		'user_id'        => $user->id,
    		'role'           => $role,
    		'content'        => $request->message,
    	]);

    	return $this->success($chat->load('messages'), "Message sent");
    }

    #[OA\Post(
    path: "/api/support/chats/{id}/close",
    summary: "Close chat",
    tags: ["Support Chat"],
    security: [["sanctum" => []]],
    parameters: [
    	new OA\Parameter(name: "id", in: "path", required: true)
    ],
    responses: [new OA\Response(response: 200, description: "Closed")]
)]
    public function close($id)
    {
    	$user = auth()->user();
    	if (!in_array($user->role_id,[1,2])) abort(403);

    	$chat = ChatThread::findOrFail($id);
    	$chat->update(['status'=>'closed']);

    	return $this->success(null,"Chat closed");
    }
}