@extends('web::layouts.grids.12')

@section('title', 'Fittings')
@section('page_header', 'Fittings')
@section('page_description', 'Alliance fittings and your personal home/deployment shopping lists')

@section('full')
@if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
@if($errors->any()) <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div> @endif
<div class="row">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><strong>Alliance Fittings</strong></div>
      <div class="card-body table-responsive">
        <table class="table table-striped table-sm">
          <thead><tr><th>Fit</th><th>Ship</th><th>Doctrine</th></tr></thead>
          <tbody>
            @forelse($fits as $fit)
              <tr><td>{{ $fit->fit_name }}</td><td>{{ $fit->ship_name }}</td><td class="text-muted">Assign through doctrines when available</td></tr>
            @empty
              <tr><td colspan="3" class="text-muted">No alliance fittings are available yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><strong>Your Shopping Lists</strong></div>
      <div class="card-body">
        <p class="text-muted">These lists belong to your pilot account. New lists use the admin-selected home or deployment station.</p>
        <form method="POST" action="{{ route('seatcore::fittings.pilot.shopping-lists.store') }}" class="mb-3">
          @csrf
          <div class="form-group"><label>List name</label><input name="list_name" class="form-control" maxlength="160" required></div>
          <div class="form-group"><label>Location</label><select name="list_type" class="form-control" required><option value="home">Home</option><option value="deployment">Deployment</option></select></div>
          <div class="form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
          <button class="btn btn-primary" type="submit">Create My List</button>
        </form>
        @forelse($shoppingLists as $list)
          <div class="border rounded p-3 mb-3">
            <strong>{{ $list->list_name }}</strong> <span class="badge badge-secondary">{{ ucfirst($list->list_type) }}</span>
            <div class="small text-muted">Station: {{ $list->station_name ?: 'Not set' }} · System: {{ $list->system_name ?: 'Not set' }}</div>
            @if($list->notes)<p class="small mt-2">{{ $list->notes }}</p>@endif
            @forelse(($shoppingListItems[$list->id] ?? collect()) as $item)
              <div class="small py-1">{{ $item->quantity }} × {{ $item->type_name }} <span class="badge badge-light">{{ ucfirst($item->category) }}</span>@if($item->notes) — {{ $item->notes }}@endif</div>
            @empty
              <div class="small text-muted mt-2">No items yet.</div>
            @endforelse
            <form method="POST" action="{{ route('seatcore::fittings.pilot.shopping-list-items.store', ['listId' => $list->id]) }}" class="mt-3">
              @csrf
              <div class="form-group"><label>Item</label><input name="type_name" class="form-control form-control-sm" maxlength="255" required></div>
              <div class="form-row">
                <div class="form-group col"><label>Quantity</label><input name="quantity" type="number" min="1" value="1" class="form-control form-control-sm" required></div>
                <div class="form-group col"><label>Category</label><select name="category" class="form-control form-control-sm"><option value="hull">Hull</option><option value="module">Module</option><option value="rig">Rig</option><option value="consumable">Consumable</option><option value="other">Other</option></select></div>
              </div>
              <button class="btn btn-secondary btn-sm" type="submit">Add Item</button>
            </form>
          </div>
        @empty
          <p class="text-muted">You have not created a shopping list yet.</p>
        @endforelse
      </div>
    </div>
  </div>
</div>
@endsection
