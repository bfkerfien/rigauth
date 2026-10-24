@extends('web::layouts.grids.12')

@section('title', 'Manage Fittings')
@section('page_header', 'Manage Fittings')
@section('page_description', 'Manage fittings and separate home/deployment fit lists')

@section('full')
<div class="row">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Fitting Library</strong>
        <form method="GET" action="{{ route('seatcore::fittings.manage') }}" class="form-inline">
          <label for="category-filter" class="mr-2">Category</label>
          <select id="category-filter" name="category" class="form-control form-control-sm" onchange="this.form.submit()">
            <option value="">All categories</option>
            @foreach($categories as $category)
              <option value="{{ $category->id }}" {{ (int)$selectedCategory === (int)$category->id ? 'selected' : '' }}>{{ $category->category_name }}</option>
            @endforeach
          </select>
        </form>
      </div>
      <div class="card-body table-responsive">
        @if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
        @if($errors->any()) <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div> @endif
        <table class="table table-striped table-sm">
          <thead><tr><th>Fit</th><th>Ship</th><th>Categories</th><th>Visibility</th><th>Updated</th></tr></thead>
          <tbody>
          @forelse($fits as $fit)
            <tr>
              <td><strong>{{ $fit->fit_name }}</strong><br><small class="text-muted">#{{ $fit->id }}</small></td>
              <td>{{ $fit->ship_name }}</td>
              <td>
                @forelse(($categoryMap[$fit->id] ?? []) as $category)
                  <span class="badge badge-secondary">{{ $category->category_name }}</span>
                @empty
                  <span class="text-muted">Uncategorized</span>
                @endforelse
              </td>
              <td>{{ $fit->visibility }}</td>
              <td>{{ $fit->updated_at }}
                @if(auth()->check() && auth()->user()->can('global.superuser'))
                  <details class="mt-2">
                    <summary class="text-primary" style="cursor:pointer">Edit</summary>
                    <form method="POST" action="{{ route('seatcore::fittings.update', ['fitId' => $fit->id]) }}" class="mt-2">
                      @csrf
                      @method('PUT')
                      <div class="form-group"><label>Fit name</label><input name="fit_name" class="form-control" maxlength="160" value="{{ $fit->fit_name }}" required></div>
                      <div class="form-group"><label>Ship name</label><input name="ship_name" class="form-control" maxlength="255" value="{{ $fit->ship_name }}" required></div>
                      <div class="form-group"><label>EVE ship type ID</label><input name="ship_type_id" type="number" min="1" class="form-control" value="{{ $fit->ship_type_id }}" required></div>
                      <div class="form-group"><label>EFT fitting text</label><textarea name="fit_text" rows="5" class="form-control">{{ $fit->fit_text ?? '' }}</textarea></div>
                      <div class="form-group"><label>Categories (select multiple)</label>
                        <select name="categories[]" class="form-control" multiple size="5">
                          @foreach($categories as $category)
                            <option value="{{ $category->id }}" {{ collect($categoryMap[$fit->id] ?? [])->contains(fn($assigned) => (int)$assigned->id === (int)$category->id) ? 'selected' : '' }}>{{ $category->category_name }}</option>
                          @endforeach
                        </select>
                      </div>
                      <div class="form-group"><label>Visibility</label><select name="visibility" class="form-control"><option value="alliance" {{ $fit->visibility === 'alliance' ? 'selected' : '' }}>Alliance</option><option value="admins" {{ $fit->visibility === 'admins' ? 'selected' : '' }}>Admins only</option></select></div>
                      <button class="btn btn-primary btn-sm" type="submit">Save changes</button>
                    </form>
                    <form method="POST" action="{{ route('seatcore::fittings.destroy', ['fitId' => $fit->id]) }}" class="mt-2" onsubmit="return confirm('Delete this fitting?')">
                      @csrf
                      @method('DELETE')
                      <button class="btn btn-danger btn-sm" type="submit">Delete fitting</button>
                    </form>
                  </details>
                @endif
              </td>
            </tr>
          @empty
            <tr><td colspan="5" class="text-muted">No fittings have been added yet.</td></tr>
          @endforelse
          </tbody>
        </table>
        {{ $fits->links() }}
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    @if(auth()->check() && auth()->user()->can('global.superuser'))
    <div class="card">
      <div class="card-header"><strong>Home &amp; Deployment Stations</strong></div>
      <div class="card-body">
        <p class="text-muted">Set the EVE station and solar system used as the default location for each shopping list. Enter the station and system IDs from EVE or SeAT.</p>
        <form method="POST" action="{{ route('seatcore::fittings.locations.store') }}">
          @csrf
          @foreach(['home' => 'Home', 'deployment' => 'Deployment'] as $key => $label)
            @php($location = $locations[$key] ?? null)
            <h5>{{ $label }} Location</h5>
            <div class="form-group"><label>{{ $label }} station name</label><input name="{{ $key }}_station_name" class="form-control" maxlength="255" value="{{ old($key.'_station_name', $location->station_name ?? '') }}"></div>
            <div class="form-group"><label>{{ $label }} station ID</label><input name="{{ $key }}_station_id" type="number" min="1" class="form-control" value="{{ old($key.'_station_id', $location->station_id ?? '') }}"></div>
            <div class="form-group"><label>{{ $label }} system name</label><input name="{{ $key }}_system_name" class="form-control" maxlength="100" value="{{ old($key.'_system_name', $location->system_name ?? '') }}"></div>
            <div class="form-group"><label>{{ $label }} system ID</label><input name="{{ $key }}_system_id" type="number" min="1" class="form-control" value="{{ old($key.'_system_id', $location->system_id ?? '') }}"></div>
            @if($key === 'home') <hr> @endif
          @endforeach
          <button class="btn btn-primary" type="submit">Save Locations</button>
        </form>
      </div>
    </div>
    @include('web::fittings.fit-tabs')
    <div class="card">
      <div class="card-header"><strong>Home &amp; Deployment Shopping Lists</strong></div>
      <div class="card-body">
        <p class="text-muted">New lists inherit the saved station and solar system for their type. Existing lists keep their recorded location.</p>
        <form method="POST" action="{{ route('seatcore::fittings.shopping-lists.store') }}" class="mb-4">
          @csrf
          <div class="form-group"><label>List name</label><input name="list_name" class="form-control" maxlength="160" required></div>
          <div class="form-group"><label>List type</label><select name="list_type" class="form-control" required><option value="home">Home</option><option value="deployment">Deployment</option></select></div>
          <div class="form-group"><label>Notes (optional)</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
          <button class="btn btn-primary" type="submit">Create Shopping List</button>
        </form>
        <h5>Saved Lists</h5>
        @forelse($shoppingLists as $list)
          <div class="border rounded p-3 mb-2">
            <strong>{{ $list->list_name }}</strong>
            <span class="badge badge-secondary">{{ ucfirst($list->list_type) }}</span>
            <div class="small text-muted mt-1">
              Station: {{ $list->station_name ?: 'Not set' }}{{ $list->station_id ? ' (ID '.$list->station_id.')' : '' }}<br>
              System: {{ $list->system_name ?: 'Not set' }}{{ $list->system_id ? ' (ID '.$list->system_id.')' : '' }}
            </div>
            @if($list->notes)<p class="mb-0 mt-2">{{ $list->notes }}</p>@endif
            <div class="mt-3">
              <strong>Items</strong>
              @forelse(($shoppingListItems[$list->id] ?? collect()) as $item)
                <div class="small py-1">{{ $item->quantity }} × {{ $item->type_name }} <span class="badge badge-light">{{ ucfirst($item->category) }}</span>@if($item->notes) — {{ $item->notes }}@endif</div>
              @empty
                <div class="small text-muted">No items added.</div>
              @endforelse
              <details class="mt-2">
                <summary class="text-primary" style="cursor:pointer">Add item</summary>
                <form method="POST" action="{{ route('seatcore::fittings.shopping-list-items.store', ['listId' => $list->id]) }}" class="mt-2">
                  @csrf
                  <div class="form-group"><label>Item name</label><input name="type_name" class="form-control form-control-sm" maxlength="255" required></div>
                  <div class="form-group"><label>EVE type ID (optional)</label><input name="type_id" type="number" min="1" class="form-control form-control-sm"></div>
                  <div class="form-group"><label>Category</label><select name="category" class="form-control form-control-sm"><option value="hull">Hull</option><option value="module">Module</option><option value="rig">Rig</option><option value="consumable">Consumable</option><option value="other">Other</option></select></div>
                  <div class="form-group"><label>Quantity</label><input name="quantity" type="number" min="1" max="1000000" value="1" class="form-control form-control-sm" required></div>
                  <div class="form-group"><label>Notes</label><input name="notes" class="form-control form-control-sm" maxlength="500"></div>
                  <button class="btn btn-secondary btn-sm" type="submit">Add Item</button>
                </form>
              </details>
            </div>
          </div>
        @empty
          <p class="text-muted">No shopping lists created yet.</p>
        @endforelse
      </div>
    </div>
    <div class="card">
      <div class="card-header"><strong>Add Fitting</strong></div>
      <div class="card-body">
        <form method="POST" action="{{ route('seatcore::fittings.store') }}">
          @csrf
          <div class="form-group"><label>Fit name</label><input name="fit_name" class="form-control" maxlength="160" required></div>
          <div class="form-group"><label>Ship name</label><input name="ship_name" class="form-control" maxlength="255" required></div>
          <div class="form-group"><label>EVE ship type ID</label><input name="ship_type_id" type="number" min="1" class="form-control" required></div>
          <div class="form-group"><label>EFT fitting text (optional)</label><textarea name="fit_text" rows="5" class="form-control" placeholder="[Scimitar, Example Fit]"></textarea></div>
          <div class="form-group"><label>Categories (select multiple)</label>
            <select name="categories[]" class="form-control" multiple size="6">
              @foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->category_name }}</option>@endforeach
            </select>
            <small class="form-text text-muted">Use Ctrl (Windows) or Cmd (Mac) to select multiple categories.</small>
          </div>
          <div class="form-group"><label>Visibility</label><select name="visibility" class="form-control"><option value="alliance">Alliance</option><option value="admins">Admins only</option></select></div>
          <button class="btn btn-primary" type="submit">Save Fitting</button>
        </form>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><strong>Add Category</strong></div>
      <div class="card-body">
        <form method="POST" action="{{ route('seatcore::fittings.category.store') }}">
          @csrf
          <div class="form-group"><label>Category name</label><input name="category_name" class="form-control" maxlength="120" required></div>
          <div class="form-group"><label>Description</label><input name="description" class="form-control" maxlength="500"></div>
          <button class="btn btn-secondary" type="submit">Save Category</button>
        </form>
      </div>
    </div>
    @endif
  </div>
</div>
@endsection
