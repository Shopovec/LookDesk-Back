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
use Illuminate\Support\Facades\Cache;


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
    /* ============================================================
     | DASHBOARD MAIN - ОПТИМИЗИРОВАННЫЙ
     ============================================================ */
    public function index(Request $request)
    {
        $user = auth()->user();

        if (!$user || $user->hasRole('user') || $user->hasRole('editor') || $user->hasRole('accountant')) {
            abort(403, "Forbidden");
        }

        $lang = $request->get('lang', 'en');

        // Ключ кэша зависит от пользователя и языка
        $cacheKey = "dashboard.summary.{$user->id}.{$lang}";

        $data = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($user, $lang) {

            $from30 = now()->subDays(30);

            return [
                'active_plans'               => Subscription::whereIn('status', ['active', 'trialing'])->count(),
                'clients_total'              => User::where('role_id', 1)->count(),
                'deleted_users'              => User::onlyTrashed()->count(),
                'active_users'               => User::count(),                    // можно заменить на cached значение, если нужно
                'csat_last_30_days'          => $this->csatOverall(),
                'csat_last'                  => $this->csatOverallAll(),
                'deleted_users_last_30_days' => $this->trashedUsersLast30Days(),
                'total_revenue_last_30_days' => $this->aiEconomics()['total_revenue'] ?? 0,
                'total_revenue'              => $this->aiEconomicsAll()['total_revenue'] ?? 0,
                'documents_total'            => Document::count(),
                'documents_total_last_30_days'=> Document::where('created_at', '>=', $from30)->count(),
                'active_users_last_30_days'  => $this->activeUsersLast30Days(),
                'searches_today'             => $this->searchesToday(),
                'most_viewed_document'       => $this->mostViewedDocument($lang),
                'categories_total'           => Category::count(),
                'translations_total'         => DocumentTranslation::count(),
                'ocr_total'                  => OcrScan::count(),
                'my_ocr_total'               => OcrScan::where('user_id', $user->id)->count(),
                'latest_documents'           => $this->latestDocuments($lang),
                'latest_ocr'                 => $this->latestOcr($user),
                'documents_per_day'          => $this->documentsGraph(),
                'categories_usage'           => $this->categoriesUsage(),
                'ai_sessions_today'          => ChatSession::whereDate('created_at', today())->count(),
                'top_ai_queries'             => $this->topAiQueries(),
            ];
        });

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
      /* ============================================================
     | TOP SEARCH DOCUMENTS (оставил почти как было, но с eager loading)
     ============================================================ */
    public function top_search_documents(Request $request)
    {
        $cacheKey = 'dashboard.top_search_documents';

        return Cache::remember($cacheKey, now()->addMinutes(5), function () {
            $stats = DB::select("
                SELECT d.id as doc_id, COUNT(*) as total
                FROM (
                    SELECT JSON_EXTRACT(meta, '$.picked_ids[0]') as doc_id
                    FROM chat_messages WHERE role = 'assistant'
                    UNION ALL
                    SELECT JSON_EXTRACT(meta, '$.picked_ids[1]') as doc_id
                    FROM chat_messages WHERE role = 'assistant'
                ) t
                JOIN documents d ON d.id = t.doc_id
                WHERE t.doc_id IS NOT NULL
                GROUP BY d.id
                ORDER BY total DESC
                LIMIT 3;
            ");

            $stats = collect($stats);

            if ($stats->isEmpty()) {
                return [];
            }

            $documents = Document::with([
                'translations' => fn($q) => $q->select('id', 'document_id', 'lang', 'title'),
                'categories:id',
                'functions:id'
            ])
            ->whereIn('id', $stats->pluck('doc_id'))
            ->get();

            $documents->transform(function ($doc) use ($stats) {
                $stat = $stats->firstWhere('doc_id', $doc->id);
                $doc->total = $stat->total ?? 0;
                return $doc;
            });

            return $documents;
        });
    }


    /* ============================================================
     | PRIVATE HELPERS (оптимизированные)
     ============================================================ */

    private function topAiQueries()
    {
        return ChatMessage::selectRaw('content, COUNT(*) as total')
            ->where('role', 'user')
            ->groupBy('content')
            ->orderByDesc('total')
            ->limit(5)
            ->get();
    }

    private function activeUsersLast30Days(): int
    {
        return ChatSession::where('created_at', '>=', now()->subDays(30))
            ->distinct('user_id')
            ->count('user_id');
    }

    private function trashedUsersLast30Days(): int
    {
        return User::onlyTrashed()
            ->where('created_at', '>=', now()->subDays(30))
            ->count();
    }

   private function searchesToday(): int
    {
        return ChatMessage::where('role', 'user')
            ->whereDate('created_at', today())
            ->count();
    }

    private function csatOverall(): float
    {
        $fromDate = now()->subDays(30);
        $totalSessions = ChatSession::where('created_at', '>=', $fromDate)->count();

        if ($totalSessions === 0) return 0;

        $positiveSessions = ChatSession::where('created_at', '>=', $fromDate)
            ->whereHas('messages.feedback', fn($q) => $q->where('is_useful', true))
            ->distinct()
            ->count();

        return round(($positiveSessions / $totalSessions) * 100, 1);
    }

    private function csatOverallAll(): float
    {
        $totalSessions = ChatSession::count();
        if ($totalSessions === 0) return 0;

        $positiveSessions = ChatSession::whereHas('messages.feedback', fn($q) => $q->where('is_useful', true))
            ->distinct()
            ->count();

        return round(($positiveSessions / $totalSessions) * 100, 1);
    }

    private function mostViewedDocument($lang = 'en')
    {
        $document = Document::query()
            ->select('id')
            ->with([
                'translations' => fn($q) => $q->where('lang', $lang)
                    ->select('id', 'document_id', 'lang', 'title', 'summary'),
            ])
            ->withCount([
                'views as views_last_30_days' => fn($q) => $q->where('created_at', '>=', now()->subDays(30))
            ])
            ->orderByDesc('views_last_30_days')
            ->first();

        if (!$document) return null;

        $translation = $document->translations->first();

        return [
            'id'                  => $document->id,
            'title'               => $translation?->title,
            'categories'          => $document->categories?->pluck('id') ?? [],
            'functions'           => $document->functions?->pluck('id') ?? [],
            'views_last_30_days'  => (int) $document->views_last_30_days,
            'ai_searches_last_30_days' => 0,
        ];
    }

    private function latestDocuments($lang = 'en')
    {
        return Document::query()
            ->select('id', 'created_at')
            ->with([
                'translations' => fn($q) => $q->where('lang', $lang)
                    ->select('id', 'document_id', 'lang', 'title', 'file', 'content', 'summary'),
                'categories:id',
                'functions:id',
            ])
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(function ($doc) {
                $translation = $doc->translations->first();
                $doc->translated = $translation ? [
                    'id'      => $translation->id,
                    'lang'    => $translation->lang,
                    'title'   => $translation->title,
                    'content' => $translation->content,
                    'summary' => $translation->summary,
                    'file'    => $translation->file,
                ] : null;
                unset($doc->translations);
                return $doc;
            });
    }

    private function latestOcr(User $user)
    {
        return OcrScan::where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }

    private function documentsGraph()
    {
        $from = now()->subDays(29)->startOfDay();
        $to   = now()->endOfDay();

        $rows = Document::query()
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy(DB::raw('DATE(created_at)'))
            ->pluck('count', 'date');

        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $days[] = [
                'date'  => $date,
                'count' => (int) ($rows[$date] ?? 0),
            ];
        }

        return $days;
    }

    private function categoriesUsage()
    {
        return Category::withCount('documents')
            ->orderByDesc('documents_count')
            ->get();
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

}
