@extends('web::layouts.grids.12')

@section('title', 'Corporation Market Orders')
@section('page_header', 'Corporation Market Orders')
@section('page_description', 'Cached corporation-owned market orders. Access is controlled separately from personal assets.')

@section('full')
<div class="row">
    <div class="col-md-12">
        <div class="card card-primary">
            <div class="card-body">
                <h3>{{ $corporationName }}</h3>
                @if (session('status'))
                    <div class="alert alert-info">{{ session('status') }}</div>
                @endif

                @if ($syncState)
                    <p><strong>Sync:</strong> {{ ucfirst($syncState->status) }} |
                        <strong>Last successful:</strong> {{ $syncState->last_success_at ?? 'Never' }} |
                        <strong>Next eligible:</strong> {{ $syncState->next_eligible_at ?? 'Eligible now' }}</p>
                @endif

                @if (config('assetmgr.sync.enabled', false))
                    <form method="post" action="{{ route('assetmgr.corporation-market-orders.refresh', ['corporationId' => $corporationId]) }}" style="margin-bottom: 15px;">
                        @csrf
                        <button type="submit" class="btn btn-primary">Refresh corporation orders</button>
                    </form>
                @endif

                <form method="get" action="{{ route('assetmgr.corporation-market-orders', ['corporationId' => $corporationId]) }}" class="form-inline" style="margin-bottom: 15px;">
                    <div class="form-group" style="margin-right: 8px;">
                        <label class="sr-only" for="corp-orders-q">Search</label>
                        <input id="corp-orders-q" class="form-control" name="q" value="{{ $search }}" placeholder="Search item, location, or issuer">
                    </div>
                    <div class="form-group" style="margin-right: 8px;">
                        <label class="sr-only" for="corp-orders-side">Side</label>
                        <select id="corp-orders-side" class="form-control" name="side">
                            <option value="">All orders</option>
                            <option value="buy" @selected($side === 'buy')>Buy orders</option>
                            <option value="sell" @selected($side === 'sell')>Sell orders</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Apply</button>
                    <a class="btn btn-secondary" style="margin-left: 8px;" href="{{ route('assetmgr.corporation-market-orders.export', ['corporationId' => $corporationId] + request()->query()) }}">Export CSV</a>
                    <a class="btn btn-secondary" style="margin-left: 8px;" href="{{ route('assetmgr.corporation-assets', ['corporationId' => $corporationId]) }}">Corporation Assets</a>
                </form>

                <div class="table-responsive">
                    <table class="table table-condensed table-striped table-hover">
                        <thead><tr><th>Issued</th><th>Side</th><th>Item</th><th>Price</th><th>Volume remaining / total</th><th>Location</th><th>Issuer</th><th>Wallet division</th><th>Range</th><th>Escrow</th></tr></thead>
                        <tbody>
                        @forelse ($orders as $order)
                            <tr>
                                <td>{{ $order->issued }}</td>
                                <td>{{ $order->is_buy_order ? 'Buy' : 'Sell' }}</td>
                                <td>{{ $order->type_name ?? ('Type ' . $order->type_id) }} <small class="text-muted">ID {{ $order->type_id }}</small></td>
                                <td>{{ number_format((float) $order->price, 2) }} ISK</td>
                                <td>{{ number_format((int) $order->volume_remain) }} / {{ number_format((int) $order->volume_total) }}</td>
                                <td>{{ $order->location_name ?? $order->location_id }} <small class="text-muted">Region {{ $order->region_id ?? '—' }}</small></td>
                                <td>{{ $order->issued_by ?? '—' }}</td>
                                <td>{{ $order->wallet_division ?? '—' }}</td>
                                <td>{{ $order->order_range ?? '—' }}</td>
                                <td>{{ $order->escrow === null ? '—' : number_format((float) $order->escrow, 2) . ' ISK' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="10">No cached corporation orders. Confirm an allowlisted corporation, a Director token with esi-markets.read_corporation_orders.v1, and the separate corporation market-order permission.</td></tr>
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
