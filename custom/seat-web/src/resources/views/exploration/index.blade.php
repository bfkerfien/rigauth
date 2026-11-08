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
            <div class="exploration-map-wrap">
              <svg class="exploration-map" viewBox="0 0 1200 800" role="img" aria-label="{{ $region }} solar system map. Click a white or green system to change its exploration status.">
                <defs>
                  <pattern id="exploration-grid-{{ strtolower($region) }}" width="40" height="40" patternUnits="userSpaceOnUse">
                    <path d="M 40 0 L 0 0 0 40" fill="none" stroke="#e6e9ed" stroke-width="1"/>
                  </pattern>
                </defs>
                <rect x="0" y="0" width="1200" height="800" fill="#f8f9fb"/>
                <rect x="0" y="0" width="1200" height="800" fill="url(#exploration-grid-{{ strtolower($region) }})"/>
                <g class="exploration-map-links">
                  @foreach($map['links'] as $link)
                    <line x1="{{ $link['x1'] }}" y1="{{ $link['y1'] }}" x2="{{ $link['x2'] }}" y2="{{ $link['y2'] }}"/>
                  @endforeach
                </g>
                <g class="exploration-map-systems">
                  @foreach($map['systems'] as $system)
                    @php($pillWidth = max(48, (strlen($system['name']) * 7.4) + 18))
                    <g class="exploration-system is-{{ $system['status'] }} {{ $system['status'] === 'red' ? 'is-locked' : '' }}"
                       data-system-id="{{ $system['id'] }}" data-system-name="{{ $system['name'] }}"
                       data-status="{{ $system['status'] }}" tabindex="{{ $system['status'] === 'red' ? '-1' : '0' }}"
                       role="{{ $system['status'] === 'red' ? 'img' : 'button' }}"
                       aria-label="{{ $system['name'] }}: {{ $system['status'] === 'red' ? 'permanently excluded' : ($system['status'] === 'green' ? 'explored' : 'unexplored') }}"
                       aria-pressed="{{ $system['status'] === 'green' ? 'true' : 'false' }}"
                       transform="translate({{ $system['x'] }}, {{ $system['y'] }})">
                      <rect x="{{ -$pillWidth / 2 }}" y="-12" width="{{ $pillWidth }}" height="24" rx="5"/>
                      <text text-anchor="middle" dominant-baseline="central">{{ $system['name'] }}</text>
                    </g>
                  @endforeach
                </g>
                <text x="24" y="770" class="exploration-map-caption">{{ $region }} · {{ count($map['systems']) }} solar systems</text>
              </svg>
            </div>
          </div>
        @endforeach
      </div>
    @endif
  </div>
</div>

<style>
.exploration-card .card-body { padding: 1rem; }
.exploration-legend { display: inline-flex; align-items: center; margin-right: 16px; white-space: nowrap; }
.exploration-legend-dot { display: inline-block; width: 12px; height: 12px; border: 1px solid #64748b; border-radius: 3px; margin-right: 6px; }
.exploration-legend-dot.white { background: #fff; }
.exploration-legend-dot.green { background: #35a853; border-color: #25843e; }
.exploration-legend-dot.red { background: #d9534f; border-color: #b52b27; }
.exploration-legend-dot.gold { background: #f2b632; border-color: #bd8310; }
.exploration-map-wrap { width: 100%; overflow: auto; border: 1px solid #d8dde4; border-radius: 4px; background: #f8f9fb; }
.exploration-map { display: block; width: 100%; min-width: 760px; height: auto; }
.exploration-map-links line { stroke: #aeb7c2; stroke-width: 1.5; opacity: .8; }
.exploration-system { cursor: pointer; outline: none; }
.exploration-system rect { fill: #fff; stroke: #687585; stroke-width: 1.4; transition: fill .12s ease, stroke .12s ease; }
.exploration-system text { fill: #253140; font: 11px Arial, sans-serif; pointer-events: none; user-select: none; }
.exploration-system:hover rect, .exploration-system:focus rect { stroke: #1674c5; stroke-width: 2.5; }
.exploration-system.is-green rect { fill: #35a853; stroke: #25843e; }
.exploration-system.is-green text { fill: #fff; font-weight: 700; }
.exploration-system.is-red { cursor: not-allowed; }
.exploration-system.is-red rect { fill: #d9534f; stroke: #b52b27; }
.exploration-system.is-red text { fill: #fff; font-weight: 700; }
.exploration-system.is-locked:hover rect, .exploration-system.is-locked:focus rect { stroke: #b52b27; }
.exploration-system.is-player-location rect { stroke: #e4a11b; stroke-width: 3.5; }
.exploration-player-dot { fill: #f2b632; stroke: #fff; stroke-width: 1.5; pointer-events: none; }
.exploration-map-caption { fill: #687585; font: 12px Arial, sans-serif; }
@media (max-width: 767px) {
  .exploration-map { min-width: 900px; }
  .exploration-legend { margin-bottom: 6px; }
}
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
                    target.classList.add('is-player-location');
                    var rect = target.querySelector('rect');
                    var width = rect ? Number(rect.getAttribute('width')) : 48;
                    var dot = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                    dot.setAttribute('class', 'exploration-player-dot');
                    dot.setAttribute('cx', String(width / 2 + 2));
                    dot.setAttribute('cy', '-12');
                    dot.setAttribute('r', '5');
                    target.appendChild(dot);
                    target.setAttribute('aria-label', (target.getAttribute('aria-label') || '') + ', current location');
                    if (status) status.textContent = 'Live location: ' + (data.character_name || 'Main character') + ' in ' + (target.dataset.systemName || 'unknown system');
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
