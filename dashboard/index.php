<?php

declare(strict_types=1);

/**
 * PHP Profiler – Web Dashboard
 *
 * Place this file (and the dashboard/ directory) alongside profiler.php:
 *
 *   /opt/php-profiler/
 *     profiler.php
 *     dashboard/
 *       index.php    ← this file
 *     data/          ← profile JSON files written by profiler.php
 *
 * Secure the dashboard in your web-server configuration, e.g. for Nginx:
 *
 *   location /profiler/ {
 *       alias /opt/php-profiler/dashboard/;
 *       allow 127.0.0.1;
 *       deny  all;
 *   }
 *
 * Or for Apache:
 *
 *   Alias /profiler /opt/php-profiler/dashboard
 *   <Directory /opt/php-profiler/dashboard>
 *       Require ip 127.0.0.1
 *   </Directory>
 */

// ── Configuration ─────────────────────────────────────────────────────────────

$dataDir = (string) (getenv('PHP_PROFILER_DATA_DIR') ?: (dirname(__DIR__) . '/data'));

// ── Helper functions ──────────────────────────────────────────────────────────

/**
 * Read all profile JSON files and return them as an array.
 *
 * @return array<int, array<string, mixed>>
 */
function readAllProfiles(string $dataDir): array
{
    $files    = glob($dataDir . '/*.json');
    $profiles = [];

    if ($files === false) {
        return $profiles;
    }

    foreach ($files as $file) {
        $raw = file_get_contents($file);

        if ($raw === false) {
            continue;
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($raw, true);

        if (is_array($data)) {
            $profiles[] = $data;
        }
    }

    return $profiles;
}

/**
 * Aggregate profiles by endpoint and return stats sorted by total samples (desc).
 *
 * @param  array<int, array<string, mixed>> $profiles
 * @return array<int, array<string, mixed>>
 */
function buildEndpointStats(array $profiles): array
{
    /** @var array<string, array<string, mixed>> $stats */
    $stats = [];

    foreach ($profiles as $p) {
        $ep = (string) ($p['endpoint'] ?? '');

        if (! isset($stats[$ep])) {
            $stats[$ep] = [
                'endpoint'          => $ep,
                'request_count'     => 0,
                'total_samples'     => 0,
                'total_duration_ms' => 0.0,
                'avg_duration_ms'   => 0.0,
                'last_seen'         => 0.0,
            ];
        }

        $stats[$ep]['request_count']++;
        $stats[$ep]['total_samples']     += (int) ($p['sample_count'] ?? 0);
        $stats[$ep]['total_duration_ms'] += (float) ($p['duration_ms'] ?? 0.0);

        $ts = (float) ($p['timestamp'] ?? 0.0);

        if ($ts > (float) $stats[$ep]['last_seen']) {
            $stats[$ep]['last_seen'] = $ts;
        }
    }

    foreach ($stats as &$s) {
        $count               = (int) $s['request_count'];
        $s['avg_duration_ms'] = $count > 0
            ? round((float) $s['total_duration_ms'] / $count, 2)
            : 0.0;
    }

    unset($s);

    usort(
        $stats,
        static fn (array $a, array $b): int => (int) $b['total_samples'] - (int) $a['total_samples']
    );

    return array_values($stats);
}

/**
 * Merge the folded-stacks strings from all profiles of a given endpoint.
 */
function mergeFoldedStacks(string $endpoint, string $dataDir): string
{
    $files = glob($dataDir . '/*.json');

    if ($files === false) {
        return '';
    }

    $merged = '';

    foreach ($files as $file) {
        $raw = file_get_contents($file);

        if ($raw === false) {
            continue;
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($raw, true);

        if (! is_array($data) || ($data['endpoint'] ?? '') !== $endpoint) {
            continue;
        }

        $stacks = trim((string) ($data['folded_stacks'] ?? ''));

        if ($stacks !== '') {
            $merged .= $stacks . "\n";
        }
    }

    return trim($merged);
}

// ── JSON API ──────────────────────────────────────────────────────────────────

$action = $_GET['action'] ?? '';

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    // Prevent caching of API responses
    header('Cache-Control: no-store');

    switch ($action) {
        case 'endpoints':
            $profiles = readAllProfiles($dataDir);
            echo json_encode(buildEndpointStats($profiles), JSON_UNESCAPED_SLASHES);
            exit;

        case 'profiles':
            $endpoint = (string) ($_GET['endpoint'] ?? '');
            $folded   = mergeFoldedStacks($endpoint, $dataDir);
            echo json_encode([
                'endpoint'      => $endpoint,
                'folded_stacks' => $folded,
            ], JSON_UNESCAPED_SLASHES);
            exit;

        case 'export':
            $endpoint = (string) ($_GET['endpoint'] ?? '');
            $folded   = mergeFoldedStacks($endpoint, $dataDir);
            $safe     = preg_replace('/[^a-zA-Z0-9._\-]/', '_', ltrim($endpoint, '/')) ?: 'profile';
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="profile-' . $safe . '.json"');
            echo json_encode([
                'endpoint'      => $endpoint,
                'folded_stacks' => $folded,
                'exported_at'   => date('c'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// ── HTML Dashboard ────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PHP Profiler Dashboard</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:        #0f1117;
    --surface:   #1a1d27;
    --border:    #2a2d3e;
    --accent:    #6366f1;
    --accent2:   #f59e0b;
    --text:      #e2e8f0;
    --muted:     #64748b;
    --danger:    #ef4444;
    --success:   #22c55e;
    --flame-hot: #ef4444;
    --flame-mid: #f59e0b;
    --flame-cool:#3b82f6;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
}

/* ── Header ── */
header {
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    padding: 0 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    height: 56px;
}
header h1 { font-size: 1.1rem; font-weight: 600; }
header h1 span { color: var(--accent); }
.badge {
    background: var(--accent);
    color: #fff;
    font-size: 0.7rem;
    padding: 2px 8px;
    border-radius: 999px;
    font-weight: 600;
    letter-spacing: .05em;
}
.header-spacer { flex: 1; }
#refresh-btn {
    background: transparent;
    border: 1px solid var(--border);
    color: var(--muted);
    border-radius: 6px;
    padding: 6px 14px;
    cursor: pointer;
    font-size: 0.85rem;
    transition: border-color .2s, color .2s;
}
#refresh-btn:hover { border-color: var(--accent); color: var(--text); }

/* ── Layout ── */
.layout {
    display: grid;
    grid-template-columns: 340px 1fr;
    height: calc(100vh - 56px);
    overflow: hidden;
}

/* ── Left panel: endpoint list ── */
.sidebar {
    background: var(--surface);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.sidebar-header {
    padding: 1rem 1rem 0.75rem;
    border-bottom: 1px solid var(--border);
}
.sidebar-header h2 {
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: .1em;
    color: var(--muted);
    margin-bottom: .5rem;
}
#filter-input {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--text);
    padding: 6px 10px;
    font-size: 0.85rem;
    outline: none;
    transition: border-color .2s;
}
#filter-input:focus { border-color: var(--accent); }

.endpoint-list {
    flex: 1;
    overflow-y: auto;
    padding: 0.5rem 0;
}
.endpoint-list::-webkit-scrollbar { width: 6px; }
.endpoint-list::-webkit-scrollbar-track { background: transparent; }
.endpoint-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

.ep-item {
    padding: 0.6rem 1rem;
    cursor: pointer;
    border-left: 3px solid transparent;
    transition: background .15s, border-color .15s;
    position: relative;
}
.ep-item:hover { background: rgba(99,102,241,.07); }
.ep-item.active {
    background: rgba(99,102,241,.13);
    border-left-color: var(--accent);
}
.ep-rank {
    font-size: 0.7rem;
    color: var(--muted);
    font-weight: 600;
    margin-bottom: 2px;
}
.ep-path {
    font-size: 0.82rem;
    font-family: 'SFMono-Regular', Consolas, monospace;
    word-break: break-all;
    line-height: 1.3;
}
.ep-meta {
    display: flex;
    gap: .75rem;
    margin-top: 3px;
    font-size: 0.72rem;
    color: var(--muted);
}
.ep-bar-wrap {
    height: 3px;
    background: var(--border);
    border-radius: 2px;
    margin-top: 4px;
    overflow: hidden;
}
.ep-bar { height: 100%; background: var(--accent); border-radius: 2px; transition: width .4s; }

/* ── Right panel: flamegraph ── */
.main {
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.main-toolbar {
    padding: .75rem 1.5rem;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: .75rem;
    background: var(--surface);
    min-height: 52px;
}
#selected-endpoint {
    font-family: 'SFMono-Regular', Consolas, monospace;
    font-size: 0.85rem;
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
#selected-endpoint.placeholder { color: var(--muted); font-family: inherit; font-style: italic; }

.toolbar-btn {
    background: transparent;
    border: 1px solid var(--border);
    color: var(--text);
    border-radius: 6px;
    padding: 5px 14px;
    cursor: pointer;
    font-size: 0.82rem;
    transition: border-color .2s, background .2s;
    white-space: nowrap;
    display: none;
}
.toolbar-btn:hover { border-color: var(--accent2); background: rgba(245,158,11,.08); }
.toolbar-btn.visible { display: inline-flex; align-items: center; gap: .35rem; }

#flame-container {
    flex: 1;
    overflow: auto;
    padding: 1rem 1.5rem;
    position: relative;
}

/* ── Flamegraph canvas ── */
#flamegraph-svg {
    display: block;
    width: 100%;
    cursor: default;
}
.flame-frame {
    cursor: pointer;
}
.flame-frame rect {
    stroke: var(--bg);
    stroke-width: 1;
    transition: opacity .15s;
}
.flame-frame:hover rect { opacity: 0.85; }
.flame-frame text {
    font-family: 'SFMono-Regular', Consolas, monospace;
    font-size: 11px;
    fill: #fff;
    pointer-events: none;
    dominant-baseline: middle;
}

/* ── Tooltip ── */
#tooltip {
    position: fixed;
    background: #1e2130;
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: .6rem .85rem;
    font-size: 0.78rem;
    pointer-events: none;
    z-index: 999;
    max-width: 380px;
    display: none;
    line-height: 1.5;
    box-shadow: 0 8px 24px rgba(0,0,0,.5);
}
#tooltip .tt-name { font-family: monospace; font-size: 0.82rem; margin-bottom: .25rem; word-break: break-all; }
#tooltip .tt-meta { color: var(--muted); }

/* ── Empty / loading states ── */
.state-msg {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: .5rem;
    color: var(--muted);
    font-size: 0.9rem;
    pointer-events: none;
}
.state-msg .icon { font-size: 2.5rem; }
.spinner {
    width: 28px; height: 28px;
    border: 3px solid var(--border);
    border-top-color: var(--accent);
    border-radius: 50%;
    animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Zoom breadcrumb ── */
#breadcrumb {
    padding: .4rem 1.5rem;
    font-size: 0.78rem;
    color: var(--muted);
    border-bottom: 1px solid var(--border);
    display: none;
    gap: .3rem;
    align-items: center;
    background: var(--bg);
}
#breadcrumb.visible { display: flex; }
#breadcrumb .crumb { cursor: pointer; color: var(--accent); }
#breadcrumb .crumb:hover { text-decoration: underline; }
#breadcrumb .sep { color: var(--border); }

/* ── Stats bar ── */
#stats-bar {
    padding: .4rem 1.5rem;
    font-size: 0.75rem;
    color: var(--muted);
    border-bottom: 1px solid var(--border);
    display: none;
    gap: 1.5rem;
    flex-wrap: wrap;
    background: var(--bg);
}
#stats-bar.visible { display: flex; }
#stats-bar strong { color: var(--text); }

/* ── Highlight search ── */
#highlight-input {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--text);
    padding: 5px 10px;
    font-size: 0.82rem;
    outline: none;
    width: 160px;
    transition: border-color .2s;
}
#highlight-input:focus { border-color: var(--accent2); }
</style>
</head>
<body>

