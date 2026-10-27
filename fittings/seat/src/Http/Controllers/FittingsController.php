<?php

namespace Seat\Web\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class FittingsController
{
    private function market()
    {
        return DB::connection();
    }

    private function requireAdmin(): void
    {
        abort_unless(auth()->check(), 401);
        // Keep writes restricted until a specific SeAT permission is wired in.
        abort_unless(auth()->user()->can('global.superuser'), 403);
    }

    public function index(Request $request): View
    {
        $this->requireAdmin();
        $db = $this->market();
        $categories = $db->table('fit_categories')->orderBy('sort_order')->orderBy('category_name')->get();
        $selectedCategory = $request->integer('category') ?: null;

        $query = $db->table('fit_fits as f')
            ->select('f.id', 'f.fit_name', 'f.ship_name', 'f.ship_type_id', 'f.fit_text', 'f.visibility', 'f.updated_at')
            ->orderBy('f.fit_name');

        if ($selectedCategory) {
            $query->join('fit_category_assignments as fc', 'fc.fit_id', '=', 'f.id')
                ->where('fc.category_id', $selectedCategory);
        }

        $fits = $query->paginate(30)->withQueryString();

        $fitIds = $fits->getCollection()->pluck('id')->all();
        $categoryMap = [];
        if ($fitIds) {
            foreach ($db->table('fit_category_assignments as fc')
                ->join('fit_categories as c', 'c.id', '=', 'fc.category_id')
                ->whereIn('fc.fit_id', $fitIds)
                ->orderBy('c.category_name')
                ->get(['fc.fit_id', 'c.id', 'c.category_name']) as $row) {
                $categoryMap[$row->fit_id][] = $row;
            }
        }

        return view('web::fittings.manage', [
            'fits' => $fits,
            'categories' => $categories,
            'selectedCategory' => $selectedCategory,
            'categoryMap' => $categoryMap,
            'locations' => $db->table('fit_location_settings')->get()->keyBy('location_type'),
            'stations' => $this->stationOptions($db),
        ]);
    }

