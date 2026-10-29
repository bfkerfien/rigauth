@extends('web::layouts.grids.12')

@section('title', 'Mining Ledger')
@section('page_header', 'Mining Ledger')
@section('page_description', 'Mining operations, reported yields, and estimated Jita value')

@section('full')
  @if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
  @endif
  @if (session('warning'))
    <div class="alert alert-warning">{{ session('warning') }}</div>
  @endif
  @if ($errors->any())
    <div class="alert alert-danger">
      <strong>Could not complete the request.</strong>
      <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
  @endif

  <div class="alert alert-info">
    ESI does not provide a complete mining-yield history. Manual and imported entries are reported data, not ESI-verified mining events. Jita values are estimates based on the lowest available sell order at the Jita market station and may be unavailable.
  </div>

  <div class="row">
    <div class="col-md-4"><div class="info-box"><span class="info-box-icon bg-info"><i class="fas fa-list"></i></span><div class="info-box-content"><span class="info-box-text">Recorded yield rows</span><span class="info-box-number">{{ number_format($summary['rows']) }}</span></div></div></div>
    <div class="col-md-4"><div class="info-box"><span class="info-box-icon bg-info"><i class="fas fa-cubes"></i></span><div class="info-box-content"><span class="info-box-text">Recorded units</span><span class="info-box-number">{{ number_format($summary['quantity']) }}</span></div></div></div>
    <div class="col-md-4"><div class="info-box"><span class="info-box-icon bg-info"><i class="fas fa-coins"></i></span><div class="info-box-content"><span class="info-box-text">Estimated Jita value</span><span class="info-box-number">{{ $summary['value'] === null ? 'N/A' : number_format((float) $summary['value'], 2) . ' ISK' }}</span></div></div></div>
  </div>


  <div class="card">
    <div class="card-header"><h3 class="card-title">Mining timer board</h3></div>
    <div class="card-body">
      <p class="text-muted">Create countdowns for site clears, anomaly timers, or fleet actions. These are manual coordination timers, not authoritative EVE respawn predictions.</p>
      <form method="POST" action="{{ route('seatcore::mining.timers.store') }}">
        @csrf
        <div class="form-row">
          <div class="form-group col-md-3"><label for="timer-title">Timer label</label><input id="timer-title" class="form-control" name="title" maxlength="160" placeholder="e.g. Ore anomaly clear" required></div>
          <div class="form-group col-md-2"><label for="timer-system">System</label><input id="timer-system" class="form-control" name="system_name" maxlength="120" required></div>
          <div class="form-group col-md-2"><label for="timer-anomaly">Anomaly (optional)</label><input id="timer-anomaly" class="form-control" name="anomaly_name" maxlength="190"></div>
          <div class="form-group col-md-2"><label for="timer-duration">Duration (minutes)</label><input id="timer-duration" class="form-control" type="number" name="duration_minutes" min="1" max="10080" value="60" required></div>
          <div class="form-group col-md-3"><label for="timer-started">Timer starts at</label><input id="timer-started" class="form-control" type="datetime-local" name="started_at" value="{{ now()->format('Y-m-d\TH:i') }}" required></div>
          <div class="form-group col-md-12"><label for="timer-notes">Notes</label><input id="timer-notes" class="form-control" name="notes" maxlength="1000"></div>
        </div>
        <button class="btn btn-primary" type="submit">Add timer</button>
      </form>
      <hr>
      <div class="table-responsive"><table class="table table-striped table-sm">
        <thead><tr><th>Timer</th><th>System / anomaly</th><th>Started</th><th>Expires</th><th>Countdown</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($miningTimers as $timer)
          <tr><td>{{ $timer->title }}<br><small class="text-muted">{{ $timer->notes }}</small></td><td>{{ $timer->system_name }}<br><small>{{ $timer->anomaly_name }}</small></td><td>{{ $timer->started_at }}</td><td>{{ $timer->expires_at }}</td>
          <td>@if ($timer->status !== 'active'){{ ucfirst($timer->status) }}@elseif (\Illuminate\Support\Carbon::parse($timer->expires_at)->isPast())<span class="text-danger">Expired</span>@else{{ \Illuminate\Support\Carbon::parse($timer->expires_at)->diffForHumans(now(), ['parts' => 3, 'short' => true]) }}@endif</td>
          <td>{{ $timer->status === 'active' && \Illuminate\Support\Carbon::parse($timer->expires_at)->isPast() ? 'expired' : $timer->status }}</td></tr>
        @empty<tr><td colspan="6" class="text-muted">No timers on the board yet.</td></tr>@endforelse
        </tbody></table></div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3 class="card-title">Paste anomalies from the in-game scan window</h3></div>
    <form method="POST" action="{{ route('seatcore::mining.anomalies.paste') }}">
      @csrf
      <div class="card-body">
        <p>Copy anomaly rows from the in-game Probe Scanner / scan results and paste them here. Use one system per paste. Tab-separated rows are supported; the first column is saved as the anomaly label and the original row is retained. Repeated present rows refresh their last-seen time.</p>
        <div class="form-group"><label for="scan-system">Solar system</label><input id="scan-system" class="form-control" name="system_name" maxlength="120" required></div>
        <div class="form-group"><label for="scan-paste">Copied scan rows</label><textarea id="scan-paste" class="form-control" name="scan_paste" rows="6" maxlength="30000" placeholder="Paste the scan-window rows here..." required></textarea></div>
      </div>
      <div class="card-footer"><button class="btn btn-primary" type="submit">Import scan rows</button></div>
    </form>
    <div class="card-body table-responsive"><table class="table table-striped table-sm">
      <thead><tr><th>System</th><th>Anomaly</th><th>First seen</th><th>Last seen</th><th>Source row</th><th>Status</th><th>Update status</th></tr></thead>
      <tbody>
      @forelse ($miningAnomalies as $anomaly)
        <tr><td>{{ $anomaly->system_name }}</td><td>{{ $anomaly->anomaly_name }}</td><td>{{ $anomaly->first_seen_at }}</td><td>{{ $anomaly->last_seen_at }}</td><td><small>{{ $anomaly->scan_text }}</small></td><td>{{ $anomaly->status }}</td>
        <td><form method="POST" action="{{ route('seatcore::mining.anomalies.status', $anomaly->id) }}" class="form-inline">@csrf
          <select class="form-control form-control-sm mr-1" name="status"><option value="present" {{ $anomaly->status === 'present' ? 'selected' : '' }}>Present</option><option value="mined" {{ $anomaly->status === 'mined' ? 'selected' : '' }}>Mined</option><option value="despawned" {{ $anomaly->status === 'despawned' ? 'selected' : '' }}>Despawned</option></select>
          <button class="btn btn-sm btn-secondary" type="submit">Save</button></form></td></tr>
      @empty<tr><td colspan="7" class="text-muted">No scan anomalies added yet.</td></tr>@endforelse
      </tbody></table></div>
  </div>

  <div class="card">
    <div class="card-header"><h3 class="card-title">Create mining operation</h3></div>
    <form method="POST" action="{{ route('seatcore::mining.operations.store') }}">
      @csrf
      <div class="card-body">
        <div class="form-row">
          <div class="form-group col-md-4"><label for="operation-name">Operation name</label><input id="operation-name" class="form-control" name="name" maxlength="160" required></div>
          <div class="form-group col-md-4"><label for="operation-system">Solar system</label><input id="operation-system" class="form-control" name="system_name" maxlength="120" required></div>
          <div class="form-group col-md-4"><label for="operation-location">Moon, belt, anomaly or location notes</label><input id="operation-location" class="form-control" name="location_name" maxlength="190"></div>
          <div class="form-group col-md-4"><label for="operation-start">Start time</label><input id="operation-start" class="form-control" type="datetime-local" name="started_at"></div>
          <div class="form-group col-md-4"><label for="operation-end">End time (optional)</label><input id="operation-end" class="form-control" type="datetime-local" name="ended_at"></div>
        </div>
        <div class="form-group"><label>Participating characters</label><div class="row">@forelse ($characters as $character)<div class="col-md-3"><div class="custom-control custom-checkbox"><input class="custom-control-input" type="checkbox" name="character_ids[]" value="{{ $character['id'] }}" id="mining-char-{{ $character['id'] }}"><label class="custom-control-label" for="mining-char-{{ $character['id'] }}">{{ $character['name'] }}</label></div></div>@empty<p class="text-muted">No linked characters found for this account.</p>@endforelse</div></div>
      </div>
      <div class="card-footer"><button class="btn btn-primary" type="submit">Create operation</button></div>
    </form>
  </div>

  <div class="card">
    <div class="card-header"><h3 class="card-title">Record a yield</h3></div>
    <form method="POST" action="{{ route('seatcore::mining.yields.store') }}">
      @csrf
      <div class="card-body">
        <div class="form-row">
          <div class="form-group col-md-3"><label for="yield-character">Character</label><select id="yield-character" class="form-control" name="character_id" required>@foreach ($characters as $character)<option value="{{ $character['id'] }}">{{ $character['name'] }}</option>@endforeach</select></div>
          <div class="form-group col-md-2"><label for="yield-operation">Operation</label><select id="yield-operation" class="form-control" name="operation_id"><option value="">No operation</option>@foreach ($operations as $operation)<option value="{{ $operation->id }}">{{ $operation->name }} — {{ $operation->system_name }}</option>@endforeach</select></div>
          <div class="form-group col-md-2"><label for="yield-type-id">Ore type ID</label><input id="yield-type-id" class="form-control" type="number" min="1" name="type_id" required></div>
          <div class="form-group col-md-2"><label for="yield-type-name">Ore name (if no SDE)</label><input id="yield-type-name" class="form-control" name="type_name" maxlength="190"></div>
          <div class="form-group col-md-1"><label for="yield-quantity">Quantity</label><input id="yield-quantity" class="form-control" type="number" min="1" name="quantity" required></div>
          <div class="form-group col-md-2"><label for="yield-recorded-at">Recorded at</label><input id="yield-recorded-at" class="form-control" type="datetime-local" name="recorded_at" value="{{ now()->format('Y-m-d\TH:i') }}" required></div>
          <div class="form-group col-md-12"><label for="yield-reference">Source reference / notes</label><input id="yield-reference" class="form-control" name="source_reference" maxlength="255"></div>
        </div>
      </div>
      <div class="card-footer"><button class="btn btn-primary" type="submit">Save reported yield</button></div>
    </form>
  </div>

  <div class="card">
    <div class="card-header"><h3 class="card-title">Import CSV</h3></div>
    <form method="POST" action="{{ route('seatcore::mining.import') }}" enctype="multipart/form-data">
      @csrf
      <div class="card-body">
        <p>CSV header: <code>character_id,type_id,quantity,recorded_at,operation_id,source_reference</code>. Required columns are character_id, type_id, quantity, recorded_at. Maximum 5 MB. A repeated identical file is rejected.</p>
        <div class="form-group"><label for="mining-csv">CSV file</label><input id="mining-csv" class="form-control-file" type="file" name="csv_file" accept=".csv,.txt,text/csv" required></div>
      </div>
      <div class="card-footer"><button class="btn btn-secondary" type="submit">Import reported yields</button></div>
    </form>
  </div>

  <div class="card">
    <div class="card-header"><h3 class="card-title">Mining operations</h3></div>
    <div class="card-body table-responsive">
      <table class="table table-striped table-sm">
        <thead><tr><th>Operation</th><th>System</th><th>Location</th><th>Started</th><th>Status</th></tr></thead>
        <tbody>@forelse ($operations as $operation)<tr><td>{{ $operation->name }}</td><td>{{ $operation->system_name }}</td><td>{{ $operation->location_name ?: '—' }}</td><td>{{ $operation->started_at ?: '—' }}</td><td>{{ $operation->status }}</td></tr>@empty<tr><td colspan="5" class="text-muted">No operations recorded yet.</td></tr>@endforelse</tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3 class="card-title">Recent yields</h3></div>
    <div class="card-body table-responsive">
      <table class="table table-striped table-sm">
        <thead><tr><th>Recorded</th><th>Character</th><th>Ore</th><th class="text-right">Quantity</th><th>Source</th><th>Status</th><th class="text-right">Estimated Jita value</th></tr></thead>
        <tbody>
        @forelse ($yields as $yield)
          <tr><td>{{ $yield->recorded_at }}</td><td>{{ $yield->character_name }}</td><td>{{ $yield->type_name }} <small class="text-muted">({{ $yield->type_id }})</small></td><td class="text-right">{{ number_format($yield->quantity) }}</td><td>{{ $yield->source_method }}</td><td>{{ $yield->verification_status }}</td><td class="text-right">{{ $yield->estimated_value_isk === null ? 'Unavailable' : number_format((float) $yield->estimated_value_isk, 2) . ' ISK' }}</td></tr>
        @empty
          <tr><td colspan="7" class="text-muted">No yield records yet.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>
@endsection
