@extends('slower::layout')

@section('title', 'Slow queries')

@php
    $currentSort = $filters['sort'];
    $currentDirection = $filters['direction'];
    $baseQuery = array_filter([
        'search' => $filters['search'],
        'status' => $filters['status'],
        'connection' => $filters['connection'],
    ], fn ($value) => $value !== '');
    $sortLink = function (string $column) use ($currentSort, $currentDirection, $baseQuery) {
        $direction = $currentSort === $column && $currentDirection === 'desc' ? 'asc' : 'desc';

        return route('slower.index', array_merge($baseQuery, [
            'sort' => $column,
            'direction' => $direction,
        ]));
    };
    $sortArrow = fn (string $column) => $currentSort === $column
        ? ($currentDirection === 'asc' ? '▲' : '▼')
        : '';
    $filtered = $baseQuery !== [];
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>Slow queries</h1>
            <p class="page-sub">Everything slower than <code>{{ number_format((float) config('slower.threshold')) }} ms</code> lands here.</p>
        </div>
        @if ($stats['pending'] > 0)
            <form method="POST" action="{{ route('slower.analyze-pending') }}"
                  data-confirm="Analyze up to {{ (int) config('slower.dashboard.analyze_pending_limit', 10) }} pending queries with AI now? This sends query and schema context to your AI provider and may incur charges.">
                @csrf
                <button type="submit" class="btn btn-primary">Analyze pending ({{ number_format($stats['pending']) }})</button>
            </form>
        @endif
    </div>

    <section class="stats-strip" aria-label="Overview">
        <div class="stat">
            <span class="stat-label">Captured</span>
            <span class="stat-value">{{ number_format($stats['total']) }}</span>
        </div>
        <div class="stat">
            <span class="stat-label">Pending analysis</span>
            <span class="stat-value {{ $stats['pending'] > 0 ? 'is-pending' : '' }}">{{ number_format($stats['pending']) }}</span>
        </div>
        <div class="stat">
            <span class="stat-label">Avg duration</span>
            <span class="stat-value">{{ number_format($stats['avg_time']) }} <span class="stat-unit">ms</span></span>
        </div>
        <div class="stat">
            <span class="stat-label">Max duration</span>
            <span class="stat-value">{{ number_format($stats['max_time']) }} <span class="stat-unit">ms</span></span>
        </div>
    </section>

    <form class="filters" method="GET" action="{{ route('slower.index') }}" data-autosubmit>
        <input type="search" name="search" value="{{ $filters['search'] }}" placeholder="Search captured SQL…" aria-label="Search captured SQL">
        <select name="status" aria-label="Filter by status">
            <option value="">All statuses</option>
            <option value="pending" @selected($filters['status'] === 'pending')>Pending</option>
            <option value="analyzed" @selected($filters['status'] === 'analyzed')>Analyzed</option>
        </select>
        <select name="connection" aria-label="Filter by connection">
            <option value="">All connections</option>
            @foreach ($connections as $connection)
                <option value="{{ $connection }}" @selected($filters['connection'] === $connection)>{{ $connection }}</option>
            @endforeach
        </select>
        @if ($currentSort)
            <input type="hidden" name="sort" value="{{ $currentSort }}">
            <input type="hidden" name="direction" value="{{ $currentDirection }}">
        @endif
        <button type="submit" class="btn">Filter</button>
        @if ($filtered)
            <a class="filters-reset" href="{{ route('slower.index') }}">Reset</a>
        @endif
    </form>

    <div class="queries-panel">
        @if ($records->isEmpty())
            <div class="empty-state">
                <div class="empty-mark">~ 0 rows</div>
                <h2>{{ $filtered ? 'Nothing matches these filters' : 'No slow queries captured yet' }}</h2>
                @if ($filtered)
                    <p>Try widening the search or <a href="{{ route('slower.index') }}">reset the filters</a>.</p>
                @else
                    <p>Queries slower than the configured threshold are captured automatically. Lower <code>slower.threshold</code> to catch more of them.</p>
                @endif
            </div>
        @else
            <table class="queries">
                <thead>
                    <tr>
                        <th scope="col">Query</th>
                        <th scope="col" class="cell-time">
                            <a href="{{ $sortLink('time') }}">Duration <span class="sort-arrow">{{ $sortArrow('time') }}</span></a>
                        </th>
                        <th scope="col" class="th-connection">Connection</th>
                        <th scope="col" class="cell-status">Status</th>
                        <th scope="col" class="th-when">
                            <a href="{{ $sortLink('date') }}">Captured <span class="sort-arrow">{{ $sortArrow('date') }}</span></a>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($records as $record)
                        <tr>
                            <td>
                                <a class="query-sql" href="{{ route('slower.show', $record->id) }}">{{ \Illuminate\Support\Str::limit($record->raw_sql, 90) }}</a>
                                <div class="query-meta">#{{ $record->id }}</div>
                            </td>
                            <td class="cell-time">
                                <span class="time-value">{{ number_format((float) $record->time) }} <span class="stat-unit">ms</span></span>
                                <div class="lat-track" aria-hidden="true">
                                    <span class="lat-bar" style="width: {{ $stats['max_time'] > 0 ? max(6, (int) round((float) $record->time / $stats['max_time'] * 100)) : 0 }}%"></span>
                                </div>
                            </td>
                            <td class="cell-connection">{{ $record->connection_name }}</td>
                            <td class="cell-status">
                                @if ($record->is_analyzed)
                                    <span class="badge badge-ok">Analyzed</span>
                                @else
                                    <span class="badge badge-pending">Pending</span>
                                @endif
                            </td>
                            <td class="cell-when" title="{{ $record->created_at }}">{{ $record->created_at?->diffForHumans(short: true) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($records->hasPages())
        {{ $records->links('slower::partials.pagination') }}
    @endif

    @if ($stats['total'] > 0)
        <form class="filters" style="justify-content: flex-end; margin-top: 22px;" method="POST" action="{{ route('slower.clean') }}"
              data-confirm="Delete captured queries older than the given number of days? Use 0 to delete everything. This cannot be undone.">
            @csrf
            @method('DELETE')
            <label class="filters-reset" for="clean-days">Clean up entries older than</label>
            <input type="number" id="clean-days" name="days" value="15" min="0" max="3650" required
                   style="width: 90px; font: inherit; font-size: 0.88rem; color: var(--text); background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 7px 11px;">
            <span class="filters-reset">days</span>
            <button type="submit" class="btn btn-danger">Clean up</button>
        </form>
    @endif
@endsection
