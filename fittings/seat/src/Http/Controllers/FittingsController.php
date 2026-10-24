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
        ]);
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
