<?php

namespace Triglavian\\AssetManager\\Services\\Pricing;

use Illuminate\\Support\\Facades\\DB;

class PriceCacheRepository
{
    public function find(int $typeId, int $regionId, string $source, string $basis): ?object
    {
        return DB::table('assetmgr_prices')
            ->where('type_id', $typeId)
            ->where('region_id', $regionId)
            ->where('source', $source)
            ->where('price_basis', $basis)
            ->whereNotNull('unit_price')
            ->first();
    }

    /**
     * @param array{type_id:int,region_id:int,source:string,price_basis:string,unit_price:?string,source_expires_at:?string,fetched_at:string} $price
     */
    public function store(array $price): void
    {
        DB::table('assetmgr_prices')->updateOrInsert(
            [
                'type_id' => $price['type_id'],
                'region_id' => $price['region_id'],
                'source' => $price['source'],
                'price_basis' => $price['price_basis'],
            ],
            [
                'unit_price' => $price['unit_price'],
                'source_expires_at' => $price['source_expires_at'],
                'fetched_at' => $price['fetched_at'],
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }
}
