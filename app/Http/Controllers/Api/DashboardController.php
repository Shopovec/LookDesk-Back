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
use Illuminate\Support\Facades\DB;


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

        // Разрешаем только: super admin, admin, owner
        if (!in_array((int)$user->role_id, [1, 2, 7], true)) {
            return $this->error('Forbidden', 403);
        // или abort(403);
        }

        $from = now()->subDays(30);

        $data = [
            'active_plans' => Subscription::whereIn('status',['active','trialing'])->count(),
            'clients_total' =>  User::where('role_id', 1)->count(),
            'deleted_users' =>  User::onlyTrashed()->count(),
            'active_users' =>  User::count(),
            'csat_last_30_days' => $this->csatOverall(),
            'csat_last' => $this->csatOverallAll(),
            'deleted_users_last_30_days' =>  $this->trashedUsersLast30Days(),
            'total_revenue_last_30_days'  => $this->aiEconomics(),
            'total_revenue'  => $this->aiEconomicsAll(),

        // ✅ total documents
            'documents_total' => Document::count(),
            'documents_total_last_30_days' => Document::where('created_at', '>=', $from)->count(),

        // ✅ активные юзеры за 30 дней (кто имел хотя бы 1 chat session)
            'active_users_last_30_days' => $this->activeUsersLast30Days(),

        // ✅ searches today (кол-во user-сообщений в чатах за сегодня)
            'searches_today' => $this->searchesToday(),

        // остальное как было
            'most_viewed_document' => $this->mostViewedDocument(),
            'categories_total'      => Category::count(),
            'translations_total'    => DocumentTranslation::count(),
            'ocr_total'             => OcrScan::count(),
            'my_ocr_total'          => OcrScan::where('user_id', $user->id)->count(),
            'latest_documents'      => $this->latestDocuments(),
            'latest_ocr'            => $this->latestOcr($user),
            'documents_per_day'     => $this->documentsGraph(),
            'categories_usage'      => $this->categoriesUsage(),

        // 📈 чаты сегодня (сессии)
            'ai_sessions_today' => ChatSession::whereDate('created_at', today())->count(),

        // ⭐ топ запросов
            'top_ai_queries' => ChatMessage::selectRaw('content, COUNT(*) as total')
            ->where('role', 'user')
            ->groupBy('content')
            ->orderByDesc('total')
            ->limit(5)
            ->get(),
        ];

        if ($user->hasRole('user')) {
            $data['billing'] = $this->aiEconomics($user);
        }

        if (in_array($user->role_id, [1,2])) {
            $data['users_total'] = User::count();
            $data['latest_users'] = $this->latestUsers();
        }

        return $this->success($data);
    }


      #[OA\Get(
    path: "/api/top_search_documents",
    summary: "Dashboard summary",
    description: "Returns overall system statistics for dashboard widgets",
    tags: ["Dashboard"],
    security: [["sanctum" => []]],
    responses: [
        new OA\Response(response: 200, description: "Dashboard data")
    ]
)]
      public function top_search_documents(Request $request)
      {
        $stats = DB::select("
          SELECT d.id as doc_id, COUNT(*) as total
          FROM (
            SELECT JSON_EXTRACT(meta, '$.picked_ids[0]') as doc_id
            FROM chat_messages
            WHERE role = 'assistant'

            UNION ALL

            SELECT JSON_EXTRACT(meta, '$.picked_ids[1]') as doc_id
            FROM chat_messages
            WHERE role = 'assistant'
        ) t
          JOIN documents d ON d.id = t.doc_id
          WHERE t.doc_id IS NOT NULL
          GROUP BY d.id
          ORDER BY total DESC
          LIMIT 3;
          ");

        $stats = collect($stats);


        $documents = Document::with(['translations','categories','functions'])->whereIn('id', $stats->pluck('doc_id'))->get();
        $documents = $documents->transform(function ($doc) use ($stats) {

            $stat = $stats->firstWhere('doc_id', $doc->id);

            $doc->total = $stat->total ?? 0;

            return $doc;
        });
        return $this->success($documents);
    }


    private function activeUsersLast30Days(): int
    {
        $from = now()->subDays(30);

        return ChatSession::where('created_at', '>=', $from)
        ->distinct('user_id')
        ->count('user_id');
    }

    private function trashedUsersLast30Days(): int
    {
        $from = now()->subDays(30);

        return User::onlyTrashed()->where('created_at', '>=', $from)->count();
    }

    private function searchesToday(): int
    {
    // считаем "поиски" как сообщения пользователя в чатах за сегодня
        return ChatMessage::where('role', 'user')
        ->whereDate('created_at', today())
        ->count();
    }

    private function csatOverall(): float
    {
        $fromDate = now()->subDays(30);

    // 1️⃣ Всего сессий за 30 дней
        $totalSessions = \App\Models\ChatSession::where(
            'created_at',
            '>=',
            $fromDate
        )->count();

        if ($totalSessions === 0) {
            return 0;
        }

    // 2️⃣ Сессии с положительным feedback
        $positiveSessions = \App\Models\ChatSession::where(
            'created_at',
            '>=',
            $fromDate
        )
        ->whereHas('messages.feedback', function ($q) {
            $q->where('is_useful', true);
        })
        ->distinct()
        ->count();

        return round(($positiveSessions / $totalSessions) * 100, 1);
    }

    private function csatOverallAll(): float
    {

    // 1️⃣ Всего сессий за 30 дней
        $totalSessions = \App\Models\ChatSession::count();

        if ($totalSessions === 0) {
            return 0;
        }

    // 2️⃣ Сессии с положительным feedback
        $positiveSessions = \App\Models\ChatSession::whereHas('messages.feedback', function ($q) {
            $q->where('is_useful', true);
        })
        ->distinct()
        ->count();

        return round(($positiveSessions / $totalSessions) * 100, 1);
    }

    private function mostViewedDocument()
    {
        $document = Document::with(['categories','functions'])
        ->withCount([
            'views as views_last_30_days' => function ($q) {
                $q->where('created_at', '>=', now()->subDays(30));
            }
        ])
        ->orderByDesc('views_last_30_days')
        ->first();

        if (!$document) {
            return null;
        }

        $aiSearches = ChatMessage::where('role','user')
        ->where('created_at', '>=', now()->subDays(30))
        ->where('content','like','%'.$document->title.'%')
        ->count();

        return [
            'id' => $document->id,
            'title' => $document->getTranslation2('title','en'),
            'description' => $document->getTranslation('description','en'),
            'categories' => $document->categories->pluck('name'),
            'functions' => $document->functions->pluck('name'),
            'views_last_30_days' => $document->views_last_30_days,
            'ai_searches_last_30_days' => $aiSearches,
        ];
    }   

    private function aiEconomics($user = null): array
    {
        $daysAgo = now()->subDays(30);

    // Revenue query
        $revenueQuery = Subscription::query()
        ->whereIn('subscriptions.status', ['active', 'canceled', 'trialing'])
        ->where('subscriptions.created_at', '>=', $daysAgo)
        ->join('plan_prices', 'subscriptions.plan_price_id', '=', 'plan_prices.id');

        if ($user) {
            $revenueQuery->where('subscriptions.user_id', $user->id);
        }

        $totalRevenue = (float) $revenueQuery->sum('plan_prices.price');

    // AI answers query
        $answersQuery = ChatMessage::query()
        ->where('role', 'assistant')
        ->where('created_at', '>=', $daysAgo);

        if ($user) {
            $answersQuery->whereHas('session', fn ($q) => $q->where('user_id', $user->id));
            $subscription = $this->subscriptionCard($user);
        }

        $aiAnswers = (int) $answersQuery->count();

        $aiCost = round($aiAnswers * (float) config('ai.cost_per_answer', 0.002), 2);

        $margin = $totalRevenue > 0
        ? round((($totalRevenue - $aiCost) / $totalRevenue) * 100, 1)
        : 0;

        if ($user) {
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

        return [
            'total_revenue' => round($totalRevenue, 2),
            'total_ai_cost' => $aiCost,
            'net_margin'    => $margin,
        ];
    }

     private function aiEconomicsAll($user = null): array
    {

    // Revenue query
        $revenueQuery = Subscription::query()
        ->whereIn('subscriptions.status', ['active', 'canceled', 'trialing'])
        ->join('plan_prices', 'subscriptions.plan_price_id', '=', 'plan_prices.id');

        if ($user) {
            $revenueQuery->where('subscriptions.user_id', $user->id);
        }

        $totalRevenue = (float) $revenueQuery->sum('plan_prices.price');

    // AI answers query
        $answersQuery = ChatMessage::query()
        ->where('role', 'assistant');

        if ($user) {
            $answersQuery->whereHas('session', fn ($q) => $q->where('user_id', $user->id));
            $subscription = $this->subscriptionCard($user);
        }

        $aiAnswers = (int) $answersQuery->count();

        $aiCost = round($aiAnswers * (float) config('ai.cost_per_answer', 0.002), 2);

        $margin = $totalRevenue > 0
        ? round((($totalRevenue - $aiCost) / $totalRevenue) * 100, 1)
        : 0;

        if ($user) {
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

        return [
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
