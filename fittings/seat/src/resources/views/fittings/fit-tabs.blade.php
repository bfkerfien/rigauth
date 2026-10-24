<div class="card mb-3">
  <div class="card-header"><strong>Home &amp; Deployment Fits</strong></div>
  <div class="card-body">
    <ul class="nav nav-tabs" role="tablist">
      <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#home-fit-panel" role="tab">Home Fits</a></li>
      <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#deployment-fit-panel" role="tab">Deployment Fits</a></li>
    </ul>
    <div class="tab-content border border-top-0 p-3">
      @foreach(['home' => 'Home', 'deployment' => 'Deployment'] as $locationType => $label)
        <div class="tab-pane fade {{ $locationType === "home" ? "show active" : "" }}" id="{{ $locationType }}-fit-panel">
          <p class="small text-muted">Station: {{ $locations[$locationType]->station_name ?? "Not set" }} · System: {{ $locations[$locationType]->system_name ?? "Not set" }}</p>
          <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#{{ $locationType }}-modules" role="tab">Modules</a></li>
            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#{{ $locationType }}-consumables" role="tab">Consumables</a></li>
            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#{{ $locationType }}-hulls-rigs" role="tab">Hulls &amp; Rigs</a></li>
          </ul>
          <div class="tab-content border border-top-0 p-3">
            @foreach(['modules' => ['module'], 'consumables' => ['consumable'], 'hulls-rigs' => ['hull', 'rig']] as $section => $categories)
              <div class="tab-pane fade {{ $section === "modules" ? "show active" : "" }}" id="{{ $locationType }}-{{ $section }}">
                @forelse($shoppingLists->where("list_type", $locationType) as $list)
                  <div class="border rounded p-3 mb-3"><strong>{{ $list->list_name }}</strong>
                    @forelse(collect($shoppingListItems[$list->id] ?? [])->whereIn("category", $categories) as $item)
                      <div class="small py-2 border-bottom">{{ $item->quantity }} × {{ $item->type_name }} <span class="badge badge-light">{{ ucfirst($item->category) }}</span></div>
                    @empty <p class="small text-muted">No items in this section.</p> @endforelse
                  </div>
                @empty <p class="text-muted">No {{ strtolower($label) }} lists yet.</p> @endforelse
              </div>
            @endforeach
          </div>
        </div>
      @endforeach
    </div>
  </div>
</div>