<header>
    <h1>🔥 PHP <span>Profiler</span></h1>
    <span class="badge">Excimer</span>
    <div class="header-spacer"></div>
    <button id="refresh-btn" onclick="loadEndpoints()">↻ Refresh</button>
</header>

<div class="layout">

    <!-- ── Left: endpoint ranking ── -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <h2>Endpoints <span id="ep-count" style="color:var(--text)"></span></h2>
            <input id="filter-input" type="search" placeholder="Filter endpoints…" oninput="filterEndpoints(this.value)">
        </div>
        <div class="endpoint-list" id="endpoint-list">
            <div class="state-msg" id="ep-loading">
                <div class="spinner"></div>
                <span>Loading…</span>
            </div>
        </div>
    </aside>

    <!-- ── Right: flamegraph ── -->
    <main class="main">
        <div class="main-toolbar">
            <div id="selected-endpoint" class="placeholder">← Select an endpoint to view its flamegraph</div>
            <input id="highlight-input" type="search" placeholder="Highlight…" oninput="applyHighlight(this.value)" title="Highlight matching frames">
            <button class="toolbar-btn" id="export-btn" title="Export merged profile as JSON" onclick="exportJson()">⬇ Export JSON</button>
            <button class="toolbar-btn" id="reset-zoom-btn" onclick="resetZoom()" title="Reset zoom">⊙ Reset zoom</button>
        </div>
        <div id="stats-bar"></div>
        <div id="breadcrumb"></div>
        <div id="flame-container">
            <div class="state-msg" id="flame-placeholder">
                <div class="icon">📊</div>
                <span>Select an endpoint from the list</span>
            </div>
        </div>
    </main>

