<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class EventController extends Controller
{
    use ApiResponse;

    /* ============================================================
     | GET USERS LIST
     ============================================================ */
    #[OA\Get(
     path: "/api/events",
     summary: "Get events list",
     tags: ["Events"],
     security: [["sanctum" => []]],
     parameters: [
        new OA\Parameter(name: "page", in: "query", schema: new OA\Schema(type: "integer")),
    ],
    responses: [
        new OA\Response(response: 200, description: "List of events")
    ]
)]
    public function index(Request $request)
    {

        $query = Event::query()->orderBy('created_at', 'DESC')->where('user_id', auth()->user()->id)->paginate(20);

        $query->transform(function ($event) {
            $event->title = $event->getTitle() ?? '';
            $event->description = $event->getLabel() ?? '';
            return $event;
        });
        return $this->success($query);
    }

    /* ============================================================
     | TOGGLE EVENTS NOTIFICATIONS (events_on)
     ============================================================ */
    #[OA\Put(
        path: "/api/events/toggle",
        summary: "Toggle events_on for current user",
        tags: ["Events"],
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                type: "object",
                properties: [
                    new OA\Property(
                        property: "events_on",
                        description: "If provided, sets events_on explicitly (true/false or 1/0). If omitted, will toggle current value.",
                        oneOf: [
                            new OA\Schema(type: "boolean"),
                            new OA\Schema(type: "integer", enum: [0, 1]),
                        ]
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Updated user events_on value",
                content: new OA\JsonContent(
                    type: "object",
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(
                            property: "data",
                            type: "object",
                            properties: [
                                new OA\Property(property: "events_on", type: "boolean", example: true),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 422, description: "Validation error"),
        ]
    )]
    public function toggle(Request $request)
    {
        $user = $request->user(); // sanctum

        // Если поле пришло — установить явно, если нет — просто переключить
        if ($request->has('events_on')) {
            $data = $request->validate([
                'events_on' => ['required'], // дальше нормализуем
            ]);

            // нормализация: true/false/"1"/"0"/1/0
            $newValue = filter_var($data['events_on'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($newValue === null) {
                // если не boolean — пробуем как 0/1
                if (in_array($data['events_on'], [0, 1, '0', '1', false, true, 'false', 'true'], true)) {
                    $newValue = (bool) $data['events_on'];
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'events_on must be boolean or 0/1',
                        'errors'  => ['events_on' => ['events_on must be boolean or 0/1']],
                    ], 422);
                }
            }

            $user->events_on = $newValue; // Eloquent сам сохранит как 1/0
        } else {
            $user->events_on = ! (bool) $user->events_on;
        }

        $user->save();

        return $this->success([
            'events_on' => (bool) $user->events_on,
        ]);
    }

/* ============================================================
     | CLEAR EVENTS FOR CURRENT USER
     ============================================================ */
    #[OA\Delete(
        path: "/api/events/clear",
        summary: "Clear (delete) events for current user",
        tags: ["Events"],
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Events cleared",
                content: new OA\JsonContent(
                    type: "object",
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated")
        ]
    )]
    public function clear(Request $request)
    {
        $user = $request->user();

        Event::query()
            ->where('user_id', $user->id)
            ->delete();

        return $this->success();
    }
}
