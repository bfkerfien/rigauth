@extends('web::layouts.grids.12')

@section('title', 'Potchven Finances')
@section('page_header', 'Potchven Finances')
@section('page_description', 'Potchven operations financial dashboard')

@section('full')
<div class="row">
  @foreach([
    ['Successful sites this period', number_format($sites)],
    ['Average sites per day', number_format($sites / $daysElapsed, 2)],
    ['Site revenue this period', number_format($siteRevenue, 0).' ISK'],
    ['Character expenses this period', number_format($expenses, 0).' ISK'],
    ['Estimated profit this period', number_format($siteRevenue - $expenses, 0).' ISK'],
    ['Pending SRP transfer', number_format($pendingSrp, 0).' ISK'],
    ['Estimated assets', number_format($assetValue, 0).' ISK'],
    ['Corporation wallet', number_format($corpWallet, 0).' ISK'],
    ['180-day counter', number_format($monthlyCountdown).' days remaining'],
  ] as $metric)
    <div class="col-md-4 col-sm-6"><div class="small-box bg-info"><div class="inner"><h4>{{ $metric[1] }}</h4><p>{{ $metric[0] }}</p></div></div></div>
  @endforeach
</div>
<div class="card mb-3"><div class="card-body d-flex justify-content-between align-items-center flex-wrap">
  <div><strong>Financial reporting period</strong><p class="mb-0 text-muted">Period started: {{ $periodStart->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') }}. Reset starts a new reporting period and restarts the 180-day countdown.</p></div>
  <form method="POST" action="{{ route('seatcore::potchven-finances.timer.reset') }}" onsubmit="return confirm('Start a new financial reporting period? Revenue, expenses and site totals will restart from zero. Historical records will be preserved and outstanding SRP obligations will remain.')">
    @csrf
    <button type="submit" class="btn btn-warning"><i class="fas fa-redo-alt"></i> Reset Financial Period</button>
  </form>
</div></div>
<div class="card mb-3"><div class="card-header"><h3 class="card-title">SRP transfers</h3></div><div class="card-body d-flex flex-wrap align-items-center">
  <form method="POST" action="{{ route('seatcore::potchven-finances.srp.add-site-without-loot') }}" class="mr-2 mb-2" onsubmit="return confirm('Add 27,000,000 ISK to pending SRP transfer for Site Without Loot?')">
    @csrf
    <button type="submit" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Site Without Loot (+27,000,000 ISK)</button>
  </form>
  <form method="POST" action="{{ route('seatcore::potchven-finances.srp.transfer') }}" class="mb-2" onsubmit="return confirm('Have you completed the ISK transfer to the SRP fund? This will mark all currently pending SRP obligations as transferred in the dashboard. It will not move ISK in EVE.')">
    @csrf
    <button type="submit" class="btn btn-success"><i class="fas fa-check-circle"></i> Transfer to SRP</button>
  </form>
  <p class="text-muted small mb-0 w-100">Use “Transfer to SRP” only after the ISK has actually been sent. The dashboard records the confirmation; it cannot transfer ISK in EVE.</p>
</div></div>
<div class="alert alert-secondary">
  <strong>Finance data last updated:</strong>
  @if($lastFinanceSync) {{ \Carbon\Carbon::parse($lastFinanceSync)->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') }} @else Never recorded @endif
  <span class="ml-2">Automatic sync runs every 15 minutes.</span>
</div>
@foreach($financeSyncErrors as $syncError)
  <div class="alert alert-warning"><strong>Sync issue ({{ $syncError->resource_key }}):</strong> {{ $syncError->last_error }}</div>
@endforeach
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('info'))<div class="alert alert-info">{{ session('info') }}</div>@endif
@if(!$owners->count())
  <div class="alert alert-warning">Finance tables have not been imported yet. Import the standalone finance database into SeAT using the fin_ prefix, then reload this page.</div>
@endif
<div class="card"><div class="card-header"><h3 class="card-title">Tracked wallets</h3></div><div class="card-body table-responsive">
<table class="table table-striped table-sm"><thead><tr><th>Owner</th><th>Type</th><th>EVE ID</th><th>Active</th></tr></thead><tbody>
@forelse($owners as $owner)
<tr><td>{{ $owner->display_name }}</td><td>{{ ucfirst($owner->owner_type) }}</td><td>{{ $owner->eve_id }}</td><td>{{ ($owner->is_active ?? true) ? 'Yes' : 'No' }}</td></tr>
@empty<tr><td colspan="4" class="text-muted">No finance owners imported.</td></tr>@endforelse
</tbody></table></div></div>
<div class="alert alert-info">Site outcomes are matched automatically from corporation reward payouts. Three 9,000,000 ISK payouts—one to each tracked character—record a successful site and credit 45,000,000 ISK revenue. Incomplete payout groups are flagged for review and the unpaid portion of the expected 27,000,000 ISK is listed as an SRP transfer obligation. Run the finance sync to refresh these results.</div>
<div class="card"><div class="card-header"><h3 class="card-title">Recent wallet entries</h3></div><div class="card-body table-responsive">
<table class="table table-striped table-sm"><thead><tr><th>Date</th><th>Owner</th><th>Reference</th><th>Description</th><th>Amount (ISK)</th><th>Operating expense</th></tr></thead><tbody>
@forelse($entries as $entry)
<tr><td>{{ $entry->occurred_at }}</td><td>{{ $entry->display_name }}</td><td>{{ $entry->reference_type }}</td><td>{{ $entry->description }}</td><td class="text-right">{{ number_format((float)$entry->amount_isk, 2) }}</td><td>{{ $entry->is_operational_expense ? 'Yes' : 'No' }}</td></tr>
@empty<tr><td colspan="6" class="text-muted">No wallet entries imported.</td></tr>@endforelse
</tbody></table></div></div>
<div class="card"><div class="card-header"><h3 class="card-title">Recent site events</h3></div><div class="card-body table-responsive">
<table class="table table-striped table-sm"><thead><tr><th>Date</th><th>Status</th><th>Payout count</th><th>Total payouts</th><th>Revenue credit</th><th>Notes</th></tr></thead><tbody>
@forelse($siteEvents as $event)
<tr><td>{{ $event->occurred_at }}</td><td>{{ $event->status }}</td><td>{{ $event->payout_count }}</td><td>{{ number_format((float)$event->total_payout_isk, 0) }}</td><td>{{ number_format((float)$event->revenue_credit_isk, 0) }}</td><td>{{ $event->review_note }}</td></tr>
@empty<tr><td colspan="6" class="text-muted">No site events imported.</td></tr>@endforelse
</tbody></table></div></div>
@endsection
