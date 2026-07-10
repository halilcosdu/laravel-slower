<?php

namespace HalilCosdu\Slower\Http\Controllers;

use HalilCosdu\Slower\Services\RecommendationService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class DashboardController
{
    private const ANALYZE_ATTEMPTS_PER_MINUTE = 5;

    public function __construct(protected RecommendationService $recommendationService) {}

    public function index(Request $request): View
    {
        $model = config('slower.resources.model');

        $records = $model::query()
            ->when(trim((string) $request->query('search')) !== '', fn (Builder $query) => $this->applySearch($query, (string) $request->query('search')))
            ->when($request->query('status') === 'pending', fn (Builder $query) => $query->where('is_analyzed', false))
            ->when($request->query('status') === 'analyzed', fn (Builder $query) => $query->where('is_analyzed', true))
            ->when($request->filled('connection'), fn (Builder $query) => $query->where('connection_name', $request->query('connection')))
            ->tap(fn (Builder $query) => $this->applySort($query, $request))
            ->paginate(max(1, (int) config('slower.dashboard.per_page', 25)))
            ->withQueryString();

        $stats = [
            'total' => $model::query()->count(),
            'pending' => $model::query()->where('is_analyzed', false)->count(),
            'avg_time' => (float) $model::query()->avg('time'),
            'max_time' => (float) $model::query()->max('time'),
        ];

        $connections = $model::query()
            ->whereNotNull('connection_name')
            ->distinct()
            ->orderBy('connection_name')
            ->pluck('connection_name');

        return view('slower::index', [
            'records' => $records,
            'stats' => $stats,
            'connections' => $connections,
        ]);
    }

    public function show(int $log): View
    {
        return view('slower::show', ['record' => $this->findRecord($log)]);
    }

    public function analyze(Request $request, int $log): RedirectResponse
    {
        $record = $this->findRecord($log);

        if (! config('slower.ai_recommendation')) {
            return back()->with('slower.error', 'AI recommendations are disabled — enable slower.ai_recommendation first.');
        }

        if ($limited = $this->rateLimitAnalysis($request)) {
            return $limited;
        }

        $lock = Cache::lock('slower:analyze:'.$record->getKey(), 60);

        if (! $lock->get()) {
            return back()->with('slower.error', 'This query is already being analyzed.');
        }

        try {
            $recommendation = $this->recommendationService->getRecommendation($record);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('slower.error', 'The AI analysis failed. The query stays pending — try again later.');
        } finally {
            $lock->release();
        }

        if (empty($recommendation)) {
            return back()->with('slower.error', 'The AI service returned no recommendation. The query stays pending and will be retried.');
        }

        return back()->with('slower.status', 'Analysis complete — the recommendation has been saved.');
    }

    public function analyzePending(Request $request): RedirectResponse
    {
        if (! config('slower.ai_recommendation')) {
            return back()->with('slower.error', 'AI recommendations are disabled — enable slower.ai_recommendation first.');
        }

        if ($limited = $this->rateLimitAnalysis($request)) {
            return $limited;
        }

        $model = config('slower.resources.model');

        if (! $model::query()->where('is_analyzed', false)->exists()) {
            return redirect()->route('slower.index')->with('slower.status', 'Nothing to analyze — there are no pending queries.');
        }

        $limit = max(1, (int) config('slower.dashboard.analyze_pending_limit', 10));
        $analyzed = 0;
        $failed = 0;

        foreach ($model::query()->where('is_analyzed', false)->orderBy('id')->limit($limit)->get() as $record) {
            try {
                $recommendation = $this->recommendationService->getRecommendation($record);
            } catch (\Throwable $e) {
                report($e);
                $failed++;

                continue;
            }

            empty($recommendation) ? $failed++ : $analyzed++;
        }

        $remaining = $model::query()->where('is_analyzed', false)->count();

        $message = sprintf('Analyzed %d of the pending queries — %d pending left.', $analyzed, $remaining);
        if ($failed > 0) {
            $message .= sprintf(' %d produced no recommendation and will be retried.', $failed);
        }
        if ($remaining > 0) {
            $message .= ' Use php artisan slower:analyze for bulk analysis.';
        }

        return redirect()->route('slower.index')->with('slower.status', $message);
    }

    public function destroy(int $log): RedirectResponse
    {
        $record = $this->findRecord($log);
        $record->delete();

        return redirect()->route('slower.index')->with('slower.status', sprintf('Query #%d deleted.', $record->getKey()));
    }

    public function clean(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'days' => ['required', 'integer', 'min:0', 'max:3650'],
        ]);

        $days = (int) $validated['days'];
        $model = config('slower.resources.model');
        $cutoff = now()->subDays($days);

        $deleted = $model::query()->where('created_at', '<', $cutoff)->count();

        $model::query()
            ->where('created_at', '<', $cutoff)
            ->chunkById(1000, fn ($logs) => $logs->each->delete());

        $message = $days === 0
            ? sprintf('Deleted all %d captured queries.', $deleted)
            : sprintf('Deleted %d captured queries older than %d %s.', $deleted, $days, $days === 1 ? 'day' : 'days');

        return redirect()->route('slower.index')->with('slower.status', $message);
    }

    private function findRecord(int $id): Model
    {
        $model = config('slower.resources.model');

        return $model::query()->findOrFail($id);
    }

    private function rateLimitAnalysis(Request $request): ?RedirectResponse
    {
        $key = 'slower-analyze:'.($request->user()?->getAuthIdentifier() ?? $request->ip());

        if (RateLimiter::tooManyAttempts($key, self::ANALYZE_ATTEMPTS_PER_MINUTE)) {
            return back()->with('slower.error', 'Too many analysis requests — try again in a minute. Use php artisan slower:analyze for bulk analysis.');
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    private function applySearch(Builder $query, string $term): Builder
    {
        // "|" is used as the LIKE escape character because it needs no escaping
        // of its own inside SQL string literals on sqlite/mysql/pgsql.
        $escaped = str_replace(['|', '%', '_'], ['||', '|%', '|_'], trim($term));

        $query->whereRaw("raw_sql like ? escape '|'", ['%'.$escaped.'%']);

        return $query;
    }

    private function applySort(Builder $query, Request $request): void
    {
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        match ($request->query('sort')) {
            'time' => $query->orderBy('time', $direction)->orderBy('id', 'desc'),
            'date' => $query->orderBy('id', $direction),
            default => $query->latest('id'),
        };
    }
}