</div>

<!-- Tooltip -->
<div id="tooltip">
    <div class="tt-name" id="tt-name"></div>
    <div class="tt-meta" id="tt-meta"></div>
</div>

<script>
'use strict';

// ── State ──────────────────────────────────────────────────────────────────────
let _allEndpoints   = [];
let _currentEp      = null;
let _flameRoot      = null;    // full tree (never mutated)
let _zoomStack      = [];      // stack of zoomed-in nodes
let _highlightTerm  = '';
let _totalSamples   = 0;

const ROW_HEIGHT    = 20;
const MIN_PX_WIDTH  = 1;       // frames narrower than this are skipped

// ── Colour palette (deterministic by name hash) ──────────────────────────────
function strHash(s) {
    let h = 0;
    for (let i = 0; i < s.length; i++) { h = Math.imul(31, h) + s.charCodeAt(i) | 0; }
    return Math.abs(h);
}

function frameColor(name, highlighted) {
    if (highlighted) return '#f59e0b';

    // Colour by namespace / internal category
    if (name === 'all' || name === '{main}' || name === 'main') return '#6366f1';
    if (name.startsWith('PHPDevsr\\'))  return '#8b5cf6';
    if (name.startsWith('Illuminate\\') || name.startsWith('Laravel\\')) return '#ec4899';
    if (name.startsWith('Symfony\\'))   return '#10b981';
    if (name.includes('Controller'))    return '#3b82f6';
    if (name.includes('Model') || name.includes('Repository')) return '#06b6d4';
    if (name.includes('View') || name.includes('Template'))    return '#84cc16';
    if (name.startsWith('PDO') || name.includes('Query') || name.includes('DB')) return '#f97316';
    if (name.startsWith('Redis') || name.includes('Cache'))    return '#a78bfa';

    const h = strHash(name) % 360;
    return `hsl(${h},55%,42%)`;
}

