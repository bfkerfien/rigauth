<ul class="nav nav-tabs mt-3" role="tablist">
  <li class="nav-item">
    <a class="nav-link active" id="{{ $prefix }}-hulls-link" data-toggle="tab" href="#{{ $prefix }}-hulls" role="tab" aria-controls="{{ $prefix }}-hulls" aria-selected="true">Hulls</a>
  </li>
  <li class="nav-item">
    <a class="nav-link" id="{{ $prefix }}-modules-link" data-toggle="tab" href="#{{ $prefix }}-modules" role="tab" aria-controls="{{ $prefix }}-modules" aria-selected="false">Modules</a>
  </li>
  <li class="nav-item">
    <a class="nav-link" id="{{ $prefix }}-rigs-link" data-toggle="tab" href="#{{ $prefix }}-rigs" role="tab" aria-controls="{{ $prefix }}-rigs" aria-selected="false">Rigs</a>
  </li>
  <li class="nav-item">
    <a class="nav-link" id="{{ $prefix }}-consumables-link" data-toggle="tab" href="#{{ $prefix }}-consumables" role="tab" aria-controls="{{ $prefix }}-consumables" aria-selected="false">Consumables</a>
  </li>
</ul>
<div class="tab-content pt-3">
  @foreach (['hulls' => 'Hulls', 'modules' => 'Modules', 'rigs' => 'Rigs', 'consumables' => 'Consumables'] as $key => $label)
    <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}" id="{{ $prefix }}-{{ $key }}" role="tabpanel" aria-labelledby="{{ $prefix }}-{{ $key }}-link">
      <div class="table-responsive">
        <table class="table table-striped table-sm">
          <thead>
            <tr>
              <th>Item</th>
              <th class="text-right">Quantity</th>
              <th>Notes</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td colspan="3" class="text-muted">No {{ strtolower($label) }} added to this shopping list yet.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  @endforeach
</div>
