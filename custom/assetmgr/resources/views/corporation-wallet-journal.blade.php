@extends('web::layouts.grids.12')

@section('title', 'Corporation Wallet Journal')
@section('page_header', 'Corporation Wallet Journal')
@section('page_description', 'Cached corporation wallet journal records. Access is controlled separately from corporation assets and orders.')

@section('full')
<div class="row">
    <div class="col-md-12">
        <div class="card card-primary">
            <div class="card-body">
                <h3>{{ $corporationName }}</h3>
                @if (session('status'))
                    <div class="alert alert-info">{{ session('status') }}</div>
                @endif

                @if ($syncStates->isNotEmpty())
                    <div class="table-responsive"><table class="table table-condensed">
                        <thead><tr><th>Wallet division</th><th>Sync status</th><th>Last successful sync</th><th>Next eligible sync</th></tr></thead>
                        <tbody>@foreach ($syncStates as $state)
                            <tr><td>{{ str_replace('corp_wallet_journal_', '', $state->dataset) }}</td><td>{{ ucfirst($state->status) }}</td><td>{{ $state->last_success_at ?? 'Never' }}</td><td>{{ $state->next_eligible_at ?? 'Eligible now' }}</td></tr>
                        @endforeach</tbody>
                    </table></div>
                @endif

                @if (config('assetmgr.sync.enabled', false))
                    <form method="post" action="{{ route('assetmgr.corporation-wallet-journal.refresh', ['corporationId' => $corporationId]) }}" style="margin-bottom: 15px;">
                        @csrf
                        <button type="submit" class="btn btn-primary">Refresh corporation wallet divisions</button>
                    </form>
                @endif

                <form method="get" action="{{ route('assetmgr.corporation-wallet-journal', ['corporationId' => $corporationId]) }}" class="form-inline" style="margin-bottom: 15px;">
                    <div class="form-group" style="margin-right: 8px;">
                        <label class="sr-only" for="corp-wallet-q">Search</label>
                        <input id="corp-wallet-q" class="form-control" name="q" value="{{ $search }}" placeholder="Search description, reason, or reference">
                    </div>
                    <div class="form-group" style="margin-right: 8px;">
                        <label class="sr-only" for="corp-wallet-division">Wallet division</label>
                        <select id="corp-wallet-division" class="form-control" name="division">
                            <option value="">All divisions</option>
                            @for ($d = 1; $d <= 7; $d++)
                                <option value="{{ $d }}" @selected($division === $d)>Division {{ $d }}</option>
                            @endfor
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Apply</button>
                    <a class="btn btn-secondary" style="margin-left: 8px;" href="{{ route('assetmgr.corporation-wallet-journal.export', ['corporationId' => $corporationId] + request()->query()) }}">Export CSV</a>
                    <a class="btn btn-secondary" style="margin-left: 8px;" href="{{ route('assetmgr.corporation.index', ['corporation_id' => $corporationId]) }}">Corporation Assets</a>
                    @if ($canViewCorporationMarketOrders)
                        <a class="btn btn-secondary" style="margin-left: 8px;" href="{{ route('assetmgr.corporation-market-orders', ['corporationId' => $corporationId]) }}">Corporation Market Orders</a>
                    @endif
                </form>

                <div class="table-responsive">
                    <table class="table table-condensed table-striped table-hover">
                        <thead><tr><th>Date</th><th>Division</th><th>Reference</th><th>Description</th><th>Amount</th><th>Balance</th><th>First party</th><th>Second party</th></tr></thead>
                        <tbody>
                        @forelse ($records as $record)
                            <tr>
                                <td>{{ $record->record_at }}</td>
                                <td>{{ $record->wallet_division }}</td>
                                <td>{{ $record->reference_type ?? '—' }}</td>
                                <td>{{ $record->description ?? $record->reason ?? '—' }}</td>
                                <td>{{ $record->amount === null ? '—' : number_format((float) $record->amount, 2) . ' ISK' }}</td>
                                <td>{{ $record->balance === null ? '—' : number_format((float) $record->balance, 2) . ' ISK' }}</td>
                                <td>{{ $record->first_party_id ?? '—' }}</td>
                                <td>{{ $record->second_party_id ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8">No cached corporation wallet journal records. Confirm a Director/Accountant token with esi-wallet.read_corporation_wallets.v1 and the separate corporation wallet-journal permission.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $records->links() }}
            </div>
        </div>
    </div>
</div>
@stop