    public function pilotIndex(): View
    {
        abort_unless(auth()->check(), 401);
        $db = $this->market();
        $fits = $db->table('fit_fits')
            ->where('visibility', 'alliance')
            ->orderBy('ship_name')
            ->orderBy('fit_name')
            ->get(['id', 'fit_name', 'ship_name', 'ship_type_id', 'fit_text']);

        $doctrines = $db->table('fit_doctrines')->orderBy('name')->get();
        $doctrineFits = $db->table('fit_doctrine_assignments as da')
            ->join('fit_doctrines as d', 'd.name', '=', 'da.doctrine')
            ->join('fit_fits as f', 'f.id', '=', 'da.fit_id')
            ->where('f.visibility', 'alliance')
            ->orderBy('f.ship_name')
            ->orderBy('f.fit_name')
            ->get(['da.doctrine', 'd.location_scope', 'f.id', 'f.fit_name', 'f.ship_name', 'f.ship_type_id', 'f.fit_text'])
            ->groupBy('doctrine');

        foreach ($doctrineFits as $doctrineFitList) {
            foreach ($doctrineFitList as $fit) {
                $fit->clipboard_text = $this->eftClipboardText($fit);
            }
        }

        $pilots = auth()->user()->characters->unique('character_id')->values();
        $pilotIds = $pilots->pluck('character_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $characterDoctrineSettings = $db->table('fit_character_doctrine_settings')->whereIn('character_id', $pilotIds)->get()->keyBy(fn ($row) => $row->character_id . '|' . $row->location_type);
        $characterDoctrineSelections = $db->table('fit_character_doctrine_assignments')->whereIn('character_id', $pilotIds)->get()->groupBy(fn ($row) => $row->character_id . '|' . $row->location_type);
        $fitSkillStatus = $this->pilotFitEligibility($db, $doctrineFits, $pilots);
        $locations = $db->table('fit_location_settings')->get()->keyBy('location_type');
        $fitInventoryStatus = $this->pilotFitInventoryStatus($db, $doctrineFits, $pilots, $locations);
        $inventoryShortages = $this->missingInventoryShortages($db, $doctrineFits, $pilots, $locations);

        $shoppingLists = $db->table('fit_shopping_lists')
            ->where('owner_user_id', auth()->id())
            ->orderBy('list_type')
            ->orderBy('list_name')
            ->get();
        $listIds = $shoppingLists->pluck('id')->all();
        $shoppingListItems = $listIds
            ? $db->table('fit_shopping_list_items')->whereIn('shopping_list_id', $listIds)
                ->orderBy('category')->orderBy('sort_order')->get()->groupBy('shopping_list_id')
            : collect();

        return view('web::fittings', [
            'fits' => $fits,
            'doctrines' => $doctrines,
            'doctrineFits' => $doctrineFits,
            'pilots' => $pilots,
            'fitSkillStatus' => $fitSkillStatus,
            'fitInventoryStatus' => $fitInventoryStatus,
            'shoppingLists' => $shoppingLists,
            'shoppingListItems' => $shoppingListItems,
            'locations' => $locations,
            'inventoryShortages' => $inventoryShortages,
            'characterDoctrineSettings' => $characterDoctrineSettings,
            'characterDoctrineSelections' => $characterDoctrineSelections,
        ]);
    }

    public function storePilotDoctrineSelections(Request $request): RedirectResponse
    {
        abort_unless(auth()->check(), 401);
        $data = $request->validate([
            'character_id' => ['required', 'integer', 'min:1'],
            'location_type' => ['required', 'in:home,deployment,third'],
            'doctrines' => ['nullable', 'array'],
            'doctrines.*' => ['required', 'string', 'max:120'],
        ]);
        $characterId = (int) $data['character_id'];
        abort_unless(auth()->user()->characters->contains(fn ($character) => (int) $character->character_id === $characterId), 403);
        $db = $this->market();
        $selected = array_values(array_unique($data['doctrines'] ?? []));
        if ($selected && $db->table('fit_doctrines')->whereIn('name', $selected)->count() !== count($selected)) {
            return back()->withErrors(['doctrines' => 'One or more selected doctrines are no longer available.'])->withInput();
        }
        $locationType = $data['location_type'];
        $db->transaction(function () use ($db, $characterId, $locationType, $selected) {
            $db->table('fit_character_doctrine_settings')->updateOrInsert(
                ['character_id' => $characterId, 'location_type' => $locationType],
                ['owner_user_id' => auth()->id(), 'is_configured' => true, 'updated_at' => now()]
            );
            $db->table('fit_character_doctrine_assignments')->where('character_id', $characterId)->where('location_type', $locationType)->delete();
            if ($selected) $db->table('fit_character_doctrine_assignments')->insert(array_map(fn ($name) => [
                'character_id' => $characterId, 'location_type' => $locationType, 'doctrine' => $name, 'created_at' => now(),
            ], $selected));
        });
        return redirect()->route('seatcore::fittings.index')->with('success', 'Doctrine selection saved for this character and location.');
    }

    public function storePilotShoppingList(Request $request): RedirectResponse
    {
        abort_unless(auth()->check(), 401);
        $data = $request->validate([
            'list_name' => ['required', 'string', 'max:160'],
            'list_type' => ['required', 'in:home,deployment,third'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);

        $db = $this->market();
        $location = $db->table('fit_location_settings')->where('location_type', $data['list_type'])->first();
        $db->table('fit_shopping_lists')->insert([
            'list_name' => trim($data['list_name']),
            'list_type' => $data['list_type'],
            'owner_user_id' => auth()->id(),
            'station_id' => $location->station_id ?? null,
            'station_name' => $location->station_name ?? null,
            'system_id' => $location->system_id ?? null,
            'system_name' => $location->system_name ?? null,
            'notes' => $data['notes'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->route('seatcore::fittings.index')->with('success', 'Shopping list created.');
    }

    public function storePilotShoppingListItem(Request $request, int $listId): RedirectResponse
    {
        abort_unless(auth()->check(), 401);
        $data = $request->validate([
            'type_name' => ['required', 'string', 'max:255'],
            'type_id' => ['nullable', 'integer', 'min:1'],
            'category' => ['required', 'in:hull,module,rig,consumable,other'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $db = $this->market();
        abort_unless($db->table('fit_shopping_lists')
            ->where('id', $listId)
            ->where('owner_user_id', auth()->id())
            ->exists(), 404);
        $db->table('fit_shopping_list_items')->insert([
            'shopping_list_id' => $listId,
            'type_id' => $data['type_id'] ?? null,
            'type_name' => trim($data['type_name']),
            'category' => $data['category'],
            'quantity' => $data['quantity'],
            'notes' => $data['notes'] ?? null,
            'sort_order' => 0,
        ]);

        return redirect()->route('seatcore::fittings.index')->with('success', 'Item added to your shopping list.');
    }


    /**
     * Build a per-user shortage list for one ship of every fit assigned to doctrines
     * scoped to each location. Each character's assets are evaluated separately; items are never pooled between characters.
     */
    private function missingInventoryShortages($db, $doctrineFits, $pilots, $locations): array
    {
        $categories = ['modules', 'consumables', 'hull_rig'];
        $result = [];
        $selectedByLocation = [];
        foreach (['home', 'deployment', 'third'] as $locationType) {
            $selectedByLocation[$locationType] = $this->selectedDoctrinesForLocation($db, $pilots, $locationType, $doctrineFits);
            $result[$locationType] = ['available' => false, 'categories' => []];
            foreach ($categories as $category) {
                $result[$locationType]['categories'][$category] = collect();
            }

            $setting = $locations->get($locationType);
            $systemId = (int) ($setting->system_id ?? 0);
            if ($systemId < 1) {
                $result[$locationType]['message'] = 'An administrator has not selected this system yet.';
                continue;
            }

            $schema = $db->getSchemaBuilder();
            if (!$schema->hasTable('character_assets') || !$schema->hasTable('fit_items')) {
                $result[$locationType]['message'] = 'SeAT asset data is not available yet.';
                continue;
            }

            $requirements = [];
            $fitIds = [];
            foreach ($doctrineFits as $doctrine => $fits) {
                if (!in_array($doctrine, $selectedByLocation[$locationType], true)) continue;
                foreach ($fits as $fit) {
                    $fitId = (int) $fit->id;
                    $fitIds[] = $fitId;
                    $requirements[$fitId] = [
                        'fit_name' => $fit->fit_name,
                        'ship_type_id' => (int) $fit->ship_type_id,
                        'doctrine' => $doctrine,
                    ];
                }
            }

            $requirementsByCategory = [];
            if ($fitIds) {
                $items = $db->table('fit_items')->whereIn('fit_id', array_unique($fitIds))
                    ->get(['fit_id', 'type_id', 'type_name', 'quantity_per_ship', 'is_hull', 'is_rig', 'is_consumable']);
                $hasHull = [];
                foreach ($items as $item) {
                    $fitId = (int) $item->fit_id;
                    $category = ((bool) $item->is_hull || (bool) $item->is_rig)
                        ? 'hull_rig'
                        : ((bool) $item->is_consumable ? 'consumables' : 'modules');
                    $typeId = (int) $item->type_id;
                    $key = $category === 'hull_rig' ? $category . ':' . $fitId . ':' . $typeId : $category . ':' . $typeId;
                    $quantity = (int) $item->quantity_per_ship;
                    if (!isset($requirementsByCategory[$category][$key])) {
                        $requirementsByCategory[$category][$key] = [
                            'type_id' => $typeId,
                            'type_name' => $item->type_name,
                            'needed' => $quantity,
                            'fit_names' => [],
                            'group_key' => $category === 'hull_rig' ? $fitId : null,
                            'group_name' => $category === 'hull_rig' ? ($requirements[$fitId]['fit_name'] ?? 'Fit') : null,
                        ];
                    } elseif ($category !== 'hull_rig') {
                        $requirementsByCategory[$category][$key]['needed'] = max(
                            $requirementsByCategory[$category][$key]['needed'],
                            $quantity
                        );
                    }
                    $requirementsByCategory[$category][$key]['fit_names'][$fitId] = $requirements[$fitId]['fit_name'] ?? '';
                    if ((bool) $item->is_hull) {
                        $hasHull[$fitId] = true;
                    }
                }

                // Imported fits include their hull in fit_items. Keep manually created
                // fits from disappearing from the hull list if they have no item rows.
                foreach ($requirements as $fitId => $fit) {
                    if (!isset($hasHull[$fitId]) && $fit['ship_type_id'] > 0) {
                        $type = $db->table('invTypes')->where('typeID', $fit['ship_type_id'])->first(['typeName']);
                        $key = 'hull_rig:' . $fitId . ':' . $fit['ship_type_id'];
                        if (!isset($requirementsByCategory['hull_rig'][$key]) ||
                            1 > $requirementsByCategory['hull_rig'][$key]['needed']) {
                            $requirementsByCategory['hull_rig'][$key] = [
                                'type_id' => $fit['ship_type_id'],
                                'type_name' => $type->typeName ?? $fit['fit_name'],
                                'needed' => 1,
                                'fit_names' => [$fitId => $fit['fit_name']],
                                'group_key' => $fitId,
                                'group_name' => $fit['fit_name'],
                            ];
                        }
                    }
                }
            }

            $pilotIds = $pilots->pluck('character_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
            $ownedByPilot = [];
            $pilotNames = $pilots->keyBy(fn ($pilot) => (int) $pilot->character_id);
            $rootLocations = [$systemId => true];
            if ($schema->hasTable('staStations')) {
                foreach ($db->table('staStations')->where('solarSystemID', $systemId)->pluck('stationID') as $id) {
                    $rootLocations[(int) $id] = true;
                }
            }
            if ($schema->hasTable('universe_structures')) {
                foreach ($db->table('universe_structures')->where('solar_system_id', $systemId)->pluck('structure_id') as $id) {
                    $rootLocations[(int) $id] = true;
                }
            }

            if ($pilotIds) {
                $assets = $db->table('character_assets')->whereIn('character_id', $pilotIds)
                    ->get(['character_id', 'item_id', 'type_id', 'quantity', 'location_id', 'location_type']);
                foreach ($pilotIds as $pilotId) {
                    $pilotAssets = $assets->where('character_id', $pilotId);
                    $parentByItem = [];
                    foreach ($pilotAssets as $asset) {
                        $parentByItem[(int) $asset->item_id] = (int) $asset->location_id;
                    }
                    foreach ($pilotAssets as $asset) {
                        $locationId = (int) $asset->location_id;
                        $isInSystem = false;
                        if (($asset->location_type ?? '') === 'solar_system' && $locationId === $systemId) {
                            $isInSystem = true;
                        } else {
                            $seen = [];
                            for ($depth = 0; $depth < 25 && $locationId > 0; $depth++) {
                                if (isset($rootLocations[$locationId])) {
                                    $isInSystem = true;
                                    break;
                                }
                                if (isset($seen[$locationId]) || !isset($parentByItem[$locationId])) {
                                    break;
                                }
                                $seen[$locationId] = true;
                                $locationId = $parentByItem[$locationId];
                            }
                        }
                        if ($isInSystem) {
                            $typeId = (int) $asset->type_id;
                            $ownedByPilot[$pilotId][$typeId] = ($ownedByPilot[$pilotId][$typeId] ?? 0) + max(1, (int) $asset->quantity);
                        }
                    }
                }
            }

            foreach ($categories as $category) {
                $shortages = [];
                // Report each pilot's shortages independently: assets from different
                // characters must not be pooled to make a doctrine look stocked.
                foreach ($pilotIds as $pilotId) {
                    $remainingOwned = $ownedByPilot[$pilotId] ?? [];
                    foreach ($requirementsByCategory[$category] ?? [] as $requirement) {
                        $have = (int) ($remainingOwned[$requirement['type_id']] ?? 0);
                        if ($category === 'hull_rig') {
                            $have = min($have, (int) $requirement['needed']);
                            $remainingOwned[$requirement['type_id']] = max(0, (int) ($remainingOwned[$requirement['type_id']] ?? 0) - $have);
                        }
                        $missing = max(0, $requirement['needed'] - $have);
                        if ($missing > 0) {
                            $shortages[] = (object) [
                                'character_id' => $pilotId,
                                'character_name' => $pilotNames->get($pilotId)->name ?? ('Character ' . $pilotId),
                                'type_id' => $requirement['type_id'],
                                'type_name' => $requirement['type_name'],
                                'needed' => $requirement['needed'],
                                'have' => $have,
                                'missing' => $missing,
                                'fit_names' => array_values(array_filter(array_unique($requirement['fit_names']))),
                                'group_key' => ($requirement['group_key'] ?? null) === null ? null : $pilotId . ':' . $requirement['group_key'],
                                'group_name' => $requirement['group_name'] ?? null,
                            ];
                        }
                    }
                }
                usort($shortages, fn ($a, $b) => strcasecmp($a->character_name, $b->character_name) ?: ($category === 'hull_rig'
                    ? (strcasecmp((string) ($a->group_name ?? ''), (string) ($b->group_name ?? '')) ?: strcasecmp($a->type_name, $b->type_name))
                    : strcasecmp($a->type_name, $b->type_name));
                $result[$locationType]['categories'][$category] = collect($shortages);
            }

            $result[$locationType]['available'] = true;
            $result[$locationType]['system_name'] = $setting->system_name ?? null;
        }

        return $result;
    }

    /**
     * Calculate whether all components for each doctrine fit are present in the
     * configured home or deployment system. Each character's assets are evaluated separately; items are never pooled between characters.
     */
    private function pilotFitInventoryStatus($db, $doctrineFits, $pilots, $locations): array
    {
        $statuses = [];
        $fitsById = [];
        $selectedByLocation = [];
        foreach (['home', 'deployment', 'third'] as $locationType) $selectedByLocation[$locationType] = $this->selectedDoctrinesForLocation($db, $pilots, $locationType, $doctrineFits);
        foreach ($doctrineFits as $doctrineName => $doctrineFitList) {
            foreach ($doctrineFitList as $fit) {
                $fitId = (int) $fit->id;
                $fit->doctrine = $doctrineName;
                $fitsById[$fitId] = $fit;
                foreach (['home', 'deployment', 'third'] as $locationType) {
                    $statuses[$fitId][$locationType] = [
                        'applicable' => in_array($doctrineName, $selectedByLocation[$locationType], true),
                        'available' => false,
                        'ready' => false,
                    ];
                }
            }
        }

        if (!$fitsById) {
            return $statuses;
        }

        $schema = $db->getSchemaBuilder();
        if (!$schema->hasTable('fit_items') || !$schema->hasTable('character_assets')) {
            return $statuses;
        }

        $fitIds = array_keys($fitsById);
        $itemsByFit = $db->table('fit_items')
            ->whereIn('fit_id', $fitIds)
            ->get(['fit_id', 'type_id', 'quantity_per_ship', 'is_hull'])
            ->groupBy('fit_id');
        $pilotIds = $pilots->pluck('character_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $assets = $pilotIds
            ? $db->table('character_assets')->whereIn('character_id', $pilotIds)
                ->get(['character_id', 'item_id', 'type_id', 'quantity', 'location_id', 'location_type'])
            : collect();

        foreach (['home', 'deployment', 'third'] as $locationType) {
            $fitIdsForLocation = array_keys(array_filter($statuses, fn ($fitStatus) => $fitStatus[$locationType]['applicable']));
            if (!$fitIdsForLocation) {
                continue;
            }

            $setting = $locations->get($locationType);
            $systemId = (int) ($setting->system_id ?? 0);
            if ($systemId < 1) {
                continue;
            }

            $rootLocations = [$systemId => true];
            $stationId = (int) ($setting->station_id ?? 0);
            if ($stationId > 0) {
                $rootLocations[$stationId] = true;
            }
            if ($schema->hasTable('staStations')) {
                foreach ($db->table('staStations')->where('solarSystemID', $systemId)->pluck('stationID') as $id) {
                    $rootLocations[(int) $id] = true;
                }
            }
            if ($schema->hasTable('universe_structures')) {
                foreach ($db->table('universe_structures')->where('solar_system_id', $systemId)->pluck('structure_id') as $id) {
                    $rootLocations[(int) $id] = true;
                }
            }
            if ($schema->hasTable('corporation_structures')) {
                foreach ($db->table('corporation_structures')->where('system_id', $systemId)->pluck('structure_id') as $id) {
                    $rootLocations[(int) $id] = true;
                }
            }

            // Keep inventory isolated per character. A pilot's assets must never be
            // combined with another pilot's assets to mark a fit as ready.
            $ownedByPilot = [];
            foreach ($pilotIds as $pilotId) {
                $pilotAssets = $assets->where('character_id', $pilotId);
                $parentByItem = [];
                foreach ($pilotAssets as $asset) {
                    $parentByItem[(int) $asset->item_id] = (int) $asset->location_id;
                }
                $ownedByPilot[$pilotId] = [];
                foreach ($pilotAssets as $asset) {
                    $locationId = (int) $asset->location_id;
                    $isInSystem = false;
                    if (($asset->location_type ?? '') === 'solar_system' && $locationId === $systemId) {
                        $isInSystem = true;
                    } else {
                        $seen = [];
                        for ($depth = 0; $depth < 25 && $locationId > 0; $depth++) {
                            if (isset($rootLocations[$locationId])) { $isInSystem = true; break; }
                            if (isset($seen[$locationId]) || !isset($parentByItem[$locationId])) break;
                            $seen[$locationId] = true;
                            $locationId = $parentByItem[$locationId];
                        }
                    }
                    if ($isInSystem) {
                        $typeId = (int) $asset->type_id;
                        $ownedByPilot[$pilotId][$typeId] = ($ownedByPilot[$pilotId][$typeId] ?? 0) + max(1, (int) $asset->quantity);
                    }
                }
            }

            foreach ($fitIdsForLocation as $fitId) {
                $fit = $fitsById[$fitId];
                $requirements = [];
                $hasHull = false;
                foreach ($itemsByFit->get($fitId, collect()) as $item) {
                    $typeId = (int) $item->type_id;
                    $requirements[$typeId] = ($requirements[$typeId] ?? 0) + max(1, (int) $item->quantity_per_ship);
                    if ((bool) $item->is_hull) $hasHull = true;
                }
                $shipTypeId = (int) $fit->ship_type_id;
                if (!$hasHull && $shipTypeId > 0) $requirements[$shipTypeId] = max(1, $requirements[$shipTypeId] ?? 0);

                $selectedPilotIds = $this->pilotIdsSelectingDoctrine($db, $pilots, $locationType, (string) ($fit->doctrine ?? ''));
                $ready = false;
                foreach ($selectedPilotIds as $pilotId) {
                    $pilotReady = (bool) $requirements;
                    foreach ($requirements as $typeId => $quantity) {
                        if (($ownedByPilot[$pilotId][$typeId] ?? 0) < $quantity) { $pilotReady = false; break; }
                    }
                    if ($pilotReady) { $ready = true; break; }
                }
                $statuses[$fitId][$locationType] = ['applicable' => true, 'available' => true, 'ready' => $ready];
            }
        }

        return $statuses;
    }

    private function pilotIdsSelectingDoctrine($db, $pilots, string $locationType, string $doctrine): array
    {
        $pilotIds = $pilots->pluck('character_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        if (!$pilotIds || $doctrine === '' || !$db->getSchemaBuilder()->hasTable('fit_character_doctrine_settings')) return $pilotIds;
        $configuredIds = $db->table('fit_character_doctrine_settings')->whereIn('character_id', $pilotIds)
            ->where('location_type', $locationType)->where('is_configured', true)
            ->pluck('character_id')->map(fn ($id) => (int) $id)->all();
        if (!$configuredIds) return $pilotIds;
        $selectedIds = $db->table('fit_character_doctrine_assignments')->whereIn('character_id', $configuredIds)
            ->where('location_type', $locationType)->where('doctrine', $doctrine)
            ->pluck('character_id')->map(fn ($id) => (int) $id)->all();
        $legacyIds = array_values(array_diff($pilotIds, $configuredIds));
        if ($legacyIds) {
            $legacyDoctrine = $db->table('fit_doctrines')->where('name', $doctrine)->value('location_scope');
            if (in_array($legacyDoctrine ?? 'both', [$locationType, 'both'], true)) $selectedIds = array_merge($selectedIds, $legacyIds);
        }
        return array_values(array_unique($selectedIds));
    }

    private function selectedDoctrinesForLocation($db, $pilots, string $locationType, $doctrineFits): array
    {
        $legacy = $doctrineFits->filter(fn ($fits) => in_array($fits->first()->location_scope ?? 'both', [$locationType, 'both'], true))->keys()->all();
        $pilotIds = $pilots->pluck('character_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        if (!$pilotIds || !$db->getSchemaBuilder()->hasTable('fit_character_doctrine_settings')) return $legacy;
        $configuredIds = $db->table('fit_character_doctrine_settings')->whereIn('character_id', $pilotIds)->where('location_type', $locationType)->where('is_configured', true)->pluck('character_id')->map(fn ($id) => (int) $id)->all();
        if (!$configuredIds) return $legacy;
        $selected = $db->table('fit_character_doctrine_assignments')->whereIn('character_id', $configuredIds)->where('location_type', $locationType)->pluck('doctrine')->unique()->values()->all();
        if (array_diff($pilotIds, $configuredIds)) $selected = array_merge($selected, $legacy);
        return array_values(array_unique($selected));
    }

    private function eftClipboardText(object $fit): string
    {
        $source = trim((string) ($fit->fit_text ?? ''));
        if ($source === '') {
            return '[' . $fit->ship_name . ', ' . $fit->fit_name . ']';
        }

        $previousLibxmlSetting = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($source, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlSetting);
        }

        if ($xml === false) {
            return $source;
        }

        $lines = ['[' . $fit->ship_name . ', ' . $fit->fit_name . ']'];
        foreach (($xml->xpath('//hardware') ?: []) as $hardware) {
            $typeName = trim((string) ($hardware['type'] ?? ''));
            if ($typeName === '' || strtolower($typeName) === 'empty') {
                continue;
            }

            $quantityValue = (string) ($hardware['qty'] ?? $hardware['quantity'] ?? '1');
            $quantity = ctype_digit($quantityValue) ? max(1, (int) $quantityValue) : 1;
            $lines[] = $quantity > 1 ? $typeName . ' x' . $quantity : $typeName;
        }

        return implode("\n", $lines);
    }

    private function pilotFitEligibility($db, $doctrineFits, $pilots): array
    {
        $fits = $doctrineFits->flatten(1);
        $fitIds = $fits->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $pilotIds = $pilots->pluck('character_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $status = [];

        foreach ($fitIds as $fitId) {
            $status[$fitId] = ['eligible' => [], 'checked_pilots' => 0, 'unsynced_pilots' => count($pilotIds)];
        }
        if (!$fitIds || !$pilotIds) {
            return $status;
        }

        $schema = $db->getSchemaBuilder();
        foreach (['fit_items', 'character_skills', 'dgmTypeAttributes', 'invTypes'] as $table) {
            if (!$schema->hasTable($table)) {
                return $status;
            }
        }

        $typeIdsByFit = [];
        foreach ($fits as $fit) {
            $typeIdsByFit[(int) $fit->id][] = (int) $fit->ship_type_id;
        }
        foreach ($db->table('fit_items')->whereIn('fit_id', $fitIds)->get(['fit_id', 'type_id']) as $item) {
            $typeIdsByFit[(int) $item->fit_id][] = (int) $item->type_id;
        }
        foreach ($typeIdsByFit as $fitId => $typeIds) {
            $typeIdsByFit[$fitId] = array_values(array_unique(array_filter($typeIds)));
        }
        $allTypeIds = array_values(array_unique(array_merge(...array_values($typeIdsByFit))));
        if (!$allTypeIds) {
            return $status;
        }

        // Read skill requirement attributes by their stable EVE attribute IDs. The
        // dgmAttributeTypes lookup table is absent in some SeAT data installs.
        $attributeSlots = [
            182 => ['skill', 1],
            183 => ['skill', 2],
            184 => ['skill', 3],
            1285 => ['skill', 4],
            1289 => ['skill', 5],
            1290 => ['skill', 6],
            277 => ['level', 1],
            278 => ['level', 2],
            279 => ['level', 3],
            1286 => ['level', 4],
            1287 => ['level', 5],
            1288 => ['level', 6],
        ];
        $attributesByType = [];
        foreach ($db->table('dgmTypeAttributes as ta')
            ->whereIn('ta.typeID', $allTypeIds)
            ->whereIn('ta.attributeID', array_keys($attributeSlots))
            ->get(['ta.typeID', 'ta.attributeID', 'ta.valueInt', 'ta.valueFloat']) as $row) {
            $value = $row->valueInt ?? $row->valueFloat;
            if ($value === null) {
                continue;
            }

            [$kind, $slot] = $attributeSlots[(int) $row->attributeID];
            $attributesByType[(int) $row->typeID][$kind][$slot] = (int) $value;
        }

        $requirementsByFit = [];
        foreach ($typeIdsByFit as $fitId => $typeIds) {
            foreach ($typeIds as $typeId) {
                foreach (($attributesByType[$typeId]['skill'] ?? []) as $slot => $skillId) {
                    $requiredLevel = (int) ($attributesByType[$typeId]['level'][$slot] ?? 0);
                    if ($skillId > 0 && $requiredLevel > 0) {
                        $requirementsByFit[$fitId][$skillId] = max($requirementsByFit[$fitId][$skillId] ?? 0, $requiredLevel);
                    }
                }
            }
        }

        $skillIds = [];
        foreach ($requirementsByFit as $requirements) {
            $skillIds = array_merge($skillIds, array_keys($requirements));
        }
        $skillIds = array_values(array_unique($skillIds));
        $skillNames = $skillIds
            ? $db->table('invTypes')->whereIn('typeID', $skillIds)->pluck('typeName', 'typeID')->all()
            : [];

        $syncedPilotIds = $db->table('character_skills')
            ->whereIn('character_id', $pilotIds)
            ->distinct()
            ->pluck('character_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $skillRows = $db->table('character_skills')
            ->whereIn('character_id', $pilotIds)
            ->when($skillIds, fn ($query) => $query->whereIn('skill_id', $skillIds))
            ->get(['character_id', 'skill_id', 'trained_skill_level']);
        $levelsByPilot = [];
        foreach ($skillRows as $skill) {
            $levelsByPilot[(int) $skill->character_id][(int) $skill->skill_id] = (int) $skill->trained_skill_level;
        }

        foreach ($fitIds as $fitId) {
            foreach ($pilots as $pilot) {
                $pilotId = (int) $pilot->character_id;
                if (!in_array($pilotId, $syncedPilotIds, true)) {
                    continue;
                }

                $missing = [];
                foreach (($requirementsByFit[$fitId] ?? []) as $skillId => $requiredLevel) {
                    if (($levelsByPilot[$pilotId][$skillId] ?? 0) < $requiredLevel) {
                        $missing[] = ($skillNames[$skillId] ?? ('Skill ' . $skillId)) . ' ' . $requiredLevel;
                    }
                }
                if (!$missing) {
                    $status[$fitId]['eligible'][] = ['character_id' => $pilotId, 'name' => $pilot->name];
                }
            }
            $status[$fitId]['checked_pilots'] = count($syncedPilotIds);
            $status[$fitId]['unsynced_pilots'] = max(0, count($pilotIds) - count($syncedPilotIds));
        }

        return $status;
    }

    public function importXml(Request $request): RedirectResponse
    {
        $this->requireAdmin();
        $data = $request->validate([
            'xml_file' => ['required', 'file', 'max:20480'],
            'visibility' => ['required', 'in:alliance,admins'],
        ]);

        $file = $request->file('xml_file');
        if (strtolower($file->getClientOriginalExtension()) !== 'xml') {
            return back()->withErrors(['xml_file' => 'Choose an EVE fitting XML file.'])->withInput();
        }

        $source = file_get_contents($file->getRealPath());
        if ($source === false || stripos($source, '<!DOCTYPE') !== false || stripos($source, '<!ENTITY') !== false) {
            return back()->withErrors(['xml_file' => 'The XML file could not be read or contains unsupported declarations.'])->withInput();
        }

        $previousLibxmlSetting = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($source, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
            $parseErrors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlSetting);
        }

        if ($xml === false || $parseErrors) {
            return back()->withErrors(['xml_file' => 'The selected file is not valid EVE fitting XML.'])->withInput();
        }

        $fittings = $xml->xpath('//fitting') ?: [];
        if (!$fittings) {
            return back()->withErrors(['xml_file' => 'No fitting entries were found in this XML file.'])->withInput();
        }
        if (count($fittings) > 500) {
            return back()->withErrors(['xml_file' => 'A single upload can contain up to 500 fittings.'])->withInput();
        }

        $db = $this->market();
        $resolveType = static function (string $value) use ($db) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }

            $query = $db->table('invTypes');
            if (ctype_digit($value)) {
                $query->where('typeID', (int) $value);
            } else {
                $query->where('typeName', $value);
            }

            return $query->first(['typeID', 'typeName']);
        };

        $prepared = [];
        foreach ($fittings as $index => $fitting) {
            $fitName = trim((string) ($fitting['name'] ?? ''));
            $shipType = trim((string) ($fitting['shipType'] ?? ''));
            if ($shipType === '') {
                $shipType = trim((string) ($fitting->shipType['value'] ?? ''));
            }
            if ($fitName === '' || $shipType === '') {
                return back()->withErrors(['xml_file' => 'Every fitting must have a name and ship type (entry ' . ($index + 1) . ').'])->withInput();
            }

            $hull = $resolveType($shipType);
            if (!$hull) {
                return back()->withErrors(['xml_file' => 'Could not match ship type "' . $shipType . '" to SeAT’s EVE type catalogue.'])->withInput();
            }

            $itemsByType = [];
            foreach (($fitting->xpath('./hardware') ?: []) as $hardware) {
                $itemName = trim((string) ($hardware['type'] ?? ''));
                if ($itemName === '' || strtolower($itemName) === 'empty') {
                    continue;
                }

                $itemType = $resolveType($itemName);
                if (!$itemType) {
                    return back()->withErrors(['xml_file' => 'Could not match fitting item "' . $itemName . '" to SeAT’s EVE type catalogue.'])->withInput();
                }

                $slot = strtolower((string) ($hardware['slot'] ?? ''));
                $quantityValue = (string) ($hardware['qty'] ?? $hardware['quantity'] ?? '1');
                $quantity = ctype_digit($quantityValue) ? max(1, (int) $quantityValue) : 1;
                $typeId = (int) $itemType->typeID;
                if (!isset($itemsByType[$typeId])) {
                    $itemsByType[$typeId] = [
                        'type_id' => $typeId,
                        'type_name' => $itemType->typeName,
                        'quantity_per_ship' => 0,
                        'is_hull' => false,
                        'is_rig' => str_contains($slot, 'rig'),
                        'is_consumable' => (bool) preg_match('/drone|cargo|fighter|charge|ammo|missile|fuel|script|nanite/', $slot),
                    ];
                }
                $itemsByType[$typeId]['quantity_per_ship'] += $quantity;
            }

            $prepared[] = [
                'fit_name' => mb_substr($fitName, 0, 160),
                'ship_type_id' => (int) $hull->typeID,
                'ship_name' => $hull->typeName,
                'fit_text' => $fitting->asXML(),
                'items' => array_merge([[
                    'type_id' => (int) $hull->typeID,
                    'type_name' => $hull->typeName,
                    'quantity_per_ship' => 1,
                    'is_hull' => true,
                    'is_rig' => false,
                    'is_consumable' => false,
                ]], array_values($itemsByType)),
            ];
        }

        $created = 0;
        $duplicates = 0;
        $db->transaction(function () use ($db, $prepared, $data, &$created, &$duplicates) {
            foreach ($prepared as $fit) {
                $existing = $db->table('fit_fits')
                    ->where('fit_name', $fit['fit_name'])
                    ->where('ship_type_id', $fit['ship_type_id'])
                    ->first(['id']);

                if ($existing) {
                    $duplicates++;
                    continue;
                }

                $fitId = $db->table('fit_fits')->insertGetId([
                    'fit_name' => $fit['fit_name'],
                    'ship_name' => $fit['ship_name'],
                    'ship_type_id' => $fit['ship_type_id'],
                    'fit_text' => $fit['fit_text'],
                    'visibility' => $data['visibility'],
                    'created_by' => auth()->id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($fit['items'] as &$item) {
                    $item['fit_id'] = $fitId;
                }
                unset($item);
                $db->table('fit_items')->insert($fit['items']);
                $created++;
            }
        });

        return redirect()->route('seatcore::fittings.manage')
            ->with('success', 'Imported ' . $created . ' fittings; skipped ' . $duplicates . ' duplicates.');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireAdmin();
        $data = $request->validate([
            'fit_name' => ['required', 'string', 'max:160'],
            'ship_name' => ['required', 'string', 'max:255'],
            'ship_type_id' => ['required', 'integer', 'min:1'],
            'fit_text' => ['nullable', 'string', 'max:100000'],
            'visibility' => ['required', 'in:alliance,admins'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['integer', 'min:1'],
        ]);

        $db = $this->market();
        $fitId = $db->table('fit_fits')->insertGetId([
            'fit_name' => $data['fit_name'],
            'ship_name' => $data['ship_name'],
            'ship_type_id' => $data['ship_type_id'],
            'fit_text' => $data['fit_text'] ?? null,
            'visibility' => $data['visibility'],
            'created_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->syncCategories($fitId, $data['categories'] ?? []);
        return redirect()->route('seatcore::fittings.manage')->with('success', 'Fitting created.');
    }

    public function update(Request $request, int $fitId): RedirectResponse
    {
        $this->requireAdmin();
        $data = $request->validate([
            'fit_name' => ['required', 'string', 'max:160'],
            'ship_name' => ['required', 'string', 'max:255'],
            'ship_type_id' => ['required', 'integer', 'min:1'],
            'fit_text' => ['nullable', 'string', 'max:100000'],
            'visibility' => ['required', 'in:alliance,admins'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['integer', 'min:1'],
        ]);

        $db = $this->market();
        abort_unless($db->table('fit_fits')->where('id', $fitId)->exists(), 404);
        $db->table('fit_fits')->where('id', $fitId)->update([
            'fit_name' => $data['fit_name'],
            'ship_name' => $data['ship_name'],
            'ship_type_id' => $data['ship_type_id'],
            'fit_text' => $data['fit_text'] ?? null,
            'visibility' => $data['visibility'],
            'updated_at' => now(),
        ]);
        $this->syncCategories($fitId, $data['categories'] ?? []);
        return redirect()->route('seatcore::fittings.manage')->with('success', 'Fitting updated.');
    }

    public function destroy(int $fitId): RedirectResponse
    {
        $this->requireAdmin();
        $this->market()->table('fit_fits')->where('id', $fitId)->delete();
        return redirect()->route('seatcore::fittings.manage')->with('success', 'Fitting deleted.');
    }


    private function homeKeepstarQuery($db)
    {
        // Keepstars are generally not public ESI structures. SeAT's corporation
        // structure cache contains structures returned by corporation ESI scopes.
        return $db->table('corporation_structures as cs')
            ->join('invTypes as t', 't.typeID', '=', 'cs.type_id')
            ->join('solar_systems as m', 'm.system_id', '=', 'cs.system_id')
            ->where('m.name', '4-HWWF')
            ->where('t.typeName', 'Keepstar')
            ->selectRaw("cs.structure_id as station_id, CONCAT('Keepstar ', cs.structure_id) as station_name, cs.system_id as system_id, m.name as system_name, 1 as is_keepstar");
    }

    private function stationOptions($db): array
    {
        $stations = $db->table('fit_alliance_stations')
            ->orderBy('location_type')
            ->orderBy('station_name')
            ->get()
            ->groupBy('location_type');

        $home = ($stations->get('home') ?? collect())
            ->merge($this->homeKeepstarQuery($db)->get())
            ->unique('station_id')
            ->sortBy('station_name')
            ->values();

        $stations->put('home', $home);
        return $stations->all();
    }

    public function storeLocations(Request $request): RedirectResponse
    {
        $this->requireAdmin();
        $data = $request->validate([
            'home_station_id' => ['nullable', 'integer', 'min:1'],
            'deployment_station_id' => ['nullable', 'integer', 'min:1'],
            'third_station_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $db = $this->market();
        $locations = [];
        foreach (['home', 'deployment', 'third'] as $locationType) {
            $stationId = $data[$locationType . '_station_id'] ?? null;
            $station = null;
            if ($stationId) {
                $station = $db->table('fit_alliance_stations')
                    ->where('location_type', $locationType)
                    ->where('station_id', $stationId)
                    ->first();
                if (!$station && $locationType === 'home') {
                    $station = $this->homeKeepstarQuery($db)
                        ->where('cs.structure_id', $stationId)
                        ->first();
                }

                if (!$station) {
                    return back()->withErrors([$locationType . '_station_id' => 'Choose a station from the ' . $locationType . ' station list.'])->withInput();
                }
            }

            $locations[$locationType] = [
                'station_id' => $station->station_id ?? null,
                'station_name' => $station->station_name ?? null,
                'system_id' => $station->system_id ?? null,
                'system_name' => $station->system_name ?? null,
                'updated_by' => auth()->id(),
                'updated_at' => now(),
            ];
        }

        $db->transaction(function () use ($db, $locations) {
            foreach ($locations as $locationType => $locationValues) {
                $db->table('fit_location_settings')->updateOrInsert(
                    ['location_type' => $locationType],
                    $locationValues
                );

                // Keep each shopping list aligned with its selected home or deployment station.
                $db->table('fit_shopping_lists')->where('list_type', $locationType)->update([
                    'station_id' => $locationValues['station_id'],
                    'station_name' => $locationValues['station_name'],
                    'system_id' => $locationValues['system_id'],
                    'system_name' => $locationValues['system_name'],
                    'updated_at' => now(),
                ]);
            }
        });

        return redirect()->route('seatcore::fittings.manage')->with('success', 'Home, deployment and third-location stations saved.');
    }

    public function storeAllianceStation(Request $request): RedirectResponse
    {
        $this->requireAdmin();
        $data = $request->validate([
            'location_type' => ['required', 'in:home,deployment,third'],
            'station_id' => ['required', 'integer', 'min:1'],
        ]);

        $db = $this->market();
        $station = $db->table('staStations as s')
            ->join('solar_systems as m', 'm.system_id', '=', 's.solarSystemID')
            ->where('s.stationID', $data['station_id'])
            ->first([
                's.stationID as station_id',
                's.stationName as station_name',
                's.solarSystemID as system_id',
                'm.name as system_name',
            ]);

        if (!$station) {
            return back()->withErrors(['station_id' => 'That station ID was not found in EVE’s station data.'])->withInput();
        }

        $db->table('fit_alliance_stations')->updateOrInsert(
            [
                'location_type' => $data['location_type'],
                'station_id' => $station->station_id,
            ],
            [
                'station_name' => $station->station_name,
                'system_id' => $station->system_id,
                'system_name' => $station->system_name,
                'added_by' => auth()->id(),
            ]
        );

        return redirect()->route('seatcore::fittings.manage')->with('success', 'Station added to the ' . $data['location_type'] . ' list.');
    }

    public function destroyAllianceStation(string $locationType, int $stationId): RedirectResponse
    {
        $this->requireAdmin();
        abort_unless(in_array($locationType, ['home', 'deployment', 'third'], true), 404);

        $db = $this->market();
        $usedAsDefault = $db->table('fit_location_settings')
            ->where('location_type', $locationType)
            ->where('station_id', $stationId)
            ->exists();
        $usedByList = $db->table('fit_shopping_lists')
            ->where('list_type', $locationType)
            ->where('station_id', $stationId)
            ->exists();

        if ($usedAsDefault || $usedByList) {
            return back()->withErrors(['station_id' => 'This station is selected as a saved location and cannot be removed yet.']);
        }

        $db->table('fit_alliance_stations')
            ->where('location_type', $locationType)
            ->where('station_id', $stationId)
            ->delete();

        return redirect()->route('seatcore::fittings.manage')->with('success', 'Station removed from the ' . $locationType . ' list.');
    }

    public function storeShoppingList(Request $request): RedirectResponse
    {
        $this->requireAdmin();
        $data = $request->validate([
            'list_name' => ['required', 'string', 'max:160'],
            'list_type' => ['required', 'in:home,deployment,third'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);

        $db = $this->market();
        $location = $db->table('fit_location_settings')
            ->where('location_type', $data['list_type'])
            ->first();

        $db->table('fit_shopping_lists')->insert([
            'list_name' => trim($data['list_name']),
            'list_type' => $data['list_type'],
            'owner_user_id' => auth()->id(),
            'station_id' => $location->station_id ?? null,
            'station_name' => $location->station_name ?? null,
            'system_id' => $location->system_id ?? null,
            'system_name' => $location->system_name ?? null,
            'notes' => $data['notes'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->route('seatcore::fittings.manage')->with('success', 'Shopping list created using the saved ' . $data['list_type'] . ' location.');
    }


    public function storeShoppingListItem(Request $request, int $listId): RedirectResponse
    {
        $this->requireAdmin();
        $data = $request->validate([
            'type_name' => ['required', 'string', 'max:255'],
            'type_id' => ['nullable', 'integer', 'min:1'],
            'category' => ['required', 'in:hull,module,rig,consumable,other'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $db = $this->market();
        abort_unless($db->table('fit_shopping_lists')->where('id', $listId)->exists(), 404);
        $db->table('fit_shopping_list_items')->insert([
            'shopping_list_id' => $listId,
            'type_id' => $data['type_id'] ?? null,
            'type_name' => trim($data['type_name']),
            'category' => $data['category'],
            'quantity' => $data['quantity'],
            'notes' => $data['notes'] ?? null,
            'sort_order' => 0,
        ]);

        return redirect()->route('seatcore::fittings.manage')->with('success', 'Item added to shopping list.');
    }

    public function doctrines(): View
    {
        $this->requireAdmin();

        return view('web::fittings.doctrines', [
            'doctrines' => $this->market()->table('fit_doctrines')->orderBy('name')->get(),
        ]);
    }

    public function storeDoctrine(Request $request): RedirectResponse
    {
        $this->requireAdmin();
        $data = $request->validate([
            'doctrine_name' => ['required', 'string', 'max:120'],
            'location_scope' => ['required', 'in:home,deployment,third,both'],
        ]);

        $name = trim($data['doctrine_name']);
        $db = $this->market();
        if ($db->table('fit_doctrines')->where('name', $name)->exists()) {
            return back()->withErrors(['doctrine_name' => 'That doctrine already exists.'])->withInput();
        }

        $db->table('fit_doctrines')->insert([
            'name' => $name,
            'location_scope' => $data['location_scope'],
        ]);

        return redirect()->route('seatcore::fittings.doctrines')->with('success', 'Doctrine added.');
    }

    public function showDoctrine(string $doctrine): View
    {
        $this->requireAdmin();
        $db = $this->market();
        $doctrineRecord = $db->table('fit_doctrines')->where('name', $doctrine)->first();
        abort_unless($doctrineRecord, 404);

        $fits = $db->table('fit_fits')
            ->orderBy('fit_name')
            ->orderBy('ship_name')
            ->get(['id', 'fit_name', 'ship_name']);
        $assignedFitIds = $db->table('fit_doctrine_assignments')
            ->where('doctrine', $doctrine)
            ->pluck('fit_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return view('web::fittings.doctrine', [
            'doctrine' => $doctrineRecord,
            'fits' => $fits,
            'assignedFitIds' => $assignedFitIds,
        ]);
    }

    public function syncDoctrineFits(Request $request, string $doctrine): RedirectResponse
    {
        $this->requireAdmin();
        $data = $request->validate([
            'location_scope' => ['required', 'in:home,deployment,third,both'],
            'fits' => ['nullable', 'array'],
            'fits.*' => ['integer', 'min:1'],
        ]);

        $db = $this->market();
        abort_unless($db->table('fit_doctrines')->where('name', $doctrine)->exists(), 404);
        $fitIds = $db->table('fit_fits')
            ->whereIn('id', array_map('intval', $data['fits'] ?? []))
            ->pluck('id')
            ->all();

        $db->transaction(function () use ($db, $doctrine, $fitIds, $data) {
            $db->table('fit_doctrines')->where('name', $doctrine)->update([
                'location_scope' => $data['location_scope'],
            ]);
            $db->table('fit_doctrine_assignments')->where('doctrine', $doctrine)->delete();
            if ($fitIds) {
                $db->table('fit_doctrine_assignments')->insert(array_map(fn ($fitId) => [
                    'fit_id' => $fitId,
                    'doctrine' => $doctrine,
                ], $fitIds));
            }
        });

        return redirect()->route('seatcore::fittings.doctrines.show', ['doctrine' => $doctrine])
            ->with('success', 'Doctrine fittings saved.');
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $this->requireAdmin();
        $data = $request->validate([
            'category_name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $name = trim($data['category_name']);
        $this->market()->table('fit_categories')->insertOrIgnore([
            'category_name' => $name,
            'slug' => Str::slug($name),
            'description' => $data['description'] ?? null,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return redirect()->route('seatcore::fittings.manage')->with('success', 'Category saved.');
    }

    private function syncCategories(int $fitId, array $categoryIds): void
    {
        $db = $this->market();
        $validIds = $db->table('fit_categories')->whereIn('id', array_map('intval', $categoryIds))->pluck('id')->all();
        $db->transaction(function () use ($db, $fitId, $validIds) {
            $db->table('fit_category_assignments')->where('fit_id', $fitId)->delete();
            if ($validIds) {
                $db->table('fit_category_assignments')->insert(array_map(fn ($id) => [
                    'fit_id' => $fitId,
                    'category_id' => $id,
                    'assigned_at' => now(),
                ], $validIds));
            }
        });
    }
}
