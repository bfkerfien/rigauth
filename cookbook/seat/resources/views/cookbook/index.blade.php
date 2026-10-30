@extends('web::layouts.grids.12')

@section('title', 'Triglavian Cookbook')
@section('page_header', 'Triglavian Cookbook')
@section('page_description', 'Manufacturing material planner using the SeAT SDE')

@section('full')
  <div class="alert alert-info">
    This is the native SeAT edition. It uses your existing SeAT login and database. Manufacturing quantities come from the SDE tables already loaded in SeAT; no standalone Cookbook account, database, or EVE OAuth application is used.
  </div>

  @if (!$sdeReady)
    <div class="alert alert-warning">
      <strong>SeAT manufacturing SDE tables are not ready.</strong>
      The Cookbook needs <code>invTypes</code>, <code>industryActivityProducts</code>, and <code>industryActivityMaterials</code>. Update/import SeAT's SDE before calculating.
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
    <div class="card-header"><h3 class="card-title">Inventory and compressed ore planning</h3></div>
    <div class="card-body">
      <p class="mb-0">The native manufacturing calculator is the first deployment increment. The original standalone app's Slyce Forge asset aggregation and compressed-ore basket are not yet wired to SeAT's character asset/token services; they are intentionally not simulated or populated with guessed inventory.</p>
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
        if (!response.ok) throw new Error(data.error || 'Calculation failed.');
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
  })();
  </script>
  @endif
@endsection
