@extends('web::layouts.grids.12')

@section('title', 'Asset Manager')
@section('page_header', 'Triglavian Asset Manager')
@section('page_description', 'Cached personal inventory, linked characters, and regional sell valuations.')

@section('full')
<div class="row">
    <div class="col-md-12">
        <div class="card card-primary">
            <div class="card-body">
                <p class="text-muted">Personal assets are scoped to characters linked to your SeAT account. Browse, sort, and export using cached data; this page does not call ESI.</p>
                @if ($syncStates->isNotEmpty())
                    <div class="table-responsive">
                        <table class="table table-condensed">
                            <thead><tr><th>Character ID</th><th>Sync status</th><th>Last successful sync</th><th>Next eligible sync</th></tr></thead>
                            <tbody>
                            @foreach ($syncStates as $state)
                                <tr>
                                    <td>{{ $state->owner_id }}</td>
                                    <td>{{ ucfirst($state->status) }}</td>
                                    <td>{{ $state->last_success_at ?? 'Never completed' }}</td>
                                    <td>{{ $state->next_eligible_at ?? 'Eligible now' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-muted">No sync history yet. After package installation, enable sync and run <code>php artisan assetmgr:sync-characters</code>.</p>
                @endif

                @if (config('assetmgr.corporation_sync_enabled', false))
                    <form method="get" action="{{ route('assetmgr.corporation.index') }}" class="form-inline" style="margin-bottom: 15px;">
                        <div class="form-group" style="margin-right: 8px;">
                            <label class="sr-only" for="assetmgr-corporation-id">Corporation ID</label>
                            <input id="assetmgr-corporation-id" class="form-control" name="corporation_id" type="number" min="1" placeholder="Corporation ID">
                        </div>
                        <button type="submit" class="btn btn-secondary">Open corporation assets</button>
                    </form>
                @endif

                <form method="get" action="{{ route('assetmgr.index') }}" class="form-inline" style="margin-bottom: 15px;">
                    <div class="form-group" style="margin-right: 8px;">
                        <label class="sr-only" for="assetmgr-q">Search</label>
                        <input id="assetmgr-q" class="form-control" name="q" value="{{ $search }}" placeholder="Search item, character, location">
                    </div>
                    <div class="form-group" style="margin-right: 8px;">
                        <label class="sr-only" for="assetmgr-character">Character ID</label>
                        <input id="assetmgr-character" class="form-control" name="character_id" type="number" min="1" value="{{ $characterId }}" placeholder="Character ID">
                    </div>
                    <div class="form-group" style="margin-right: 8px;">
                        <label class="sr-only" for="assetmgr-sort">Sort</label>
                        <select id="assetmgr-sort" class="form-control" name="sort">
                            @foreach (['character' => 'Character', 'item' => 'Item', 'quantity' => 'Quantity', 'location' => 'Location', 'jita' => 'Jita sell price', 'regional' => 'Regional sell price', 'last_seen' => 'Last synced'] as $key => $label)
                                <option value="{{ $key }}" {{ $sort === $key ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group" style="margin-right: 8px;">
                        <label class="sr-only" for="assetmgr-direction">Sort direction</label>
                        <select id="assetmgr-direction" class="form-control" name="direction">
                            <option value="asc" {{ $direction === 'asc' ? 'selected' : '' }}>Ascending</option>
                            <option value="desc" {{ $direction === 'desc' ? 'selected' : '' }}>Descending</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Apply</button>
                    <a class="btn btn-secondary" style="margin-left: 8px;" href="{{ route('assetmgr.export', request()->query()) }}">Export CSV</a>
                </form>

                <div class="table-responsive">
                    <table class="table table-condensed table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Quantity</th>
                                <th>Character owner</th>
                                <th>Location</th>
                                <th>Jita sell total</th>
                                <th>Regional sell total</th>
                                <th>Price cache timestamps</th>
                                <th>Last seen</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse ($assets as $asset)
                            <tr>
                                <td>{{ $asset->type_name }}</td>
                                <td>{{ number_format($asset->quantity) }}</td>
                                <td>{{ $asset->character_name ?? ('Character ' . $asset->character_id) }}</td>
                                <td>{{ $asset->location_name ?? $asset->location_id }}</td>
                                <td>{{ $asset->jita_unit_price === null ? '—' : number_format((float) $asset->jita_unit_price * $asset->quantity, 2) . ' ISK' }}</td>
                                <td>{{ $asset->regional_unit_price === null ? '—' : number_format((float) $asset->regional_unit_price * $asset->quantity, 2) . ' ISK' }}</td>
                                <td>
                                    <div>Jita: {{ $asset->jita_price_updated_at ?? '—' }}
                                        @if ($asset->jita_price_updated_at && \Illuminate\Support\Carbon::parse($asset->jita_price_updated_at)->addSeconds((int) config('assetmgr.pricing.stale_after_seconds', 3600))->isPast()) <strong>(stale)</strong> @endif
                                    </div>
                                    <div>Region: {{ $asset->regional_price_updated_at ?? '—' }}
                                        @if ($asset->regional_price_updated_at && \Illuminate\Support\Carbon::parse($asset->regional_price_updated_at)->addSeconds((int) config('assetmgr.pricing.stale_after_seconds', 3600))->isPast()) <strong>(stale)</strong> @endif
                                    </div>
                                </td>
                                <td>{{ $asset->last_seen_at ?? 'Unknown' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8">No cached assets found. Sync jobs require valid SeAT scopes and completed background synchronization.</td></tr>
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
