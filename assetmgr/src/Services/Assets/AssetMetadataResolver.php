<?php

namespace Triglavian\AssetManager\Services\Assets;

use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Sde\InvType;
use Seat\Eveapi\Models\Sde\SolarSystem;
use Seat\Eveapi\Models\Universe\UniverseStation;
use Seat\Eveapi\Models\Universe\UniverseStructure;

class AssetMetadataResolver
{
    /**
     * Resolve type and known location names using SeAT's existing SDE/universe data.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function resolve(string $ownerKind, int $ownerId, array $rows): array
    {
        $typeIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['type_id'],
            $rows
        )));
        $typeNames = $typeIds === []
            ? []
            : InvType::query()->whereIn('typeID', $typeIds)->pluck('typeName', 'typeID')->all();

        $characterName = null;
        if ($ownerKind === 'character') {
            $characterName = CharacterInfo::query()->where('character_id', $ownerId)->value('name');
        }

        $stationIds = $systemIds = $structureIds = [];
        foreach ($rows as $row) {
            $locationId = (int) $row['location_id'];
            switch ($row['location_type'] ?? null) {
                case 'station':
                    $stationIds[] = $locationId;
                    break;
                case 'solar_system':
                    $systemIds[] = $locationId;
                    break;
                case 'structure':
                    $structureIds[] = $locationId;
                    break;
            }
        }

        $stationNames = $stationIds === [] ? [] : UniverseStation::query()
            ->whereIn('station_id', array_values(array_unique($stationIds)))
            ->pluck('name', 'station_id')->all();
        $systemNames = $systemIds === [] ? [] : SolarSystem::query()
            ->whereIn('system_id', array_values(array_unique($systemIds)))
            ->pluck('name', 'system_id')->all();
        $structureNames = $structureIds === [] ? [] : UniverseStructure::query()
            ->whereIn('structure_id', array_values(array_unique($structureIds)))
            ->pluck('name', 'structure_id')->all();

        foreach ($rows as &$row) {
            $typeId = (int) $row['type_id'];
            $locationId = (int) $row['location_id'];
            $row['type_name'] = $typeNames[$typeId] ?? ('Type ' . $typeId);
            $row['character_name'] = $ownerKind === 'character' ? $characterName : null;

            $row['location_name'] = match ($row['location_type'] ?? null) {
                'station' => $stationNames[$locationId] ?? ('Station ' . $locationId),
                'solar_system' => $systemNames[$locationId] ?? ('System ' . $locationId),
                'structure' => $structureNames[$locationId] ?? ('Structure ' . $locationId),
                'item' => 'Container item ' . $locationId,
                default => 'Location ' . $locationId,
            };
        }
        unset($row);

        return $rows;
    }
}
