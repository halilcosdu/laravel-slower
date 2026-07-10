<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Slow queries') — Slower</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Crect width='16' height='16' rx='3' fill='%230b0e15'/%3E%3Cpath d='M3 11h6v2H3z' fill='%23f0b454'/%3E%3Cpath d='M3 3h10v2H3zM3 7h8v2H3z' fill='%23e7ebf4'/%3E%3C/svg%3E">
    <script>
        (function () {
            try {
                var theme = localStorage.getItem('slower-theme');
                if (theme === 'light' || theme === 'dark') {
                    document.documentElement.setAttribute('data-theme', theme);
                }
            } catch (e) {
                // localStorage unavailable (private mode) — fall back to system theme.
            }
        })();
    </script>
    <style>
        :root {
            --font-sans: ui-sans-serif, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            --font-mono: ui-monospace, "SF Mono", SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;

            /* Each color is declared once via light-dark(); the active side is
               chosen by `color-scheme` below (OS preference by default, or the
               manual data-theme override). */
            color-scheme: light dark;
            --bg: light-dark(#f2f4f8, #0b0e15);
            --surface: light-dark(#ffffff, #121724);
            --surface-2: light-dark(#eaedf3, #0e131e);
            --border: light-dark(#d9dee8, #242d41);
            --text: light-dark(#16203a, #e7ebf4);
            --muted: light-dark(#5d6880, #94a0b8);
            --accent: light-dark(#9a5b00, #f0b454);
            --accent-strong: light-dark(#7c4a00, #f6c979);
            --bar-from: light-dark(#e8a33d, #f0b454);
            --bar-to: light-dark(#c97f16, #b97a1a);
            --ok: light-dark(#0c7d56, #4cc38a);
            --ok-bg: light-dark(rgba(14, 138, 95, 0.1), rgba(76, 195, 138, 0.12));
            --warn-bg: light-dark(rgba(232, 163, 61, 0.14), rgba(240, 180, 84, 0.12));
            --danger: light-dark(#b13a30, #f07c72);
            --danger-bg: light-dark(rgba(194, 69, 58, 0.09), rgba(240, 124, 114, 0.12));
            --focus: light-dark(#2e5aac, #8ab4ff);
            /* Shadows are visible on light and effectively absent (transparent) on dark. */
            --shadow: 0 1px 2px light-dark(rgba(22, 32, 58, 0.05), transparent),
                0 8px 24px -16px light-dark(rgba(22, 32, 58, 0.25), transparent);
        }

        :root[data-theme="light"] { color-scheme: light; }
        :root[data-theme="dark"] { color-scheme: dark; }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: var(--font-sans);
            font-size: 15px;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
        }

        a { color: inherit; }
        :focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; border-radius: 4px; }

        .container { max-width: 1080px; margin: 0 auto; padding: 0 20px 64px; }

        /* ---- Top bar ------------------------------------------------- */
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 0 14px;
            margin-bottom: 22px;
            border-bottom: 1px solid var(--border);
        }
        .brand {
            font-family: var(--font-mono);
            font-size: 1.05rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-decoration: none;
        }
        .brand .brand-cursor { color: var(--accent); }
        .topbar-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

        /* ---- Buttons -------------------------------------------------- */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 14px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text);
            font: inherit;
            font-size: 0.86rem;
            font-weight: 550;
            text-decoration: none;
            cursor: pointer;
            transition: border-color 0.15s ease, background 0.15s ease, color 0.15s ease;
        }
        .btn:hover { border-color: var(--muted); }
        .btn-primary {
            background: var(--accent);
            border-color: var(--accent);
            color: var(--bg);
        }
        .btn-primary:hover { background: var(--accent-strong); border-color: var(--accent-strong); }
        .btn-danger { color: var(--danger); border-color: var(--danger); background: transparent; }
        .btn-danger:hover { background: var(--danger-bg); border-color: var(--danger); }
        .btn-sm { padding: 4px 10px; font-size: 0.8rem; }
        .btn.is-loading { opacity: 0.6; pointer-events: none; }
        .btn[disabled] { opacity: 0.6; cursor: default; }

        .theme-toggle { min-width: 38px; justify-content: center; padding: 7px 9px; }
        .theme-toggle .icon-sun { display: none; }
        [data-theme="dark"] .theme-toggle .icon-sun { display: inline; }
        [data-theme="dark"] .theme-toggle .icon-moon { display: none; }
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) .theme-toggle .icon-sun { display: inline; }
            :root:not([data-theme="light"]) .theme-toggle .icon-moon { display: none; }
        }

        /* ---- Flash ---------------------------------------------------- */
        .flash {
            display: flex;
            gap: 10px;
            align-items: baseline;
            padding: 11px 16px;
            border-radius: 10px;
            border: 1px solid var(--ok);
            background: var(--ok-bg);
            color: var(--text);
            font-size: 0.9rem;
            margin-bottom: 18px;
        }
        .flash-error { border-color: var(--danger); background: var(--danger-bg); }

        /* ---- Page head ------------------------------------------------ */
        .page-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 18px; flex-wrap: wrap; }
        .page-head h1 { margin: 0; font-size: 1.35rem; letter-spacing: -0.01em; }
        .page-sub { margin: 4px 0 0; color: var(--muted); font-size: 0.88rem; }
        .page-sub code { font-family: var(--font-mono); font-size: 0.82rem; }

        /* ---- Stats strip ---------------------------------------------- */
        .stats-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: var(--shadow);
            margin-bottom: 18px;
            overflow: hidden;
        }
        .stat {
            padding: 14px 18px;
            border-left: 1px solid var(--border);
            min-width: 0;
        }
        .stat:first-child { border-left: none; }
        .stat-label {
            display: block;
            font-size: 0.68rem;
            font-weight: 650;
            letter-spacing: 0.09em;
            text-transform: uppercase;
            color: var(--muted);
        }
        .stat-value {
            font-family: var(--font-mono);
            font-size: 1.45rem;
            font-weight: 600;
            line-height: 1.3;
            white-space: nowrap;
        }
        .stat-value .stat-unit { font-size: 0.78rem; color: var(--muted); font-weight: 500; }
        .stat-value.is-pending { color: var(--accent); }

        /* ---- Filters --------------------------------------------------- */
        .filters {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }
        .filters input[type="search"],
        .filters select {
            font: inherit;
            font-size: 0.88rem;
            color: var(--text);
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 7px 11px;
        }
        .filters input[type="search"] { flex: 1 1 220px; min-width: 180px; }
        .filters input[type="search"]::placeholder { color: var(--muted); }
        .filters select { appearance: auto; }
        .filters .filters-reset { color: var(--muted); font-size: 0.84rem; }

        /* ---- Query table ----------------------------------------------- */
        .queries-panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        table.queries { width: 100%; border-collapse: collapse; }
        .queries th {
            text-align: left;
            font-size: 0.68rem;
            font-weight: 650;
            text-transform: uppercase;
            letter-spacing: 0.09em;
            color: var(--muted);
            padding: 10px 16px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        .queries th a { text-decoration: none; }
        .queries th a:hover { color: var(--text); }
        .queries th .sort-arrow { color: var(--accent); }
        .queries td { padding: 12px 16px; border-bottom: 1px solid var(--border); vertical-align: top; }
        .queries tbody tr:last-child td { border-bottom: none; }
        .queries tbody tr:hover td { background: var(--surface-2); }

        .query-sql {
            font-family: var(--font-mono);
            font-size: 0.8rem;
            line-height: 1.5;
            word-break: break-word;
            text-decoration: none;
            display: block;
        }
        .query-sql:hover { color: var(--accent-strong); }
        .query-meta { color: var(--muted); font-size: 0.76rem; margin-top: 3px; font-family: var(--font-mono); }

        .cell-time { width: 160px; }
        .time-value { font-family: var(--font-mono); font-size: 0.84rem; font-weight: 600; white-space: nowrap; }
        .time-value .stat-unit { color: var(--muted); font-weight: 500; font-size: 0.72rem; }
        .lat-track { margin-top: 5px; height: 4px; border-radius: 2px; background: var(--surface-2); overflow: hidden; }
        .lat-bar {
            display: block;
            height: 100%;
            border-radius: 2px;
            background: linear-gradient(90deg, var(--bar-from), var(--bar-to));
            animation: lat-grow 0.5s ease-out;
        }
        @keyframes lat-grow { from { width: 0; } }

        .cell-status { width: 110px; white-space: nowrap; }
        .cell-when { width: 130px; white-space: nowrap; color: var(--muted); font-size: 0.8rem; }
        .cell-connection { width: 110px; font-family: var(--font-mono); font-size: 0.78rem; color: var(--muted); }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.74rem;
            font-weight: 600;
            padding: 3px 9px;
            border-radius: 999px;
        }
        .badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        .badge-ok { color: var(--ok); background: var(--ok-bg); }
        .badge-pending { color: var(--accent); background: var(--warn-bg); }

        /* ---- Empty state ------------------------------------------------ */
        .empty-state { padding: 56px 24px; text-align: center; }
        .empty-state .empty-mark { font-family: var(--font-mono); font-size: 1rem; color: var(--accent); }
        .empty-state h2 { margin: 10px 0 6px; font-size: 1.05rem; }
        .empty-state p { margin: 0 auto; max-width: 46ch; color: var(--muted); font-size: 0.88rem; }
        .empty-state code { font-family: var(--font-mono); font-size: 0.8rem; background: var(--surface-2); padding: 1px 6px; border-radius: 5px; }

        /* ---- Pagination -------------------------------------------------- */
        .pagination { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 14px; }
        .pagination .page-info { color: var(--muted); font-size: 0.82rem; font-family: var(--font-mono); }
        .pagination .page-links { display: flex; gap: 8px; }

        /* ---- Detail page -------------------------------------------------- */
        .back-link { display: inline-flex; align-items: center; gap: 6px; color: var(--muted); font-size: 0.86rem; text-decoration: none; margin-bottom: 14px; }
        .back-link:hover { color: var(--text); }

        .detail-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 16px; }
        .detail-head .detail-title { display: flex; align-items: center; gap: 12px; }
        .detail-head h1 { margin: 0; font-size: 1.25rem; font-family: var(--font-mono); }
        .detail-actions { display: flex; gap: 8px; flex-wrap: wrap; }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1px;
            background: var(--border);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 16px;
        }
        .meta-cell { background: var(--surface); padding: 12px 16px; min-width: 0; }
        .meta-cell .stat-label { margin-bottom: 2px; }
        .meta-cell .meta-value { font-family: var(--font-mono); font-size: 0.9rem; overflow-wrap: anywhere; }
        .meta-cell .meta-value.is-time { color: var(--accent); font-weight: 650; }

        .panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: var(--shadow);
            margin-bottom: 16px;
            overflow: hidden;
        }
        .panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 16px;
            border-bottom: 1px solid var(--border);
        }
        .panel-head h2 {
            margin: 0;
            font-size: 0.68rem;
            font-weight: 650;
            text-transform: uppercase;
            letter-spacing: 0.09em;
            color: var(--muted);
        }
        .panel-body { padding: 16px; }

        .code {
            font-family: var(--font-mono);
            font-size: 0.82rem;
            line-height: 1.65;
            white-space: pre-wrap;
            word-break: break-word;
            margin: 0;
            tab-size: 4;
        }

        /* ---- Recommendation (rendered markdown) --------------------------- */
        .recommendation { font-size: 0.92rem; }
        .recommendation > :first-child { margin-top: 0; }
        .recommendation > :last-child { margin-bottom: 0; }
        .recommendation h3, .recommendation h4, .recommendation h5, .recommendation h6 { margin: 1.2em 0 0.5em; }
        .recommendation p, .recommendation ul, .recommendation ol { margin: 0.6em 0; }
        .recommendation li { margin: 0.25em 0; }
        .recommendation code {
            font-family: var(--font-mono);
            font-size: 0.82em;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: 5px;
            padding: 1px 5px;
        }
        .recommendation pre {
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px 14px;
            overflow-x: auto;
        }
        .recommendation pre code { background: none; border: none; padding: 0; font-size: 0.8rem; line-height: 1.6; display: block; white-space: pre; }

        .callout {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            justify-content: space-between;
            padding: 14px 16px;
            border: 1px dashed var(--border);
            border-radius: 10px;
            color: var(--muted);
            font-size: 0.9rem;
        }

        .ai-note { color: var(--muted); font-size: 0.76rem; font-family: var(--font-mono); }

        /* ---- Responsive ----------------------------------------------------- */
        @media (max-width: 760px) {
            .stats-strip { grid-template-columns: repeat(2, 1fr); }
            .stat:nth-child(3) { border-left: none; }
            .stat:nth-child(n+3) { border-top: 1px solid var(--border); }
            .meta-grid { grid-template-columns: repeat(2, 1fr); }
            .cell-connection, .queries .th-connection { display: none; }
            .cell-when, .queries .th-when { display: none; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="topbar">
            <a class="brand" href="{{ route('slower.index') }}">slower<span class="brand-cursor">_</span></a>
            <div class="topbar-actions">
                @yield('topbar-actions')
                <button type="button" class="btn theme-toggle" data-theme-toggle aria-label="Toggle color theme">
                    <span class="icon-moon" aria-hidden="true">◐</span>
                    <span class="icon-sun" aria-hidden="true">◑</span>
                </button>
            </div>
        </header>

        @if (session('slower.status'))
            <div class="flash" role="status">{{ session('slower.status') }}</div>
        @endif
        @if (session('slower.error'))
            <div class="flash flash-error" role="alert">{{ session('slower.error') }}</div>
        @endif

        <main>
            @yield('content')
        </main>
    </div>

    <script>
        (function () {
            document.addEventListener('click', function (event) {
                var toggle = event.target.closest('[data-theme-toggle]');
                if (toggle) {
                    var root = document.documentElement;
                    var dark = root.getAttribute('data-theme') === 'dark'
                        || (root.getAttribute('data-theme') !== 'light'
                            && window.matchMedia('(prefers-color-scheme: dark)').matches);
                    var next = dark ? 'light' : 'dark';
                    root.setAttribute('data-theme', next);
                    try { localStorage.setItem('slower-theme', next); } catch (e) { /* private mode */ }
                    return;
                }

                var copy = event.target.closest('[data-copy]');
                if (copy) {
                    var source = document.querySelector(copy.getAttribute('data-copy'));
                    var text = source ? source.textContent : '';
                    var done = function () {
                        var label = copy.textContent;
                        copy.textContent = 'Copied';
                        setTimeout(function () { copy.textContent = label; }, 1200);
                    };
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text).then(done);
                    } else {
                        var area = document.createElement('textarea');
                        area.value = text;
                        document.body.appendChild(area);
                        area.select();
                        try { document.execCommand('copy'); done(); } finally { document.body.removeChild(area); }
                    }
                }
            });

            document.addEventListener('submit', function (event) {
                var form = event.target;
                var message = form.getAttribute('data-confirm');
                if (message && ! window.confirm(message)) {
                    event.preventDefault();
                    return;
                }
                var button = form.querySelector('button[type="submit"]');
                if (button) {
                    setTimeout(function () {
                        button.classList.add('is-loading');
                        button.setAttribute('disabled', 'disabled');
                    }, 0);
                }
            });

            document.addEventListener('change', function (event) {
                var form = event.target.form;
                if (form && form.hasAttribute('data-autosubmit') && event.target.matches('select')) {
                    form.submit();
                }
            });
        })();
    </script>
</body>
</html>
