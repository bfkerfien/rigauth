@extends('web::layouts.grids.12')

@section('title', 'Manage Fittings')
@section('page_header', 'Manage Fittings')
@section('page_description', 'Maintain one fitting and assign it to any number of categories')

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
              <td>{{ $fit->updated_at }}</td>
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
    @if(auth()->check() && auth()->user()->hasSuperUserPermission())
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
