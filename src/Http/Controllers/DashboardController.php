<?php

namespace HalilCosdu\Slower\Http\Controllers;

use HalilCosdu\Slower\Services\RecommendationService;
use HalilCosdu\Slower\Services\SlowLogPruner;
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

    // The AI service is resolved lazily inside the analyze actions only, so a
    // slow-query-only install (no AI provider configured) can still browse and
    // prune the dashboard without the OpenAI driver being resolved.

    public function index(Request $request): View
    {
        $model = config('slower.resources.model');

        // Every filter is sanitized to a scalar here (array-valued query params
        // are coerced away) so both the query and the view stay crash-safe.
        $filters = [
            'search' => $this->stringParam($request, 'search'),
            'status' => in_array($request->query('status'), ['pending', 'analyzed'], true) ? $request->query('status') : '',
            'connection' => $this->stringParam($request, 'connection'),
            'sort' => in_array($request->query('sort'), ['time', 'date'], true) ? $request->query('sort') : '',
            'direction' => $request->query('direction') === 'asc' ? 'asc' : 'desc',
        ];

        $records = $model::query()
            ->when($filters['search'] !== '', fn (Builder $query) => $this->applySearch($query, $filters['search']))
            ->when($filters['status'] === 'pending', fn (Builder $query) => $query->where('is_analyzed', false))
            ->when($filters['status'] === 'analyzed', fn (Builder $query) => $query->where('is_analyzed', true))
            ->when($filters['connection'] !== '', fn (Builder $query) => $query->where('connection_name', $filters['connection']))
            ->tap(fn (Builder $query) => $this->applySort($query, $filters))
            ->paginate(max(1, (int) config('slower.dashboard.per_page', 25)))
            ->withQueryString();

        // count/avg/max come from one aggregate query; the pending count stays
        // separate because a conditional SUM would not be driver-portable.
        $aggregate = $model::query()
            ->selectRaw('count(*) as total, avg(time) as avg_time, max(time) as max_time')
            ->first();

        $stats = [
            'total' => (int) $aggregate->total,
            'pending' => $model::query()->where('is_analyzed', false)->count(),
            'avg_time' => (float) $aggregate->avg_time,
            'max_time' => (float) $aggregate->max_time,
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
            'filters' => $filters,
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

        // Lock acquisition and driver resolution are inside the try/catch so a
        // cache store without lock support or a missing AI provider surfaces as
        // a flash message instead of a 500.
        try {
            $lock = Cache::lock('slower:analyze:'.$record->getKey(), 60);

            if (! $lock->get()) {
                return back()->with('slower.error', 'This query is already being analyzed.');
            }

            try {
                $recommendation = app(RecommendationService::class)->getRecommendation($record);
            } finally {
                $lock->release();
            }
        } catch (\Throwable $e) {
            report($e);

            return back()->with('slower.error', 'The AI analysis failed — the query stays pending. Check the application logs for the cause, then try again.');
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

        try {
            $service = app(RecommendationService::class);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('slower.error', 'The AI service is not available. Check your configuration and try again.');
        }

        $limit = max(1, (int) config('slower.dashboard.analyze_pending_limit', 10));
        $analyzed = 0;
        $failed = 0;

        foreach ($model::query()->where('is_analyzed', false)->orderBy('id')->limit($limit)->get() as $record) {
            try {
                $recommendation = $service->getRecommendation($record);
            } catch (\Throwable $e) {
                report($e);
                $failed++;

                continue;
            }

            empty($recommendation) ? $failed++ : $analyzed++;
        }

        // Nothing succeeded — likely a persistent misconfiguration (AI provider
        // credentials, or a query's database connection) rather than a transient
        // hiccup, so surface it as an error and point at the logs for the cause.
        if ($analyzed === 0 && $failed > 0) {
            return redirect()->route('slower.index')->with('slower.error',
                sprintf('Could not analyze any of the %d pending queries. Check the application logs for the cause.', $failed));
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

    public function clean(Request $request, SlowLogPruner $pruner): RedirectResponse
    {
        $validated = $request->validate([
            'days' => ['required', 'integer', 'min:0', 'max:3650'],
        ]);

        $days = (int) $validated['days'];
        $deleted = $pruner->olderThan($days);

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

    private function stringParam(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? trim($value) : '';
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

    /**
     * @param  array{sort: string, direction: string}  $filters
     */
    private function applySort(Builder $query, array $filters): void
    {
        match ($filters['sort']) {
            'time' => $query->orderBy('time', $filters['direction'])->orderBy('id', 'desc'),
            'date' => $query->orderBy('id', $filters['direction']),
            default => $query->latest('id'),
        };
    }
}
