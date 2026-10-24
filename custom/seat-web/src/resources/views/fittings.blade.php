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
    @foreach($doctrines as $doctrine)
      @php($doctrineDoctrineFits = $doctrineFits[$doctrine->name] ?? collect())
      <details class="card mb-3">
        <summary class="card-header" style="cursor:pointer"><strong>{{ $doctrine->name }}</strong> <span class="badge badge-secondary">{{ $doctrineDoctrineFits->count() }} fits</span></summary>
        <div class="card-body table-responsive">
          <table class="table table-striped table-sm mb-0">
            <thead><tr><th>Fit</th><th>Ship</th><th>Qualified pilots</th><th class="text-center" aria-label="Clipboard"><i class="far fa-clipboard" title="Clipboard"></i></th></tr></thead>
            <tbody>
              @forelse($doctrineDoctrineFits as $fit)
                @php($fitStatus = $fitSkillStatus[$fit->id] ?? ['eligible' => [], 'checked_pilots' => 0, 'unsynced_pilots' => $pilots->count()])
                <tr class="{{ $fitStatus['checked_pilots'] > 0 && count($fitStatus['eligible']) === 0 ? 'table-danger' : '' }}">
                  <td>{{ $fit->fit_name }}
                    @if($fitStatus['checked_pilots'] > 0 && count($fitStatus['eligible']) === 0)
                      <div class="small text-danger">No linked pilot with synced skills meets this fit's requirements.</div>
                    @elseif($fitStatus['checked_pilots'] === 0)
                      <div class="small text-muted">Pilot skills have not synced yet.</div>
                    @endif
                  </td>
                  <td>{{ $fit->ship_name }}</td>
                  <td>
                    @forelse($fitStatus['eligible'] as $pilot)
                      <span class="d-inline-block mr-1" title="{{ $pilot['name'] }}">{!! img('characters', 'portrait', $pilot['character_id'], 32, ['class' => 'img-circle', 'alt' => $pilot['name'], 'title' => $pilot['name']], false) !!}</span>
                    @empty
                      @if($fitStatus['checked_pilots'] === 0)
                        <span class="text-muted">Waiting for skill sync</span>
                      @else
                        <span class="text-danger">No synced pilot meets the requirements</span>
                      @endif
                    @endforelse
                    @if($fitStatus['unsynced_pilots'] > 0)<div class="small text-muted">{{ $fitStatus['unsynced_pilots'] }} pilot(s) have no synced skill data.</div>@endif
                  </td>
                  <td>
                    <textarea id="fitting-eft-{{ $fit->id }}" class="d-none" readonly>{{ $fit->clipboard_text }}</textarea>
                    <button type="button" class="btn btn-link btn-sm p-0" title="Copy fitting to clipboard" aria-label="Copy fitting to clipboard" onclick="copyFittingForEve('fitting-eft-{{ $fit->id }}', this)"><i class="far fa-clipboard" aria-hidden="true"></i></button>
                  </td>
                </tr>
              @empty
                <tr><td colspan="4" class="text-muted">No alliance fittings are assigned to this doctrine yet.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </details>
    @endforeach
    @if($doctrines->isEmpty())<div class="alert alert-info">No doctrines have been added yet.</div>@endif
    <p class="small text-muted">Skill checks use synced skills for all pilots linked to your SeAT account. Red fits have no linked pilot with synced skills that meet the requirements.</p>
  </div>

  @foreach(['home' => 'Home System', 'deployment' => 'Deployment System'] as $listType => $label)
    @php($location = $locations[$listType] ?? null)
    <div class="tab-pane fade" id="fittings-{{ $listType }}" role="tabpanel">
      <div class="card"><div class="card-header"><strong>{{ $label }}</strong></div><div class="card-body">
        <p class="text-muted mb-3">Alliance location: {{ $location->station_name ?? 'Not selected' }}{{ !empty($location->system_name) ? ' — '.$location->system_name : '' }}</p>
        @php($shortageCategories = ['modules' => 'Modules', 'consumables' => 'Consumables', 'hull_rig' => 'Hull & Rig Combo'])
        @php($systemShortages = $inventoryShortages[$listType] ?? ['available' => false, 'categories' => []])
        @if($systemShortages['available'])
          <p class="small text-muted">Missing quantities are calculated across one of every fit assigned to doctrines scoped to {{ strtolower($label) }}, using assets from all pilots linked to your SeAT account in this system.</p>
          <ul class="nav nav-tabs mb-3" role="tablist">
            @foreach($shortageCategories as $categoryKey => $categoryLabel)
              <li class="nav-item"><a class="nav-link {{ $loop->first ? 'active' : '' }}" data-toggle="tab" href="#fittings-{{ $listType }}-{{ $categoryKey }}" role="tab">{{ $categoryLabel }}</a></li>
            @endforeach
          </ul>
          <div class="tab-content mb-4">
            @foreach($shortageCategories as $categoryKey => $categoryLabel)
              @php($missingItems = $systemShortages['categories'][$categoryKey] ?? collect())
              <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}" id="fittings-{{ $listType }}-{{ $categoryKey }}" role="tabpanel">
                <div class="table-responsive">
                  <table class="table table-sm table-striped">
                    <thead><tr><th>Missing item</th><th>Needed</th><th>Available</th><th>Missing</th><th>Used by fits</th></tr></thead>
                    <tbody>
                      @forelse($missingItems as $item)
                        <tr>
                          <td>{{ $item->type_name }}</td><td>{{ $item->needed }}</td><td>{{ $item->have }}</td><td class="text-danger font-weight-bold">{{ $item->missing }}</td>
                          <td>{{ implode(', ', $item->fit_names) }}</td>
                        </tr>
                      @empty
                        <tr><td colspan="5" class="text-success">No missing {{ strtolower($categoryLabel) }} for the assigned doctrine fits.</td></tr>
                      @endforelse
                    </tbody>
                  </table>
                </div>
              </div>
            @endforeach
          </div>
        @else
          <div class="alert alert-info">{{ $systemShortages['message'] ?? 'Inventory requirements are not available.' }}</div>
        @endif
        <form method="POST" action="{{ route('seatcore::fittings.pilot.shopping-lists.store') }}" class="mb-4">
          @csrf<input type="hidden" name="list_type" value="{{ $listType }}">
          <div class="form-row align-items-end"><div class="form-group col-md-8"><label>New personal list</label><input name="list_name" class="form-control" maxlength="160" required></div><div class="form-group col-md-4"><button class="btn btn-primary" type="submit">Create List</button></div></div>
          <div class="form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
        </form>
        @forelse($shoppingLists->where('list_type', $listType) as $list)
          <div class="border rounded p-3 mb-3"><strong>{{ $list->list_name }}</strong>
            <div class="small text-muted">Station: {{ $list->station_name ?: 'Not set' }} · System: {{ $list->system_name ?: 'Not set' }}</div>
            @if($list->notes)<p class="small mt-2">{{ $list->notes }}</p>@endif
            @forelse(($shoppingListItems[$list->id] ?? collect()) as $item)<div class="small py-1">{{ $item->quantity }} × {{ $item->type_name }} <span class="badge badge-light">{{ ucfirst($item->category) }}</span>@if($item->notes) — {{ $item->notes }}@endif</div>
            @empty<div class="small text-muted mt-2">No items yet.</div>@endforelse
            <form method="POST" action="{{ route('seatcore::fittings.pilot.shopping-list-items.store', ['listId' => $list->id]) }}" class="mt-3">@csrf
              <div class="form-row align-items-end"><div class="form-group col-md-6"><label>Item</label><input name="type_name" class="form-control form-control-sm" maxlength="255" required></div><div class="form-group col-md-2"><label>Qty</label><input name="quantity" type="number" min="1" value="1" class="form-control form-control-sm" required></div><div class="form-group col-md-4"><label>Category</label><select name="category" class="form-control form-control-sm"><option value="hull">Hull</option><option value="module">Module</option><option value="rig">Rig</option><option value="consumable">Consumable</option><option value="other">Other</option></select></div></div>
              <button class="btn btn-secondary btn-sm" type="submit">Add Item</button>
            </form>
          </div>
        @empty<p class="text-muted">You have no personal {{ strtolower($label) }} lists yet.</p>@endforelse
      </div></div>
    </div>
  @endforeach
  <div class="tab-pane fade" id="fittings-reserved" role="tabpanel"><div class="card"><div class="card-header"><strong>Reserved</strong></div><div class="card-body text-muted">Reserved for future use.</div></div></div>
</div>
<script>
function copyFittingForEve(id, button) {
  var text = document.getElementById(id).value;
  function showCopied() {
    var icon = button.querySelector('i');
    icon.className = 'fas fa-check';
    window.setTimeout(function () { icon.className = 'far fa-clipboard'; }, 1800);
  }
  if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(showCopied); return; }
  var field = document.getElementById(id); field.classList.remove('d-none'); field.select(); document.execCommand('copy'); field.classList.add('d-none'); showCopied();
}
</script>
</div>
@endsection