// ── Parse folded stacks → tree ────────────────────────────────────────────────
function parseFolded(folded) {
    const root = { name: 'all', value: 0, self: 0, children: Object.create(null) };

    for (const rawLine of folded.split('\n')) {
        const line = rawLine.trim();
        if (!line) continue;

        const sp = line.lastIndexOf(' ');
        if (sp === -1) continue;

        const frames = line.slice(0, sp).split(';');
        const count  = parseInt(line.slice(sp + 1), 10);
        if (!count) continue;

        root.value += count;
        let node = root;

        for (const frame of frames) {
            if (!node.children[frame]) {
                node.children[frame] = { name: frame, value: 0, self: 0, children: Object.create(null) };
            }
            node.children[frame].value += count;
            node = node.children[frame];
        }

        node.self += count;
    }

    return flattenTree(root);
}

function flattenTree(node) {
    const children = Object.values(node.children).map(flattenTree);
    children.sort((a, b) => b.value - a.value);
    return { name: node.name, value: node.value, self: node.self, children };
}

// ── SVG Flamegraph renderer ───────────────────────────────────────────────────
function renderFlamegraph(root, containerWidth) {
    const total = root.value;
    if (!total) return null;

    _totalSamples = total;

    // Collect all nodes to render via DFS
    const rects = [];
    let maxDepth = 0;

    function walk(node, depth, x, w) {
        if (w < MIN_PX_WIDTH) return;
        maxDepth = Math.max(maxDepth, depth);
        rects.push({ node, depth, x, w });
        let cx = x;
        for (const child of node.children) {
            const cw = (child.value / total) * containerWidth;
            walk(child, depth + 1, cx, cw);
            cx += cw;
        }
    }

    walk(root, 0, 0, containerWidth);

    const svgH = (maxDepth + 1) * ROW_HEIGHT + 4;
    const ns   = 'http://www.w3.org/2000/svg';
    const svg  = document.createElementNS(ns, 'svg');
    svg.id     = 'flamegraph-svg';
    svg.setAttribute('width',  String(containerWidth));
    svg.setAttribute('height', String(svgH));
    svg.setAttribute('viewBox', `0 0 ${containerWidth} ${svgH}`);

    for (const { node, depth, x, w } of rects) {
        const highlighted = _highlightTerm && node.name.toLowerCase().includes(_highlightTerm);
        const y = depth * ROW_HEIGHT;
        const g = document.createElementNS(ns, 'g');
        g.classList.add('flame-frame');
        g.dataset.name  = node.name;
        g.dataset.value = String(node.value);
        g.dataset.self  = String(node.self);
        g.dataset.depth = String(depth);
        g.dataset.x     = String(x);
        g.dataset.w     = String(w);

        const rect = document.createElementNS(ns, 'rect');
        rect.setAttribute('x',      String(x + 0.5));
        rect.setAttribute('y',      String(y + 0.5));
        rect.setAttribute('width',  String(Math.max(0, w - 1)));
        rect.setAttribute('height', String(ROW_HEIGHT - 1));
        rect.setAttribute('fill',   frameColor(node.name, !!highlighted));
        rect.setAttribute('rx',     '2');

        g.appendChild(rect);

        // Label (only if wide enough)
        if (w > 36) {
            const label = document.createElementNS(ns, 'text');
            label.setAttribute('x', String(x + 4));
            label.setAttribute('y', String(y + ROW_HEIGHT / 2));
            const maxChars = Math.floor((w - 8) / 6.5);
            let txt = node.name;
            if (txt.length > maxChars) txt = txt.slice(0, Math.max(1, maxChars - 1)) + '…';
            label.textContent = txt;
            g.appendChild(label);
        }

        // Event listeners
        g.addEventListener('click',     () => zoomInto(node));
        g.addEventListener('mousemove', (e) => showTooltip(e, node, total));
        g.addEventListener('mouseleave', hideTooltip);

        svg.appendChild(g);
    }

    return svg;
}

