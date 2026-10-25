<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Asset Manager</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem; }
        form { display: flex; flex-wrap: wrap; gap: .5rem; margin: 1rem 0; }
        input, select, button { padding: .55rem; }
        input[name="q"] { min-width: 18rem; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border-bottom: 1px solid #bbb; padding: .55rem; text-align: left; }
        th { position: sticky; top: 0; background: #222; color: #fff; }
        .muted { color: #666; }
    </style>
</head>
<body>
    <h1>Triglavian Asset Manager</h1>
    <p class="muted">Cached asset snapshot · Character ownership is shown for every personal asset.</p>

    <form method="get" action="{{ route('assetmgr.index') }}">
        <input name="q" value="{{ $search }}" placeholder="Search item, character, or location">
        <input name="character_id" type="number" min="1" value="{{ $characterId }}" placeholder="Character ID (optional)">
        <label>Sort
            <select name="sort">
                @foreach (['character' => 'Character', 'item' => 'Item', 'quantity' => 'Quantity', 'location' => 'Location', 'jita' => 'Jita price', 'regional' => 'Regional price', 'last_seen' => 'Last synced'] as $key => $label)
                    <option value="{{ $key }}" @selected($sort === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <select name="direction" aria-label="Sort direction">
            <option value="asc" @selected($direction === 'asc')>Ascending</option>
            <option value="desc" @selected($direction === 'desc')>Descending</option>
        </select>
        <button type="submit">Apply</button>
    </form>

    <table>
        <thead><tr>
            <th>Item</th><th>Quantity</th><th>Character owner</th><th>Location</th>
            <th>Jita sell total</th><th>Regional sell total</th><th>Price cache timestamps</th><th>Last seen</th>
        </tr></thead>
        <tbody>
        @forelse ($assets as $asset)
            <tr>
                <td>{{ $asset->type_name }}</td>
                <td>{{ number_format($asset->quantity) }}</td>
                <td>{{ $asset->owner_kind === 'corporation' ? 'Corporation ' . $asset->owner_id : ($asset->character_name ?? 'Character ' . $asset->character_id) }}</td>
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
            <tr><td colspan="8">No cached assets found. Sync jobs require the SeAT ESI adapter to be configured.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $assets->links() }}
</body>
</html>
