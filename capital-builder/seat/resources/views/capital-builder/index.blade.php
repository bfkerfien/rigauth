@extends('web::layouts.grids.12')

@section('title', 'Capital Builder')
@section('page_header', 'Capital Builder')
@section('page_description', 'Recursive capital ship and component manufacturing planner')

@section('full')
  <div class="alert alert-info">
    Capital Builder uses your SeAT login and the existing SeAT manufacturing SDE. It expands capital ships into their required manufactured components, then recursively expands those components into their own subcomponents, minerals and remaining inputs.
  </div>

  @unless ($sdeReady)
    <div class="alert alert-warning">
      <strong>Manufacturing SDE data is not ready.</strong>
      Capital Builder requires <code>invTypes</code>, <code>industryActivityProducts</code>, and <code>industryActivityMaterials</code> in the SeAT database.
    </div>
  @else
    <div class="card">
      <div class="card-header"><h3 class="card-title">Capital manufacturing planner</h3></div>
      <div class="card-body">
        <div class="form-group position-relative">
          <label for="capital-search">Ship or manufactured product</label>
          <input id="capital-search" class="form-control" autocomplete="off" placeholder="Search Chimera Navy Issue, capital ships, components…">
          <div id="capital-results" class="list-group mt-1" style="max-height: 300px; overflow-y: auto;"></div>
          <small class="form-text text-muted">Searches published products with a manufacturing blueprint in the loaded SeAT SDE.</small>
        </div>
        <div class="form-row">
          <div class="form-group col-md-3"><label for="capital-runs">Runs</label><input id="capital-runs" class="form-control" type="number" min="1" max="100000" value="1"></div>
          <div class="form-group col-md-3"><label for="capital-product-me">Ship / product ME</label><input id="capital-product-me" class="form-control" type="number" min="0" max="10" value="0"></div>
          <div class="form-group col-md-3"><label for="capital-component-me">Component ME</label><input id="capital-component-me" class="form-control" type="number" min="0" max="10" value="0"></div>
          <div class="form-group col-md-3 d-flex align-items-end"><button id="capital-calculate" class="btn btn-primary btn-block" type="button" disabled>Build manufacturing plan</button></div>
        </div>
        <div id="capital-selected" class="text-muted mb-3">No product selected.</div>
        <div id="capital-error" class="alert alert-danger d-none"></div>
        <div id="capital-output"></div>
      </div>
    </div>
    <script>
    (function () {
      const search = document.getElementById('capital-search');
      const results = document.getElementById('capital-results');
      const selected = document.getElementById('capital-selected');
      const calculate = document.getElementById('capital-calculate');
      const output = document.getElementById('capital-output');
      const error = document.getElementById('capital-error');
      const searchUrl = @json(route('seatcore::capital-builder.products'));
      const calculateUrl = @json(route('seatcore::capital-builder.calculate'));
      const token = @json(csrf_token());
      let product = null;
      let debounce = null;
      const esc = value => String(value).replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
      search.addEventListener('input', function () {
        clearTimeout(debounce);
        product = null;
        calculate.disabled = true;
        selected.textContent = 'Choose a product from the results.';
        const term = search.value.trim();
        if (term.length < 2) { results.innerHTML = ''; return; }
        debounce = setTimeout(async () => {
          try {
            const response = await fetch(searchUrl + '?q=' + encodeURIComponent(term), {headers:{'Accept':'application/json'}});
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || data.error || 'Product search failed.');
            results.innerHTML = '';
            (data.items || []).forEach(item => {
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
                output.innerHTML = '';
                error.classList.add('d-none');
              });
              results.appendChild(button);
            });
            if (!(data.items || []).length) results.innerHTML = '<div class="list-group-item text-muted">No manufacturing blueprint product found in the SeAT SDE.</div>';
          } catch (e) {
            error.textContent = e.message;
            error.classList.remove('d-none');
          }
        }, 250);
      });

      calculate.addEventListener('click', async function () {
        if (!product) return;
        error.classList.add('d-none');
        output.innerHTML = '<p class="text-muted">Expanding the complete manufacturing chain…</p>';
        const body = new URLSearchParams({
          _token: token,
          product_type_id: product.product_type_id,
          runs: document.getElementById('capital-runs').value,
          product_me: document.getElementById('capital-product-me').value,
          component_me: document.getElementById('capital-component-me').value
        });
        try {
          const response = await fetch(calculateUrl, {method:'POST', headers:{'Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'}, body});
          const data = await response.json();
          if (!response.ok) throw new Error(data.message || data.error || 'Manufacturing calculation failed.');
          const table = (rows, heading) => '<h5 class="mt-4">' + heading + '</h5><div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>Item</th><th class="text-right">Total required</th><th>Type ID</th></tr></thead><tbody>' +
            (rows.length ? rows.map(row => '<tr><td>' + esc(row.name) + '</td><td class="text-right">' + Number(row.quantity).toLocaleString() + '</td><td>' + row.type_id + '</td></tr>').join('') : '<tr><td colspan="3" class="text-muted">None</td></tr>') +
            '</tbody></table></div>';
          output.innerHTML = '<h4>' + esc(data.product) + ' <small class="text-muted">· ' + data.runs + ' run(s)</small></h4>' +
            '<p class="text-muted">Product ME ' + data.product_me + ' · Component ME ' + data.component_me + '</p>' +
            table(data.component_builds || [], '1. Manufactured components and subcomponents') +
            table(data.minerals || [], '2. Consolidated mineral requirements') +
            table(data.other_materials || [], '3. Remaining inputs to acquire') +
            '<div class="alert alert-secondary mt-3">' + esc(data.note) + '</div>';
        } catch (e) {
          output.innerHTML = '';
          error.textContent = e.message;
          error.classList.remove('d-none');
        }
      });
    })();
    </script>
  @endunless
@endsection
