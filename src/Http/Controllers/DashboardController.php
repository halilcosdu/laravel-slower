<?php

namespace HalilCosdu\Slower\Http\Controllers;

use HalilCosdu\Slower\Jobs\AnalyzeSlowLog;
use HalilCosdu\Slower\Services\RecommendationService;
use HalilCosdu\Slower\Services\SlowLogPruner;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
        $view = $request->query('view') === 'grouped' ? 'grouped' : 'events';

        // Every filter is sanitized to a scalar here (array-valued query params
        // are coerced away) so both the query and the view stay crash-safe.
        $filters = [
            'search' => $this->stringParam($request, 'search'),
            'status' => in_array($request->query('status'), ['pending', 'analyzed'], true) ? $request->query('status') : '',
            'connection' => $this->stringParam($request, 'connection'),
            'fingerprint' => $this->stringParam($request, 'fingerprint'),
            'sort' => in_array($request->query('sort'), ['time', 'date', 'count'], true) ? $request->query('sort') : '',
            'direction' => $request->query('direction') === 'asc' ? 'asc' : 'desc',
        ];

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

        $data = [
            'view' => $view,
            'stats' => $stats,
            'connections' => $connections,
            'filters' => $filters,
        ];

        if ($view === 'grouped') {
            return view('slower::index', $data + $this->groupedData($model, $filters));
        }

        $records = $this->filteredQuery($model, $filters)
            ->when($filters['fingerprint'] !== '', fn (Builder $query) => $query->where('fingerprint', $filters['fingerprint']))
            ->tap(fn (Builder $query) => $this->applySort($query, $filters))
            ->paginate(max(1, (int) config('slower.dashboard.per_page', 25)))
            ->withQueryString();

        return view('slower::index', $data + ['records' => $records]);
    }

    /**
     * One row per query shape (fingerprint) and connection. Aggregates are
     * computed over the *filtered* events, and each group carries its latest
     * event id so a representative SQL can be fetched in a single query —
     * portable across sqlite/mysql/pgsql (no window functions).
     *
     * @return array<string, mixed>
     */
    private function groupedData(string $model, array $filters): array
    {
        $groups = $this->filteredQuery($model, $filters)
            ->whereNotNull('fingerprint')
            ->selectRaw('fingerprint, connection_name, count(*) as occurrences, avg(time) as avg_time, max(time) as max_time, min(created_at) as first_seen_at, max(created_at) as last_seen_at, max(id) as latest_id')
            ->groupBy('fingerprint', 'connection_name')
            ->tap(function (Builder $query) use ($filters) {
                match ($filters['sort']) {
                    'time' => $query->orderBy('max_time', $filters['direction']),
                    'date' => $query->orderBy('last_seen_at', $filters['direction']),
                    'count' => $query->orderBy('occurrences', $filters['direction']),
                    default => $query->orderByDesc('occurrences'),
                };
                $query->orderBy('fingerprint');
            })
            ->paginate(max(1, (int) config('slower.dashboard.per_page', 25)))
            ->withQueryString();

        $representatives = $model::query()
            ->findMany($groups->getCollection()->pluck('latest_id'))
            ->keyBy('id');

        return [
            'groups' => $groups,
            'representatives' => $representatives,
            'unfingerprinted' => $model::query()->whereNull('fingerprint')->count(),
        ];
    }

    /** The filters shared by the events and grouped views. */
    private function filteredQuery(string $model, array $filters): Builder
    {
        return $model::query()
            ->when($filters['search'] !== '', fn (Builder $query) => $this->applySearch($query, $filters['search']))
            ->when($filters['status'] === 'pending', fn (Builder $query) => $query->where('is_analyzed', false))
            ->when($filters['status'] === 'analyzed', fn (Builder $query) => $query->where('is_analyzed', true))
            ->when($filters['connection'] !== '', fn (Builder $query) => $query->where('connection_name', $filters['connection']));
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

        // Queue mode: hand the work to a background job (unique per record,
        // so double-clicks never queue twice) and return immediately.
        if ($queue = $this->analyzeQueue()) {
            AnalyzeSlowLog::dispatch($record)->onQueue($queue);

            return back()->with('slower.status', 'Analysis queued — the recommendation will appear once the job has run.');
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

        if ($queue = $this->analyzeQueue()) {
            $queued = 0;

            foreach ($this->pendingRecords($model) as $record) {
                AnalyzeSlowLog::dispatch($record)->onQueue($queue);
                $queued++;
            }

            return redirect()->route('slower.index')->with('slower.status',
                sprintf('%d %s queued for analysis — recommendations will appear once the jobs have run.', $queued, $queued === 1 ? 'query' : 'queries'));
        }

        try {
            $service = app(RecommendationService::class);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('slower.error', 'The AI service is not available. Check your configuration and try again.');
        }

        $analyzed = 0;
        $failed = 0;

        foreach ($this->pendingRecords($model) as $record) {
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

    /** The queue name analysis jobs should go to, or null for synchronous mode. */
    private function analyzeQueue(): ?string
    {
        $queue = config('slower.analyze_queue');

        return is_string($queue) && $queue !== '' ? $queue : null;
    }

    /**
     * @return Collection<int, Model>
     */
    private function pendingRecords(string $model)
    {
        $limit = max(1, (int) config('slower.dashboard.analyze_pending_limit', 10));

        return $model::query()->where('is_analyzed', false)->orderBy('id')->limit($limit)->get();
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
