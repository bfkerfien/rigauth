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
            'shoppingLists' => $db->table('fit_shopping_lists')->orderBy('list_type')->orderBy('list_name')->get(),
            'shoppingListItems' => $db->table('fit_shopping_list_items')->orderBy('shopping_list_id')->orderBy('category')->orderBy('sort_order')->get()->groupBy('shopping_list_id'),
        ]);
    }

    public function importXml(Request $request): RedirectResponse
    {
        $this->requireAdmin();
        $data = $request->validate([
            'xml_file' => ['required', 'file', 'max:20480'],
            'doctrine' => ['required', 'string', 'max:120'],
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

        $doctrine = trim($data['doctrine']);
        $created = 0;
        $duplicates = 0;
        $db->transaction(function () use ($db, $prepared, $doctrine, $data, &$created, &$duplicates) {
            $db->table('fit_doctrines')->insertOrIgnore([
                'name' => $doctrine,
                'created_at' => now(),
            ]);

            foreach ($prepared as $fit) {
                $existing = $db->table('fit_fits')
                    ->where('fit_name', $fit['fit_name'])
                    ->where('ship_type_id', $fit['ship_type_id'])
                    ->first(['id']);

                if ($existing) {
                    $db->table('fit_doctrine_assignments')->insertOrIgnore([
                        'fit_id' => $existing->id,
                        'doctrine' => $doctrine,
                    ]);
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
                $db->table('fit_doctrine_assignments')->insertOrIgnore([
                    'fit_id' => $fitId,
                    'doctrine' => $doctrine,
                ]);
                $created++;
            }
        });

        return redirect()->route('seatcore::fittings.manage')
            ->with('success', 'Imported ' . $created . ' fittings into ' . $doctrine . '; skipped ' . $duplicates . ' duplicates.');
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


    public function storeLocations(Request $request): RedirectResponse
    {
        $this->requireAdmin();
        $data = $request->validate([
            'home_station_id' => ['nullable', 'integer', 'min:1'],
            'home_station_name' => ['nullable', 'string', 'max:255'],
            'home_system_id' => ['nullable', 'integer', 'min:1'],
            'home_system_name' => ['nullable', 'string', 'max:100'],
            'deployment_station_id' => ['nullable', 'integer', 'min:1'],
            'deployment_station_name' => ['nullable', 'string', 'max:255'],
            'deployment_system_id' => ['nullable', 'integer', 'min:1'],
            'deployment_system_name' => ['nullable', 'string', 'max:100'],
        ]);

        $db = $this->market();
        foreach (['home', 'deployment'] as $locationType) {
            $locationValues = [
                'station_id' => $data[$locationType . '_station_id'] ?? null,
                'station_name' => trim($data[$locationType . '_station_name'] ?? '') ?: null,
                'system_id' => $data[$locationType . '_system_id'] ?? null,
                'system_name' => trim($data[$locationType . '_system_name'] ?? '') ?: null,
                'updated_by' => auth()->id(),
                'updated_at' => now(),
            ];

            $db->table('fit_location_settings')->updateOrInsert(
                ['location_type' => $locationType],
                $locationValues
            );

            // Keep all lists of this type synchronized with the administrator's current default location.
            $db->table('fit_shopping_lists')->where('list_type', $locationType)->update([
                'station_id' => $locationValues['station_id'],
                'station_name' => $locationValues['station_name'],
                'system_id' => $locationValues['system_id'],
                'system_name' => $locationValues['system_name'],
                'updated_at' => now(),
            ]);
        }

        return redirect()->route('seatcore::fittings.manage')->with('success', 'Home and deployment stations saved.');
    }


    public function storeShoppingList(Request $request): RedirectResponse
    {
        $this->requireAdmin();
        $data = $request->validate([
            'list_name' => ['required', 'string', 'max:160'],
            'list_type' => ['required', 'in:home,deployment'],
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
