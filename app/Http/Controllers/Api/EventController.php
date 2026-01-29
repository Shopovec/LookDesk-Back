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

        $query = Event::query()->orderBy('created_at', 'DESC')->paginate(20);

        $query->transform(function ($event) {
            $event->title = $event->getTitle();
            $event->description = $event->getLabel();
            return $event;
        });
        return $this->success($query);
    }
}
