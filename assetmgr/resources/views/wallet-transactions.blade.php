@extends('web::layouts.grids.12')

@section('title', 'Wallet Transactions')
@section('page_header', 'Wallet Transactions')
@section('page_description', 'Cached market transactions for characters linked to your SeAT account.')

@section('full')
<div class="row">
    <div class="col-md-12">
        <div class="card card-primary">
            <div class="card-body">
                @if ($syncStates->isNotEmpty())
                    <div class="table-responsive">
                        <table class="table table-condensed">
                            <thead><tr><th>Character ID</th><th>Sync status</th><th>Last successful sync</th><th>Next eligible sync</th></tr></thead>
                            <tbody>@foreach ($syncStates as $state)
                                <tr><td>{{ $state->owner_id }}</td><td>{{ ucfirst($state->status) }}</td><td>{{ $state->last_success_at ?? 'Never completed' }}</td><td>{{ $state->next_eligible_at ?? 'Eligible now' }}</td></tr>
                            @endforeach</tbody>
                        </table>
                    </div>
                @endif

                <form method="get" action="{{ route('assetmgr.wallet-transactions') }}" class="form-inline" style="margin-bottom: 15px;">
                    <div class="form-group" style="margin-right: 8px;">
                        <label class="sr-only" for="wallet-transactions-q">Search</label>
                        <input id="wallet-transactions-q" class="form-control" name="q" value="{{ $search }}" placeholder="Search item, location, or client">
                    </div>
                    <div class="form-group" style="margin-right: 8px;">
                        <label class="sr-only" for="wallet-transactions-character">Character ID</label>
                        <input id="wallet-transactions-character" class="form-control" name="character_id" type="number" min="1" value="{{ $characterId }}" placeholder="Character ID">
                    </div>
                    <button type="submit" class="btn btn-primary">Apply</button>
                    <a class="btn btn-secondary" style="margin-left: 8px;" href="{{ route('assetmgr.wallet-transactions.export', request()->query()) }}">Export CSV</a>
                    <a class="btn btn-secondary" style="margin-left: 8px;" href="{{ route('assetmgr.wallet-journal') }}">Wallet Journal</a>
                    <a class="btn btn-secondary" style="margin-left: 8px;" href="{{ route('assetmgr.index') }}">Assets</a>
                </form>

                <div class="table-responsive">
                    <table class="table table-condensed table-striped table-hover">
                        <thead><tr><th>Date</th><th>Character owner</th><th>Transaction</th><th>Item</th><th>Quantity</th><th>Unit price</th><th>Amount</th><th>Location</th><th>Client ID</th></tr></thead>
                        <tbody>
                        @forelse ($records as $record)
                            <tr>
                                <td>{{ $record->record_at }}</td>
                                <td>{{ $record->character_name ?? ('Character ' . $record->character_id) }}</td>
                                <td>{{ $record->is_buy ? 'Buy' : 'Sell' }}</td>
                                <td>{{ $record->type_name ?? ('Type ' . $record->type_id) }} <small class="text-muted">ID {{ $record->type_id }}</small></td>
                                <td>{{ number_format((int) $record->quantity) }}</td>
                                <td>{{ number_format((float) $record->unit_price, 2) }} ISK</td>
                                <td>{{ number_format((float) $record->amount, 2) }} ISK</td>
                                <td>{{ $record->location_name ?? $record->location_id }}</td>
                                <td>{{ $record->first_party_id ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9">No cached wallet transactions. Confirm the character has granted esi-wallet.read_character_wallet.v1 and run a background sync.</td></tr>
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
