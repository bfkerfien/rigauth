@extends('web::layouts.grids.12')

@section('title', 'Fittings')
@section('page_header', 'Fittings')
@section('page_description', 'Alliance doctrines and your personal home/deployment lists')

@section('full')
@if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
@if($errors->any()) <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div> @endif

<ul class="nav nav-tabs mb-3" role="tablist">
  <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#fittings-doctrines" role="tab">Doctrines</a></li>
  <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#fittings-home" role="tab">Home System</a></li>
  <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#fittings-deployment" role="tab">Deployment System</a></li>
  <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#fittings-reserved" role="tab">Reserved</a></li>
</ul>

<div class="tab-content">
  <div class="tab-pane fade show active" id="fittings-doctrines" role="tabpanel">
    @forelse($doctrines as $doctrine)
      <div class="card">
        <div class="card-header"><strong>{{ $doctrine->name }}</strong></div>
        <div class="card-body table-responsive">
          <table class="table table-striped table-sm mb-0">
            <thead><tr><th>Fit</th><th>Ship</th></tr></thead>
            <tbody>
              @forelse(($doctrineFits[$doctrine->name] ?? collect()) as $fit)
                <tr><td>{{ $fit->fit_name }}</td><td>{{ $fit->ship_name }}</td></tr>
              @empty
                <tr><td colspan="2" class="text-muted">No alliance fittings assigned to this doctrine yet.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    @empty
      <div class="alert alert-info">No doctrines have been added yet.</div>
    @endforelse
  </div>

  @foreach(['home' => 'Home System', 'deployment' => 'Deployment System'] as $listType => $label)
    @php($location = $locations[$listType] ?? null)
    <div class="tab-pane fade" id="fittings-{{ $listType }}" role="tabpanel">
      <div class="card">
        <div class="card-header"><strong>{{ $label }}</strong></div>
        <div class="card-body">
          <p class="text-muted mb-3">Alliance location: {{ $location->station_name ?? 'Not selected' }}{{ !empty($location->system_name) ? ' — '.$location->system_name : '' }}</p>
          <form method="POST" action="{{ route('seatcore::fittings.pilot.shopping-lists.store') }}" class="mb-4">
            @csrf
            <input type="hidden" name="list_type" value="{{ $listType }}">
            <div class="form-row align-items-end">
              <div class="form-group col-md-8"><label>New personal list</label><input name="list_name" class="form-control" maxlength="160" required></div>
              <div class="form-group col-md-4"><button class="btn btn-primary" type="submit">Create List</button></div>
            </div>
            <div class="form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
          </form>
          @forelse($shoppingLists->where('list_type', $listType) as $list)
            <div class="border rounded p-3 mb-3">
              <strong>{{ $list->list_name }}</strong>
              <div class="small text-muted">Station: {{ $list->station_name ?: 'Not set' }} · System: {{ $list->system_name ?: 'Not set' }}</div>
              @if($list->notes)<p class="small mt-2">{{ $list->notes }}</p>@endif
              @forelse(($shoppingListItems[$list->id] ?? collect()) as $item)
                <div class="small py-1">{{ $item->quantity }} × {{ $item->type_name }} <span class="badge badge-light">{{ ucfirst($item->category) }}</span>@if($item->notes) — {{ $item->notes }}@endif</div>
              @empty
                <div class="small text-muted mt-2">No items yet.</div>
              @endforelse
              <form method="POST" action="{{ route('seatcore::fittings.pilot.shopping-list-items.store', ['listId' => $list->id]) }}" class="mt-3">
                @csrf
                <div class="form-row align-items-end">
                  <div class="form-group col-md-6"><label>Item</label><input name="type_name" class="form-control form-control-sm" maxlength="255" required></div>
                  <div class="form-group col-md-2"><label>Qty</label><input name="quantity" type="number" min="1" value="1" class="form-control form-control-sm" required></div>
                  <div class="form-group col-md-4"><label>Category</label><select name="category" class="form-control form-control-sm"><option value="hull">Hull</option><option value="module">Module</option><option value="rig">Rig</option><option value="consumable">Consumable</option><option value="other">Other</option></select></div>
                </div>
                <button class="btn btn-secondary btn-sm" type="submit">Add Item</button>
              </form>
            </div>
          @empty
            <p class="text-muted">You have no personal {{ strtolower($label) }} lists yet.</p>
          @endforelse
        </div>
      </div>
    </div>
  @endforeach

  <div class="tab-pane fade" id="fittings-reserved" role="tabpanel">
    <div class="card"><div class="card-header"><strong>Reserved</strong></div><div class="card-body text-muted">Reserved for future use.</div></div>
  </div>
</div>
@endsection
