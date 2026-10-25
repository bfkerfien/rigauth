@extends('web::layouts.grids.12')

@section('title', 'Corporation Asset Manager')
@section('page_header', 'Corporation Asset Manager')
@section('page_description', 'Cached corporation inventory and regional sell valuations.')

@section('full')
<div class="row">
    <div class="col-md-12">
        <div class="card card-primary">
            <div class="card-body">
                <h4>{{ $corporationName }} <small>({{ $corporationId }})</small></h4>
                <p class="text-muted">Corporation assets are stored separately from personal character portfolios. Access is checked through SeAT's corporation policy and the configured corporation allowlist.</p>
                <div class="row">
                    <div class="col-md-6">
                        <h4>Jita known sell subtotal</h4>
                        <p><strong>{{ number_format((float) ($currentValuation->jita_total_value ?? 0), 2) }} ISK</strong></p>
                    </div>
                    <div class="col-md-6">
                        <h4>Regional known sell subtotal</h4>
                        <p><strong>{{ number_format((float) ($currentValuation->regional_total_value ?? 0), 2) }} ISK</strong></p>
                    </div>
                </div>
                <p class="text-muted">Asset rows: {{ number_format((int) ($currentValuation->asset_count ?? 0)) }} · Units: {{ number_format((int) ($currentValuation->quantity_sum ?? 0)) }} · Unpriced rows (Jita / regional): {{ number_format((int) ($currentValuation->jita_unpriced_count ?? 0)) }} / {{ number_format((int) ($currentValuation->regional_unpriced_count ?? 0)) }}.</p>
                @if ($valuationHistory->isNotEmpty())
                    <h4>Hourly valuation history</h4>
                    <div class="table-responsive"><table class="table table-condensed table-striped">
                        <thead><tr><th>Snapshot</th><th>Jita known subtotal</th><th>Regional known subtotal</th></tr></thead>
                        <tbody>@foreach ($valuationHistory as $snapshot)
                            <tr><td>{{ $snapshot->snapshot_at }}</td><td>{{ number_format((float) $snapshot->jita_total_value, 2) }} ISK</td><td>{{ number_format((float) $snapshot->regional_total_value, 2) }} ISK</td></tr>
                        @endforeach</tbody>
                    </table></div>
                @else
                    <p class="text-muted">No valuation history yet. Run php artisan assetmgr:capture-valuations after syncs and price cache updates.</p>
                @endif

                <form method="get" action="{{ route('assetmgr.corporation.index') }}" class="form-inline" style="margin-bottom: 15px;">
                    <input type="hidden" name="corporation_id" value="{{ $corporationId }}">
                    <div class="form-group" style="margin-right: 8px;">
                        <label class="sr-only" for="assetmgr-corp-q">Search</label>
                        <input id="assetmgr-corp-q" class="form-control" name="q" value="{{ $search }}" placeholder="Search item or location">
                    </div>
                    <div class="form-group" style="margin-right: 8px;">
                        <label class="sr-only" for="assetmgr-corp-sort">Sort</label>
                        <select id="assetmgr-corp-sort" class="form-control" name="sort">
                            @foreach (['item' => 'Item', 'quantity' => 'Quantity', 'location' => 'Location', 'jita' => 'Jita sell price', 'regional' => 'Regional sell price', 'last_seen' => 'Last synced'] as $key => $label)
                                <option value="{{ $key }}" {{ $sort === $key ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <select class="form-control" name="direction" aria-label="Sort direction">
                        <option value="asc" {{ $direction === 'asc' ? 'selected' : '' }}>Ascending</option>
                        <option value="desc" {{ $direction === 'desc' ? 'selected' : '' }}>Descending</option>
                    </select>
                    <button type="submit" class="btn btn-primary">Apply</button>
                    <a class="btn btn-secondary" style="margin-left: 8px;" href="{{ route('assetmgr.corporation.export', array_merge(request()->query(), ['corporation_id' => $corporationId])) }}">Export CSV</a>
                </form>

                <div class="table-responsive">
                    <table class="table table-condensed table-striped table-hover">
                        <thead><tr>
                            <th>Item</th><th>Quantity</th><th>Owner</th><th>Location</th>
                            <th>Jita sell total</th><th>Regional sell total</th><th>Last seen</th>
                        </tr></thead>
                        <tbody>
                        @forelse ($assets as $asset)
                            <tr>
                                <td>{{ $asset->type_name }}</td>
                                <td>{{ number_format($asset->quantity) }}</td>
                                <td>{{ $asset->corporation_name ?? $corporationName }}</td>
                                <td>{{ $asset->location_name ?? $asset->location_id }}</td>
                                <td>{{ $asset->jita_unit_price === null ? '—' : number_format((float) $asset->jita_unit_price * $asset->quantity, 2) . ' ISK' }}</td>
                                <td>{{ $asset->regional_unit_price === null ? '—' : number_format((float) $asset->regional_unit_price * $asset->quantity, 2) . ' ISK' }}</td>
                                <td>{{ $asset->last_seen_at ?? 'Unknown' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7">No cached corporation assets found. The corporation sync command may not have run, or no eligible Director token is available.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $assets->links() }}
            </div>
        </div>
    </div>
</div>
@stop
