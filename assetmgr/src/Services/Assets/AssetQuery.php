<?php

namespace Triglavian\AssetManager\Services\Assets;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AssetQuery
{
    /**
     * Personal-only query, scoped to SeAT's verified linked character IDs.
     * Prices are joined from the local cache; no ESI calls happen here.
     *
     * @param list<int> $characterIds
     */
    public function personal(array $characterIds, array $filters = []): Builder
    {
        $characterIds = array_values(array_unique(array_filter(
            array_map('intval', $characterIds),
            static fn (int $id): bool => $id > 0
        )));

        $jitaPrices = DB::table('assetmgr_prices')
            ->select(['type_id', 'unit_price', 'fetched_at', 'source_expires_at'])
            ->where('region_id', (int) config('assetmgr.pricing.jita_region_id', 10000002))
            ->where('source', 'esi-orders')
            ->where('price_basis', 'sell');

        $regionalPrices = DB::table('assetmgr_prices')
            ->select(['region_id', 'type_id', 'unit_price', 'fetched_at', 'source_expires_at'])
            ->where('source', 'esi-orders')
            ->where('price_basis', 'sell');

        $query = DB::table('assetmgr_assets as assets')
            ->leftJoinSub($jitaPrices, 'jita_prices', static function ($join): void {
                $join->on('assets.type_id', '=', 'jita_prices.type_id');
            })
            ->leftJoinSub($regionalPrices, 'regional_prices', static function ($join): void {
                $join->on('assets.type_id', '=', 'regional_prices.type_id')
                    ->on('assets.regional_region_id', '=', 'regional_prices.region_id');
            })
            ->where('assets.owner_kind', 'character')
            ->whereIn('assets.owner_id', $characterIds)
            ->select([
                'assets.owner_kind', 'assets.owner_id', 'assets.character_id',
                'assets.character_name', 'assets.corporation_id', 'assets.corporation_name', 'assets.item_id',
                'assets.type_id', 'assets.type_name', 'assets.quantity',
                'assets.location_id', 'assets.location_name', 'assets.location_flag',
                'assets.location_type', 'assets.regional_region_id', 'assets.last_seen_at',
                'jita_prices.unit_price as jita_unit_price',
                'jita_prices.fetched_at as jita_price_updated_at',
                'jita_prices.source_expires_at as jita_price_expires_at',
                'regional_prices.unit_price as regional_unit_price',
                'regional_prices.fetched_at as regional_price_updated_at',
                'regional_prices.source_expires_at as regional_price_expires_at',
            ]);

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\\\') . '%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder->where('assets.type_name', 'like', $like)
                    ->orWhere('assets.character_name', 'like', $like)
                    ->orWhere('assets.location_name', 'like', $like);
            });
        }

        if (! empty($filters['character_id'])) {
            $characterId = (int) $filters['character_id'];
            if (! in_array($characterId, $characterIds, true)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('assets.character_id', $characterId);
            }
        }

        $sorts = [
            'item' => 'assets.type_name',
            'quantity' => 'assets.quantity',
            'character' => 'assets.character_name',
            'location' => 'assets.location_name',
            'jita' => 'jita_prices.unit_price',
            'regional' => 'regional_prices.unit_price',
            'last_seen' => 'assets.last_seen_at',
        ];
        $sort = (string) ($filters['sort'] ?? 'character');
        if (! isset($sorts[$sort])) {
            throw new InvalidArgumentException('Unsupported asset sort key.');
        }

        $direction = strtolower((string) ($filters['direction'] ?? 'asc'));
        if (! in_array($direction, ['asc', 'desc'], true)) $direction = 'asc';

        return $query->orderBy($sorts[$sort], $direction)->orderBy('assets.type_id');
    }

    /**
     * Corporation inventory query. The caller must authorize the requested
     * corporation through SeAT's corporation policy before calling this method.
     */
    public function corporation(int $corporationId, array $filters = []): Builder
    {
        if ($corporationId < 1) {
            throw new InvalidArgumentException('Invalid corporation ID.');
        }

        $jitaPrices = DB::table('assetmgr_prices')
            ->select(['type_id', 'unit_price', 'fetched_at'])
            ->where('region_id', (int) config('assetmgr.pricing.jita_region_id', 10000002))
            ->where('source', 'esi-orders')
            ->where('price_basis', 'sell');

        $regionalPrices = DB::table('assetmgr_prices')
            ->select(['region_id', 'type_id', 'unit_price', 'fetched_at'])
            ->where('source', 'esi-orders')
            ->where('price_basis', 'sell');

        $query = DB::table('assetmgr_assets as assets')
            ->leftJoinSub($jitaPrices, 'jita_prices', static function ($join): void {
                $join->on('assets.type_id', '=', 'jita_prices.type_id');
            })
            ->leftJoinSub($regionalPrices, 'regional_prices', static function ($join): void {
                $join->on('assets.type_id', '=', 'regional_prices.type_id')
                    ->on('assets.regional_region_id', '=', 'regional_prices.region_id');
            })
            ->where('assets.owner_kind', 'corporation')
            ->where('assets.owner_id', $corporationId)
            ->select([
                'assets.owner_kind', 'assets.owner_id', 'assets.character_id',
                'assets.character_name', 'assets.corporation_id', 'assets.corporation_name',
                'assets.item_id', 'assets.type_id', 'assets.type_name', 'assets.quantity',
                'assets.location_id', 'assets.location_name', 'assets.location_flag',
                'assets.location_type', 'assets.regional_region_id', 'assets.last_seen_at',
                'jita_prices.unit_price as jita_unit_price',
                'jita_prices.fetched_at as jita_price_updated_at',
                'regional_prices.unit_price as regional_unit_price',
                'regional_prices.fetched_at as regional_price_updated_at',
            ]);

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\\\\\\\') . '%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder->where('assets.type_name', 'like', $like)
                    ->orWhere('assets.location_name', 'like', $like);
            });
        }

        $sorts = [
            'item' => 'assets.type_name',
            'quantity' => 'assets.quantity',
            'location' => 'assets.location_name',
            'jita' => 'jita_prices.unit_price',
            'regional' => 'regional_prices.unit_price',
            'last_seen' => 'assets.last_seen_at',
        ];
        $sort = (string) ($filters['sort'] ?? 'item');
        if (! isset($sorts[$sort])) {
            throw new InvalidArgumentException('Unsupported asset sort key.');
        }

        $direction = strtolower((string) ($filters['direction'] ?? 'asc'));
        if (! in_array($direction, ['asc', 'desc'], true)) $direction = 'asc';

        return $query->orderBy($sorts[$sort], $direction)->orderBy('assets.type_id');
    }
}
