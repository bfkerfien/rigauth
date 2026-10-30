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
        <small class="form-text text-muted">The yield percentage is entered by you to represent your actual combined character and facility yield. The planner uses SeAT's invTypeMaterials reprocessing quantities, processes only complete batches, and reports leftovers. It does not guess skills, implants, rigs, structure bonuses, taxes or current prices. Character assets include all locations in that character's SeAT asset cache.</small>
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
    let debounce = null;

    const esc = value => String(value).replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
    const postUrl = @json(route('seatcore::cookbook.calculate'));
    const searchUrl = @json(route('seatcore::cookbook.products'));
    const inventoryUrl = @json(route('seatcore::cookbook.inventory'));
    const reprocessUrl = @json(route('seatcore::cookbook.reprocess'));
    const token = @json(csrf_token());

    search.addEventListener('input', function () {
      clearTimeout(debounce);
      const term = search.value.trim();
      product = null;
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
        component_me: document.getElementById('cookbook-component-me').value
      });
      try {
        const response = await fetch(postUrl, {method:'POST', headers:{'Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'}, body});
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || data.error || 'Calculation failed.');
        const table = (rows, title) => '<h5 class="mt-3">' + title + '</h5><div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>Material</th><th class="text-right">Quantity</th></tr></thead><tbody>' +
          (rows.length ? rows.map(row => '<tr><td>' + esc(row.name) + ' <small class="text-muted">(' + row.type_id + ')</small></td><td class="text-right">' + Number(row.quantity).toLocaleString() + '</td></tr>').join('') : '<tr><td colspan="2" class="text-muted">None</td></tr>') +
          '</tbody></table></div>';
        output.innerHTML = '<h4>' + esc(data.product) + ' <small class="text-muted">· ' + data.runs + ' runs</small></h4>' +
          table(data.minerals, 'Minerals') + table(data.other_materials, 'Other required inputs') +
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
      let inventoryItems = [];

      inventoryButton.addEventListener('click', async function () {
        status.textContent = 'Loading cached assets from SeAT…';
        inventoryTable.innerHTML = '';
        oreOutput.innerHTML = '';
        reprocess.disabled = true;
        try {
          const response = await fetch(inventoryUrl, {
            method: 'POST',
            headers: {'Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'},
            body: new URLSearchParams({_token: token, character_id: character.value})
          });
          const data = await response.json();
          if (!response.ok) throw new Error(data.message || data.error || 'Could not load character assets.');
          inventoryItems = data.items || [];
          status.textContent = inventoryItems.length
            ? 'Loaded ' + inventoryItems.length + ' compressed ore type(s) from the selected character cache.'
            : 'No compressed ore assets found for this character in the current SeAT cache.';
          if (!inventoryItems.length) {
            inventoryTable.innerHTML = '<p class="text-muted">No compressed ore rows found. Refresh this character’s ESI assets in SeAT and try again.</p>';
            return;
          }
          inventoryTable.innerHTML = '<table class="table table-sm table-striped"><thead><tr><th>Compressed ore</th><th class="text-right">Cached quantity</th><th style="width:180px">Basket quantity</th><th class="text-right">Batch size</th></tr></thead><tbody>' +
            inventoryItems.map((item, index) => '<tr><td>' + esc(item.name) + ' <small class="text-muted">(' + item.type_id + ')</small></td><td class="text-right">' + Number(item.quantity).toLocaleString() + '</td><td><input class="form-control form-control-sm cookbook-ore-qty" data-index="' + index + '" type="number" min="0" max="1000000000" value="' + item.quantity + '"></td><td class="text-right">' + Number(item.portion_size).toLocaleString() + '</td></tr>').join('') +
            '</tbody></table>';
          reprocess.disabled = false;
        } catch (e) {
          status.textContent = e.message;
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
