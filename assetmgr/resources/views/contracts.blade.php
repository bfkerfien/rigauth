@extends('web::layouts.grids.12')

@section('title', 'Contracts')
@section('page_header', 'Contracts')
@section('page_description', 'Cached contracts for characters linked to your SeAT account. Corporate contracts are kept separate.')

@section('full')
<div class="row">
    <div class="col-md-12">
        <div class="card card-primary">
            <div class="card-body">
                @if (session('status'))
                    <div class="alert alert-info">{{ session('status') }}</div>
                @endif

                @if (config('assetmgr.sync.enabled', false))
                    <form method="post" action="{{ route('assetmgr.contracts.refresh') }}" style="margin-bottom: 15px;">
                        @csrf
                        <button type="submit" class="btn btn-primary">Refresh my linked contracts</button>
                    </form>
                @endif

                @if ($syncStates->isNotEmpty())
                    <div class="table-responsive"><table class="table table-condensed">
                        <thead><tr><th>Character ID</th><th>Sync status</th><th>Last successful sync</th><th>Next eligible sync</th></tr></thead>
                        <tbody>@foreach ($syncStates as $state)
                            <tr><td>{{ $state->owner_id }}</td><td>{{ ucfirst($state->status) }}</td><td>{{ $state->last_success_at ?? 'Never' }}</td><td>{{ $state->next_eligible_at ?? 'Eligible now' }}</td></tr>
                        @endforeach</tbody>
                    </table></div>
                @endif

                <form method="get" action="{{ route('assetmgr.contracts') }}" class="form-inline" style="margin-bottom: 15px;">
                    <div class="form-group" style="margin-right: 8px;">
                        <label class="sr-only" for="contracts-q">Search</label>
                        <input id="contracts-q" class="form-control" name="q" value="{{ $search }}" placeholder="Search title, status, or contract ID">
                    </div>
                    <div class="form-group" style="margin-right: 8px;">
                        <label class="sr-only" for="contracts-character">Character ID</label>
                        <input id="contracts-character" class="form-control" name="character_id" type="number" min="1" value="{{ $characterId }}" placeholder="Character ID">
                    </div>
                    <div class="form-group" style="margin-right: 8px;">
                        <label class="sr-only" for="contracts-status">Status</label>
                        <input id="contracts-status" class="form-control" name="status" value="{{ $status }}" placeholder="Status">
                    </div>
                    <div class="form-group" style="margin-right: 8px;">
                        <label class="sr-only" for="contracts-type">Type</label>
                        <input id="contracts-type" class="form-control" name="type" value="{{ $type }}" placeholder="Type">
                    </div>
                    <button type="submit" class="btn btn-primary">Apply</button>
                    <a class="btn btn-secondary" style="margin-left: 8px;" href="{{ route('assetmgr.contracts.export', request()->query()) }}">Export CSV</a>
                    <a class="btn btn-secondary" style="margin-left: 8px;" href="{{ route('assetmgr.index') }}">Assets</a>
                    <a class="btn btn-secondary" style="margin-left: 8px;" href="{{ route('assetmgr.market-orders') }}">Market Orders</a>
                </form>

                <div class="table-responsive">
                    <table class="table table-condensed table-striped table-hover">
                        <thead><tr><th>Issued</th><th>Character owner</th><th>Contract ID</th><th>Type</th><th>Status</th><th>Title</th><th>Expires</th><th>Start location</th><th>End location</th><th>Price / reward</th></tr></thead>
                        <tbody>
                        @forelse ($contracts as $contract)
                            <tr>
                                <td>{{ $contract->date_issued }}</td>
                                <td>{{ $contract->character_name ?? ('Character ' . $contract->character_id) }}</td>
                                <td>{{ $contract->contract_id }}</td>
                                <td>{{ $contract->contract_type }}</td>
                                <td>{{ $contract->status }}</td>
                                <td>{{ $contract->title ?? '—' }}</td>
                                <td>{{ $contract->date_expired ?? '—' }}</td>
                                <td>{{ $contract->start_location_id ?? '—' }}</td>
                                <td>{{ $contract->end_location_id ?? '—' }}</td>
                                <td>Price {{ $contract->price === null ? '—' : number_format((float) $contract->price, 2) . ' ISK' }}<br>Reward {{ $contract->reward === null ? '—' : number_format((float) $contract->reward, 2) . ' ISK' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="10">No cached personal contracts. Confirm the character has granted esi-contracts.read_character_contracts.v1 and run a background sync.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $contracts->links() }}
            </div>
        </div>
    </div>
</div>
@stop
