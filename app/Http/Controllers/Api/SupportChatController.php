<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatThread;
use App\Models\ChatSupportMessage;
use App\Models\ChatSupportMessageAttachment;
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
    content: new OA\MediaType(
        mediaType: "multipart/form-data",
        schema: new OA\Schema(
            type: "object",
            properties: [
               new OA\Property(property: "subject", type: "string"),
                new OA\Property(property: "message", type: "string"),

                      new OA\Property(
                        property: "attachments[0][file]",
                        type: "string",
                        format: "binary",
                        description: "attachment 1 for document"
                    ), 

                      new OA\Property(
                        property: "attachments[1][file]",
                        type: "string",
                        format: "binary",
                        description: "attachment 2 for document"
                    )

                ]
            )
)
),
	responses: [new OA\Response(response: 201, description: "Created")]
)]
    public function create(Request $request)
    {
    	$request->validate([
    		'subject' => 'nullable|string|max:255',
    		'message' => 'required|string',
            'attachments'          => 'nullable|array',
            'attachments.*.file' => 'nullable|file',
    	]);

    	$thread = ChatThread::create([
    		'user_id' => auth()->id(),
    		'subject' => $request->subject,
    	]);

    	$message = ChatSupportMessage::create([
    		'chat_thread_id' => $thread->id,
    		'user_id' => auth()->id(),
    		'role' => 'user',
    		'content' => $request->message,
    	]);

        foreach ($request->input('attachments', []) as $t) {


        if (!empty($t['file'])) {

         $file = $t['file'];

         $name = $file->getClientOriginalName();

         $path = $file->storeAs('attacments', $name, 'public');

         ChatSupportMessageAttachment::create([
            'chat_support_message_id' => $message->id,'file' =>  $path ]);
     }

     }


    	return $this->success($thread->load('messages','messages.attachments'), "Chat created", 201);
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

    	$q = ChatThread::with('user','admin','messages','messages.attachments');

    	if (!in_array($user->role_id, [1,2,7])) {
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
    	$chat = ChatThread::with('messages.user','messages.attachments')->findOrFail($id);

    	$user = auth()->user();

    	if ($chat->user_id !== $user->id && !in_array($user->role_id,[7])) {
    		abort(403);
    	}

    	return $this->success($chat);
    }

    #[OA\Get(
    path: "/api/support/chats/current",
    summary: "Get chat",
    tags: ["Support Chat"],
    security: [["sanctum" => []]],
    responses: [new OA\Response(response: 200, description: "Chat")]
)]

    public function current()
    {
        $user = auth()->user();

        if ($user->hasRole('superadmin')) {
             $chat = ChatThread::with('messages.user','messages.attachments')->where('assigned_admin_id', $user->id)->orderBy('id', 'DESC')->first();
        } else {
             $chat = ChatThread::with('messages.user','messages.attachments')->where('user_id', $user->id)->orderBy('id', 'DESC')->first();
        }

        if (!$chat) {
            abort(403);
        }

        if ($chat->user_id !== $user->id && !in_array($user->role_id,[7])) {
            abort(403);
        }

        return $this->success($chat);
    }


    #[OA\Delete(
    path: "/api/support/chats/{id}/clear",
    summary: "Clear chat",
    tags: ["Support Chat"],
    security: [["sanctum" => []]],
    parameters: [
        new OA\Parameter(name: "id", in: "path", required: true)
    ],
    responses: [new OA\Response(response: 200, description: "Chat")]
)]

    public function clearChat($id)
    {
        ChatSupportMessage::where('chat_thread_id', $id)->delete();
        return $this->success([]);
    }

    #[OA\Delete(
    path: "/api/support/chats/current/clear",
    summary: "Get chat",
    tags: ["Support Chat"],
    security: [["sanctum" => []]],
    responses: [new OA\Response(response: 200, description: "Chat")]
)]

    public function clearChatCurrent()
    {
        $user = auth()->user();

        if ($user->hasRole('superadmin')) {
             $chat = ChatThread::with('messages.user')->where('assigned_admin_id', $user->id)->orderBy('id', 'DESC')->first();
        } else {
             $chat = ChatThread::with('messages.user')->where('user_id', $user->id)->orderBy('id', 'DESC')->first();
        }

        if (!$chat) {
            abort(403);
        }

        ChatSupportMessage::where('chat_thread_id', $chat->id)->delete();

        return $this->success([]);
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
    	content: new OA\MediaType(
        mediaType: "multipart/form-data",
        schema: new OA\Schema(
            type: "object",
            properties: [
               new OA\Property(property: "subject", type: "string"),
                new OA\Property(property: "message", type: "string"),

                      new OA\Property(
                        property: "attachments[0][file]",
                        type: "string",
                        format: "binary",
                        description: "attachment 1 for document"
                    ), 

                      new OA\Property(
                        property: "attachments[1][file]",
                        type: "string",
                        format: "binary",
                        description: "attachment 2 for document"
                    )

                ]
            )
)
    ),
    responses: [new OA\Response(response: 201, description: "Sent")]
)]
    public function sendMessage($id, Request $request)
    {
    	$request->validate([
    		'message' => 'required|string',
            'attachments'          => 'nullable|array',
        'attachments.*.file' => 'nullable|file',
    	]);

    	$chat = ChatThread::findOrFail($id);
    	$user = auth()->user();

    	if ($chat->user_id !== $user->id && !in_array($user->role_id,[7])) {
    		abort(403);
    	}

    	$isAdmin = in_array($user->role_id,[7]);
    	$role = $isAdmin ? 'admin' : 'user';

    // 🔥 АВТО-НАЗНАЧЕНИЕ АДМИНА
    	if ($isAdmin && !$chat->assigned_admin_id) {
    		$chat->update([
    			'assigned_admin_id' => $user->id
    		]);
    	}

    	$message = ChatSupportMessage::create([
    		'chat_thread_id' => $chat->id,
    		'user_id'        => $user->id,
    		'role'           => $role,
    		'content'        => $request->message,
    	]);


        foreach ($request->input('attachments', []) as $t) {


        if (!empty($t['file'])) {

         $file = $t['file'];

         $name = $file->getClientOriginalName();

         $path = $file->storeAs('attacments', $name, 'public');

         ChatSupportMessageAttachment::create([
            'chat_support_message_id' => $message->id,'file' =>  $path ]);
     }

     }

    	return $this->success($chat->load('messages','messages.attachments'), "Message sent");
    }


     #[OA\Post(
    path: "/api/support/chats/current/message",
    summary: "Send message",
    tags: ["Support Chat"],
    security: [["sanctum" => []]],
     requestBody: new OA\RequestBody(
        content: new OA\MediaType(
        mediaType: "multipart/form-data",
        schema: new OA\Schema(
            type: "object",
            properties: [
               new OA\Property(property: "subject", type: "string"),
                new OA\Property(property: "message", type: "string"),

                      new OA\Property(
                        property: "attachments[0][file]",
                        type: "string",
                        format: "binary",
                        description: "attachment 1 for document"
                    ), 

                      new OA\Property(
                        property: "attachments[1][file]",
                        type: "string",
                        format: "binary",
                        description: "attachment 2 for document"
                    )

                ]
            )
)
    ),
    responses: [new OA\Response(response: 201, description: "Sent")]
)]
    public function sendMessageCurrent(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'attachments'          => 'nullable|array',
        'attachments.*.file' => 'nullable|file',
        ]);

        $user = auth()->user();

        if ($user->hasRole('superadmin')) {
             $chat = ChatThread::where('assigned_admin_id', $user->id)->orderBy('id', 'DESC')->first();
        } else {

             $chat = ChatThread::where('user_id', $user->id)->orderBy('id', 'DESC')->first();
        }

        if (!$chat) {
            abort(403);
        }


        $isAdmin = in_array($user->role_id,[7]);
        $role = $isAdmin ? 'admin' : 'user';

    // 🔥 АВТО-НАЗНАЧЕНИЕ АДМИНА
        if ($isAdmin && !$chat->assigned_admin_id) {
            $chat->update([
                'assigned_admin_id' => $user->id
            ]);
        }

       
        $message = ChatSupportMessage::create([
            'chat_thread_id' => $chat->id,
            'user_id'        => $user->id,
            'role'           => $role,
            'content'        => $request->message,
        ]);


        foreach ($request->input('attachments', []) as $t) {


        if (!empty($t['file'])) {

         $file = $t['file'];

         $name = $file->getClientOriginalName();

         $path = $file->storeAs('attacments', $name, 'public');

         ChatSupportMessageAttachment::create([
            'chat_support_message_id' => $message->id,'file' =>  $path ]);
     }

     }

        return $this->success($chat->load('messages','messages.attachments'), "Message sent");
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
    	$chat = ChatThread::findOrFail($id);
    	$chat->update(['status'=>'closed']);

    	return $this->success(null,"Chat closed");
    }
}