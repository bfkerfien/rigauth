@extends('web::layouts.grids.12')

@section('title', $doctrine->name)
@section('page_header', $doctrine->name)
@section('page_description', 'Select the fittings that belong to this doctrine')

@section('full')
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>Doctrine Fits</strong>
    <a class="btn btn-outline-secondary btn-sm" href="{{ route('seatcore::fittings.doctrines') }}">Back to Manage Doctrines</a>
  </div>
  <div class="card-body">
    @if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
    @if($errors->any()) <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div> @endif
    <p class="text-muted">Choose where this doctrine applies, then check fittings to assign them. Clear a checkbox to unassign it.</p>
    <form method="POST" action="{{ route('seatcore::fittings.doctrines.fits.update', ['doctrine' => $doctrine->name]) }}">
      @csrf
      <div class="form-group col-md-5 pl-0">
        <label for="doctrine-scope">System assignment</label>
        <select id="doctrine-scope" name="location_scope" class="form-control" required>
          <option value="home" {{ ($doctrine->location_scope ?? 'both') === 'home' ? 'selected' : '' }}>Home system</option>
          <option value="deployment" {{ ($doctrine->location_scope ?? 'both') === 'deployment' ? 'selected' : '' }}>Deployment system</option>
          <option value="third" {{ ($doctrine->location_scope ?? 'both') === 'third' ? 'selected' : '' }}>Third location</option>
          <option value="both" {{ ($doctrine->location_scope ?? 'both') === 'both' ? 'selected' : '' }}>All locations</option>
        </select>
      </div>
      <div class="table-responsive">
        <table class="table table-striped table-sm">
          <thead><tr><th>Assigned</th><th>Fit</th><th>Ship</th></tr></thead>
          <tbody>
            @forelse($fits as $fit)
              <tr>
                <td><input type="checkbox" name="fits[]" value="{{ $fit->id }}" aria-label="Assign {{ $fit->fit_name }}" {{ in_array((int)$fit->id, $assignedFitIds, true) ? 'checked' : '' }}></td>
                <td>{{ $fit->fit_name }}</td>
                <td>{{ $fit->ship_name }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-muted">No fittings are in the library yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      @if($fits->isNotEmpty())
        <button class="btn btn-primary" type="submit">Save Doctrine Fits</button>
      @endif
    </form>
  </div>
</div>
@endsection
