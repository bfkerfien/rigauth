@extends('web::layouts.grids.12')

@section('title', 'Fittings')
@section('page_header', 'Fittings')
@section('page_description', 'Alliance doctrines and your personal location-specific inventory lists')

@section('full')
@if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
@if($errors->any()) <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div> @endif

<ul class="nav nav-tabs mb-3" role="tablist">
  <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#fittings-doctrines" role="tab">Doctrines</a></li>
  <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#fittings-home" role="tab">Home System</a></li>
  <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#fittings-deployment" role="tab">Deployment System</a></li>
  <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#fittings-third" role="tab">Third Location</a></li>
  <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#fittings-reserved" role="tab">Reserved</a></li>
</ul>

<div class="tab-content">
  <div class="tab-pane fade show active" id="fittings-doctrines" role="tabpanel">
    <div class="card mb-3"><div class="card-header"><strong>My character doctrine selections</strong></div><div class="card-body">
      <p class="text-muted">Choose which doctrines each character should be checked against at each location. Selections are independent per character and location.</p>
      @foreach($pilots as $pilot)
        <details class="border rounded p-3 mb-2"><summary style="cursor:pointer"><strong>{{ $pilot->name ?? ('Character '.$pilot->character_id) }}</strong></summary>
          @foreach(['home' => 'Home System', 'deployment' => 'Deployment System', 'third' => 'Third Location'] as $locationType => $locationLabel)
            @php($selectionKey = $pilot->character_id.'|'.$locationType)
            @php($isConfigured = isset($characterDoctrineSettings[$selectionKey]))
            @php($selectedDoctrineNames = collect($characterDoctrineSelections[$selectionKey] ?? [])->pluck('doctrine')->all())
            @if(!$isConfigured) @php($selectedDoctrineNames = $doctrines->filter(fn($d) => in_array($d->location_scope ?? 'both', [$locationType, 'both'], true))->pluck('name')->all()) @endif
            <form method="POST" action="{{ route('seatcore::fittings.pilot.doctrines.store') }}" class="border-top pt-3 mt-3">
              @csrf<input type="hidden" name="character_id" value="{{ $pilot->character_id }}"><input type="hidden" name="location_type" value="{{ $locationType }}">
              <h6>{{ $locationLabel }}</h6><div class="row">
                @foreach($doctrines as $doctrine)<div class="col-md-6 col-lg-4"><label class="font-weight-normal"><input type="checkbox" name="doctrines[]" value="{{ $doctrine->name }}" {{ in_array($doctrine->name, $selectedDoctrineNames, true) ? 'checked' : '' }}> {{ $doctrine->name }}</label></div>@endforeach
              </div><button type="submit" class="btn btn-sm btn-primary mt-2">Save {{ $locationLabel }}</button>
            </form>
          @endforeach
        </details>
      @endforeach
    </div></div>
    @foreach($doctrines as $doctrine)
      @php($doctrineDoctrineFits = $doctrineFits[$doctrine->name] ?? collect())
      <details class="card mb-3">
        <summary class="card-header" style="cursor:pointer"><strong>{{ $doctrine->name }}</strong> <span class="badge badge-secondary">{{ $doctrineDoctrineFits->count() }} fits</span></summary>
        <div class="card-body table-responsive">
          <table class="table table-striped table-sm mb-0">
            <thead><tr><th>Fit</th><th>Ship</th><th>Qualified pilots</th><th class="text-center" title="Home inventory">Home</th><th class="text-center" title="Deployment inventory">Deployment</th><th class="text-center" title="Third location inventory">Third</th><th class="text-center" aria-label="Clipboard"><i class="far fa-clipboard" title="Clipboard"></i></th></tr></thead>
            <tbody>
              @forelse($doctrineDoctrineFits as $fit)
                @php($fitStatus = $fitSkillStatus[$fit->id] ?? ['eligible' => [], 'checked_pilots' => 0, 'unsynced_pilots' => $pilots->count()])
                @php($fitInventory = $fitInventoryStatus[$fit->id] ?? [])
                @php($homeInventory = $fitInventory['home'] ?? ['applicable' => false, 'available' => false, 'ready' => false])
                @php($deploymentInventory = $fitInventory['deployment'] ?? ['applicable' => false, 'available' => false, 'ready' => false])
                @php($thirdInventory = $fitInventory['third'] ?? ['applicable' => false, 'available' => false, 'ready' => false])
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
                  <td class="text-center">
                    @if(!$homeInventory['applicable'])
                      <span class="fa-stack text-muted" title="This doctrine is not assigned to the home system" aria-label="Not a home doctrine">
                        <i class="fas fa-home fa-stack-1x" aria-hidden="true"></i><i class="fas fa-times fa-stack-1x text-danger" aria-hidden="true"></i>
                      </span>
                    @elseif(!$homeInventory['available'])
                      <i class="fas fa-home text-muted" title="Home inventory status unavailable" aria-label="Home inventory status unavailable"></i>
                    @else
                      <i class="fas fa-home {{ $homeInventory['ready'] ? 'text-success' : 'text-danger' }}" title="{{ $homeInventory['ready'] ? 'All required items are in the home system' : 'Missing required items in the home system' }}" aria-label="{{ $homeInventory['ready'] ? 'Home inventory ready' : 'Home inventory missing' }}"></i>
                    @endif
                  </td>
                  <td class="text-center">
                    @if(!$deploymentInventory['applicable'])
                      <span class="fa-stack text-muted" title="This doctrine is not assigned to the deployment system" aria-label="Not a deployment doctrine">
                        <i class="fas fa-caravan fa-stack-1x" aria-hidden="true"></i><i class="fas fa-times fa-stack-1x text-danger" aria-hidden="true"></i>
                      </span>
                    @elseif(!$deploymentInventory['available'])
                      <i class="fas fa-caravan text-muted" title="Deployment inventory status unavailable" aria-label="Deployment inventory status unavailable"></i>
                    @else
                      <i class="fas fa-caravan {{ $deploymentInventory['ready'] ? 'text-success' : 'text-danger' }}" title="{{ $deploymentInventory['ready'] ? 'All required items are in the deployment system' : 'Missing required items in the deployment system' }}" aria-label="{{ $deploymentInventory['ready'] ? 'Deployment inventory ready' : 'Deployment inventory missing' }}"></i>
                    @endif
                  </td>
                  <td class="text-center">
                    @if(!$thirdInventory['applicable'])
                      <span class="fa-stack text-muted" title="This doctrine is not selected for the third location" aria-label="Not selected for third location"><i class="fas fa-map-marker-alt fa-stack-1x" aria-hidden="true"></i><i class="fas fa-times fa-stack-1x text-danger" aria-hidden="true"></i></span>
                    @elseif(!$thirdInventory['available'])
                      <i class="fas fa-map-marker-alt text-muted" title="Third-location inventory status unavailable" aria-label="Third-location inventory status unavailable"></i>
                    @else
                      <i class="fas fa-map-marker-alt {{ $thirdInventory['ready'] ? 'text-success' : 'text-danger' }}" title="{{ $thirdInventory['ready'] ? 'All required items are in the third location' : 'Missing required items in the third location' }}" aria-label="{{ $thirdInventory['ready'] ? 'Third-location inventory ready' : 'Third-location inventory missing' }}"></i>
                    @endif
                  </td>
                  <td>
                    <textarea id="fitting-eft-{{ $fit->id }}" class="d-none" readonly>{{ $fit->clipboard_text }}</textarea>
                    <button type="button" class="btn btn-link btn-sm p-0" title="Copy fitting to clipboard" aria-label="Copy fitting to clipboard" onclick="copyFittingForEve('fitting-eft-{{ $fit->id }}', this)"><i class="far fa-clipboard" aria-hidden="true"></i></button>
                  </td>
                </tr>
              @empty
                <tr><td colspan="7" class="text-muted">No alliance fittings are assigned to this doctrine yet.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </details>
    @endforeach
    @if($doctrines->isEmpty())<div class="alert alert-info">No doctrines have been added yet.</div>@endif
    <p class="small text-muted">Skill checks use synced skills for all pilots linked to your SeAT account. Red fits have no linked pilot with synced skills that meet the requirements. House and camper icons show whether the full fit inventory is present at the assigned home or deployment system; a crossed icon means that doctrine is not assigned there.</p>
  </div>

  @foreach(['home' => 'Home System', 'deployment' => 'Deployment System', 'third' => 'Third Location'] as $listType => $label)
    @php($location = $locations[$listType] ?? null)
    <div class="tab-pane fade" id="fittings-{{ $listType }}" role="tabpanel">
      <div class="card"><div class="card-header"><strong>{{ $label }}</strong></div><div class="card-body">
        <p class="text-muted mb-3">Alliance location: {{ $location->station_name ?? 'Not selected' }}{{ !empty($location->system_name) ? ' — '.$location->system_name : '' }}</p>
        @php($shortageCategories = ['modules' => 'Modules', 'consumables' => 'Consumables', 'hull_rig' => 'Hull & Rig Combo'])
        @php($systemShortages = $inventoryShortages[$listType] ?? ['available' => false, 'categories' => []])
        @if($systemShortages['available'])
          <p class="small text-muted">Hulls &amp; Rigs are grouped separately for each fit so each ship is shown with its own rig requirements. Modules and consumables continue to use the highest quantity required by any applicable fit. Shortages are calculated separately for each character; one character's assets cannot cover another character's missing items.</p>
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
                    <thead><tr><th>Character</th><th>Missing item</th><th>Needed</th><th>Available</th><th>Missing</th><th>Fit</th></tr></thead>
                    <tbody>
                      @if($categoryKey === 'hull_rig')
                        @forelse($missingItems->groupBy('group_key') as $fitShortages)
                          @php($fitGroupName = $fitShortages->first()->group_name ?? ($fitShortages->first()->fit_names[0] ?? 'Fit'))
                          <tr class="table-secondary"><th colspan="6">{{ $fitGroupName }}</th></tr>
                          @foreach($fitShortages as $item)
                            <tr>
                              <td>{{ $item->character_name ?? 'Character' }}</td><td>{{ $item->type_name }}</td><td>{{ $item->needed }}</td><td>{{ $item->have }}</td><td class="text-danger font-weight-bold">{{ $item->missing }}</td><td>{{ $fitGroupName }}</td>
                            </tr>
                          @endforeach
                        @empty
                          <tr><td colspan="6" class="text-success">No missing hulls or rigs for the selected characters' doctrine fits.</td></tr>
                        @endforelse
                      @else
                        @forelse($missingItems as $item)
                          <tr>
                            <td>{{ $item->character_name ?? 'Character' }}</td><td>{{ $item->type_name }}</td><td>{{ $item->needed }}</td><td>{{ $item->have }}</td><td class="text-danger font-weight-bold">{{ $item->missing }}</td>
                            <td>{{ implode(', ', $item->fit_names) }}</td>
                          </tr>
                        @empty
                          <tr><td colspan="6" class="text-success">No missing {{ strtolower($categoryLabel) }} for the selected characters' doctrine fits.</td></tr>
                        @endforelse
                      @endif
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
