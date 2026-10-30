@extends('web::layouts.grids.12')

@section('title', 'Triglavian Cookbook')
@section('page_header', 'Triglavian Cookbook')
@section('page_description', 'Manufacturing, character inventory and compressed ore planning')

@section('full')
  <div class="alert alert-info">
    Native SeAT Cookbook using your SeAT login, linked characters, cached character assets and existing SDE database. Asset quantities are only as current as SeAT's last successful ESI asset refresh.
  </div>

  @if (!$sdeReady)
    <div class="alert alert-warning">
      <strong>SeAT manufacturing SDE tables are not ready.</strong>
      The Cookbook needs <code>invTypes</code>, <code>industryActivityProducts</code>, and <code>industryActivityMaterials</code>. Import/update the manufacturing SDE before calculating.
    </div>
  @else
    <div class="card">
      <div class="card-header"><h3 class="card-title">Manufacturing planner</h3></div>
      <div class="card-body">
        <div class="form-group position-relative">
          <label for="cookbook-search">Blueprint product</label>
          <input id="cookbook-search" class="form-control" autocomplete="off" placeholder="Search ships, modules, structures…">
          <div id="cookbook-results" class="list-group mt-1" style="max-height: 280px; overflow-y: auto;"></div>
          <small class="form-text text-muted">Searches published products with a manufacturing blueprint in the SeAT SDE.</small>
        </div>
        <div class="form-row">
          <div class="form-group col-md-3"><label for="cookbook-runs">Runs</label><input id="cookbook-runs" class="form-control" type="number" min="1" max="100000" value="1"></div>
          <div class="form-group col-md-3"><label for="cookbook-product-me">Product ME</label><input id="cookbook-product-me" class="form-control" type="number" min="0" max="10" value="0"></div>
          <div class="form-group col-md-3"><label for="cookbook-component-me">Component ME</label><input id="cookbook-component-me" class="form-control" type="number" min="0" max="10" value="0"></div>
          <div class="form-group col-md-3 d-flex align-items-end"><button id="cookbook-calculate" class="btn btn-primary btn-block" type="button" disabled>Calculate materials</button></div>
        </div>
        <div id="cookbook-selected" class="text-muted mb-3">No product selected.</div>
        <div id="cookbook-error" class="alert alert-danger d-none"></div>
        <div id="cookbook-output"></div>
      </div>
    </div>
  @endif

  <div class="card">
    <div class="card-header"><h3 class="card-title">Character inventory and compressed ore basket</h3></div>
    <div class="card-body">
      @if ($characters->isEmpty())
        <div class="alert alert-warning mb-0">No linked SeAT characters were found for this account. Link a character in SeAT before using inventory planning.</div>
      @else
        <div class="form-row align-items-end">
          <div class="form-group col-md-8">
            <label for="cookbook-character">Inventory character</label>
            <select id="cookbook-character" class="form-control">
              @foreach ($characters as $character)
                <option value="{{ $character->character_id }}">{{ $character->name }} ({{ $character->character_id }})</option>
              @endforeach
            </select>
          </div>
          <div class="form-group col-md-4">
            <button id="cookbook-load-inventory" class="btn btn-outline-primary btn-block" type="button">Load compressed ore assets</button>
          </div>
        </div>
        <div id="cookbook-inventory-status" class="text-muted mb-2">Select a character and load their cached compressed ore inventory.</div>
        <div id="cookbook-inventory-table" class="table-responsive"></div>
        <hr>
        <div class="form-row align-items-end">
          <div class="form-group col-md-3">
            <label for="cookbook-yield">Combined reprocessing yield (%)</label>
            <input id="cookbook-yield" class="form-control" type="number" min="0.01" max="100" step="0.01" value="85">
          </div>
          <div class="form-group col-md-4">
            <button id="cookbook-reprocess" class="btn btn-primary btn-block" type="button" disabled>Calculate mineral basket</button>
          </div>
        </div>
        <div id="cookbook-ore-output"></div>
        <hr>
        <h4>Calculate additional compressed ore needed for this build</h4>
        <p class="text-muted">After calculating a blueprint above, this uses minerals at The New Dominion (temporary manufacturing-stock location) first, then base-grade compressed ore stored at Draken Barrel Refinery. Choose the compressed ore you want to use for any remaining shortfall.</p>
        <style>
          #cookbook-ore-basket { background: #0d1822; color: #d7e5f2; border: 1px solid #263847; }
          #cookbook-ore-basket .card-header { background: #101e2a; border-bottom: 1px solid #263847; color: #f1f6fa; }
          #cookbook-ore-basket .card-body { background: #0f1b26; }
          #cookbook-ore-basket .cookbook-ore-section { background: #101e2a; border-color: #263847 !important; }
          #cookbook-ore-basket .cookbook-ore-section strong { color: #eaf2f8; }
          #cookbook-ore-basket .custom-control-label { color: #9fc1d9; }
          #cookbook-ore-basket .custom-control-label::before { background-color: #07111a; border-color: #60798b; }
          #cookbook-ore-basket .custom-control-input:checked ~ .custom-control-label::before { background-color: #68d9b8; border-color: #68d9b8; }
          #cookbook-ore-basket a { color: #68e2c1; }
          #cookbook-ore-basket .text-muted { color: #8ca8bc !important; }
        </style>
        <div class="card border mb-3" id="cookbook-ore-basket">
          <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Ore basket options</strong>
            <button id="cookbook-plan-ore" class="btn btn-success" type="button" disabled>Plan ore basket</button>
          </div>
          <div class="card-body">
            <div class="d-flex flex-wrap align-items-center mb-2" style="gap: 1rem;">
              <a href="#" id="cookbook-ore-select-all">Select all</a>
              <a href="#" id="cookbook-ore-deselect-all">Deselect all</a>
            </div>
            <div class="custom-control custom-checkbox mb-3">
              <input class="custom-control-input" type="checkbox" id="cookbook-ignore-low-volume">
              <label class="custom-control-label" for="cookbook-ignore-low-volume">Ignore low-market-volume (Spodumain / Bezdnacine / Rakovene / Talassonite)</label>
            </div>
            <div id="cookbook-plan-ore-options" class="row mx-0" style="max-height: 420px; overflow-y: auto;">
              <div class="col-12 text-muted">Load a character's inventory to populate the normal compressed ore list.</div>
            </div>
            <small class="form-text text-muted mt-2">Standard asteroid ores only. One checkbox per ore: ice, moon ores and named variants (including Glistening) are not included.</small>
          </div>
        </div>
        <div id="cookbook-ore-plan-output"></div>
        <small class="form-text text-muted">The planner uses only the checked standard asteroid ores for additional mining requirements. Existing standard compressed asteroid ore at the refinery is counted first. You can check multiple ore types across the sections and the planner combines them to cover the remaining mineral shortfall.</small>
        <small class="form-text text-muted">The yield percentage is entered by you to represent your actual combined character and facility yield. The planner uses SeAT's invTypeMaterials reprocessing quantities, processes only complete batches, and reports leftovers. It does not guess skills, implants, rigs, structure bonuses, taxes or current prices. Inventory is restricted to BKG-Q2 — Draken Manufacturing and BKG-Q2 — Draken Barrel Refinery, including nested containers inside those structures.</small>
      @endif
    </div>
  </div>

  @if ($sdeReady)
  <script>
  (function () {
    const search = document.getElementById('cookbook-search');
    const results = document.getElementById('cookbook-results');
    const selected = document.getElementById('cookbook-selected');
    const calculate = document.getElementById('cookbook-calculate');
    const output = document.getElementById('cookbook-output');
    const error = document.getElementById('cookbook-error');
    let product = null;
    let latestCalculation = null;
    let facilityInventoryLoaded = false;
    let debounce = null;

    const esc = value => String(value).replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
    const postUrl = @json(route('seatcore::cookbook.calculate'));
    const searchUrl = @json(route('seatcore::cookbook.products'));
    const inventoryUrl = @json(route('seatcore::cookbook.inventory'));
    const reprocessUrl = @json(route('seatcore::cookbook.reprocess'));
    const oreTypesUrl = @json(route('seatcore::cookbook.ore-types'));
    const orePlanUrl = @json(route('seatcore::cookbook.ore-plan'));
    const token = @json(csrf_token());
    const characterSelect = document.getElementById('cookbook-character');

    search.addEventListener('input', function () {
      clearTimeout(debounce);
      const term = search.value.trim();
      product = null;
      latestCalculation = null;
      const orePlannerButton = document.getElementById('cookbook-plan-ore');
      if (orePlannerButton) orePlannerButton.disabled = true;
      calculate.disabled = true;
      selected.textContent = 'Choose a product from the results.';
      if (term.length < 2) { results.innerHTML = ''; return; }
      debounce = setTimeout(async () => {
        try {
          const response = await fetch(searchUrl + '?q=' + encodeURIComponent(term), {headers: {'Accept':'application/json'}});
          const data = await response.json();
          if (!response.ok) throw new Error(data.error || 'Search failed.');
          results.innerHTML = '';
          data.items.forEach(item => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'list-group-item list-group-item-action';
            button.textContent = item.product_name + ' — ' + item.blueprint_name;
            button.addEventListener('click', () => {
              product = item;
              search.value = item.product_name;
              selected.textContent = 'Selected: ' + item.product_name + ' (' + item.blueprint_name + ')';
              calculate.disabled = false;
              results.innerHTML = '';
            });
            results.appendChild(button);
          });
          if (!data.items.length) results.innerHTML = '<div class="list-group-item text-muted">No manufacturing products found.</div>';
        } catch (e) {
          error.textContent = e.message;
          error.classList.remove('d-none');
        }
      }, 250);
    });

    calculate.addEventListener('click', async function () {
      if (!product) return;
      error.classList.add('d-none');
      output.innerHTML = '<p class="text-muted">Calculating recursive blueprint materials…</p>';
      const body = new URLSearchParams({
        _token: token,
        product_type_id: product.product_type_id,
        runs: document.getElementById('cookbook-runs').value,
        product_me: document.getElementById('cookbook-product-me').value,
        component_me: document.getElementById('cookbook-component-me').value,
        character_id: characterSelect ? characterSelect.value : ''
      });
      try {
        const response = await fetch(postUrl, {method:'POST', headers:{'Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'}, body});
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || data.error || 'Calculation failed.');
        latestCalculation = data;
        const planButton = document.getElementById('cookbook-plan-ore');
        if (planButton && facilityInventoryLoaded) planButton.disabled = false;
        const table = (rows, title) => '<h5 class="mt-3">' + title + '</h5><div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>Material</th><th class="text-right">Required</th>' + (data.inventory_character_id ? '<th class="text-right">Draken Manufacturing</th><th class="text-right">Shortfall</th>' : '') + '</tr></thead><tbody>' +
          (rows.length ? rows.map(row => '<tr><td>' + esc(row.name) + ' <small class="text-muted">(' + row.type_id + ')</small></td><td class="text-right">' + Number(row.quantity).toLocaleString() + '</td>' + (data.inventory_character_id ? '<td class="text-right">' + Number(row.available_quantity).toLocaleString() + '</td><td class="text-right">' + Number(row.shortfall_quantity).toLocaleString() + '</td>' : '') + '</tr>').join('') : '<tr><td colspan="' + (data.inventory_character_id ? '4' : '2') + '" class="text-muted">None</td></tr>') +
          '</tbody></table></div>';
        output.innerHTML = '<h4>' + esc(data.product) + ' <small class="text-muted">· ' + data.runs + ' runs</small></h4>' +
          table(data.minerals, 'Minerals') + table(data.other_materials, 'Other required inputs') +
          (data.inventory_character_id ? '<div class="alert alert-info">Material availability is checked only at BKG-Q2 — The New Dominion (temporary manufacturing-stock location). Ore stock is checked separately at BKG-Q2 — Draken Barrel Refinery.</div>' : '') +
          '<div class="alert alert-secondary">' + esc(data.note) + '</div>';
      } catch (e) {
        output.innerHTML = '';
        error.textContent = e.message;
        error.classList.remove('d-none');
      }
    });

    const inventoryButton = document.getElementById('cookbook-load-inventory');
    if (inventoryButton) {
      const character = document.getElementById('cookbook-character');
      const status = document.getElementById('cookbook-inventory-status');
      const inventoryTable = document.getElementById('cookbook-inventory-table');
      const reprocess = document.getElementById('cookbook-reprocess');
      const oreOutput = document.getElementById('cookbook-ore-output');
      const oreTypeOptions = document.getElementById('cookbook-plan-ore-options');
      const orePlanButton = document.getElementById('cookbook-plan-ore');
      const orePlanOutput = document.getElementById('cookbook-ore-plan-output');
      let inventoryItems = [];

      character.addEventListener('change', function () {
        facilityInventoryLoaded = false;
        latestCalculation = null;
        inventoryItems = [];
        inventoryTable.innerHTML = '';
        oreOutput.innerHTML = '';
        orePlanOutput.innerHTML = '';
        status.textContent = 'Character changed. Reload facility inventory and recalculate the blueprint.';
        reprocess.disabled = true;
        orePlanButton.disabled = true;
      });

      inventoryButton.addEventListener('click', async function () {
        status.textContent = 'Loading cached assets from SeAT…';
        inventoryTable.innerHTML = '';
        oreOutput.innerHTML = '';
        reprocess.disabled = true;
        orePlanButton.disabled = true;
        orePlanOutput.innerHTML = '';
        try {
          const response = await fetch(inventoryUrl, {
            method: 'POST',
            headers: {'Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'},
            body: new URLSearchParams({_token: token, character_id: character.value})
          });
          const data = await response.json();
          if (!response.ok) throw new Error(data.message || data.error || 'Could not load character assets.');
          inventoryItems = data.items || [];
          facilityInventoryLoaded = true;
          status.textContent = data.note || 'Loaded BKG-Q2 facility inventory.';
          inventoryTable.innerHTML = inventoryItems.length ? '<table class="table table-sm table-striped"><thead><tr><th>Compressed ore</th><th class="text-right">Cached quantity</th><th style="width:180px">Basket quantity</th><th class="text-right">Batch size</th></tr></thead><tbody>' +
            inventoryItems.map((item, index) => '<tr><td>' + esc(item.name) + ' <small class="text-muted">(' + item.type_id + ')</small></td><td class="text-right">' + Number(item.quantity).toLocaleString() + '</td><td><input class="form-control form-control-sm cookbook-ore-qty" data-index="' + index + '" type="number" min="0" max="1000000000" value="' + item.quantity + '"></td><td class="text-right">' + Number(item.portion_size).toLocaleString() + '</td></tr>').join('') +
            '</tbody></table>' : '<p class="text-muted">No compressed ore found at Draken Barrel Refinery for this character.</p>';
          reprocess.disabled = inventoryItems.length === 0;
          const typeResponse = await fetch(oreTypesUrl, {headers:{'Accept':'application/json'}});
          const typeData = await typeResponse.json();
          if (!typeResponse.ok) throw new Error(typeData.message || typeData.error || 'Could not load compressed ore types.');
          const oreSections = (typeData.items || []).reduce((sections, item) => {
            const section = item.section || 'Asteroid ores';
            if (!sections[section]) sections[section] = [];
            sections[section].push(item);
            return sections;
          }, {});
          const sectionOrder = ['Simple ores', 'Coherent ores', 'Variegated ores', 'Complex ores', 'Abyssal ores', 'Mercoxit'];
          oreTypeOptions.innerHTML = sectionOrder.filter(section => oreSections[section]).map(section =>
            '<div class="col-md-6 mb-2"><div class="border rounded p-3 h-100 cookbook-ore-section">' +
              '<div class="d-flex justify-content-between align-items-center mb-2"><strong>' + esc(section) + '</strong><small class="text-muted">' + oreSections[section].length + ' / ' + oreSections[section].length + '</small></div>' +
              '<div class="d-flex flex-wrap" style="gap: .55rem 1rem;">' + oreSections[section].map(item => {
                const oreName = item.name.replace(/^Compressed /, '');
                return '<div class="custom-control custom-checkbox">' +
                  '<input class="custom-control-input cookbook-plan-ore-checkbox" type="checkbox" id="cookbook-plan-ore-' + item.type_id + '" value="' + item.type_id + '" data-ore-name="' + esc(oreName) + '" checked>' +
                  '<label class="custom-control-label" for="cookbook-plan-ore-' + item.type_id + '">' + esc(oreName) + '</label>' +
                '</div>';
              }).join('') + '</div></div></div>'
          ).join('') || '<div class="col-12 text-muted">No standard compressed asteroid ores were found in the SeAT SDE.</div>';
          const updateOrePicker = () => {
            const ignoreLow = document.getElementById('cookbook-ignore-low-volume').checked;
            const lowNames = ['Spodumain', 'Bezdnacine', 'Rakovene', 'Talassonite'];
            oreTypeOptions.querySelectorAll('.cookbook-plan-ore-checkbox').forEach(input => {
              const excluded = ignoreLow && lowNames.includes(input.dataset.oreName);
              if (excluded) input.checked = false;
              input.closest('.custom-control').style.display = excluded ? 'none' : '';
            });
          };
          document.getElementById('cookbook-ignore-low-volume').addEventListener('change', updateOrePicker);
          document.getElementById('cookbook-ore-select-all').addEventListener('click', event => {
            event.preventDefault();
            oreTypeOptions.querySelectorAll('.cookbook-plan-ore-checkbox').forEach(input => {
              if (input.closest('.custom-control').style.display !== 'none') input.checked = true;
            });
          });
          document.getElementById('cookbook-ore-deselect-all').addEventListener('click', event => {
            event.preventDefault();
            oreTypeOptions.querySelectorAll('.cookbook-plan-ore-checkbox').forEach(input => input.checked = false);
          });
          updateOrePicker();
          orePlanButton.disabled = !latestCalculation || !character.value;
        } catch (e) {
          status.textContent = e.message;
        }
      });

      orePlanButton.addEventListener('click', async function () {
        const selectedOreIds = Array.from(oreTypeOptions.querySelectorAll('.cookbook-plan-ore-checkbox:checked')).filter(input => input.closest('.custom-control').style.display !== 'none').map(input => input.value);
        if (!latestCalculation || !character.value || !selectedOreIds.length) {
          orePlanOutput.innerHTML = '<div class="alert alert-warning">Calculate a blueprint, load a character, and tick at least one normal compressed ore type first.</div>';
          return;
        }
        const needs = (latestCalculation.minerals || []).map(row => ({
          type_id: row.type_id,
          quantity: Number(row.quantity)
        }));
        if (!needs.length) {
          orePlanOutput.innerHTML = '<div class="alert alert-info">This blueprint has no mineral requirements to cover.</div>';
          return;
        }
        orePlanOutput.innerHTML = '<p class="text-muted">Calculating remaining compressed ore requirements…</p>';
        const body = new URLSearchParams({
          _token: token,
          character_id: character.value,
          yield_percent: document.getElementById('cookbook-yield').value
        });
        selectedOreIds.forEach(id => body.append('ore_type_ids[]', id));
        needs.forEach((item, index) => {
          body.append('mineral_needs[' + index + '][type_id]', item.type_id);
          body.append('mineral_needs[' + index + '][quantity]', item.quantity);
        });
        try {
          const response = await fetch(orePlanUrl, {method:'POST', headers:{'Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'}, body});
          const data = await response.json();
          if (!response.ok) throw new Error(data.message || data.error || 'Ore planning failed.');
          const mineralRows = data.minerals.map(row => '<tr><td>' + esc(row.name) + '</td><td class="text-right">' + Number(row.required).toLocaleString() + '</td><td class="text-right">' + Number(row.at_manufacturing).toLocaleString() + '</td><td class="text-right">' + Number(row.from_refinery_ore_stock).toLocaleString() + '</td><td class="text-right">' + Number(row.remaining_before_new_ore).toLocaleString() + '</td><td class="text-right">' + Number(row.from_additional_ore).toLocaleString() + '</td><td class="text-right">' + Number(row.surplus).toLocaleString() + '</td></tr>').join('');
          const stockRows = data.ore_stock.map(row => '<tr><td>' + esc(row.name) + '</td><td class="text-right">' + Number(row.quantity).toLocaleString() + '</td><td class="text-right">' + Number(row.complete_batches).toLocaleString() + '</td><td class="text-right">' + Number(row.leftover_units).toLocaleString() + '</td></tr>').join('');
          const planRows = data.ore_plan.map(row => '<tr><td>' + esc(row.name) + '</td><td class="text-right">' + Number(row.batches).toLocaleString() + '</td><td class="text-right">' + Number(row.portion_size).toLocaleString() + '</td><td class="text-right">' + Number(row.additional_units).toLocaleString() + '</td></tr>').join('');
          const totalOreUnits = data.ore_plan.reduce((sum, row) => sum + Number(row.additional_units), 0);
          orePlanOutput.innerHTML = '<div class="alert alert-success"><strong>Additional compressed ore to mine:</strong> ' + totalOreUnits.toLocaleString() + ' units across ' + data.ore_plan.length + ' ore type(s)</div>' +
            '<h5>Additional ore requirements</h5><div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>Compressed ore</th><th class="text-right">Batches</th><th class="text-right">Batch size</th><th class="text-right">Additional units</th></tr></thead><tbody>' + (planRows || '<tr><td colspan="4">No additional ore required.</td></tr>') + '</tbody></table></div>' +
            '<h5 class="mt-3">Mineral coverage</h5><div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>Mineral</th><th class="text-right">Required</th><th class="text-right">At manufacturing</th><th class="text-right">From refinery ore stock</th><th class="text-right">Still needed</th><th class="text-right">From new ore</th><th class="text-right">Surplus</th></tr></thead><tbody>' + mineralRows + '</tbody></table></div>' +
            '<h5 class="mt-3">Compressed ore already at Draken Barrel Refinery</h5><div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>Ore</th><th class="text-right">Units</th><th class="text-right">Complete batches</th><th class="text-right">Leftover units</th></tr></thead><tbody>' + (stockRows || '<tr><td colspan="4">No compressed ore currently stored at the refinery.</td></tr>') + '</tbody></table></div>' +
            '<div class="alert alert-secondary">' + esc(data.note) + '</div>';
        } catch (e) {
          orePlanOutput.innerHTML = '<div class="alert alert-danger">' + esc(e.message) + '</div>';
        }
      });

      reprocess.addEventListener('click', async function () {
        const inputs = Array.from(document.querySelectorAll('.cookbook-ore-qty'));
        const items = inputs.map(input => ({
          type_id: inventoryItems[Number(input.dataset.index)].type_id,
          quantity: Number(input.value || 0)
        }));
        oreOutput.innerHTML = '<p class="text-muted">Calculating reprocessing output from SDE quantities…</p>';
        try {
          const body = new URLSearchParams({_token: token, yield_percent: document.getElementById('cookbook-yield').value});
          items.forEach((item, index) => {
            body.append('items[' + index + '][type_id]', item.type_id);
            body.append('items[' + index + '][quantity]', item.quantity);
          });
          const response = await fetch(reprocessUrl, {method:'POST', headers:{'Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'}, body});
          const data = await response.json();
          if (!response.ok) throw new Error(data.message || data.error || 'Reprocessing calculation failed.');
          const oreRows = data.ores.map(ore => '<tr><td>' + esc(ore.name) + '</td><td class="text-right">' + Number(ore.quantity).toLocaleString() + '</td><td class="text-right">' + Number(ore.batches).toLocaleString() + '</td><td class="text-right">' + Number(ore.leftover).toLocaleString() + '</td></tr>').join('');
          const mineralRows = data.minerals.map(mineral => '<tr><td>' + esc(mineral.name) + ' <small class="text-muted">(' + mineral.type_id + ')</small></td><td class="text-right">' + Number(mineral.quantity).toLocaleString() + '</td></tr>').join('');
          oreOutput.innerHTML = '<h5>Compressed ore batches</h5><div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>Ore</th><th class="text-right">Units</th><th class="text-right">Full batches</th><th class="text-right">Leftover</th></tr></thead><tbody>' + (oreRows || '<tr><td colspan="4">No ore quantity entered.</td></tr>') + '</tbody></table></div>' +
            '<h5 class="mt-3">Estimated mineral output at ' + esc(data.yield_percent) + '% yield</h5><div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>Mineral</th><th class="text-right">Quantity</th></tr></thead><tbody>' + (mineralRows || '<tr><td colspan="2">No reprocessing output.</td></tr>') + '</tbody></table></div>' +
            '<div class="alert alert-secondary">' + esc(data.note) + '</div>';
        } catch (e) {
          oreOutput.innerHTML = '<div class="alert alert-danger">' + esc(e.message) + '</div>';
        }
      });
    }
  })();
  </script>
  @endif
@endsection
