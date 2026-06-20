<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ApiUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $monthStart = Carbon::now()->startOfMonth();

        $tokens = $user->tokens()
            ->latest()
            ->get(['id', 'name', 'last_used_at', 'monthly_limit', 'rate_limit_per_minute', 'created_at'])
            ->map(fn ($t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'last_used_at' => $t->last_used_at?->diffForHumans(),
                'monthly_limit' => $t->monthly_limit,
                'rate_limit_per_minute' => $t->rate_limit_per_minute,
                'used_this_month' => ApiUsage::where('token_id', $t->id)->where('created_at', '>=', $monthStart)->count(),
                'created_at' => $t->created_at?->toDateString(),
            ]);

        return Inertia::render('dashboard', [
            'tokens' => $tokens,
            'newToken' => $request->session()->get('newToken'),
            'usage' => [
                'total_this_month' => ApiUsage::where('created_at', '>=', $monthStart)->count(),
                'by_endpoint' => ApiUsage::where('created_at', '>=', $monthStart)
                    ->selectRaw('endpoint, count(*) as count')
                    ->groupBy('endpoint')->orderByDesc('count')->get(),
                'daily' => ApiUsage::where('created_at', '>=', Carbon::now()->subDays(13)->startOfDay())
                    ->selectRaw('date(created_at) as date, count(*) as count')
                    ->groupBy('date')->orderBy('date')->get(),
                'avg_duration_ms' => (int) round((float) ApiUsage::where('created_at', '>=', $monthStart)->avg('duration_ms')),
            ],
        ]);
    }
}
