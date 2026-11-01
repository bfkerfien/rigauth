<div class="table-responsive">
  <table class="table table-striped table-sm">
    <thead><tr><th>Item</th><th class="text-right">Structure best sell</th><th class="text-right">Jita 4-4 best sell</th><th class="text-right">Markup</th><th class="text-right">Structure sell volume</th><th class="text-right">Jita sell volume</th></tr></thead>
    <tbody>
    @forelse($rows as $row)
      <tr>
        <td>{{ $row->type_name ?: ('Type '.$row->type_id) }} <small class="text-muted">({{ $row->type_id }})</small></td>
        <td class="text-right">{{ $row->structure_sell_min === null ? '—' : number_format((float)$row->structure_sell_min, 2) }}</td>
        <td class="text-right">{{ $row->jita_sell_min === null ? '—' : number_format((float)$row->jita_sell_min, 2) }}</td>
        <td class="text-right">{{ $row->markup_percent === null ? '—' : number_format($row->markup_percent, 2).'%' }}</td>
        <td class="text-right">{{ number_format((int)$row->structure_sell_volume) }}</td>
        <td class="text-right">{{ number_format((int)$row->jita_sell_volume) }}</td>
      </tr>
    @empty
      <tr><td colspan="6" class="text-muted">No matching items in the latest completed snapshot.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
