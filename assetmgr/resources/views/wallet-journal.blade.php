@extends('web::layouts.grids.12')

@section('title', 'Wallet Journal')
@section('page_header', 'Wallet Journal')
@section('page_description', 'Cached wallet journal records for characters linked to your SeAT account.')

@section('full')
<div class="row">
    <div class="col-md-12">
        <div class="card card-primary">
            <div class="card-body">
                @if (session('status'))
                    <div class="alert alert-info">{{ session('status') }}</div>
                @endif

                @if (config('assetmgr.sync.enabled', false))
                    <form method="post" action="{{ route('assetmgr.wallet-journal.refresh') }}" style="margin-bottom: 15px;">
                        @csrf
                        <button type="submit" class="btn btn-primary">Refresh my linked wallet journals</button>
                    </form>
                @endif

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

                <form method="get" action="{{ route('assetmgr.wallet-journal') }}" class="form-inline" style="margin-bottom: 15px;">
                    <div class="form-group" style="margin-right: 8px;">
                        <label class="sr-only" for="wallet-journal-q">Search</label>
                        <input id="wallet-journal-q" class="form-control" name="q" value="{{ $search }}" placeholder="Search description, reason, reference">
                    </div>
                    <div class="form-group" style="margin-right: 8px;">
                        <label class="sr-only" for="wallet-journal-character">Character ID</label>
                        <input id="wallet-journal-character" class="form-control" name="character_id" type="number" min="1" value="{{ $characterId }}" placeholder="Character ID">
                    </div>
                    <button type="submit" class="btn btn-primary">Apply</button>
                    <a class="btn btn-secondary" style="margin-left: 8px;" href="{{ route('assetmgr.index') }}">Back to assets</a>
                </form>

                <div class="table-responsive">
                    <table class="table table-condensed table-striped table-hover">
                        <thead><tr><th>Date</th><th>Character owner</th><th>Reference</th><th>Description</th><th>Amount</th><th>Balance</th><th>First party</th><th>Second party</th></tr></thead>
                        <tbody>
                        @forelse ($records as $record)
                            <tr>
                                <td>{{ $record->record_at }}</td>
                                <td>{{ $record->character_name ?? ('Character ' . $record->character_id) }}</td>
                                <td>{{ $record->reference_type ?? '—' }}</td>
                                <td>{{ $record->description ?? $record->reason ?? '—' }}</td>
                                <td>{{ $record->amount === null ? '—' : number_format((float) $record->amount, 2) . ' ISK' }}</td>
                                <td>{{ $record->balance === null ? '—' : number_format((float) $record->balance, 2) . ' ISK' }}</td>
                                <td>{{ $record->first_party_id ?? '—' }}</td>
                                <td>{{ $record->second_party_id ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8">No cached wallet journal records. Confirm the character has granted esi-wallet.read_character_wallet.v1 and run a background sync.</td></tr>
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