// ── Zoom ──────────────────────────────────────────────────────────────────────
function zoomInto(node) {
    if (node === _flameRoot) return;
    _zoomStack.push(node);
    drawFlamegraph(node);
    updateBreadcrumb();
}

function resetZoom() {
    _zoomStack = [];
    drawFlamegraph(_flameRoot);
    updateBreadcrumb();
}

function updateBreadcrumb() {
    const bc = document.getElementById('breadcrumb');

    if (_zoomStack.length === 0) {
        bc.classList.remove('visible');
        return;
    }

    bc.classList.add('visible');
    bc.innerHTML = '';

    const addCrumb = (label, idx) => {
        const span = document.createElement('span');
        span.className = 'crumb';
        span.textContent = label;
        span.onclick = () => {
            _zoomStack = _zoomStack.slice(0, idx);
            drawFlamegraph(idx === 0 ? _flameRoot : _zoomStack[idx - 1]);
            updateBreadcrumb();
        };
        bc.appendChild(span);
    };

    addCrumb('all', 0);

    for (let i = 0; i < _zoomStack.length; i++) {
        const sep = document.createElement('span');
        sep.className = 'sep';
        sep.textContent = ' › ';
        bc.appendChild(sep);
        addCrumb(_zoomStack[i].name, i + 1);
    }

    const resetBtn = document.getElementById('reset-zoom-btn');
    resetBtn.classList.toggle('visible', _zoomStack.length > 0);
}

