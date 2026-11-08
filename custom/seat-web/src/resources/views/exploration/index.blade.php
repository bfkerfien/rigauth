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
    </ul>
  </div>
  <div class="card-body">
    @if($mapError)
      <div class="alert alert-warning">{{ $mapError }}</div>
    @else
      <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div class="small text-muted mb-2 mb-md-0">
          <span class="exploration-legend"><span class="exploration-legend-dot white"></span> Unexplored</span>
          <span class="exploration-legend"><span class="exploration-legend-dot green"></span> Explored</span>
          <span class="exploration-legend"><span class="exploration-legend-dot red"></span> Permanently excluded</span>
          <span class="exploration-legend"><span class="exploration-legend-dot gold"></span> Your live location</span>
          <span id="exploration-live-status" class="ml-2">Live location: connecting…</span>
        </div>
        <div class="small text-muted">Select a white system to mark it explored. Select a green system to clear it.</div>
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
              <svg class="exploration-map" viewBox="0 0 1200 800" role="img" aria-label="{{ $region }} solar system map. Click a white or green system to change its exploration status.">
                <defs>
                  <pattern id="exploration-grid-{{ strtolower($region) }}" width="48" height="48" patternUnits="userSpaceOnUse">
                    <path d="M 48 0 L 0 0 0 48" fill="none" stroke="#17263a" stroke-width="1"/>
                    <circle cx="1" cy="1" r="1" fill="#31435b"/>
                  </pattern>
                </defs>
                <rect x="0" y="0" width="1200" height="800" fill="#070d18"/>
                <rect x="0" y="0" width="1200" height="800" fill="url(#exploration-grid-{{ strtolower($region) }})"/>
                <g class="exploration-dotlan-grid" aria-hidden="true">
                  @for($column = 0; $column <= 8; $column++)
                    <line x1="{{ 58 + ($column * 135.5) }}" y1="42" x2="{{ 58 + ($column * 135.5) }}" y2="744"/>
                    @if($column < 8)
                      <text x="{{ 125.75 + ($column * 135.5) }}" y="766">{{ $column + 1 }}</text>
                    @endif
                  @endfor
                  @foreach(['A','B','C','D','E','F'] as $rowLabel)
                    <text x="24" y="{{ 105 + ($loop->index * 116) }}">{{ $rowLabel }}</text>
                  @endforeach
                  @for($row = 0; $row <= 6; $row++)
                    <line x1="58" y1="{{ 52 + ($row * 116) }}" x2="1142" y2="{{ 52 + ($row * 116) }}"/>
                  @endfor
                </g>
                <g class="exploration-map-links">
                  @foreach($map['links'] as $link)
                    <line x1="{{ $link['x1'] }}" y1="{{ $link['y1'] }}" x2="{{ $link['x2'] }}" y2="{{ $link['y2'] }}"/>
                  @endforeach
                </g>
                <g class="exploration-map-systems">
                  @foreach($map['systems'] as $system)
                    <g class="exploration-system is-{{ $system['status'] }} {{ $system['status'] === 'red' ? 'is-locked' : '' }}"
                       data-system-id="{{ $system['id'] }}" data-system-name="{{ $system['name'] }}"
                       data-status="{{ $system['status'] }}" tabindex="{{ $system['status'] === 'red' ? '-1' : '0' }}"
                       role="{{ $system['status'] === 'red' ? 'img' : 'button' }}"
                       aria-label="{{ $system['name'] }}: {{ $system['status'] === 'red' ? 'permanently excluded' : ($system['status'] === 'green' ? 'explored' : 'unexplored') }}"
                       aria-pressed="{{ $system['status'] === 'green' ? 'true' : 'false' }}"
                       transform="translate({{ $system['x'] }}, {{ $system['y'] }})">
                      @php($nodeWidth = max(54, (strlen($system['name']) * 7) + 18))
                      <rect class="exploration-system-marker" x="{{ -($nodeWidth / 2) }}" y="-12" width="{{ $nodeWidth }}" height="24" rx="9"/>
                      <text x="0" y="4" text-anchor="middle">{{ $system['name'] }}</text>
                    </g>
                  @endforeach
                </g>
                <text x="24" y="770" class="exploration-map-caption">{{ $region }} · {{ count($map['systems']) }} solar systems · {{ count($map['links']) }} stargate connections</text>
              </svg>
            </div>
          </div>
        @endforeach
      </div>
    @endif
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
.exploration-map-links line { stroke: #344b67; stroke-width: 1.35; opacity: .82; }
.exploration-dotlan-grid line { stroke: #26364a; stroke-width: 1; opacity: .7; }
.exploration-dotlan-grid text { fill: #71839a; font: 12px Arial, sans-serif; text-anchor: middle; }
.exploration-system { cursor: pointer; outline: none; }
.exploration-system-marker { fill: #e8eef7; stroke: #8194ad; stroke-width: 1.3; transition: fill .12s ease, stroke .12s ease; }
.exploration-system text { fill: #101a29; font: 11px Arial, sans-serif; font-weight: 700; pointer-events: none; user-select: none; }
.exploration-system:hover .exploration-system-marker, .exploration-system:focus .exploration-system-marker { stroke: #55b9ff; stroke-width: 2.5; }
.exploration-system.is-green .exploration-system-marker { fill: #31c66b; stroke: #15964a; }
.exploration-system.is-green text { fill: #092516; font-weight: 700; }
.exploration-system.is-red { cursor: not-allowed; }
.exploration-system.is-red .exploration-system-marker { fill: #e05252; stroke: #a92f38; }
.exploration-system.is-red text { fill: #fff; font-weight: 700; }
.exploration-system.is-locked:hover .exploration-system-marker, .exploration-system.is-locked:focus .exploration-system-marker { stroke: #e05252; }
.exploration-system.is-player-location .exploration-system-marker { stroke: #f2b632; stroke-width: 3.5; }
.exploration-player-dot { fill: #f2b632; stroke: #fff; stroke-width: 1.5; pointer-events: none; }
.exploration-map-caption { fill: #6f829d; font: 12px Arial, sans-serif; }
.exploration-card .btn-outline-secondary { color: #bdcadd; border-color: #52657e; }
.exploration-card .btn-outline-secondary:hover { color: #fff; background: #25364d; }
@media (max-width: 767px) { .exploration-map { min-width: 1000px; } .exploration-legend { margin-bottom: 6px; } }
</style>>

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
        var view = { x: 0, y: 0, w: 1200, h: 800 };
        var minW = 150, maxW = 1200;
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
                var match = (node.getAttribute('transform') || '').match(/translate\\(([-0-9.]+)[ ,]+([-0-9.]+)\\)/);
                if (!match) return;
                var cx = parseFloat(match[1]), cy = parseFloat(match[2]);
                view.w = 600; view.h = 400; view.x = cx - view.w / 2; view.y = cy - view.h / 2; apply();
            }
        };
    }
    var mapApis = {};
    root.querySelectorAll('.exploration-map').forEach(function (svg) {
        mapApis[svg.closest('.tab-pane').id] = mapApi(svg);
    });

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
                    activateRegion(region);
                    target.classList.add('is-player-location');
                                        var dot = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                    dot.setAttribute('class', 'exploration-player-dot');
                    dot.setAttribute('cx', '0');
                    dot.setAttribute('cy', '-12');
                    dot.setAttribute('r', '5');
                    target.appendChild(dot);
                    target.setAttribute('aria-label', (target.getAttribute('aria-label') || '') + ', current location');
                    if (status) status.textContent = 'Live location: ' + (data.character_name || 'Main character') + ' in ' + (target.dataset.systemName || 'unknown system');
                    if (mapApis[pane.id]) mapApis[pane.id].center(target);
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
        if (node.dataset.status === 'red' || node.dataset.busy === '1') return;
        var pane = node.closest('.tab-pane');
        var region = pane.id.replace('exploration-', '');
        node.dataset.busy = '1';
        fetch(@json(url('/exploration')) + '/' + encodeURIComponent(region.charAt(0).toUpperCase() + region.slice(1)) + '/systems/' + node.dataset.systemId + '/toggle', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            if (!response.ok) throw new Error('Could not update this system.');
            return response.json();
        }).then(function (data) {
            setSystemStatus(node, data.status);
            updateCount(region);
        }).catch(function (error) {
            window.alert(error.message || 'Could not update this system.');
        }).finally(function () {
            delete node.dataset.busy;
        });
    }

    root.addEventListener('click', function (event) {
        var node = event.target.closest('.exploration-system');
        if (node && root.contains(node)) toggleSystem(node);
    });
    root.addEventListener('keydown', function (event) {
        var node = event.target.closest('.exploration-system');
        if (node && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            toggleSystem(node);
        }
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
