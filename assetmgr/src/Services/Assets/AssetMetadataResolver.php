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
     * Resolve type names and location/region metadata through SeAT's existing data.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function resolve(string $ownerKind, int $ownerId, array $rows): array
    {
        $typeIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['type_id'], $rows)));
        $typeNames = $typeIds === [] ? [] : InvType::query()
            ->whereIn('typeID', $typeIds)->pluck('typeName', 'typeID')->all();

        $characterName = $ownerKind === 'character'
            ? CharacterInfo::query()->where('character_id', $ownerId)->value('name')
            : null;
        $corporationName = $ownerKind === 'corporation'
            ? CorporationInfo::query()->where('corporation_id', $ownerId)->value('name')
            : null;

        $stationIds = $systemIds = $structureIds = [];
        foreach ($rows as $row) {
            $locationId = (int) $row['location_id'];
            switch ($row['location_type'] ?? null) {
                case 'station': $stationIds[] = $locationId; break;
                case 'solar_system': $systemIds[] = $locationId; break;
                case 'structure': $structureIds[] = $locationId; break;
            }
        }

        $stationNames = $stationSystems = [];
        if ($stationIds !== []) {
            $stations = UniverseStation::query()->whereIn('station_id', array_unique($stationIds))->get(['station_id', 'name', 'system_id']);
            foreach ($stations as $station) {
                $stationNames[(int) $station->station_id] = $station->name;
                if ($station->system_id) $stationSystems[(int) $station->station_id] = (int) $station->system_id;
            }
        }

        $structureNames = $structureSystems = [];
        if ($structureIds !== []) {
            $structures = UniverseStructure::query()->whereIn('structure_id', array_unique($structureIds))->get(['structure_id', 'name', 'solar_system_id']);
            foreach ($structures as $structure) {
                $structureNames[(int) $structure->structure_id] = $structure->name;
                if ($structure->solar_system_id) $structureSystems[(int) $structure->structure_id] = (int) $structure->solar_system_id;
            }
        }

        $systemIds = array_values(array_unique(array_merge(
            array_map('intval', $systemIds),
            array_values($stationSystems),
            array_values($structureSystems)
        )));
        $systemNames = $systemRegions = [];
        if ($systemIds !== []) {
            foreach (SolarSystem::query()->whereIn('system_id', $systemIds)->get(['system_id', 'name', 'region_id']) as $system) {
                $systemNames[(int) $system->system_id] = $system->name;
                $systemRegions[(int) $system->system_id] = (int) $system->region_id;
            }
        }

        $itemRowsById = [];
        foreach ($rows as $row) $itemRowsById[(int) $row['item_id']] = $row;
        $regionByItemId = [];

        foreach ($rows as &$row) {
            $typeId = (int) $row['type_id'];
            $locationId = (int) $row['location_id'];
            $locationType = $row['location_type'] ?? null;
            $row['type_name'] = $typeNames[$typeId] ?? ('Type ' . $typeId);
            $row['character_name'] = $ownerKind === 'character' ? $characterName : null;
            $row['corporation_name'] = $ownerKind === 'corporation' ? $corporationName : null;
            $row['location_name'] = match ($locationType) {
                'station' => $stationNames[$locationId] ?? ('Station ' . $locationId),
                'solar_system' => $systemNames[$locationId] ?? ('System ' . $locationId),
                'structure' => $structureNames[$locationId] ?? ('Structure ' . $locationId),
                'item' => isset($itemRowsById[$locationId])
                    ? ($typeNames[(int) $itemRowsById[$locationId]['type_id']] ?? ('Item ' . $locationId))
                    : ('Container item ' . $locationId),
                default => 'Location ' . $locationId,
            };

            $systemId = match ($locationType) {
                'station' => $stationSystems[$locationId] ?? null,
                'solar_system' => $locationId,
                'structure' => $structureSystems[$locationId] ?? null,
                default => null,
            };
            $row['regional_region_id'] = $systemId === null ? null : ($systemRegions[$systemId] ?? null);
            if ($row['regional_region_id'] !== null) {
                $regionByItemId[(int) $row['item_id']] = (int) $row['regional_region_id'];
            }
        }
        unset($row);

        // Propagate region from a container/ship to nested assets; repeat to handle nested containers.
        for ($pass = 0; $pass < 8; $pass++) {
            $changed = false;
            foreach ($rows as &$row) {
                if (($row['location_type'] ?? null) !== 'item' || ! empty($row['regional_region_id'])) continue;
                $parentId = (int) $row['location_id'];
                if (isset($regionByItemId[$parentId])) {
                    $row['regional_region_id'] = $regionByItemId[$parentId];
                    $regionByItemId[(int) $row['item_id']] = $regionByItemId[$parentId];
                    $changed = true;
                }
            }
            unset($row);
            if (! $changed) break;
        }

        return $rows;
    }
}
