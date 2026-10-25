@extends('web::layouts.grids.12')

@section('title', 'Market Orders')
@section('page_header', 'Market Orders')
@section('page_description', 'Cached active market orders for characters linked to your SeAT account.')

@section('full')
<div class="row">
    <div class="col-md-12">
        <div class="card card-primary">
            <div class="card-body">
                @if (session('status'))
                    <div class="alert alert-info">{{ session('status') }}</div>
                @endif

                @if (config('assetmgr.sync.enabled', false))
                    <form method="post" action="{{ route('assetmgr.market-orders.refresh') }}" style="margin-bottom: 15px;">
                        @csrf
                        <button type="submit" class="btn btn-primary">Refresh my linked market orders</button>
                    </form>
                @endif

                @if ($syncStates->isNotEmpty())
                    <div class="table-responsive"><table class="table table-condensed">
                        <thead><tr><th>Character ID</th><th>Sync status</th><th>Last successful sync</th><th>Next eligible sync</th></tr></thead>
                        <tbody>@foreach ($syncStates as $state)
                            <tr><td>{{ $state->owner_id }}</td><td>{{ ucfirst($state->status) }}</td><td>{{ $state->last_success_at ?? 'Never completed' }}</td><td>{{ $state->next_eligible_at ?? 'Eligible now' }}</td></tr>
                        @endforeach</tbody>
                    </table></div>
                @endif

                <form method="get" action="{{ route('assetmgr.market-orders') }}" class="form-inline" style="margin-bottom: 15px;">
                    <div class="form-group" style="margin-right: 8px;">
                        <label class="sr-only" for="market-orders-q">Search</label>
                        <input id="market-orders-q" class="form-control" name="q" value="{{ $search }}" placeholder="Search item, location, or order ID">
                    </div>
                    <div class="form-group" style="margin-right: 8px;">
                        <label class="sr-only" for="market-orders-character">Character ID</label>
                        <input id="market-orders-character" class="form-control" name="character_id" type="number" min="1" value="{{ $characterId }}" placeholder="Character ID">
                    </div>
                    <div class="form-group" style="margin-right: 8px;">
                        <label class="sr-only" for="market-orders-side">Side</label>
                        <select id="market-orders-side" class="form-control" name="side">
                            <option value="">All orders</option>
                            <option value="buy" @selected($side === 'buy')>Buy orders</option>
                            <option value="sell" @selected($side === 'sell')>Sell orders</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Apply</button>
                    <a class="btn btn-secondary" style="margin-left: 8px;" href="{{ route('assetmgr.market-orders.export', request()->query()) }}">Export CSV</a>
                    <a class="btn btn-secondary" style="margin-left: 8px;" href="{{ route('assetmgr.wallet-journal') }}">Wallet Journal</a>
                    <a class="btn btn-secondary" style="margin-left: 8px;" href="{{ route('assetmgr.index') }}">Assets</a>
                </form>

                <div class="table-responsive">
                    <table class="table table-condensed table-striped table-hover">
                        <thead><tr><th>Issued</th><th>Character owner</th><th>Side</th><th>Item</th><th>Price</th><th>Volume remaining / total</th><th>Location</th><th>Range</th><th>Escrow</th></tr></thead>
                        <tbody>
                        @forelse ($orders as $order)
                            <tr>
                                <td>{{ $order->issued }}</td>
                                <td>{{ $order->character_name ?? ('Character ' . $order->character_id) }}</td>
                                <td>{{ $order->is_buy_order ? 'Buy' : 'Sell' }}</td>
                                <td>{{ $order->type_name ?? ('Type ' . $order->type_id) }} <small class="text-muted">ID {{ $order->type_id }}</small></td>
                                <td>{{ number_format((float) $order->price, 2) }} ISK</td>
                                <td>{{ number_format((int) $order->volume_remain) }} / {{ number_format((int) $order->volume_total) }}</td>
                                <td>{{ $order->location_name ?? $order->location_id }} <small class="text-muted">Region {{ $order->region_id ?? '—' }}</small></td>
                                <td>{{ $order->order_range ?? '—' }}</td>
                                <td>{{ $order->escrow === null ? '—' : number_format((float) $order->escrow, 2) . ' ISK' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9">No cached active market orders. Confirm the character has granted esi-markets.read_character_orders.v1 and run a background sync.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $orders->links() }}
            </div>
        </div>
    </div>
</div>
@stop
