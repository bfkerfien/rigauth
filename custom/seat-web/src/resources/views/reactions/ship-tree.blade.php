@extends('web::layouts.grids.12')

@section('title', $ship . ' Production Tree')
@section('page_header', $ship . ' Production Tree')
@section('page_description', 'Triglavian Consortium · SeAT manufacturing and reaction production')

@section('full')
<style>
#ship-tree{--bg:#060b10;--panel:#101922;--line:#263744;--text:#e9f1f6;--muted:#9aadb9;--cyan:#65d9f5;color:var(--text);font:14px/1.5 system-ui,Segoe UI,Arial,sans-serif;background:radial-gradient(ellipse at top,#10202c,var(--bg) 58%);border:1px solid var(--line);border-radius:12px;padding:18px;margin-bottom:20px}
#ship-tree *{box-sizing:border-box}#ship-tree h1{font-size:clamp(24px,3vw,34px);margin:4px 0;color:var(--text)}#ship-tree h2{font-size:17px;margin:0 0 8px}
#ship-tree .muted,#ship-tree .help{color:var(--muted)}#ship-tree .help{font-size:11px}#ship-tree .eyebrow{color:var(--cyan);font-size:10px;font-weight:800;letter-spacing:.14em;text-transform:uppercase}
#ship-tree .navtabs{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0}#ship-tree .tab{display:inline-block;text-decoration:none;background:#0a1219;border:1px solid var(--line);border-radius:6px;color:var(--muted);font-weight:700;padding:10px 16px}#ship-tree .tab.active{background:#123342;border-color:var(--cyan);color:var(--cyan)}
#ship-tree .panel,#ship-tree .card{background:linear-gradient(145deg,#111c26,#0b1219);border:1px solid var(--line);border-radius:9px;padding:14px;margin:10px 0}
#ship-tree .sliders{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}#ship-tree label{display:grid;gap:8px;font-size:12px;color:var(--muted)}#ship-tree input[type=range]{width:100%;accent-color:var(--cyan)}
#ship-tree .kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:12px 0}#ship-tree .kpi{background:#0a1219;border:1px solid var(--line);border-radius:8px;padding:10px;min-width:0}#ship-tree .kpi span{font-size:9px;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);display:block}#ship-tree .kpi strong{font-size:16px;display:block;overflow-wrap:anywhere}
#ship-tree .status{border:1px solid var(--line);border-radius:7px;padding:10px;margin:12px 0;font-size:12px;overflow-wrap:anywhere}#ship-tree .error{border-color:#754b35;color:#ffb36a}
#ship-tree .scroll{overflow:auto;border:1px solid var(--line);border-radius:7px}#ship-tree table{border-collapse:collapse;width:100%;min-width:650px}#ship-tree th,#ship-tree td{text-align:left;padding:8px;border-bottom:1px solid #20303b;font-size:11px;vertical-align:top}#ship-tree th{background:#0a1219;color:var(--muted)}#ship-tree tr:last-child td{border-bottom:0}
@media(max-width:680px){#ship-tree{padding:12px}#ship-tree .sliders,#ship-tree .kpis{grid-template-columns:1fr}}
</style>
<div id="ship-tree" data-ship="{{ $ship }}">
  <header>
    <div class="eyebrow">Triglavian Consortium · SeAT production</div>
    <h1>{{ $ship }} Production Tree</h1>
    <div class="muted">Recursive manufacturing and reaction inputs from bundled EVE blueprint recipes.</div>
  </header>

  <nav class="navtabs" aria-label="Ship production pages">
    <a class="tab {{ $ship === 'Bustard' ? 'active' : '' }}" href="{{ route('seatcore::production.bustard') }}" {{ $ship === 'Bustard' ? 'aria-current=page' : '' }}>Bustard</a>
    <a class="tab {{ $ship === 'Crane' ? 'active' : '' }}" href="{{ route('seatcore::production.crane') }}" {{ $ship === 'Crane' ? 'aria-current=page' : '' }}>Crane</a>
  </nav>

  <section class="panel">
    <h2>Blueprint efficiency</h2>
    <div class="sliders">
      <label for="ship-me">Material Efficiency (ME): <strong id="ship-me-value">0</strong>
        <input id="ship-me" type="range" min="-10" max="10" step="1" value="0">
      </label>
      <label for="ship-pe">Production Efficiency (PE): <strong id="ship-pe-value">0</strong>
        <input id="ship-pe" type="range" min="-10" max="20" step="1" value="0">
      </label>
    </div>
    <p class="help">ME and PE apply to this ship’s blueprint. Component blueprints and reactions use base SDE quantities. Negative settings increase material use or estimated build time.</p>
  </section>

  <section id="ship-tree-result" aria-live="polite">
    <div class="status">Loading production tree and Jita sell prices…</div>
  </section>
</div>
<script>
(function(){
 const root=document.getElementById('ship-tree');
 const ship=root.dataset.ship;
 const $=id=>document.getElementById(id);
 const escapeHtml=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
 const fmt=value=>Number(value||0).toLocaleString(undefined,{maximumFractionDigits:2});
 const formatDuration=seconds=>{
  const total=Math.round(Number(seconds)||0),days=Math.floor(total/86400),hours=Math.floor((total%86400)/3600),minutes=Math.floor((total%3600)/60);
  return [days?days+'d':'',hours?hours+'h':'',minutes?minutes+'m':''].filter(Boolean).join(' ')||'<1m';
 };
 const storageKey='triglavian.ship-production.settings.v1';
 let settings={Bustard:{me:0,pe:0},Crane:{me:0,pe:0}};
 try{settings={...settings,...JSON.parse(localStorage.getItem(storageKey)||'{}')};}catch(_){}
 settings[ship]=settings[ship]||{me:0,pe:0};
 $('ship-me').value=String(settings[ship].me);
 $('ship-pe').value=String(settings[ship].pe);
 $('ship-me-value').textContent=String(settings[ship].me);
 $('ship-pe-value').textContent=String(settings[ship].pe);

 function flatten(node,depth=0,rows=[]){rows.push({...node,depth});(node.children||[]).forEach(child=>flatten(child,depth+1,rows));return rows;}
 async function loadTree(){
  const result=$('ship-tree-result'),current={...settings[ship]};
  result.innerHTML='<div class="status">Building '+escapeHtml(ship)+' tree and checking Jita IV-4 sell orders…</div>';
  try{
   const query=new URLSearchParams({activity:'1',tree:'1',product:ship,me:String(current.me),pe:String(current.pe)});
   const response=await fetch('/reactions/recipe?'+query.toString(),{headers:{'Accept':'application/json'}});
   const data=await response.json();
   if(!response.ok)throw new Error(data.error||'Production tree unavailable');
   const raw=data.rawMaterials||[];
   const marketNames=[...raw.map(item=>item.name),ship];
   const priceResponse=await fetch('/reactions/market?names='+encodeURIComponent(marketNames.join(',')),{headers:{'Accept':'application/json'}});
   const priceData=await priceResponse.json();
   if(!priceResponse.ok)throw new Error(priceData.error||'Jita market lookup failed');
   const prices=priceData.prices||{};
   let knownCost=0,missing=0;
   const rawRows=raw.map(item=>{
    const price=Number(prices[item.name]?.buyPrice),valid=Number.isFinite(price)&&price>0;
    if(valid)knownCost+=price*Number(item.quantity||0);else missing++;
    return {...item,unitPrice:valid?price:null,total:valid?price*Number(item.quantity||0):null};
   });
   const shipSplit=Number(prices[ship]?.splitPrice);
   const shipSplitKnown=Number.isFinite(shipSplit)&&shipSplit>0;
   const grossProfit=missing===0&&shipSplitKnown?shipSplit-knownCost:null;
   const margin=grossProfit!==null&&knownCost>0?(grossProfit/knownCost)*100:null;
   const rows=flatten(data.tree);
   const totalSeconds=rows.reduce((sum,row)=>sum+(Number(row.estimatedSeconds)||0),0);
   const timeKnown=rows.some(row=>row.estimatedSeconds!==null&&row.estimatedSeconds!==undefined&&Number.isFinite(Number(row.estimatedSeconds)));
   result.innerHTML='<div class="kpis"><div class="kpi"><span>Raw input cost · Jita buy</span><strong>'+fmt(knownCost)+' ISK'+(missing?' · '+fmt(missing)+' prices missing':'')+'</strong></div><div class="kpi"><span>'+escapeHtml(ship)+' value · Jita split</span><strong>'+(shipSplitKnown?fmt(shipSplit)+' ISK':'Unavailable')+'</strong></div><div class="kpi"><span>Estimated gross profit</span><strong>'+(grossProfit===null?'Unavailable':fmt(grossProfit)+' ISK')+'</strong></div><div class="kpi"><span>Profit / input cost</span><strong>'+(margin===null?'Unavailable':fmt(margin)+'%')+'</strong></div><div class="kpi"><span>Estimated production time</span><strong>'+(timeKnown?formatDuration(totalSeconds):'Unavailable')+'</strong></div></div>'+
    '<div class="scroll"><table><thead><tr><th>Production tree item</th><th>Step</th><th>Quantity</th><th>Runs</th><th>Jita buy / raw cost</th></tr></thead><tbody>'+
    rows.map(row=>{
     const isRaw=row.activityId===null;
     const price=rawRows.find(item=>item.name===row.name);
     const indent='&nbsp;'.repeat(Math.min(12,row.depth)*4);
     const step=isRaw?(String(row.groupName||'').toLowerCase().includes('moon')?'Moon goo · Jita buy':'Raw input · Jita buy'):(row.activityId===11?'Reaction':'Manufacturing');
     const value=isRaw?(price&&price.unitPrice!==null?fmt(price.unitPrice)+' ISK / '+fmt(price.total)+' ISK':'Unavailable'):'—';
     return '<tr><td>'+indent+escapeHtml(row.name)+'</td><td>'+step+'</td><td>'+fmt(row.quantityNeeded)+'</td><td>'+(row.runs===null?'—':fmt(row.runs))+'</td><td>'+value+'</td></tr>';
    }).join('')+'</tbody></table></div><p class="help">Hull ME '+fmt(current.me)+'% · hull PE '+fmt(current.pe)+'%. Inputs use the Jita IV-4 buy order; ship value uses the Jita IV-4 buy/sell midpoint. Profit is unavailable if any raw input or the ship split has no price.</p>';
  }catch(error){result.innerHTML='<div class="status error">'+escapeHtml(error.message)+'</div>';}
 }
 function saveAndReload(key){
  settings[ship][key]=Number($(key==='me'?'ship-me':'ship-pe').value);
  $('ship-'+key+'-value').textContent=String(settings[ship][key]);
  localStorage.setItem(storageKey,JSON.stringify(settings));
  loadTree();
 }
 $('ship-me').addEventListener('input',()=>{$('ship-me-value').textContent=$('ship-me').value;});
 $('ship-pe').addEventListener('input',()=>{$('ship-pe-value').textContent=$('ship-pe').value;});
 $('ship-me').addEventListener('change',()=>saveAndReload('me'));
 $('ship-pe').addEventListener('change',()=>saveAndReload('pe'));
 loadTree();
})();
</script>
@endsection
