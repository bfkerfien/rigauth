@extends('web::layouts.grids.12')

@section('title', 'Potchven Finances')
@section('page_header', 'Potchven Finances')
@section('page_description', 'Potchven operations financial dashboard')

@section('full')
<div class="row">
  @foreach([
    ['Successful sites this month', number_format($sites)],
    ['Average sites per day', number_format($sites / $daysElapsed, 2)],
    ['Site revenue credit', number_format($siteRevenue, 0).' ISK'],
    ['Character operating expenses', number_format($expenses, 0).' ISK'],
    ['Estimated operating profit', number_format($siteRevenue - $expenses, 0).' ISK'],
    ['Pending SRP transfer', number_format($pendingSrp, 0).' ISK'],
    ['Estimated assets', number_format($assetValue, 0).' ISK'],
    ['Corporation wallet', number_format($corpWallet, 0).' ISK'],
    ['180-day counter', number_format($monthlyCountdown).' days remaining'],
  ] as $metric)
    <div class="col-md-4 col-sm-6"><div class="small-box bg-info"><div class="inner"><h4>{{ $metric[1] }}</h4><p>{{ $metric[0] }}</p></div></div></div>
  @endforeach
</div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(!$owners->count())
  <div class="alert alert-warning">Finance tables have not been imported yet. Import the standalone finance database into SeAT using the fin_ prefix, then reload this page.</div>
@endif
<div class="card"><div class="card-header"><h3 class="card-title">Tracked wallets</h3></div><div class="card-body table-responsive">
<table class="table table-striped table-sm"><thead><tr><th>Owner</th><th>Type</th><th>EVE ID</th><th>Active</th></tr></thead><tbody>
@forelse($owners as $owner)
<tr><td>{{ $owner->display_name }}</td><td>{{ ucfirst($owner->owner_type) }}</td><td>{{ $owner->eve_id }}</td><td>{{ ($owner->is_active ?? true) ? 'Yes' : 'No' }}</td></tr>
@empty<tr><td colspan="4" class="text-muted">No finance owners imported.</td></tr>@endforelse
</tbody></table></div></div>
<div class="card"><div class="card-header"><h3 class="card-title">Record a successful site</h3></div><div class="card-body">
<p>Records three payouts and applies the existing 45,000,000 ISK site revenue credit.</p>
<form method="POST" action="{{ route('seatcore::potchven-finances.sites.store') }}">@csrf
<div class="form-group"><label for="occurred_at">Completion time (optional)</label><input class="form-control" id="occurred_at" name="occurred_at" type="datetime-local"></div>
<div class="form-group"><label for="review_note">Notes</label><input class="form-control" id="review_note" name="review_note" maxlength="1000"></div>
<button class="btn btn-primary" type="submit">Record successful site</button></form></div></div>
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
