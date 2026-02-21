<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Document;
use App\Models\DocumentTranslation;
use App\Models\OcrScan;
use App\Models\User;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use App\Models\ChatSession;
use App\Models\ChatMessage;
use App\Models\AiDocumentStat;
use App\Models\Subscription;

class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /* ============================================================
     | DASHBOARD MAIN
     ============================================================ */
    #[OA\Get(
     path: "/api/dashboard",
     summary: "Dashboard summary",
     description: "Returns overall system statistics for dashboard widgets",
     tags: ["Dashboard"],
     security: [["sanctum" => []]],
     responses: [
        new OA\Response(response: 200, description: "Dashboard data")
    ]
)]
    public function index(Request $request)
    {
        $user = auth()->user();

        // base response for any role
        $data = [
            'documents_total'       => Document::count(),
            'categories_total'      => Category::count(),
            'translations_total'    => DocumentTranslation::count(),
            'ocr_total'             => OcrScan::count(),
            'my_ocr_total'          => OcrScan::where('user_id', $user->id)->count(),
            'latest_documents'      => $this->latestDocuments(),
            'latest_ocr'            => $this->latestOcr($user),
            'documents_per_day'     => $this->documentsGraph(),
            'categories_usage'      => $this->categoriesUsage(),
            // 📈 чаты сегодня
            'ai_sessions_today' => ChatSession::whereDate('created_at', today())->count(),

    // ⭐ топ запросов (частота)
            'top_ai_queries' => ChatMessage::selectRaw('content, COUNT(*) as total')
            ->where('role','user')
            ->groupBy('content')
            ->orderByDesc('total')
            ->limit(5)
            ->get(),
        ];

        // 🔥 ТОЛЬКО ДЛЯ USER
    if ($user->hasRole('user')) {
                $data['billing'] = $this->aiEconomics($user);

    }

        // ONLY admin/owner gets global users info
        if (in_array($user->role_id, [1,2])) {
            $data['users_total'] = User::count();
            $data['latest_users'] = $this->latestUsers();
        }

        return $this->success($data);
    }

private function aiEconomics($user): array
{
    $subscription = $this->subscriptionCard($user);

    $totalRevenue = Subscription::where('user_id', $user->id)
        ->whereIn('status', ['active','canceled', 'trialing'])
        ->join('plan_prices', 'subscriptions.plan_price_id', '=', 'plan_prices.id')
        ->sum('plan_prices.price');

    $aiAnswers = ChatMessage::where('role','assistant')
        ->whereHas('session', fn($q)=>$q->where('user_id',$user->id))
        ->count();

    $aiCost = round($aiAnswers * config('ai.cost_per_answer', 0.002), 2);

    $margin = $totalRevenue > 0
        ? round((($totalRevenue - $aiCost) / $totalRevenue) * 100, 1)
        : 0;

    return [
        'current_plan' => [
            'name'   => $subscription['name'] ?? 'None',
            'status' => $subscription['status'] ?? '',
            'price'  => $subscription['price'] ?? 0,
            'period' => $subscription['period'] ?? 0,
        ],

        'total_revenue' => round($totalRevenue, 2),
        'total_ai_cost' => $aiCost,
        'net_margin'    => $margin,
    ];
}

private function subscriptionCard($user): array
{
    $subscription = $user->subscription()
        ->with(['planPrice.plan'])
        ->first();

    if (!$subscription) {
        return [
            'name'   => 'Free',
            'price'  => 0,
            'status' => 'inactive'
        ];
    }

    return [
        'name'   => $subscription->planPrice->plan->name,
        'price'  => $subscription->planPrice->price,
        'period' => $subscription->planPrice->period ?? 'month',
        'status' => $subscription->status
    ];
}

    /* ============================================================
     | LAST 10 DOCUMENTS
     ============================================================ */
     private function latestDocuments()
     {
        return Document::with('categories', 'functions')
        ->orderBy('id', 'desc')
        ->limit(10)
        ->get();
    }

    /* ============================================================
     | LAST 10 OCR SCANS (only own)
     ============================================================ */
     private function latestOcr(User $user)
     {
        return OcrScan::where('user_id', $user->id)
        ->orderBy('id', 'desc')
        ->limit(10)
        ->get();
    }

    /* ============================================================
     | LAST USERS (admin only)
     ============================================================ */
     private function latestUsers()
     {
        return User::orderBy('id', 'desc')->with('functions')
        ->limit(10)
        ->get();
    }

    /* ============================================================
     | GRAPH: documents per day (last 30 days)
     ============================================================ */
     private function documentsGraph()
     {
        $days = [];

        for ($i = 29; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');

            $days[] = [
                'date' => $date,
                'count' => Document::whereDate('created_at', $date)->count()
            ];
        }

        return $days;
    }

    /* ============================================================
     | CATEGORIES USAGE
     ============================================================ */
     private function categoriesUsage()
     {
        return Category::query()
        ->withCount('documents')
        ->orderBy('documents_count', 'desc')
        ->get();
    }
}
