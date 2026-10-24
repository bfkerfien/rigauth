@extends('web::layouts.grids.12')
@section('title','Market')
@section('page_header','Market')
@section('page_description','Consortium market intelligence')
@section('full')
<div class="card"><div class="card-header p-0"><ul class="nav nav-tabs">
@foreach(['shame-list'=>'Shame List','under-30'=>'Under 30','18-percent'=>'18 Percent','doctrine-health'=>'Doctrine Health'] as $key=>$label)
<li class="nav-item"><a class="nav-link {{ $tab===$key?'active':'' }}" href="{{ route('seatcore::market.index',['tab'=>$key]) }}">{{ $label }}</a></li>
@endforeach
</ul></div><div class="card-body">
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
@if($tab==='shame-list')
<form method="POST" action="{{ route('seatcore::market.shame.store') }}" class="border rounded p-3 mb-4">@csrf
<div class="form-row"><div class="form-group col-md-4"><label>Character name</label><input class="form-control" name="character_name" maxlength="120" required></div>
<div class="form-group col-md-4"><label>Character ID</label><input class="form-control" type="number" min="1" name="character_id"></div>
<div class="form-group col-md-4"><label>Evidence URL</label><input class="form-control" type="url" name="evidence_url" maxlength="2048"></div></div>
<div class="form-group"><label>Reason</label><textarea class="form-control" name="reason" maxlength="4000" required></textarea></div><button class="btn btn-primary">Add entry</button></form>
<div class="table-responsive"><table class="table table-striped"><thead><tr><th>Status</th><th>Character</th><th>Reason</th><th>Evidence</th><th>Added</th><th></th></tr></thead><tbody>
@forelse($shame as $e)<tr><td>{{ ucfirst($e->status) }}</td><td>{{ $e->character_name }} @if($e->character_id)({{ $e->character_id }})@endif</td><td>{{ $e->reason }}</td><td>@if($e->evidence_url)<a href="{{ $e->evidence_url }}" target="_blank" rel="noopener noreferrer">Open</a>@endif</td><td>{{ $e->created_at }}</td><td>@if($e->status==='open')<form method="POST" action="{{ route('seatcore::market.shame.resolve',['id'=>$e->id]) }}">@csrf<button class="btn btn-sm btn-outline-secondary">Resolve</button></form>@endif</td></tr>@empty<tr><td colspan="6">No entries.</td></tr>@endforelse
</tbody></table></div>
@elseif($tab==='under-30')
<h4>Under 30</h4><p>Latest collector price differences below 30%.</p>@include('web::market.comparison-table',['rows'=>$under30])
@elseif($tab==='18-percent')
<h4>18 Percent</h4><p>Structure and Jita price comparisons. Legacy 18% calculation requires verification.</p>@include('web::market.comparison-table',['rows'=>$profit18])
@else
<h4>Doctrine Health</h4><div class="row"><div class="col-md-4"><h3>{{ number_format($health['fits']) }}</h3>Fits</div><div class="col-md-4"><h3>{{ number_format($health['doctrines']) }}</h3>Doctrines</div><div class="col-md-4"><h3>{{ number_format($health['items']) }}</h3>Fit items</div></div>
<table class="table table-striped"><thead><tr><th>Doctrine</th><th>Fits</th><th>Missing</th><th>Health</th><th>Captured</th></tr></thead><tbody>@forelse($health['snapshots'] as $s)<tr><td>{{ $s->doctrine }}</td><td>{{ $s->fit_count }}</td><td>{{ $s->missing_item_count }}</td><td>{{ $s->health_percent===null?'—':number_format((float)$s->health_percent,1).'%' }}</td><td>{{ $s->captured_at }}</td></tr>@empty<tr><td colspan="5">No health snapshots imported yet.</td></tr>@endforelse</tbody></table>
@endif
@if($tab!=='shame-list' && !$run)<div class="alert alert-info">No collector runs imported into SeAT's database yet.</div>@endif
</div></div>
@endsection
