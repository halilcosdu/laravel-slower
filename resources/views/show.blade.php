@extends('slower::layout')

@section('title', 'Query #'.$record->id)

@section('content')
    <a class="back-link" href="{{ route('slower.index') }}">‹ All queries</a>

    <div class="detail-head">
        <div class="detail-title">
            <h1>#{{ $record->id }}</h1>
            @if ($record->is_analyzed)
                <span class="badge badge-ok">Analyzed</span>
            @else
                <span class="badge badge-pending">Pending</span>
            @endif
        </div>
        <div class="detail-actions">
            <button type="button" class="btn" data-copy="#raw-sql">Copy SQL</button>
            @if (config('slower.ai_recommendation'))
                <form method="POST" action="{{ route('slower.analyze', $record->id) }}"
                      data-confirm="{{ $record->is_analyzed ? 'Re-analyze this query with AI? The current recommendation will be replaced.' : 'Analyze this query with AI?' }} This sends the query and schema context to your AI provider and may incur charges.">
                    @csrf
                    <button type="submit" class="btn btn-primary">{{ $record->is_analyzed ? 'Re-analyze with AI' : 'Analyze with AI' }}</button>
                </form>
            @endif
            <form method="POST" action="{{ route('slower.destroy', $record->id) }}"
                  data-confirm="Delete this captured query? This cannot be undone.">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
        </div>
    </div>

    <div class="meta-grid">
        <div class="meta-cell">
            <span class="stat-label">Duration</span>
            <span class="meta-value is-time">{{ number_format((float) $record->time) }} ms</span>
        </div>
        <div class="meta-cell">
            <span class="stat-label">Connection</span>
            <span class="meta-value">{{ $record->connection_name ?? '—' }}</span>
        </div>
        <div class="meta-cell">
            <span class="stat-label">Driver</span>
            <span class="meta-value" title="{{ $record->connection }}">{{ class_basename((string) $record->connection) }}</span>
        </div>
        <div class="meta-cell">
            <span class="stat-label">Captured</span>
            <span class="meta-value" title="{{ $record->created_at }}">{{ $record->created_at?->diffForHumans() }}</span>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2>Query</h2>
        </div>
        <div class="panel-body">
            <pre class="code" id="raw-sql">{{ \HalilCosdu\Slower\Support\SqlFormatter::format((string) $record->raw_sql) }}</pre>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2>Parameterized SQL</h2>
        </div>
        <div class="panel-body">
            <pre class="code">{{ $record->sql }}</pre>
        </div>
    </div>

    @if (! empty($record->bindings))
        <div class="panel">
            <div class="panel-head">
                <h2>Bindings</h2>
            </div>
            <div class="panel-body">
                <pre class="code">{{ json_encode($record->bindings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>
        </div>
    @endif

    @if ($record->is_analyzed && filled($record->recommendation))
        <div class="panel">
            <div class="panel-head">
                <h2>AI recommendation</h2>
                <span class="ai-note">{{ config('slower.recommendation_model') }}</span>
            </div>
            <div class="panel-body">
                {{-- Safe: MarkdownRenderer escapes all input before its transforms. --}}
                <div class="recommendation">{!! \HalilCosdu\Slower\Support\MarkdownRenderer::render((string) $record->recommendation) !!}</div>
            </div>
        </div>
    @else
        <div class="callout">
            <span>This query has not been analyzed yet. Run the analysis to get an AI optimization recommendation.</span>
        </div>
    @endif
@endsection
