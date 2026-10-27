@extends('web::layouts.grids.12')

@section('title', 'Manage Doctrines')
@section('page_header', 'Manage Doctrines')
@section('page_description', 'Create doctrines and assign fittings to each doctrine')

@section('full')
<div class="row">
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><strong>Add Doctrine</strong></div>
      <div class="card-body">
        @if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
        @if($errors->any()) <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div> @endif
        <form method="POST" action="{{ route('seatcore::fittings.doctrines.store') }}">
          @csrf
          <div class="form-group">
            <label for="doctrine-name">Doctrine name</label>
            <input id="doctrine-name" name="doctrine_name" class="form-control" maxlength="120" value="{{ old('doctrine_name') }}" required>
          </div>
          <div class="form-group">
            <label for="doctrine-scope">System assignment</label>
            <select id="doctrine-scope" name="location_scope" class="form-control" required>
              <option value="home" {{ old('location_scope', 'both') === 'home' ? 'selected' : '' }}>Home system</option>
              <option value="deployment" {{ old('location_scope') === 'deployment' ? 'selected' : '' }}>Deployment system</option>
              <option value="third" {{ old('location_scope') === 'third' ? 'selected' : '' }}>Third location</option>
              <option value="both" {{ old('location_scope', 'both') === 'both' ? 'selected' : '' }}>All locations</option>
            </select>
          </div>
          <button class="btn btn-primary" type="submit">Add Doctrine</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Doctrines</strong>
        <a class="btn btn-outline-secondary btn-sm" href="{{ route('seatcore::fittings.manage') }}">Back to Manage Fittings</a>
      </div>
      <div class="card-body">
        <p class="text-muted">Select a doctrine to assign or remove fittings.</p>
        <div class="list-group">
          @forelse($doctrines as $doctrine)
            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="{{ route('seatcore::fittings.doctrines.show', ['doctrine' => $doctrine->name]) }}">
              <span>{{ $doctrine->name }} <span class="badge badge-info">{{ ucfirst($doctrine->location_scope ?? 'both') }}</span></span>
              <span class="text-muted small">Manage fits &rsaquo;</span>
            </a>
          @empty
            <p class="text-muted mb-0">No doctrines have been added yet.</p>
          @endforelse
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
