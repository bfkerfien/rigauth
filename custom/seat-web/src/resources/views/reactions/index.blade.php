@extends('web::layouts.grids.12')

@section('title', 'Reaction Planner')
@section('page_header', 'Reaction Planner')
@section('page_description', 'Find reaction inputs in your SeAT inventory and see what is missing for the next run.')

@section('full')
<div class="container-fluid">
  @if($recipeError)<div class="alert alert-warning">{{ $recipeError }} <a href="https://gas.triglavianconsortium.com" target="_blank" rel="noopener">Open Gas Calculator</a></div>@endif
  @if($inventoryError)<div class="alert alert-info">{{ $inventoryError }}</div>@endif
  <form method="GET" action="{{ route('seatcore::reactions.index') }}" class="card card-body mb-3">
    <div class="form-row align-items-end">
      <div class="form-group col-md-9 mb-0"><label for="q">Search reaction product or base material</label><input id="q" name="q" class="form-control" value="{{ $search }}" placeholder="Fullerite, Mykoserocin, reaction product..."></div>
      <div class="form-group col-md-3 mb-0"><button class="btn btn-primary btn-block" type="submit"><i class="fas fa-search"></i> Search</button></div>
    </div>
  </form>
  <div class="row">
    <div class="col-lg-4">
      <div class="card">
        <div class="card-header"><strong>Base products in inventory</strong></div>
        <div class="card-body p-0" style="max-height:500px;overflow:auto">
          <table class="table table-sm table-striped mb-0"><thead><tr><th>Item</th><th class="text-right">Qty</th><th class="text-right">Locations</th></tr></thead><tbody>
          @forelse($inventory as $item)<tr><td>{{ $item['name'] }}</td><td class="text-right">{{ number_format($item['quantity']) }}</td><td class="text-right">{{ number_format($item['locations']) }}</td></tr>
          @empty<tr><td colspan="3" class="text-muted p-3">No cached assets found for your linked characters.</td></tr>@endforelse
          </tbody></table>
        </div>
      </div>
    </div>
    <div class="col-lg-8">
      <h4>Reaction recipes ({{ number_format($rows->count()) }})</h4>
      @forelse($rows as $recipe)
      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center"><strong>{{ $recipe['product'] }}</strong><span class="badge {{ $recipe['runs'] > 0 ? 'badge-success' : 'badge-secondary' }}">{{ number_format($recipe['runs']) }} complete run(s)</span></div>
        <div class="card-body">
          @if($recipe['group'])<p class="text-muted mb-2">{{ $recipe['group'] }}</p>@endif
          @if($recipe['products'])<p><strong>Output:</strong> @foreach($recipe['products'] as $product){{ $product['name'] }} × {{ number_format($product['quantity']) }}@if(!$loop->last), @endif @endforeach</p>@endif
          <div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th>Input material</th><th class="text-right">Per run</th><th class="text-right">Owned</th><th>Location(s)</th></tr></thead><tbody>
          @foreach($recipe['materials'] as $material)
            <tr><td>{{ $material['name'] }}</td><td class="text-right">{{ number_format($material['per_run']) }}</td><td class="text-right">{{ number_format($material['owned']) }}</td><td>
            @forelse($material['locations'] as $location)<div>{{ $location['owner'] }} — {{ $location['location'] }}: {{ number_format($location['quantity']) }}</div>@empty<span class="text-danger">Not found in cached inventory</span>@endforelse
            </td></tr>
          @endforeach
          </tbody></table></div>
          @if($recipe['missing'])<div class="alert alert-warning mb-0"><strong>Missing for the next run:</strong> @foreach($recipe['missing'] as $missing){{ $missing['name'] }}: {{ number_format($missing['quantity']) }}@if(!$loop->last); @endif @endforeach</div>
          @else<div class="alert alert-info mb-0">All listed inputs are available for another run.</div>@endif
        </div>
      </div>
      @empty<div class="alert alert-info">No recipes matched the search, or the reaction catalog is unavailable.</div>@endforelse
    </div>
  </div>
</div>
@endsection
