<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Asset Manager</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem; }
        form { display: flex; gap: .5rem; margin: 1rem 0; }
        input { padding: .55rem; min-width: 18rem; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border-bottom: 1px solid #bbb; padding: .55rem; text-align: left; }
        th { position: sticky; top: 0; background: #222; color: #fff; }
        .muted { color: #666; }
    </style>
</head>
<body>
    <h1>Triglavian Asset Manager</h1>
    <p class="muted">Cached asset snapshot · Character ownership is shown for every row.</p>

    <form method="get" action="{{ route('assetmgr.index') }}">
        <input name="q" value="{{ $search }}" placeholder="Search item, character, or location">
        <input name="character_id" type="number" value="{{ $characterId }}" placeholder="Character ID (optional)">
        <button type="submit">Search</button>
    </form>

    <table>
        <thead><tr>
            <th>Item</th><th>Quantity</th><th>Character owner</th><th>Location</th>
            <th>Jita value</th><th>Regional value</th><th>Last seen</th>
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
                <td>{{ $asset->last_seen_at ?? 'Unknown' }}</td>
            </tr>
        @empty
            <tr><td colspan="7">No cached assets found. Synchronization is not implemented in this foundation stage.</td></tr>
        @endforelse
        </tbody>
    </table>

    {{ $assets->links() }}
</body>
</html>
