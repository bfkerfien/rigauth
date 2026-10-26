@extends('web::layouts.grids.12')

@section('title', 'Market')
@section('page_header', 'Market')
@section('page_description', 'Consortium market intelligence')

@section('full')
<div class="card">
  <div class="card-header p-0">
    <ul class="nav nav-tabs">
      @foreach(['shame-list' => 'Shame List', 'under-30' => 'Under 30', '18-percent' => '18 Percent', 'doctrine-health' => 'Doctrine Health'] as $key => $label)
        <li class="nav-item">
          <a class="nav-link {{ $tab === $key ? 'active' : '' }}" href="{{ route('seatcore::market.index', ['tab' => $key]) }}">{{ $label }}</a>
        </li>
      @endforeach
    </ul>
  </div>
  <div class="card-body">
    @if($tab === 'shame-list')
      <h4>Shame List</h4>
      <p class="text-muted">Items where the structure's lowest sell price is more than 30% above Jita 4-4's lowest sell price.</p>
      @include('web::market.comparison-table', ['rows' => $shameList])
    @elseif($tab === 'under-30')
      <h4>Under 30</h4>
      <p class="text-muted">Items with a structure sell markup from 0% through 30% over Jita 4-4.</p>
      @include('web::market.comparison-table', ['rows' => $under30])
    @elseif($tab === '18-percent')
      <h4>18 Percent Profit</h4>
      <p class="text-muted">Qualifies when Jita best sell plus 500 ISK per m³ transport is at least 18% below structure sale proceeds after 4.37% fees. {{ number_format($profit18Count) }} items meet the threshold.</p>
      <div class="table-responsive">
        <table class="table table-striped table-sm">
          <thead><tr><th>Item</th><th class="text-right">Structure sell</th><th class="text-right">Jita sell</th><th class="text-right">Volume m³</th><th class="text-right">Net structure sale</th><th class="text-right">Transport</th><th class="text-right">Jita total cost</th><th class="text-right">Saving</th><th>18% rule</th></tr></thead>
          <tbody>
          @forelse($profit18 as $row)
            <tr>
              <td>{{ $row->type_name ?: ('Type '.$row->type_id) }} <small class="text-muted">({{ $row->type_id }})</small></td>
              <td class="text-right">{{ number_format((float)$row->structure_sell_min, 2) }}</td>
              <td class="text-right">{{ number_format((float)$row->jita_sell_min, 2) }}</td>
              <td class="text-right">{{ number_format((float)$row->item_volume, 3) }}</td>
              <td class="text-right">{{ number_format($row->net_structure_sale, 2) }}</td>
              <td class="text-right">{{ number_format($row->transport_cost, 2) }}</td>
              <td class="text-right">{{ number_format($row->jita_total_cost, 2) }}</td>
              <td class="text-right">{{ number_format($row->saving_percent, 2) }}%</td>
              <td class="{{ $row->qualifies_18_percent ? 'text-success' : 'text-danger' }}">{{ $row->qualifies_18_percent ? 'YES' : 'NO' }}</td>
            </tr>
          @empty
            <tr><td colspan="9" class="text-muted">No items have the required price and volume data yet.</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    @else
      <h4>Doctrine Health</h4>
      <p class="text-muted">Availability is calculated against the latest completed structure-market snapshot and existing SeAT Fittings data.</p>
      <div class="row">
        <div class="col-md-4"><h3>{{ number_format($doctrineHealth['fits']) }}</h3><p>Fits tracked</p></div>
        <div class="col-md-4"><h3>{{ number_format($doctrineHealth['doctrines']) }}</h3><p>Doctrines tracked</p></div>
        <div class="col-md-4"><h3>{{ number_format($doctrineHealth['items']) }}</h3><p>Fit item records</p></div>
      </div>
      <div class="table-responsive"><table class="table table-striped table-sm">
        <thead><tr><th>Doctrine</th><th>Fits</th><th>Required item types</th><th>Missing item types</th><th>Total shortfall</th><th>Health</th></tr></thead>
        <tbody>
        @forelse($doctrineHealth['reports'] as $report)
          <tr>
            <td>{{ $report['doctrine'] }}</td>
            <td>{{ number_format($report['fit_count']) }}</td>
            <td>{{ number_format($report['required_item_types']) }}</td>
            <td>{{ number_format($report['missing_item_count']) }}</td>
            <td>{{ number_format($report['total_shortfall']) }}</td>
            <td>{{ $report['health_percent'] === null ? '—' : number_format($report['health_percent'], 1).'%' }}</td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-muted">No doctrine assignments are available.</td></tr>
        @endforelse
        </tbody></table></div>
      @if(!$run)
        <div class="alert alert-info">No completed market snapshot is available, so inventory health cannot be evaluated.</div>
      @endif
    @endif
    @if(!$run && $tab !== 'doctrine-health')
      <div class="alert alert-info mt-3">No completed market collection runs are available in SeAT's database yet.</div>
    @endif
  </div>
</div>
@endsection
