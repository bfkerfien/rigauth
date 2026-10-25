<?php

namespace Triglavian\AssetManager\Services\Assets;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AssetQuery
{
    /**
     * Build a personal-only query scoped to verified character IDs.
     *
     * An empty character list intentionally produces no rows.
     *
     * @param list<int> $characterIds
     */
    public function personal(array $characterIds, array $filters = []): Builder
    {
        $characterIds = array_values(array_unique(array_filter(
            array_map('intval', $characterIds),
            static fn (int $id): bool => $id > 0
        )));

        $query = DB::table('assetmgr_assets')
            ->where('owner_kind', 'character')
            ->whereIn('owner_id', $characterIds)
            ->select([
                'owner_kind', 'owner_id', 'character_id', 'character_name',
                'corporation_id', 'type_id', 'type_name', 'quantity',
                'location_id', 'location_name', 'location_flag',
                'jita_unit_price', 'regional_unit_price', 'regional_region_id',
                'price_updated_at', 'last_seen_at',
            ]);

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $like = '%' . addcslashes($search, '%_\\\\') . '%';
                $builder->where('type_name', 'like', $like)
                    ->orWhere('character_name', 'like', $like)
                    ->orWhere('location_name', 'like', $like);
            });
        }

        if (! empty($filters['character_id'])) {
            $characterId = (int) $filters['character_id'];
            if (! in_array($characterId, $characterIds, true)) {
                // Explicitly empty results for a character outside this user’s portfolio.
                $query->whereRaw('1 = 0');
            } else {
                $query->where('character_id', $characterId);
            }
        }

        $sorts = [
            'item' => 'type_name',
            'quantity' => 'quantity',
            'character' => 'character_name',
            'location' => 'location_name',
            'jita' => 'jita_unit_price',
            'regional' => 'regional_unit_price',
            'last_seen' => 'last_seen_at',
        ];
        $sort = (string) ($filters['sort'] ?? 'character');
        if (! isset($sorts[$sort])) {
            throw new InvalidArgumentException('Unsupported asset sort key.');
        }

        $direction = strtolower((string) ($filters['direction'] ?? 'asc'));
        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }

        return $query->orderBy($sorts[$sort], $direction)->orderBy('type_id');
    }
}