function drawFlamegraph(root) {
    const container = document.getElementById('flame-container');
    const old = document.getElementById('flamegraph-svg');
    if (old) old.remove();

    const ph = document.getElementById('flame-placeholder');
    if (ph) ph.style.display = 'none';

    const w = container.clientWidth - 48;
    const svg = renderFlamegraph(root, Math.max(w, 400));

    if (svg) {
        container.appendChild(svg);
    }
}

// ── Highlight ─────────────────────────────────────────────────────────────────
function applyHighlight(term) {
    _highlightTerm = term.trim().toLowerCase();
    if (_flameRoot) {
        drawFlamegraph(_zoomStack.length ? _zoomStack[_zoomStack.length - 1] : _flameRoot);
    }
}

// ── Tooltip ───────────────────────────────────────────────────────────────────
function showTooltip(e, node, total) {
    const tt   = document.getElementById('tooltip');
    const pct  = ((node.value / total) * 100).toFixed(1);
    const self = ((node.self  / total) * 100).toFixed(1);

    document.getElementById('tt-name').textContent = node.name;
    document.getElementById('tt-meta').innerHTML =
        `Samples: <strong>${node.value}</strong> (${pct}% total) &nbsp;·&nbsp; Self: <strong>${node.self}</strong> (${self}%)`;

    tt.style.display = 'block';
    positionTooltip(e, tt);
}

function hideTooltip() {
    document.getElementById('tooltip').style.display = 'none';
}

function positionTooltip(e, tt) {
    const pad = 12;
    let x = e.clientX + pad;
    let y = e.clientY + pad;
    const w = tt.offsetWidth  || 300;
    const h = tt.offsetHeight || 60;

    if (x + w > window.innerWidth  - pad) x = e.clientX - w - pad;
    if (y + h > window.innerHeight - pad) y = e.clientY - h - pad;

    tt.style.left = x + 'px';
    tt.style.top  = y + 'px';
}

document.addEventListener('mousemove', (e) => {
    const tt = document.getElementById('tooltip');
    if (tt.style.display !== 'none') positionTooltip(e, tt);
});

// ── Load endpoint list ────────────────────────────────────────────────────────
async function loadEndpoints() {
    document.getElementById('ep-loading').style.display = 'flex';
    document.getElementById('ep-loading').innerHTML =
        '<div class="spinner"></div><span>Loading…</span>';

    try {
        const res  = await fetch('?action=endpoints');
        _allEndpoints = await res.json();
    } catch (err) {
        document.getElementById('ep-loading').innerHTML =
            '<span style="color:var(--danger)">⚠ Failed to load endpoints</span>';
        return;
    }

    document.getElementById('ep-count').textContent =
        `(${_allEndpoints.length})`;

    renderEndpointList(_allEndpoints);
}

