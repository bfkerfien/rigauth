@extends('web::layouts.grids.12')

@section('title', 'Exploration')
@section('page_header', 'Exploration')
@section('page_description', 'Track explored solar systems across Branch, Tenal, and Venal.')

@section('full')
<div class="card exploration-card">
  <div class="card-header p-0">
    <ul class="nav nav-tabs" id="exploration-region-tabs" role="tablist">
      @foreach(['Branch', 'Tenal', 'Venal'] as $region)
        <li class="nav-item">
          <a class="nav-link {{ $loop->first ? 'active' : '' }}" id="exploration-{{ strtolower($region) }}-tab" data-toggle="tab" href="#exploration-{{ strtolower($region) }}" role="tab" aria-controls="exploration-{{ strtolower($region) }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}">{{ $region }}</a>
        </li>
      @endforeach
      <li class="nav-item">
        <a class="nav-link" id="exploration-system-information-tab" data-toggle="tab" href="#exploration-system-information" role="tab" aria-controls="exploration-system-information" aria-selected="false">System Information</a>
      </li>
      <li class="nav-item">
        <a class="nav-link" id="exploration-last-scanned-tab" data-toggle="tab" href="#exploration-last-scanned" role="tab" aria-controls="exploration-last-scanned" aria-selected="false">Last Scanned</a>
      </li>
    </ul>
  </div>
  <div class="card-body">
    @if($mapError)
      <div class="alert alert-warning">{{ $mapError }}</div>
      <div class="p-3" id="exploration-system-information-fallback">
        <h4>System Information</h4>
        <p id="exploration-system-info-status" class="text-muted">Select System Information to load your main character's current system.</p>
        <div id="exploration-system-info-content"></div>
      </div>
    @else
      <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div class="small text-muted mb-2 mb-md-0">
          <span class="exploration-legend"><span class="exploration-legend-dot white"></span> Unexplored</span>
          <span class="exploration-legend"><span class="exploration-legend-dot green"></span> Explored</span>
          <span class="exploration-legend"><span class="exploration-legend-dot red"></span> Permanently excluded</span>
          <span class="exploration-legend"><span class="exploration-legend-dot gold"></span> Your live location</span>
          <span id="exploration-live-status" class="ml-2">Live location: connecting…</span>
        </div>
        <div class="small text-muted">Left-click a system to toggle explored (green). Right-click a system to open its signatures.</div>
      </div>
      <div class="tab-content">
        @foreach(['Branch', 'Tenal', 'Venal'] as $region)
          @php($map = $maps[$region])
          <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}" id="exploration-{{ strtolower($region) }}" role="tabpanel" aria-labelledby="exploration-{{ strtolower($region) }}-tab">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <div>
                <h4 class="mb-0">{{ $region }}</h4>
                <small class="text-muted"><span class="exploration-green-count">{{ number_format($map['green_count']) }}</span> explored / {{ number_format(count($map['systems'])) }} systems</small>
              </div>
              <button type="button" class="btn btn-outline-secondary btn-sm exploration-reset" data-region="{{ $region }}">
                <i class="fas fa-undo"></i> Reset {{ $region }}
              </button>
            </div>
            <div class="exploration-map-toolbar small text-muted mb-2">Mouse wheel: zoom · Drag: pan · Center: your live location</div>
            <div class="exploration-map-wrap">
              @if(!empty($map['svg']))
                {!! $map['svg'] !!}
              @else
                <div class="alert alert-warning m-3">No solar-system map data is available for {{ $region }}. Check that the SeAT EVE SDE system coordinates are imported.</div>
              @endif
            </div>
          </div>
        @endforeach
        <div class="tab-pane fade" id="exploration-system-information" role="tabpanel" aria-labelledby="exploration-system-information-tab">
          <div class="p-3">
            <h4>Current system</h4>
            <p id="exploration-system-info-status" class="text-muted">Loading your main character's current system…</p>
            <div id="exploration-system-info-content"></div>
          </div>
        </div>
        <div class="tab-pane fade" id="exploration-last-scanned" role="tabpanel" aria-labelledby="exploration-last-scanned-tab">
          <div class="p-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
              <div><h4 class="mb-1">Last Scanned Systems</h4><p class="small text-muted mb-0">Most recent scan time for every system in Branch, Tenal, and Venal. Select a column heading to sort.</p></div>
              <button type="button" class="btn btn-outline-secondary btn-sm" id="exploration-last-scanned-refresh">Refresh list</button>
            </div>
            <p id="exploration-last-scanned-status" class="small text-muted">Open this tab to load scan history.</p>
            <div id="exploration-last-scanned-groups">
              <p class="text-muted mb-0">Loading…</p>
            </div>
          </div>
        </div>
      </div>
    @endif
  </div>
  <div class="small text-muted text-center mt-3 exploration-map-attribution">Native exploration map by Triglavian Consortium · System data from the EVE Online Static Data Export.</div>
  <div class="exploration-modal-backdrop" id="exploration-signature-modal" aria-hidden="true">
    <div class="exploration-modal" role="dialog" aria-modal="true" aria-labelledby="exploration-modal-title">
      <div class="exploration-modal-header">
        <div><h4 id="exploration-modal-title" class="mb-1">System signatures</h4><small id="exploration-modal-meta" class="text-muted"></small></div>
        <div><button type="button" class="btn btn-outline-secondary btn-sm mr-2" id="exploration-modal-status">Mark explored</button><button type="button" class="btn btn-outline-secondary btn-sm" id="exploration-modal-close">Close</button></div>
      </div>
      <div class="exploration-modal-body">
        <section>
          <h5>Currently scanned</h5>
          <div id="exploration-signature-list" class="exploration-signature-list"><span class="text-muted">Select a system to view its scan.</span></div>
        </section>
        <section>
          <h5>Paste new scan</h5>
          <p class="small text-muted">Paste Probe Scanner results from EVE. Replace Scan replaces all saved signatures; Add to Scan keeps existing signatures and adds or updates the pasted ones. Update Scan changes only the last-scanned timestamp.</p>
          <textarea id="exploration-scan-input" class="form-control" rows="12" placeholder="Paste Probe Scanner results here…"></textarea>
          <div class="d-flex flex-wrap justify-content-between align-items-center mt-2">
            <span id="exploration-scan-message" class="small text-muted"></span>
            <div class="d-flex flex-wrap" style="gap:.5rem">
              <button type="button" class="btn btn-outline-secondary" id="exploration-scan-timestamp">Update Scan</button>
              <button type="button" class="btn btn-outline-primary" id="exploration-scan-add">Add to Scan</button>
              <button type="button" class="btn btn-primary" id="exploration-scan-submit">Replace Scan</button>
            </div>
          </div>
        </section>
      </div>
      <div class="exploration-wormhole-backdrop" id="exploration-wormhole-editor" aria-hidden="true">
        <div class="exploration-wormhole-panel" role="dialog" aria-modal="true" aria-labelledby="exploration-wormhole-title">
          <div class="exploration-modal-header">
            <div><h4 id="exploration-wormhole-title" class="mb-1">Wormhole notes</h4><small id="exploration-wormhole-meta" class="text-muted"></small></div>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="exploration-wormhole-close">Back to signatures</button>
          </div>
          <div class="p-3">
            <div class="form-group">
              <label for="exploration-wormhole-type">Wormhole type</label>
              <input id="exploration-wormhole-type" class="form-control" type="text" maxlength="64" placeholder="e.g. C2, C5, K162">
            </div>
            <div class="form-group">
              <label for="exploration-connection-system">Connection system</label>
              <input id="exploration-connection-system" class="form-control" type="text" maxlength="128" placeholder="Connected system name">
            </div>
            <div class="form-group">
              <label for="exploration-wormhole-exits">Added wormhole exits</label>
              <textarea id="exploration-wormhole-exits" class="form-control" rows="5" maxlength="10000" placeholder="List additional exits, one per line…"></textarea>
            </div>
            <div class="d-flex justify-content-between align-items-center">
              <span id="exploration-wormhole-message" class="small text-muted"></span>
              <button type="button" class="btn btn-primary" id="exploration-wormhole-save">Save wormhole notes</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
