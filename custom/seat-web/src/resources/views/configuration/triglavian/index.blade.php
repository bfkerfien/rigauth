@extends('web::layouts.grids.12')

@section('title', 'Triglavian Admin Setup')
@section('page_header', 'Triglavian Admin Setup')
@section('page_description', 'Manage designated T2 stations and alliance fittings')

@section('full')
<div class="card">
  <div class="card-header p-0">
    <ul class="nav nav-tabs" role="tablist">
      <li class="nav-item">
        <a class="nav-link {{ request('tab', 'stations') === 'stations' ? 'active' : '' }}" data-toggle="tab" href="#t2-stations" role="tab">T2 Stations</a>
      </li>
      <li class="nav-item">
        <a class="nav-link {{ request('tab') === 'fittings' ? 'active' : '' }}" data-toggle="tab" href="#fitting-management" role="tab">Fitting Management</a>
      </li>
    </ul>
  </div>
  <div class="card-body">
    @if(session('success'))
      <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if($errors->any())
      <div class="alert alert-danger">
        <ul class="mb-0">
          @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
      </div>
    @endif

    <div class="tab-content">
      <div class="tab-pane fade {{ request('tab', 'stations') === 'stations' ? 'show active' : '' }}" id="t2-stations" role="tabpanel">
        <h4>Designated T2 Stations</h4>
        <p class="text-muted">Add stations that the consortium designates as T2 stations. The EVE station ID is optional.</p>
        <form method="POST" action="{{ route('seatcore::configuration.triglavian.stations.save') }}" class="border rounded p-3 mb-4">
          @csrf
          <div class="form-row">
            <div class="form-group col-md-3">
              <label>Station name</label>
              <input class="form-control" name="station_name" maxlength="255" required>
            </div>
            <div class="form-group col-md-3">
              <label>Solar system</label>
              <input class="form-control" name="solar_system_name" maxlength="255" required>
            </div>
            <div class="form-group col-md-3">
              <label>EVE station ID (optional)</label>
              <input class="form-control" type="number" min="1" name="eve_station_id">
            </div>
            <div class="form-group col-md-3">
              <label>Notes</label>
              <input class="form-control" name="notes">
            </div>
          </div>
          <button class="btn btn-primary" type="submit">Add T2 Station</button>
        </form>

        <div class="table-responsive">
          <table class="table table-striped table-sm">
            <thead><tr><th>Station</th><th>Solar system</th><th>EVE station ID</th><th>Notes</th><th>Actions</th></tr></thead>
            <tbody>
            @forelse($stations as $station)
              <tr>
                <td colspan="5">
                  <form method="POST" action="{{ route('seatcore::configuration.triglavian.stations.save') }}">
                    @csrf
                    <input type="hidden" name="id" value="{{ $station->id }}">
                    <div class="form-row align-items-center">
                      <div class="col-md-3 mb-1"><input class="form-control form-control-sm" name="station_name" value="{{ $station->station_name }}" required></div>
                      <div class="col-md-2 mb-1"><input class="form-control form-control-sm" name="solar_system_name" value="{{ $station->solar_system_name }}" required></div>
                      <div class="col-md-2 mb-1"><input class="form-control form-control-sm" type="number" min="1" name="eve_station_id" value="{{ $station->eve_station_id }}"></div>
                      <div class="col-md-3 mb-1"><input class="form-control form-control-sm" name="notes" value="{{ $station->notes }}"></div>
                      <div class="col-md-2 mb-1">
                        <button class="btn btn-sm btn-primary" type="submit">Save</button>
                        <button class="btn btn-sm btn-danger" type="submit" form="delete-station-{{ $station->id }}">Delete</button>
                      </div>
                    </div>
                  </form>
                  <form id="delete-station-{{ $station->id }}" method="POST" action="{{ route('seatcore::configuration.triglavian.stations.delete', $station->id) }}" class="d-none">
                    @csrf
                    @method('DELETE')
                  </form>
                </td>
              </tr>
            @empty
              <tr><td colspan="5" class="text-muted">No T2 stations designated yet.</td></tr>
            @endforelse
            </tbody>
          </table>
        </div>
      </div>

      <div class="tab-pane fade {{ request('tab') === 'fittings' ? 'show active' : '' }}" id="fitting-management" role="tabpanel">
        <h4>Fitting Management</h4>
        <p class="text-muted">Store managed fittings and their EFT-format fitting text. These records are separate from shopping lists.</p>
        <form method="POST" action="{{ route('seatcore::configuration.triglavian.fittings.save') }}" class="border rounded p-3 mb-4">
          @csrf
          <div class="form-row">
            <div class="form-group col-md-3"><label>Fitting name</label><input class="form-control" name="name" maxlength="255" required></div>
            <div class="form-group col-md-3"><label>Ship hull</label><input class="form-control" name="hull" maxlength="255" required></div>
            <div class="form-group col-md-3">
              <label>Category</label>
              <select class="form-control" name="category" required>
                <option value="hulls">Hulls</option><option value="modules">Modules</option><option value="rigs">Rigs</option><option value="consumables">Consumables</option><option value="other">Other</option>
              </select>
            </div>
            <div class="form-group col-md-3"><label>Notes</label><input class="form-control" name="notes"></div>
          </div>
          <div class="form-group"><label>EFT fitting text</label><textarea class="form-control" name="fitting_text" rows="4" placeholder="[Ship Name, Fit Name]"></textarea></div>
          <button class="btn btn-primary" type="submit">Add Fitting</button>
        </form>

        <div class="table-responsive">
          <table class="table table-striped table-sm">
            <thead><tr><th>Name</th><th>Hull</th><th>Category</th><th>Fitting text / notes</th><th>Actions</th></tr></thead>
            <tbody>
            @forelse($fittings as $fitting)
              <tr>
                <td colspan="5">
                  <form method="POST" action="{{ route('seatcore::configuration.triglavian.fittings.save') }}">
                    @csrf
                    <input type="hidden" name="id" value="{{ $fitting->id }}">
                    <div class="form-row">
                      <div class="col-md-2 mb-1"><input class="form-control form-control-sm" name="name" value="{{ $fitting->name }}" required></div>
                      <div class="col-md-2 mb-1"><input class="form-control form-control-sm" name="hull" value="{{ $fitting->hull }}" required></div>
                      <div class="col-md-2 mb-1">
                        <select class="form-control form-control-sm" name="category" required>
                          @foreach(['hulls' => 'Hulls', 'modules' => 'Modules', 'rigs' => 'Rigs', 'consumables' => 'Consumables', 'other' => 'Other'] as $value => $label)
                            <option value="{{ $value }}" {{ $fitting->category === $value ? 'selected' : '' }}>{{ $label }}</option>
                          @endforeach
                        </select>
                      </div>
                      <div class="col-md-4 mb-1"><textarea class="form-control form-control-sm" name="fitting_text" rows="2">{{ $fitting->fitting_text }}</textarea><input class="form-control form-control-sm mt-1" name="notes" value="{{ $fitting->notes }}" placeholder="Notes"></div>
                      <div class="col-md-2 mb-1">
                        <button class="btn btn-sm btn-primary" type="submit">Save</button>
                        <button class="btn btn-sm btn-danger" type="submit" form="delete-fitting-{{ $fitting->id }}">Delete</button>
                      </div>
                    </div>
                  </form>
                  <form id="delete-fitting-{{ $fitting->id }}" method="POST" action="{{ route('seatcore::configuration.triglavian.fittings.delete', $fitting->id) }}" class="d-none">
                    @csrf
                    @method('DELETE')
                  </form>
                </td>
              </tr>
            @empty
              <tr><td colspan="5" class="text-muted">No fittings managed yet.</td></tr>
            @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