function renderEndpointList(list) {
    const el    = document.getElementById('endpoint-list');
    el.innerHTML = '';

    if (list.length === 0) {
        el.innerHTML = '<div class="state-msg" style="position:static;padding:2rem 1rem;color:var(--muted)">No profiles yet.<br>Make some requests first.</div>';
        return;
    }

    const maxSamples = list[0].total_samples || 1;

    list.forEach((ep, idx) => {
        const item = document.createElement('div');
        item.className = 'ep-item';
        item.dataset.endpoint = ep.endpoint;

        if (ep.endpoint === _currentEp) item.classList.add('active');

        const barPct = ((ep.total_samples / maxSamples) * 100).toFixed(1);

        item.innerHTML = `
            <div class="ep-rank">#${idx + 1}</div>
            <div class="ep-path">${escHtml(ep.endpoint)}</div>
            <div class="ep-meta">
                <span title="Requests">${ep.request_count} req</span>
                <span title="Total samples">${ep.total_samples.toLocaleString()} samples</span>
                <span title="Avg duration">${ep.avg_duration_ms} ms avg</span>
            </div>
            <div class="ep-bar-wrap"><div class="ep-bar" style="width:${barPct}%"></div></div>
        `;
        item.addEventListener('click', () => selectEndpoint(ep.endpoint));
        el.appendChild(item);
    });
}

function filterEndpoints(term) {
    const t = term.trim().toLowerCase();
    const filtered = t
        ? _allEndpoints.filter(ep => ep.endpoint.toLowerCase().includes(t))
        : _allEndpoints;
    renderEndpointList(filtered);
}

// ── Select endpoint & load flamegraph ─────────────────────────────────────────
async function selectEndpoint(endpoint) {
    _currentEp   = endpoint;
    _zoomStack   = [];
    _flameRoot   = null;

    // Update sidebar active state
    document.querySelectorAll('.ep-item').forEach(el => {
        el.classList.toggle('active', el.dataset.endpoint === endpoint);
    });

    // Toolbar
    document.getElementById('selected-endpoint').textContent = endpoint;
    document.getElementById('selected-endpoint').classList.remove('placeholder');
    document.getElementById('export-btn').classList.add('visible');
    document.getElementById('stats-bar').classList.remove('visible');
    document.getElementById('breadcrumb').classList.remove('visible');
    document.getElementById('reset-zoom-btn').classList.remove('visible');

    // Show loading
    const container = document.getElementById('flame-container');
    container.innerHTML = '<div class="state-msg"><div class="spinner"></div><span>Building flamegraph…</span></div>';

    let data;
    try {
        const res = await fetch('?action=profiles&endpoint=' + encodeURIComponent(endpoint));
        data = await res.json();
    } catch {
        container.innerHTML = '<div class="state-msg" style="color:var(--danger)">⚠ Failed to load profile data</div>';
        return;
    }

    if (!data.folded_stacks || !data.folded_stacks.trim()) {
        container.innerHTML = '<div class="state-msg"><div class="icon">🕳</div><span>No sample data for this endpoint</span></div>';
        return;
    }

    _flameRoot = parseFolded(data.folded_stacks);
    container.innerHTML = '';

    const ep = _allEndpoints.find(e => e.endpoint === endpoint);
    if (ep) {
        const sb = document.getElementById('stats-bar');
        sb.innerHTML = `
            <span><strong>${ep.request_count}</strong> requests merged</span>
            <span><strong>${ep.total_samples.toLocaleString()}</strong> total samples</span>
            <span>avg <strong>${ep.avg_duration_ms} ms</strong></span>
        `;
        sb.classList.add('visible');
    }

    drawFlamegraph(_flameRoot);
}

// ── Export JSON ───────────────────────────────────────────────────────────────
function exportJson() {
    if (!_currentEp) return;
    window.location.href = '?action=export&endpoint=' + encodeURIComponent(_currentEp);
}

// ── Utilities ─────────────────────────────────────────────────────────────────
function escHtml(s) {
    return s.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c] || c));
}

// ── Init ──────────────────────────────────────────────────────────────────────
loadEndpoints();

// Re-render flamegraph on window resize
let _resizeTimer;
window.addEventListener('resize', () => {
    clearTimeout(_resizeTimer);
    _resizeTimer = setTimeout(() => {
        if (_flameRoot) {
            const node = _zoomStack.length ? _zoomStack[_zoomStack.length - 1] : _flameRoot;
            drawFlamegraph(node);
        }
    }, 150);
});
</script>

</body>
</html>