.exploration-card { background: #111a29; border: 1px solid #26364b; color: #dbe5f1; }
.exploration-card .card-header, .exploration-card .card-body { background: #111a29; }
.exploration-card .card-header { border-bottom: 1px solid #26364b; }
.exploration-card .card-body { padding: 1rem; }
.exploration-card .nav-tabs { border-bottom: 1px solid #26364b; }
.exploration-card .nav-tabs .nav-link { color: #9eafc5; border: 0; border-bottom: 2px solid transparent; }
.exploration-card .nav-tabs .nav-link.active { color: #fff; background: transparent; border-bottom-color: #3b9bd8; }
.exploration-card .text-muted, .exploration-card small { color: #91a2b8 !important; }
.exploration-legend { display: inline-flex; align-items: center; margin-right: 16px; white-space: nowrap; }
.exploration-legend-dot { display: inline-block; width: 10px; height: 10px; border: 1px solid #8292a8; border-radius: 50%; margin-right: 6px; }
.exploration-legend-dot.white { background: #e8eef7; }
.exploration-legend-dot.green { background: #31c66b; border-color: #31c66b; }
.exploration-legend-dot.red { background: #e05252; border-color: #e05252; }
.exploration-legend-dot.gold { background: #f2b632; border-color: #f2b632; }
.exploration-map-toolbar { color: #91a2b8; }
.exploration-map-wrap { width: 100%; height: min(72vh, 820px); min-height: 520px; overflow: hidden; border: 1px solid #263b55; border-radius: 5px; background: #070d18; box-shadow: inset 0 0 40px rgba(0,0,0,.45); cursor: grab; touch-action: none; }
.exploration-map-wrap.is-dragging { cursor: grabbing; }
.exploration-map { display: block; width: 100%; height: 100%; min-width: 0; user-select: none; }
.exploration-map svg { display: block; width: 100%; height: 100%; }
.exploration-map-links line { stroke: #40536b; stroke-width: 1.35; opacity: .68; }\n.exploration-region-title { fill: #dce6f3; font: 700 30px Arial, sans-serif; letter-spacing: 3px; }\n.exploration-region-subtitle { fill: #63778f; font: 11px Arial, sans-serif; letter-spacing: 2px; }\n.exploration-system .s { stroke-width: 1.1px !important; }\n.exploration-system .ss { font: 12px Arial, sans-serif; fill: #c4d2e4 !important; }\n.exploration-system.is-green .s { filter: url(#exploration-glow); }
.exploration-map .ss { fill: #c4d2e4 !important; font: 11px Arial, sans-serif; text-anchor: start; dominant-baseline: middle; pointer-events: none; }
.exploration-system .ss { paint-order: stroke; stroke: #070d18; stroke-width: 2px; stroke-linejoin: round; }
.exploration-system { cursor: pointer; outline: none; }
.exploration-system { pointer-events: all; }
.exploration-system .exploration-hit { fill: transparent; pointer-events: all; }
.exploration-system .s { fill: #e8edf4 !important; stroke: #26384d !important; stroke-width: 1px !important; }
.exploration-system .ss { fill: #c4d2e4 !important; }
.exploration-system:hover .s, .exploration-system:focus .s { stroke: #55b9ff !important; stroke-width: 3px !important; }
.exploration-system.is-green .s { fill: #31c66b !important; stroke: #15964a !important; }
.exploration-system.is-green .ss { fill: #092516 !important; }
.exploration-system.is-red { cursor: not-allowed; }
.exploration-system.is-red .s { fill: #e05252 !important; stroke: #a92f38 !important; }
.exploration-system.is-red .ss { fill: #fff !important; }
.exploration-system.is-locked:hover .s, .exploration-system.is-locked:focus .s { stroke: #e05252 !important; }
.exploration-system.is-player-location .s { fill: #f2b632 !important; stroke: #ffe09a !important; stroke-width: 2px !important; }
.exploration-system.is-player-location .ss { fill: #ffe09a !important; font-weight: bold; }
.exploration-player-dot { fill: none; stroke: #f2b632; stroke-width: 3; pointer-events: none; }
.exploration-map-caption { fill: #6f829d; font: 12px Arial, sans-serif; }
.exploration-card .btn-outline-secondary { color: #bdcadd; border-color: #52657e; }
.exploration-card .btn-outline-secondary:hover { color: #fff; background: #25364d; }
.exploration-modal-backdrop { display:none; position:fixed; inset:0; z-index:1055; background:rgba(0,0,0,.78); padding:4vh 2vw; overflow:auto; }
.exploration-modal-backdrop.is-open { display:flex; align-items:flex-start; justify-content:center; }
.exploration-modal { position:relative; width:min(1100px, 96vw); background:#111a29; color:#dbe5f1; border:1px solid #52657e; border-radius:7px; box-shadow:0 18px 60px rgba(0,0,0,.6); }
.exploration-wormhole-backdrop { display:none; position:absolute; inset:0; z-index:2; background:rgba(4,9,17,.96); padding:1rem; }
.exploration-wormhole-backdrop.is-open { display:flex; align-items:center; justify-content:center; }
.exploration-wormhole-panel { width:min(620px, 96%); background:#111a29; color:#dbe5f1; border:1px solid #52657e; border-radius:7px; box-shadow:0 18px 60px rgba(0,0,0,.6); }
.exploration-wormhole-panel label { color:#c4d2e4; }
.exploration-wormhole-panel .form-control { background:#08111e; color:#e5edf7; border-color:#52657e; }
.exploration-wormhole-note { color:#f2b632; border:0; background:transparent; padding:0 .35rem; cursor:pointer; }
.exploration-wormhole-note:hover { color:#ffe09a; }
.exploration-wormhole-summary { display:block; color:#91a2b8; font-size:.78rem; margin-top:.2rem; white-space:pre-line; }
.exploration-modal-header { display:flex; justify-content:space-between; align-items:center; padding:1rem 1.25rem; border-bottom:1px solid #26364b; }
.exploration-modal-body { display:grid; grid-template-columns:1fr 1fr; gap:1rem; padding:1.25rem; }
.exploration-modal-body h5 { color:#fff; margin-bottom:.75rem; }
.exploration-signature-list { max-height:58vh; overflow:auto; }
.exploration-signature-table { width:100%; font-size:.88rem; border-collapse:collapse; }
.exploration-signature-table th,.exploration-signature-table td { padding:.45rem; border-bottom:1px solid #26364b; text-align:left; vertical-align:top; }
.exploration-signature-table th { color:#9eafc5; position:sticky; }
.exploration-scan-group { border:1px solid #263b55; border-radius:5px; margin-bottom:.75rem; background:#0b1421; }
.exploration-scan-group summary { cursor:pointer; padding:.8rem 1rem; color:#dbe5f1; font-weight:600; list-style:revert; }
.exploration-scan-group summary:hover { color:#55b9ff; }
.exploration-scan-group .table-responsive { padding:0 .75rem .75rem; }
.exploration-sort { border:0; padding:0; background:transparent; color:inherit; font:inherit; font-weight:600; cursor:pointer; }
.exploration-sort:hover, .exploration-sort:focus { color:#fff; text-decoration:underline; outline:none; }
.exploration-sort span { display:inline-block; min-width:.8em; margin-left:.2rem; color:#55b9ff; }
.exploration-signature-table th { color:#9eafc5; position:sticky; top:0; background:#111a29; }
#exploration-scan-input { min-height:280px; background:#08111e; color:#e5edf7; border-color:#52657e; font-family:monospace; }
@media(max-width:760px) { .exploration-modal-body { grid-template-columns:1fr; } .exploration-signature-list { max-height:35vh; } }

@media (max-width: 767px) { .exploration-legend { margin-bottom: 6px; } }
</style>

@if(!$mapError)
<script>
(function () {
    var root = document.querySelector('.exploration-card');
    if (!root || root.dataset.explorationBound === '1') return;
    root.dataset.explorationBound = '1';
    var csrf = @json(csrf_token());

    function setSystemStatus(node, status) {
        node.dataset.status = status;
        node.classList.remove('is-white', 'is-green', 'is-red');
        node.classList.add('is-' + status);
        node.setAttribute('aria-pressed', status === 'green' ? 'true' : 'false');
        node.setAttribute('aria-label', node.dataset.systemName + ': ' + (status === 'green' ? 'explored' : 'unexplored'));
    }

    function updateCount(region) {
        var pane = root.querySelector('#exploration-' + region.toLowerCase());
        if (!pane) return;
        var count = pane.querySelectorAll('.exploration-system[data-status="green"]').length;
        var target = pane.querySelector('.exploration-green-count');
        if (target) target.textContent = count.toLocaleString();
    }

    function activateRegion(region) {
        var tab = root.querySelector('#exploration-' + region.toLowerCase() + '-tab');
        if (tab) {
            if (window.jQuery && window.jQuery.fn && window.jQuery.fn.tab) window.jQuery(tab).tab('show');
            else tab.click();
        }
    }

    function mapApi(svg) {
        var wrap = svg.closest('.exploration-map-wrap');
        var initial = (svg.getAttribute('viewBox') || '0 0 1200 800').trim().split(/[ ,]+/).map(Number);
        if (initial.length !== 4 || !initial.every(Number.isFinite)) initial = [0, 0, 1200, 800];
        var view = { x: initial[0], y: initial[1], w: initial[2], h: initial[3] };
        var minW = Math.max(80, view.w * 0.12), maxW = view.w;
        function apply() { svg.setAttribute('viewBox', [view.x, view.y, view.w, view.h].join(' ')); }
        function point(event) {
            var rect = svg.getBoundingClientRect();
            return { x: view.x + ((event.clientX - rect.left) / rect.width) * view.w,
                     y: view.y + ((event.clientY - rect.top) / rect.height) * view.h };
        }
        wrap.addEventListener('wheel', function (event) {
            event.preventDefault();
            var at = point(event);
            var factor = event.deltaY < 0 ? 0.82 : 1.22;
            var nextW = Math.max(minW, Math.min(maxW, view.w * factor));
            var ratio = nextW / view.w;
            var nextH = view.h * ratio;
            view.x = at.x - (at.x - view.x) * ratio;
            view.y = at.y - (at.y - view.y) * ratio;
            view.w = nextW; view.h = nextH; apply();
        }, { passive: false });
        var drag = null;
        wrap.addEventListener('pointerdown', function (event) {
            if (event.target.closest('.exploration-system')) return;
            drag = { x: event.clientX, y: event.clientY, vx: view.x, vy: view.y };
            wrap.classList.add('is-dragging'); wrap.setPointerCapture(event.pointerId);
        });
        wrap.addEventListener('pointermove', function (event) {
            if (!drag) return;
            var rect = svg.getBoundingClientRect();
            view.x = drag.vx - ((event.clientX - drag.x) / rect.width) * view.w;
            view.y = drag.vy - ((event.clientY - drag.y) / rect.height) * view.h;
            apply();
        });
        function endDrag() { drag = null; wrap.classList.remove('is-dragging'); }
        wrap.addEventListener('pointerup', endDrag);
        wrap.addEventListener('pointercancel', endDrag);
        return {
            center: function (node) {
                // Convert the marker's local center into the root SVG's user
                // coordinate space. getScreenCTM() includes the current viewBox
                // scale/translation, so invert the root matrix before applying
                // the marker matrix; using marker.getCTM() alone can feed screen
                // pixels back into the viewBox and fling the map to a corner.
                var marker = node.querySelector('.s') || node;
                var bounds = marker.getBBox();
                var localCenter = svg.createSVGPoint();
                localCenter.x = bounds.x + bounds.width / 2;
                localCenter.y = bounds.y + bounds.height / 2;
                var rootMatrix = svg.getScreenCTM();
                var markerMatrix = marker.getScreenCTM();
                if (!rootMatrix || !markerMatrix) return;
                var matrix = rootMatrix.inverse().multiply(markerMatrix);
                var center = localCenter.matrixTransform(matrix);
                view.w = initial[2] * 0.42;
                view.h = initial[3] * 0.42;
                view.x = center.x - view.w / 2;
                view.y = center.y - view.h / 2;
                apply();
            }
        };
    }
    var mapApis = {};
    root.querySelectorAll('.exploration-map').forEach(function (svg) {
        mapApis[svg.closest('.tab-pane').id] = mapApi(svg);
    });

    var lastCenteredSystemId = null;
    function updatePlayerLocation() {
        var status = root.querySelector('#exploration-live-status');
        fetch(@json(url('/exploration/player-location')), {
            method: 'GET',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store'
        }).then(function (response) {
            if (!response.ok) throw new Error('Location request failed.');
            return response.json();
        }).then(function (data) {
            root.querySelectorAll('.exploration-system.is-player-location').forEach(function (node) {
                node.classList.remove('is-player-location');
                var dot = node.querySelector('.exploration-player-dot');
                if (dot) dot.remove();
                var original = node.getAttribute('aria-label') || '';
                node.setAttribute('aria-label', original.replace(/, current location$/, ''));
            });

            if (data.status === 'located') {
                var target = null;
                root.querySelectorAll('.exploration-system').forEach(function (node) {
                    if (node.dataset.systemId === String(data.solar_system_id)) target = node;
                });
                if (target) {
                    var pane = target.closest('.tab-pane');
                    var region = pane.id.replace('exploration-', '');
                    target.classList.add('is-player-location');
                    var marker = target.querySelector('.s') || target;
                    var bounds = marker.getBBox();
                    var dot = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                    dot.setAttribute('class', 'exploration-player-dot');
                    dot.setAttribute('cx', String(bounds.x + bounds.width / 2));
                    dot.setAttribute('cy', String(bounds.y + bounds.height / 2));
                    dot.setAttribute('r', String(Math.max(bounds.width, bounds.height) * 0.72));
                    target.appendChild(dot);
                    target.setAttribute('aria-label', (target.getAttribute('aria-label') || '') + ', current location');
                    if (status) status.textContent = 'Live location: ' + (data.character_name || 'Main character') + ' in ' + (target.dataset.systemName || 'unknown system');
                    // Recenter only when the character actually changes systems.
                    // Repeated 15-second location refreshes must not yank the map
                    // away while the user is inspecting or panning it.
                    var currentSystemId = String(data.solar_system_id);
                    if (lastCenteredSystemId !== currentSystemId) {
                        if (pane.classList.contains('active') && mapApis[pane.id]) mapApis[pane.id].center(target);
                        lastCenteredSystemId = currentSystemId;
                    }
                } else if (status) {
                    status.textContent = 'Live location: ' + (data.character_name || 'Main character') + ' is outside Branch, Tenal and Venal';
                }
            } else if (status) {
                status.textContent = data.message || 'Live location: unavailable';
            }
        }).catch(function () {
            if (status) status.textContent = 'Live location: temporarily unavailable';
        });
    }

    function toggleSystem(node) {
        if (node.dataset.status === 'red' || node.dataset.busy === '1') return Promise.reject(new Error('This system cannot be changed.'));
        var pane = node.closest('.tab-pane');
        var region = pane.id.replace('exploration-', '');
        node.dataset.busy = '1';
        return fetch(@json(url('/exploration')) + '/' + encodeURIComponent(region.charAt(0).toUpperCase() + region.slice(1)) + '/systems/' + node.dataset.systemId + '/toggle', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            if (!response.ok) throw new Error('Could not update this system.');
            return response.json();
        }).then(function (data) {
            setSystemStatus(node, data.status);
            updateCount(region);
            return data;
        }).catch(function (error) {
            window.alert(error.message || 'Could not update this system.');
            throw error;
        }).finally(function () { delete node.dataset.busy; });
    }

    var signatureModal = root.querySelector('#exploration-signature-modal');
    if (signatureModal && signatureModal.parentNode !== document.body) document.body.appendChild(signatureModal);
    var selectedSystem = null;
    function renderSignatures(items) {
        var list = signatureModal.querySelector('#exploration-signature-list');
        signatureModal._signatureItems = items || [];
        if (!items || !items.length) { list.textContent = 'No signatures recorded for this system yet.'; return; }
        var table = document.createElement('table');
        table.className = 'exploration-signature-table';
        var head = document.createElement('thead');
        head.innerHTML = '<tr><th>ID</th><th>Group</th><th>Type / Name</th><th>Strength</th></tr>';
        table.appendChild(head);
        var body = document.createElement('tbody');
        items.forEach(function (item) {
            var tr = document.createElement('tr');
            [item.signature_id, item.group_name].forEach(function (value) {
                var td = document.createElement('td'); td.textContent = value == null ? '' : String(value); tr.appendChild(td);
            });
            var typeCell = document.createElement('td');
            typeCell.appendChild(document.createTextNode([item.type_name, item.signature_name].filter(Boolean).join(' — ')));
            // Every signature gets a notes action. EVE's scanner often leaves
            // wormholes unresolved, so filtering by a "Wormhole" name hides the
            // exact signatures users need to annotate.
            var noteButton = document.createElement('button');
            noteButton.type = 'button';
            noteButton.className = 'exploration-wormhole-note';
            noteButton.title = 'Add or edit wormhole / signature notes';
            noteButton.setAttribute('aria-label', 'Add or edit notes for ' + item.signature_id);
            noteButton.dataset.signatureId = item.signature_id;
            noteButton.innerHTML = '<i class="far fa-sticky-note"></i> Notes';
            typeCell.appendChild(noteButton);
            if (item.wormhole_type || item.connection_system || item.wormhole_exits) {
                var summary = document.createElement('span');
                summary.className = 'exploration-wormhole-summary';
                summary.textContent = [item.wormhole_type ? 'Type: ' + item.wormhole_type : '', item.connection_system ? 'Connection: ' + item.connection_system : '', item.wormhole_exits ? 'Exits: ' + item.wormhole_exits : ''].filter(Boolean).join(' · ');
                typeCell.appendChild(summary);
            }
            tr.appendChild(typeCell);
            var strengthCell = document.createElement('td');
            strengthCell.textContent = item.signal_strength == null ? '' : String(item.signal_strength) + '%';
            tr.appendChild(strengthCell);
            body.appendChild(tr);
        });
        table.appendChild(body); list.replaceChildren(table);
    }
    var wormholeEditor = signatureModal.querySelector('#exploration-wormhole-editor');
    var selectedWormholeSignature = null;
    function openWormholeEditor(itemButton) {
        var signatureId = itemButton.dataset.signatureId;
        var row = itemButton.closest('tr');
        var cells = row ? row.querySelectorAll('td') : [];
        selectedWormholeSignature = signatureId;
        wormholeEditor.querySelector('#exploration-wormhole-title').textContent = 'Wormhole notes · ' + signatureId;
        wormholeEditor.querySelector('#exploration-wormhole-meta').textContent = signatureModal.dataset.region + ' · ' + signatureModal.querySelector('#exploration-modal-title').textContent;
        wormholeEditor.querySelector('#exploration-wormhole-type').value = '';
        wormholeEditor.querySelector('#exploration-connection-system').value = '';
        wormholeEditor.querySelector('#exploration-wormhole-exits').value = '';
        wormholeEditor.querySelector('#exploration-wormhole-message').textContent = '';
        // Populate from the signature data retained on the row's note button.
        wormholeEditor.querySelector('#exploration-wormhole-type').value = itemButton.dataset.wormholeType || '';
        wormholeEditor.querySelector('#exploration-connection-system').value = itemButton.dataset.connectionSystem || '';
        wormholeEditor.querySelector('#exploration-wormhole-exits').value = itemButton.dataset.wormholeExits || '';
        wormholeEditor.classList.add('is-open');
        wormholeEditor.setAttribute('aria-hidden', 'false');
    }
    function closeWormholeEditor() {
        wormholeEditor.classList.remove('is-open');
        wormholeEditor.setAttribute('aria-hidden', 'true');
        selectedWormholeSignature = null;
    }
    wormholeEditor.querySelector('#exploration-wormhole-close').addEventListener('click', closeWormholeEditor);
    wormholeEditor.querySelector('#exploration-wormhole-save').addEventListener('click', function () {
        if (!selectedWormholeSignature) return;
        var button = this;
        var message = wormholeEditor.querySelector('#exploration-wormhole-message');
        button.disabled = true; message.textContent = 'Saving…';
        var region = signatureModal.dataset.region;
        var systemId = signatureModal.dataset.systemId;
        fetch(@json(url('/exploration')) + '/' + region + '/systems/' + systemId + '/signatures/' + encodeURIComponent(selectedWormholeSignature) + '/wormhole-notes', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                wormhole_type: wormholeEditor.querySelector('#exploration-wormhole-type').value,
                connection_system: wormholeEditor.querySelector('#exploration-connection-system').value,
                wormhole_exits: wormholeEditor.querySelector('#exploration-wormhole-exits').value
            })
        }).then(function (response) {
            return response.json().then(function (data) { if (!response.ok) throw new Error(data.message || 'Could not save wormhole notes.'); return data; });
        }).then(function () {
            message.textContent = 'Wormhole notes saved.';
            return fetch(@json(url('/exploration')) + '/' + region + '/systems/' + systemId + '/signatures', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
        }).then(function (response) { if (!response.ok) throw new Error('Saved, but could not refresh signatures.'); return response.json(); })
          .then(function (data) { renderSignatures(data.signatures); closeWormholeEditor(); })
          .catch(function (error) { message.textContent = error.message; })
          .finally(function () { button.disabled = false; });
    });
    signatureModal.querySelector('#exploration-signature-list').addEventListener('click', function (event) {
        var noteButton = event.target.closest('.exploration-wormhole-note');
        if (!noteButton) return;
        event.preventDefault();
        var signatureId = noteButton.dataset.signatureId;
        var rowData = (signatureModal._signatureItems || []).find(function (item) { return item.signature_id === signatureId; });
        noteButton.dataset.wormholeType = rowData ? (rowData.wormhole_type || '') : '';
        noteButton.dataset.connectionSystem = rowData ? (rowData.connection_system || '') : '';
        noteButton.dataset.wormholeExits = rowData ? (rowData.wormhole_exits || '') : '';
        openWormholeEditor(noteButton);
    });
    function loadSignatures(node) {
        selectedSystem = node;
        var region = node.closest('.tab-pane').id.replace('exploration-', '');
        region = region.charAt(0).toUpperCase() + region.slice(1);
        signatureModal.dataset.region = region;
        signatureModal.dataset.systemId = node.dataset.systemId;
        signatureModal.querySelector('#exploration-modal-title').textContent = node.dataset.systemName + ' signatures';
        signatureModal.querySelector('#exploration-modal-status').textContent = node.dataset.status === 'green' ? 'Clear explored' : 'Mark explored';
        signatureModal.querySelector('#exploration-modal-meta').textContent = region + ' · Loading scan…';
        signatureModal.querySelector('#exploration-signature-list').textContent = 'Loading…';
        signatureModal.querySelector('#exploration-scan-input').value = '';
        signatureModal.querySelector('#exploration-scan-message').textContent = '';
        signatureModal.classList.add('is-open'); signatureModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        fetch(@json(url('/exploration')) + '/' + region + '/systems/' + node.dataset.systemId + '/signatures', {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) { if (!response.ok) throw new Error('Could not load this system scan.'); return response.json(); })
          .then(function (data) {
              renderSignatures(data.signatures);
              signatureModal.querySelector('#exploration-modal-meta').textContent = region + ' · ' + data.signatures.length + ' signatures' + (data.scanned_at ? ' · Last scanned ' + data.scanned_at : '');
          }).catch(function (error) { signatureModal.querySelector('#exploration-signature-list').textContent = error.message; });
    }
    function closeSignatureModal() {
        signatureModal.classList.remove('is-open'); signatureModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = ''; selectedSystem = null;
    }
    signatureModal.querySelector('#exploration-modal-close').addEventListener('click', closeSignatureModal);
    signatureModal.querySelector('#exploration-modal-status').addEventListener('click', function () {
        if (!selectedSystem || selectedSystem.dataset.status === 'red') return;
        var button = this; button.disabled = true;
        toggleSystem(selectedSystem).then(function () {
            button.textContent = selectedSystem.dataset.status === 'green' ? 'Clear explored' : 'Mark explored';
        }).catch(function () {}).finally(function () { button.disabled = false; });
    });
    signatureModal.addEventListener('click', function (event) { if (event.target === signatureModal) closeSignatureModal(); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && signatureModal.classList.contains('is-open')) closeSignatureModal(); });
    function refreshCurrentScan(messageText, clearInput) {
        var input = signatureModal.querySelector('#exploration-scan-input');
        return fetch(@json(url('/exploration')) + '/' + signatureModal.dataset.region + '/systems/' + signatureModal.dataset.systemId + '/signatures', {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store'
        }).then(function (response) {
            if (!response.ok) throw new Error('Saved, but could not refresh the displayed scan.');
            return response.json();
        }).then(function (data) {
            renderSignatures(data.signatures);
            signatureModal.querySelector('#exploration-modal-meta').textContent =
                signatureModal.dataset.region + ' · ' + data.signatures.length + ' signatures · Last scanned ' + (data.scanned_at || 'just now');
            if (clearInput) input.value = '';
            signatureModal.querySelector('#exploration-scan-message').textContent = messageText;
        });
    }

    function submitSignatureScan(mode, button) {
        if (!selectedSystem) return;
        var input = signatureModal.querySelector('#exploration-scan-input');
        var message = signatureModal.querySelector('#exploration-scan-message');
        if (!input.value.trim()) {
            message.textContent = 'Paste the new scan before submitting.';
            return;
        }
        button.disabled = true;
        message.textContent = mode === 'add' ? 'Adding signatures to the existing scan…' : 'Replacing all saved signatures…';
        fetch(@json(url('/exploration')) + '/' + signatureModal.dataset.region + '/systems/' + signatureModal.dataset.systemId + '/signatures', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ scan_data: input.value, mode: mode })
        }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok) throw new Error(data.message || 'Could not save scan.');
                return data;
            });
        }).then(function (data) {
            var summary = mode === 'add'
                ? 'Added/updated ' + data.count + ' signatures. Existing signatures were retained.'
                : 'Saved ' + data.count + ' signatures. Previous scan replaced.';
            return refreshCurrentScan(summary, true);
        }).catch(function (error) {
            message.textContent = error.message;
        }).finally(function () {
            button.disabled = false;
        });
    }

    signatureModal.querySelector('#exploration-scan-submit').addEventListener('click', function () {
        submitSignatureScan('replace', this);
    });
    signatureModal.querySelector('#exploration-scan-add').addEventListener('click', function () {
        submitSignatureScan('add', this);
    });
    signatureModal.querySelector('#exploration-scan-timestamp').addEventListener('click', function () {
        if (!selectedSystem) return;
        var button = this;
        var message = signatureModal.querySelector('#exploration-scan-message');
        button.disabled = true;
        message.textContent = 'Updating last-scanned date and time only…';
        fetch(@json(url('/exploration')) + '/' + signatureModal.dataset.region + '/systems/' + signatureModal.dataset.systemId + '/scanned-empty', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok) throw new Error(data.message || 'Could not update scan time.');
                return data;
            });
        }).then(function (data) {
            return refreshCurrentScan('Last-scanned date and time updated. Existing signatures were not changed.', false);
        }).catch(function (error) {
            message.textContent = error.message;
        }).finally(function () {
            button.disabled = false;
        });
    });
    // Left-click toggles explored state; right-click opens signatures.
    root.addEventListener('click', function (event) {
        var anchor = event.target.closest('a');
        if (anchor) event.preventDefault();
        var node = event.target.closest('.exploration-system');
        if (!node || !root.contains(node)) return;
        event.preventDefault();
        event.stopPropagation();
        if (node.dataset.status === 'red') return;
        toggleSystem(node).catch(function () {});
    }, true);
    root.addEventListener('contextmenu', function (event) {
        var node = event.target.closest('.exploration-system');
        if (!node || !root.contains(node)) return;
        event.preventDefault();
        event.stopPropagation();
        if (node.dataset.status === 'red') return;
        loadSignatures(node);
    }, true);
    root.querySelectorAll('.exploration-system').forEach(function (node) {
        node.style.cursor = node.dataset.status === 'red' ? 'not-allowed' : 'pointer';
        node.setAttribute('title', node.dataset.status === 'red'
            ? node.dataset.systemName + ' — permanently excluded'
            : node.dataset.systemName + ' — left-click to toggle explored; right-click for signatures');
        node.addEventListener('keydown', function (event) {
            if (node.dataset.status === 'red') return;
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                if (event.shiftKey) loadSignatures(node);
                else toggleSystem(node).catch(function () {});
            }
        });
    });
    function loadSystemInformation() {
        var status = root.querySelector('#exploration-system-info-status');
        var content = root.querySelector('#exploration-system-info-content');
        if (!status || !content) return;
        status.textContent = 'Checking your main character location…';
        content.replaceChildren();
        fetch(@json(url('/exploration/player-location')), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store'
        }).then(function (response) { if (!response.ok) throw new Error('Could not retrieve live location.'); return response.json(); })
          .then(function (location) {
              if (location.status !== 'located') throw new Error(location.message || 'Your main character location is unavailable.');
              var systemNode = null;
              root.querySelectorAll('.exploration-system').forEach(function (node) {
                  if (node.dataset.systemId === String(location.solar_system_id)) systemNode = node;
              });
              if (!systemNode) throw new Error('Your character is in ' + (location.solar_system_name || 'a system') + ', outside the Branch, Tenal, and Venal maps.');
              var region = systemNode.closest('.tab-pane').id.replace('exploration-', '');
              region = region.charAt(0).toUpperCase() + region.slice(1);
              var heading = document.createElement('h5');
              heading.textContent = systemNode.dataset.systemName + ' · ' + region;
              content.appendChild(heading);
              var character = document.createElement('p');
              character.className = 'text-muted';
              character.textContent = 'Main character: ' + (location.character_name || 'Unknown') + ' · Live system location';
              content.appendChild(character);
              return fetch(@json(url('/exploration')) + '/' + region + '/systems/' + location.solar_system_id + '/signatures', {
                  headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
              });
          }).then(function (response) {
              if (!response) return null;
              if (!response.ok) throw new Error('Could not load signatures for your current system.');
              return response.json();
          }).then(function (data) {
              if (!data) return;
              var content = root.querySelector('#exploration-system-info-content');
              var title = document.createElement('h5');
              title.textContent = 'Recorded signatures and wormholes';
              content.appendChild(title);
              var wormholes = (data.signatures || []).filter(function (item) {
                  return [item.group_name, item.type_name, item.signature_name].filter(Boolean).join(' ').toLowerCase().indexOf('wormhole') !== -1
                      || (item.group_name || '').toLowerCase().indexOf('cosmic signature') !== -1;
              });
              if (!wormholes.length) {
                  var empty = document.createElement('p');
                  empty.className = 'text-muted';
                  empty.textContent = 'No wormhole or unresolved cosmic-signature records are saved for this system yet.';
                  content.appendChild(empty);
              }
              wormholes.forEach(function (item) {
                  var card = document.createElement('div');
                  card.className = 'card mb-2';
                  card.style.cssText = 'background:#0b1421;border:1px solid #263b55;color:#dbe5f1';
                  var body = document.createElement('div');
                  body.className = 'card-body';
                  var name = document.createElement('strong');
                  name.textContent = item.signature_id + ' · ' + [item.type_name, item.signature_name].filter(Boolean).join(' — ');
                  body.appendChild(name);
                  var details = document.createElement('p');
                  details.className = 'mb-0 mt-2 text-muted';
                  details.style.whiteSpace = 'pre-line';
                  details.textContent = [
                      item.wormhole_type ? 'Wormhole type: ' + item.wormhole_type : 'Wormhole type: not entered',
                      item.connection_system ? 'Connection system: ' + item.connection_system : 'Connection system: not entered',
                      item.wormhole_exits ? 'Added exits: ' + item.wormhole_exits : 'Added exits: none entered'
                  ].join('\n');
                  body.appendChild(details);
                  card.appendChild(body);
                  content.appendChild(card);
              });
              status.textContent = 'System information refreshed from live location and saved scan data.';
          }).catch(function (error) { status.textContent = error.message; });
    }


    var scanRows = [];
    var scanSort = { key: 'scanned_at', direction: 'desc' };
    var scanRegions = ['Branch', 'Tenal', 'Venal'];
    function scanTimestamp(value) {
        if (!value) return null;
        var date = new Date(String(value).replace(' ', 'T'));
        return isNaN(date.getTime()) ? null : date;
    }
    function sortedScanRows(rows) {
        return rows.slice().sort(function (a, b) {
            var av, bv;
            if (scanSort.key === 'scanned_at') {
                av = scanTimestamp(a.scanned_at); bv = scanTimestamp(b.scanned_at);
                av = av ? av.getTime() : (scanSort.direction === 'asc' ? Number.POSITIVE_INFINITY : Number.NEGATIVE_INFINITY);
                bv = bv ? bv.getTime() : (scanSort.direction === 'asc' ? Number.POSITIVE_INFINITY : Number.NEGATIVE_INFINITY);
            } else if (scanSort.key === 'signature_count') {
                av = Number(a.signature_count || 0); bv = Number(b.signature_count || 0);
            } else {
                var field = scanSort.key === 'system' ? 'system_name' : 'scan_status';
                av = String(a[field] || '').toLowerCase(); bv = String(b[field] || '').toLowerCase();
            }
            var result = av < bv ? -1 : (av > bv ? 1 : 0);
            return result * (scanSort.direction === 'asc' ? 1 : -1);
        });
    }
    function renderLastScanned() {
        var groups = root.querySelector('#exploration-last-scanned-groups');
        if (!groups) return;
        groups.replaceChildren();
        scanRegions.forEach(function (region) {
            var items = sortedScanRows(scanRows.filter(function (item) { return item.region_name === region; }));
            var details = document.createElement('details');
            details.className = 'exploration-scan-group';
            details.open = false;
            var summary = document.createElement('summary');
            var scannedCount = items.filter(function (item) { return !!item.scanned_at; }).length;
            summary.textContent = region + ' — ' + scannedCount + ' of ' + items.length + ' systems scanned';
            details.appendChild(summary);
            var wrap = document.createElement('div');
            wrap.className = 'table-responsive';
            var table = document.createElement('table');
            table.className = 'table table-sm exploration-signature-table mb-0';
            var head = document.createElement('thead');
            var header = document.createElement('tr');
            [
                ['system', 'System'],
                ['scanned_at', 'Last Scanned'],
                ['signature_count', 'Signatures'],
                ['scan_status', 'Scan Status']
            ].forEach(function (definition) {
                var th = document.createElement('th');
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'exploration-sort';
                button.dataset.sort = definition[0];
                button.textContent = definition[1] + (scanSort.key === definition[0] ? (scanSort.direction === 'asc' ? ' ▲' : ' ▼') : '');
                button.setAttribute('aria-sort', scanSort.key === definition[0] ? (scanSort.direction === 'asc' ? 'ascending' : 'descending') : 'none');
                th.appendChild(button); header.appendChild(th);
            });
            head.appendChild(header); table.appendChild(head);
            var body = document.createElement('tbody');
            items.forEach(function (item) {
                var row = document.createElement('tr');
                function cell(value) { var td = document.createElement('td'); td.textContent = value == null || value === '' ? '—' : String(value); row.appendChild(td); }
                var date = scanTimestamp(item.scanned_at);
                cell(item.system_name);
                cell(date ? date.toLocaleString() : 'Never');
                cell(item.signature_count == null ? 0 : item.signature_count);
                cell(item.scan_status === 'timestamp_only' ? 'Timestamp only' : (item.scan_status === 'with_signatures' ? 'Signatures recorded' : 'Never scanned'));
                body.appendChild(row);
            });
            if (!items.length) { var row=document.createElement('tr'), td=document.createElement('td'); td.colSpan=4; td.className='text-muted'; td.textContent='No systems in this region.'; row.appendChild(td); body.appendChild(row); }
            table.appendChild(body); wrap.appendChild(table); details.appendChild(wrap); groups.appendChild(details);
        });
    }
    function loadLastScanned() {
        var status=root.querySelector('#exploration-last-scanned-status'), groups=root.querySelector('#exploration-last-scanned-groups');
        if(!status||!groups) return;
        status.textContent='Loading scan history…';
        fetch(@json(url('/exploration/system-scans')), { headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}, cache:'no-store' })
          .then(function(response){ if(!response.ok) throw new Error('Could not load system scan history.'); return response.json(); })
          .then(function(data){ scanRows=data.systems||[]; renderLastScanned(); var scanned=scanRows.filter(function(item){return !!item.scanned_at;}).length; status.textContent=scanned+' of '+scanRows.length+' eligible systems have a recorded scan. Permanently excluded systems are omitted.'; })
          .catch(function(error){ status.textContent=error.message; groups.textContent=error.message; });
    }
    root.addEventListener('click', function(event) {
        var button = event.target.closest('.exploration-sort');
        if (!button || !root.contains(button)) return;
        var key=button.dataset.sort;
        if(scanSort.key===key) scanSort.direction=scanSort.direction==='asc'?'desc':'asc';
        else {scanSort.key=key;scanSort.direction=key==='scanned_at'?'desc':'asc';}
        renderLastScanned();
    });
    var lastScannedTab=root.querySelector('#exploration-last-scanned-tab');
    if(lastScannedTab) lastScannedTab.addEventListener('click',function(event){
        event.preventDefault();
        root.querySelectorAll('#exploration-region-tabs .nav-link').forEach(function(link){link.classList.remove('active');link.setAttribute('aria-selected','false');});
        lastScannedTab.classList.add('active');lastScannedTab.setAttribute('aria-selected','true');
        root.querySelectorAll('.tab-content > .tab-pane').forEach(function(pane){pane.classList.remove('show','active');});
        var pane=root.querySelector('#exploration-last-scanned');if(pane)pane.classList.add('show','active');
        loadLastScanned();
    });
    var lastScannedRefresh=root.querySelector('#exploration-last-scanned-refresh');
    if(lastScannedRefresh) lastScannedRefresh.addEventListener('click',loadLastScanned);

    var infoTab = root.querySelector('#exploration-system-information-tab');
    if (infoTab) infoTab.addEventListener('click', function (event) {
        event.preventDefault();
        // Switch panes directly as a fallback if the host page's Bootstrap tab
        // plugin is unavailable or its delegated handler does not initialize.
        root.querySelectorAll('#exploration-region-tabs .nav-link').forEach(function (link) {
            link.classList.remove('active');
            link.setAttribute('aria-selected', 'false');
        });
        infoTab.classList.add('active');
        infoTab.setAttribute('aria-selected', 'true');
        root.querySelectorAll('.tab-content > .tab-pane').forEach(function (pane) {
            pane.classList.remove('show', 'active');
        });
        var pane = root.querySelector('#exploration-system-information');
        if (pane) {
            pane.classList.add('show', 'active');
        } else {
            var fallback = root.querySelector('#exploration-system-information-fallback');
            if (fallback) fallback.style.display = 'block';
        }
        loadSystemInformation();
    });

    updatePlayerLocation();
    var locationTimer = window.setInterval(function () {
        if (!document.hidden) updatePlayerLocation();
    }, 15000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) updatePlayerLocation();
    });

    root.querySelectorAll('.exploration-reset').forEach(function (button) {
        button.addEventListener('click', function () {
            var region = button.dataset.region;
            if (!window.confirm('Reset all green explored systems in ' + region + '? Red systems will remain red.')) return;
            button.disabled = true;
            fetch(@json(url('/exploration')) + '/' + encodeURIComponent(region) + '/reset', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                if (!response.ok) throw new Error('Could not reset this region.');
                return response.json();
            }).then(function () {
                var pane = root.querySelector('#exploration-' + region.toLowerCase());
                pane.querySelectorAll('.exploration-system[data-status="green"]').forEach(function (node) {
                    setSystemStatus(node, 'white');
                });
                updateCount(region.toLowerCase());
            }).catch(function (error) {
                window.alert(error.message || 'Could not reset this region.');
            }).finally(function () {
                button.disabled = false;
            });
        });
    });
})();
</script>
@endif
@endsection
