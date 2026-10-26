@extends('web::layouts.grids.12')

@section('title', 'Reaction Planner')
@section('page_header', 'Reaction Planner')
@section('page_description', 'Triglavian Consortium · New Eden industry · Inventory-aware reactions and manufacturing')

@section('full')
<style>
#rx-app{--rx-bg:#060b10;--rx-panel:#101922;--rx-line:#263744;--rx-text:#e9f1f6;--rx-muted:#9aadb9;--rx-cyan:#65d9f5;--rx-orange:#ffb36a;color:var(--rx-text);font:14px/1.5 system-ui,Segoe UI,Arial,sans-serif;background:radial-gradient(ellipse at top,#10202c,var(--rx-bg) 58%);border:1px solid #263744;border-radius:12px;padding:18px;margin:0 0 20px}
#rx-app *{box-sizing:border-box}#rx-app .rx-hero{display:flex;justify-content:space-between;gap:16px;align-items:end;border-bottom:1px solid var(--rx-line);padding-bottom:16px;margin-bottom:16px}
#rx-app .rx-eyebrow{color:var(--rx-cyan);font-size:10px;font-weight:800;letter-spacing:.14em;text-transform:uppercase}#rx-app h1{font-size:clamp(24px,3vw,34px);margin:4px 0;color:var(--rx-text)}#rx-app h2{font-size:18px;margin:0 0 10px;color:var(--rx-text)}#rx-app h3{font-size:15px;margin:0;color:var(--rx-text)}#rx-app .rx-muted,#rx-app .rx-help{color:var(--rx-muted)}#rx-app .rx-help,#rx-app .rx-small{font-size:11px}
#rx-app .rx-layout{display:grid;grid-template-columns:285px minmax(0,1fr);gap:14px;align-items:start}#rx-app .rx-panel,#rx-app .rx-card{background:linear-gradient(145deg,#111c26,#0b1219);border:1px solid var(--rx-line);border-radius:10px;padding:14px;margin-bottom:13px;min-width:0}
#rx-app .rx-field{display:grid;gap:5px;margin-bottom:11px}#rx-app label{font-size:11px;color:var(--rx-muted)}#rx-app input,#rx-app select{background:#080f15;color:var(--rx-text);border:1px solid #354a58;border-radius:6px;padding:9px;width:100%;min-width:0}#rx-app button{cursor:pointer;background:#13303d;border:1px solid #2d6375;color:var(--rx-cyan);border-radius:6px;padding:9px 11px;font-weight:700}#rx-app button:hover{filter:brightness(1.15)}
#rx-app .rx-status{border:1px solid var(--rx-line);border-radius:7px;padding:9px;margin-bottom:12px;font-size:11px;overflow-wrap:anywhere}#rx-app .rx-error{border-color:#754b35;color:var(--rx-orange)}#rx-app .rx-tabs{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:12px}#rx-app .rx-tab{flex:1;min-width:115px;background:#0a1219;border-color:var(--rx-line);color:var(--rx-muted);padding:11px}#rx-app .rx-tab.active{background:#123342;border-color:var(--rx-cyan);color:var(--rx-cyan);box-shadow:inset 0 -2px 0 var(--rx-cyan)}
#rx-app .rx-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-bottom:12px}#rx-app .rx-kpi{background:#0a1219;border:1px solid var(--rx-line);border-radius:8px;padding:10px;min-width:0}#rx-app .rx-kpi span{font-size:9px;text-transform:uppercase;letter-spacing:.07em;color:var(--rx-muted);display:block}#rx-app .rx-kpi strong{font-size:17px;display:block;overflow-wrap:anywhere}
#rx-app .rx-scroll{overflow:auto;border:1px solid var(--rx-line);border-radius:7px}#rx-app table{border-collapse:collapse;width:100%;min-width:530px;margin:0;background:transparent}#rx-app th,#rx-app td{text-align:left;padding:8px;border-bottom:1px solid #20303b;font-size:11px;color:var(--rx-text);vertical-align:top}#rx-app th{background:#0a1219;color:var(--rx-muted)}#rx-app tr:last-child td{border-bottom:0}
#rx-app .rx-pill{display:inline-block;border:1px solid var(--rx-line);border-radius:99px;padding:2px 7px;color:var(--rx-muted);font-size:10px}#rx-app .rx-positive{color:var(--rx-cyan)}#rx-app .rx-warning{color:var(--rx-orange)}#rx-app .rx-inline{display:flex;gap:8px;align-items:center;flex-wrap:wrap}#rx-app .rx-between{justify-content:space-between}
#rx-app .rx-recipe{background:linear-gradient(145deg,#111c26,#0b1219);border:1px solid var(--rx-line);border-radius:9px;margin-bottom:10px;overflow:hidden}#rx-app .rx-recipe-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:12px;cursor:pointer}#rx-app .rx-recipe-head:hover{background:#13222d}#rx-app .rx-recipe-body{padding:12px;border-top:1px solid var(--rx-line)}#rx-app .rx-recipe-body[hidden]{display:none}#rx-app .rx-chip{background:#0a1219;border:1px solid var(--rx-line);padding:4px 7px;border-radius:6px;font-size:10px;color:var(--rx-muted)}#rx-app .rx-settings-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}#rx-app .rx-empty{padding:18px;color:var(--rx-muted);border:1px dashed var(--rx-line);border-radius:8px;text-align:center}
@media(max-width:1050px){#rx-app .rx-layout{grid-template-columns:1fr}#rx-app .rx-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:600px){#rx-app{padding:10px}#rx-app .rx-hero{display:block}#rx-app .rx-kpis,#rx-app .rx-settings-grid{grid-template-columns:1fr}#rx-app .rx-tabs{display:grid;grid-template-columns:1fr}#rx-app .rx-tab{width:100%}#rx-app .rx-recipe-head{align-items:flex-start;flex-direction:column}}
</style>
<div id="rx-app">
  <header class="rx-hero"><div><div class="rx-eyebrow">Triglavian Consortium · New Eden industry</div><h1>Reaction Planner</h1><div class="rx-muted">Live Jita pricing, reaction profitability and your SeAT character inventory in one place.</div></div><div class="rx-inline"><span class="rx-pill">SeAT native</span><span class="rx-pill">Inventory-aware</span></div></header>
  @if($recipeError)<div class="rx-status rx-error">{{ $recipeError }}</div>@endif
  @if($inventoryError)<div class="rx-status">{{ $inventoryError }}</div>@endif
  <div id="rx-market-status" class="rx-status">Recipe catalog loaded. Market prices load when you open a recipe.</div>
  <div class="rx-layout">
    <aside>
      <section class="rx-panel">
        <h2>Find reactions</h2>
        <div class="rx-field"><label for="rx-search">Product or input material</label><input id="rx-search" value="{{ $search }}" placeholder="Fullerite, Cytoserocin, product…"></div>
        <div class="rx-field"><label for="rx-run-count">Runs to calculate</label><input id="rx-run-count" type="number" min="1" step="1" value="1"></div>
        <div class="rx-field"><label>Valuation rules</label><div class="rx-chip">Inputs: Jita Split</div><div class="rx-chip">Outputs: Jita Sell</div></div>
        <div class="rx-settings-grid">
          <div class="rx-field"><label for="rx-transport">Transport ISK/m³</label><input id="rx-transport" type="number" min="0" value="500"></div>
          <div class="rx-field"><label for="rx-sell-tax">Sales tax %</label><input id="rx-sell-tax" type="number" min="0" max="100" step=".01" value="4.37"></div>
          <div class="rx-field"><label for="rx-job-tax">Job tax %</label><input id="rx-job-tax" type="number" min="0" step=".01" value="0"></div><div class="rx-field"><label for="rx-fuel-per-run">Fuel blocks / run</label><input id="rx-fuel-per-run" type="number" min="0" step="1" value="1"></div>
        </div>
        <p class="rx-help">Inputs are costed at Jita Split; reacted products are valued at Jita Sell. Defaults: 500 ISK/m³ transport and 4.37% sales tax. Missing prices stay unavailable rather than being treated as zero.</p>
        <button id="rx-clear-search" type="button" style="width:100%">Clear search</button>
      </section>
      <section class="rx-panel">
        <div class="rx-inline rx-between"><h2>My reactable inventory</h2><span class="rx-pill">{{ number_format($inventory->count()) }} item types</span></div>
        <div class="rx-field"><label for="rx-inventory-search">Filter inventory</label><input id="rx-inventory-search" placeholder="Search owned materials…"></div>
        <div class="rx-scroll" style="max-height:430px"><table style="min-width:0"><thead><tr><th>Base material</th><th>Qty</th><th>Loc.</th></tr></thead><tbody id="rx-inventory">
        @forelse($inventory as $item)<tr data-item="{{ strtolower($item['name']) }}"><td><button type="button" class="rx-inventory-pick" data-name="{{ $item['name'] }}" style="border:0;background:none;padding:0;text-align:left;font-weight:500">{{ $item['name'] }}</button></td><td>{{ number_format($item['quantity']) }}</td><td>{{ number_format($item['locations']) }}</td></tr>
        @empty<tr><td colspan="3" class="rx-muted">No cached assets found for your linked characters.</td></tr>@endforelse
        </tbody></table></div>
        <p class="rx-help">Select a material to find reactions that consume it. Quantities are summed across your linked characters.</p>
      </section>
    </aside>
    <section>
      <nav class="rx-tabs" role="tablist" aria-label="Reaction planner sections">
        <button type="button" class="rx-tab active" data-tab="gas" aria-selected="true">Gas / Polymer</button>
        <button type="button" class="rx-tab" data-tab="biochemical" aria-selected="false">Biochemical</button>
        <button type="button" class="rx-tab" data-tab="moon" aria-selected="false">Moon Reactions</button>
        <button type="button" class="rx-tab" data-tab="molecular" aria-selected="false">Molecular-Forged</button>
        <button type="button" class="rx-tab" data-tab="manufacturing" aria-selected="false">Manufacturing</button>
      </nav>
      <div class="rx-kpis">
        <div class="rx-kpi"><span>Recipes shown</span><strong id="rx-count">{{ number_format($rows->count()) }}</strong></div>
        <div class="rx-kpi"><span>Recipes with owned inputs</span><strong id="rx-owned-count">—</strong></div>
        <div class="rx-kpi"><span>Pricing source</span><strong style="font-size:13px">Jita IV-4 · ESI</strong></div>
      </div>
      <div id="rx-recipe-list"></div>
      <section class="rx-panel" id="rx-manufacturing-panel" hidden>
        <h2>Manufacturing chain lookup</h2><p class="rx-help">Look up a capital component or other manufactured item using SeAT’s local EVE blueprint data.</p>
        <form id="rx-manufacturing-form" class="rx-inline"><input id="rx-manufacturing-product" placeholder="e.g. Capital Armor Plates" style="flex:1;min-width:220px"><button type="submit">Load recipe</button></form>
        <div id="rx-manufacturing-result" style="margin-top:12px"></div>
      </section>
    </section>
  </div>
</div>
<script>
(function(){
const recipes = @json($rows);
const inventory = @json($inventory);
const root = document.getElementById('rx-app');
const $ = id => document.getElementById(id);
const fmt = n => Number(n || 0).toLocaleString(undefined,{maximumFractionDigits:2});
const esc = s => String(s ?? '').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let activeTab = 'gas', priceCache = {}, expanded = new Set(), manufacturingRecipe = null;
function setStatus(message, error=false){$('rx-market-status').textContent=message;$('rx-market-status').className='rx-status'+(error?' rx-error':'');}
function categoryMatches(r){return activeTab==='gas' ? r.category==='gas' : activeTab==='biochemical' ? r.category==='biochemical' : activeTab==='moon' ? r.category==='moon' : activeTab==='molecular' ? r.category==='molecular' : false;}
function filteredRecipes(){const q=$('rx-search').value.trim().toLowerCase();return recipes.filter(r=>categoryMatches(r)&&(!q||r.product.toLowerCase().includes(q)||(r.group||'').toLowerCase().includes(q)||(r.materials||[]).some(m=>m.name.toLowerCase().includes(q))||(r.products||[]).some(p=>p.name.toLowerCase().includes(q))));}
function ownedCount(r){return (r.materials||[]).filter(m=>m.owned>0).length;}
function render(){
 const list=filteredRecipes();
 $('rx-count').textContent=fmt(list.length);
 $('rx-owned-count').textContent=fmt(list.filter(r=>ownedCount(r)>0).length);
 $('rx-manufacturing-panel').hidden=activeTab!=='manufacturing';
 if(activeTab==='manufacturing'){ $('rx-recipe-list').innerHTML=''; return; }
 if(!list.length){$('rx-recipe-list').innerHTML='<div class="rx-empty">No recipes match this tab and search. Try another material or tab.</div>';return;}
 $('rx-recipe-list').innerHTML=list.map((r,i)=>{
  const key=String(r.type_id||r.product)+'-'+i, open=expanded.has(key);
  const canRuns=r.materials?.length ? Math.min(...r.materials.map(m=>m.per_run>0?Math.floor(m.owned/m.per_run):0)) : 0;
  return '<article class="rx-recipe" data-key="'+esc(key)+'"><div class="rx-recipe-head" role="button" tabindex="0" data-expand="'+esc(key)+'"><div><h3>'+esc(r.product)+'</h3><div class="rx-help">'+esc(r.group||'Reaction formula')+' · '+(r.materials||[]).length+' input types</div></div><div class="rx-inline"><span class="rx-pill">'+fmt(canRuns)+' run(s) from owned inputs</span><span class="rx-pill">'+(open?'Hide details':'Calculate cost & show details')+'</span></div></div><div class="rx-recipe-body" '+(open?'':'hidden')+' id="rx-body-'+esc(key)+'"><div class="rx-status">Opening recipe and checking Jita prices…</div></div></article>';
 }).join('');
 list.forEach((r,i)=>{const key=String(r.type_id||r.product)+'-'+i;if(expanded.has(key)) renderDetails(r,key);});
}
async function getPrices(names){
 const missing=[...new Set(names)].filter(n=>n&&!priceCache[n]);
 for(let i=0;i<missing.length;i+=50){
  const batch=missing.slice(i,i+50);
  const response=await fetch('/reactions/market?names='+encodeURIComponent(batch.join(',')),{headers:{'Accept':'application/json'}});
  const data=await response.json();
  if(!response.ok)throw new Error(data.error||'Jita market request failed');
  Object.assign(priceCache,data.prices||{});
 }
 return priceCache;
}
function isGas(name){return /Fullerite-|Mykoserocin|Cytoserocin|Polymer/i.test(name);}
function unitPrice(name,kind){const p=priceCache[name];if(!p)return null;const field=kind==='Output'?'sellPrice':'splitPrice';return p[field]===null||p[field]===undefined?null:Number(p[field]);}
function qtyRuns(){return Math.max(1,Math.floor(Number($('rx-run-count').value)||1));}
function costAndProfit(r){
 const runs=qtyRuns(), materials=r.materials||[], products=r.products||[];
 let inputCost=0,missing=[],rows=[];
 for(const m of materials){const qty=Number(m.per_run)*runs,price=unitPrice(m.name,'Input');if(price===null||!Number.isFinite(price))missing.push(m.name);else inputCost+=price*qty;rows.push({kind:'Input',name:m.name,qty,price,cost:price===null?null:price*qty,owned:Number(m.owned||0),locations:m.locations||[]});}
 let gross=0,outputTransport=0,outputMissing=[];
 for(const p of products){const price=unitPrice(p.name,'Output'),qty=Number(p.quantity)*runs,info=priceCache[p.name];if(price===null||!Number.isFinite(price)){outputMissing.push(p.name);rows.push({kind:'Output',name:p.name,qty,price:null,cost:null});continue;}gross+=price*qty;if(info?.volume==null){outputMissing.push(p.name+' (volume unavailable)');}else outputTransport+=Number(info.volume)*qty*(Number($('rx-transport').value)||0);rows.push({kind:'Output',name:p.name,qty,price,cost:price*qty});}
 const salesTax=gross*(Number($('rx-sell-tax').value||0)/100),jobTax=(inputCost)*(Number($('rx-job-tax').value||0)/100);
 const profit=missing.length||outputMissing.length?null:gross-salesTax-outputTransport-inputCost-jobTax;
 const margin=inputCost>0&&profit!==null?(profit/inputCost)*100:null;
 return {runs,inputCost,gross,salesTax,outputTransport,jobTax,profit,margin,missing:[...missing,...outputMissing],rows};
}
async function renderDetails(r,key){
 const body=$('rx-body-'+CSS.escape(key));if(!body)return;
 try{
  await getPrices([...(r.materials||[]).map(m=>m.name),...(r.products||[]).map(p=>p.name)]);
  const result=costAndProfit(r);
  const materialRows=(r.materials||[]).map(m=>'<tr><td>'+esc(m.name)+'</td><td>'+fmt(m.per_run)+'</td><td>'+fmt(m.owned)+'</td><td>'+fmt(Math.floor(Number(m.owned||0)/Math.max(1,Number(m.per_run||0))))+'</td><td>'+((m.locations||[]).map(l=>esc(l.owner)+' — '+esc(l.location)+': '+fmt(l.quantity)).join('<br>')||'<span class="rx-warning">Not in cached inventory</span>')+'</td></tr>').join('');
  const calcRows=result.rows.map(row=>'<tr><td>'+row.kind+'</td><td>'+esc(row.name)+'</td><td>'+fmt(row.qty)+'</td><td>'+(row.price===null?'—':fmt(row.price))+'</td><td>'+(row.cost===null?'Unavailable':fmt(row.cost))+'</td></tr>').join('');
  body.innerHTML='<div class="rx-kpis"><div class="rx-kpi"><span>Input cost</span><strong>'+fmt(result.inputCost)+' ISK</strong></div><div class="rx-kpi"><span>Output value</span><strong>'+fmt(result.gross)+' ISK</strong></div><div class="rx-kpi"><span>Profit / loss</span><strong class="'+(result.profit!==null&&result.profit>=0?'rx-positive':'rx-warning')+'">'+(result.profit===null?'Unavailable':fmt(result.profit)+' ISK')+'</strong></div><div class="rx-kpi"><span>Margin vs input cost</span><strong class="'+(result.margin!==null&&result.margin>=0?'rx-positive':'rx-warning')+'">'+(result.margin===null?'Unavailable':fmt(result.margin)+'%')+'</strong></div></div><div class="rx-inline" style="margin-bottom:10px"><span class="rx-chip">Runs: '+fmt(result.runs)+'</span><span class="rx-chip">Sales tax: '+fmt(result.salesTax)+' ISK</span><span class="rx-chip">Output transport: '+fmt(result.outputTransport)+' ISK</span><span class="rx-chip">Job tax: '+fmt(result.jobTax)+' ISK</span></div><h3 style="margin-bottom:8px">Your input inventory</h3><div class="rx-scroll" style="margin-bottom:12px"><table><thead><tr><th>Material</th><th>Per run</th><th>Owned</th><th>Owned runs</th><th>Character / location</th></tr></thead><tbody>'+materialRows+'</tbody></table></div><h3 style="margin-bottom:8px">Cost breakdown</h3><div class="rx-scroll"><table><thead><tr><th>Type</th><th>Item</th><th>Qty</th><th>Jita unit value</th><th>Total</th></tr></thead><tbody>'+calcRows+'</tbody></table></div>'+(result.missing.length?'<div class="rx-status rx-error" style="margin-top:10px">Profit withheld because data is missing: '+result.missing.map(esc).join(', ')+'.</div>':'<div class="rx-status" style="margin-top:10px">Estimated value only; prices are Jita IV-4 ESI orders and output transport requires a known item volume.</div>');
 }catch(e){body.innerHTML='<div class="rx-status rx-error">Could not load live prices: '+esc(e.message)+'</div>';setStatus('Market lookup failed for this recipe: '+e.message,true);}
}
root.querySelectorAll('[data-tab]').forEach(button=>button.addEventListener('click',()=>{activeTab=button.dataset.tab;root.querySelectorAll('[data-tab]').forEach(b=>{b.classList.toggle('active',b===button);b.setAttribute('aria-selected',b===button?'true':'false')});render();}));
$('rx-search').addEventListener('input',render);
$('rx-clear-search').addEventListener('click',()=>{$('rx-search').value='';render();});
$('rx-run-count').addEventListener('change',()=>{render();});
['rx-transport','rx-sell-tax','rx-job-tax','rx-fuel-per-run'].forEach(id=>$(id).addEventListener('change',()=>{render();}));
$('rx-inventory-search').addEventListener('input',()=>{const q=$('rx-inventory-search').value.toLowerCase();root.querySelectorAll('#rx-inventory tr[data-item]').forEach(row=>row.hidden=!row.dataset.item.includes(q));});
root.querySelectorAll('.rx-inventory-pick').forEach(button=>button.addEventListener('click',()=>{$('rx-search').value=button.dataset.name;activeTab='gas';root.querySelectorAll('[data-tab]').forEach(b=>{b.classList.toggle('active',b.dataset.tab==='gas');b.setAttribute('aria-selected',b.dataset.tab==='gas'?'true':'false')});render();}));
$('rx-recipe-list').addEventListener('click',e=>{const head=e.target.closest('[data-expand]');if(!head)return;const key=head.dataset.expand;if(expanded.has(key))expanded.delete(key);else expanded.add(key);render();});
$('rx-recipe-list').addEventListener('keydown',e=>{if((e.key==='Enter'||e.key===' ')&&e.target.matches('[data-expand]')){e.preventDefault();e.target.click();}});
$('rx-manufacturing-form').addEventListener('submit',async e=>{
 e.preventDefault();const product=$('rx-manufacturing-product').value.trim();if(!product)return;
 const result=$('rx-manufacturing-result');result.innerHTML='<div class="rx-status">Loading blueprint recipe and market prices…</div>';
 try{
  const response=await fetch('/reactions/recipe?activity=1&product='+encodeURIComponent(product),{headers:{'Accept':'application/json'}});
  const recipe=await response.json();if(!response.ok)throw new Error(recipe.error||'Manufacturing recipe unavailable');
  await getPrices([...(recipe.materials||[]).map(m=>m.name),...(recipe.products||[]).map(p=>p.name)]);
  const fake={materials:(recipe.materials||[]).map(m=>({name:m.name,per_run:m.quantity,owned:0,locations:[]})),products:recipe.products||[]};
  const calc=costAndProfit(fake);
  result.innerHTML='<div class="rx-kpis"><div class="rx-kpi"><span>Input cost (Jita Split)</span><strong>'+fmt(calc.inputCost)+' ISK</strong></div><div class="rx-kpi"><span>Output value (Jita Sell)</span><strong>'+fmt(calc.gross)+' ISK</strong></div><div class="rx-kpi"><span>Profit / loss</span><strong>'+(calc.profit===null?'Unavailable':fmt(calc.profit)+' ISK')+'</strong></div><div class="rx-kpi"><span>Margin vs input cost</span><strong>'+(calc.margin===null?'Unavailable':fmt(calc.margin)+'%')+'</strong></div></div><div class="rx-scroll"><table><thead><tr><th>Item</th><th>Qty</th><th>Unit value (split in / sell out)</th><th>Total</th></tr></thead><tbody>'+calc.rows.map(row=>'<tr><td>'+esc(row.name)+'</td><td>'+fmt(row.qty)+'</td><td>'+(row.price===null?'—':fmt(row.price))+'</td><td>'+(row.cost===null?'Unavailable':fmt(row.cost))+'</td></tr>').join('')+'</tbody></table></div><p class="rx-help">One-level manufacturing estimate; not a recursive chain. Missing prices/volumes are not assumed to be zero.</p>';
 }catch(err){result.innerHTML='<div class="rx-status rx-error">'+esc(err.message)+'</div>';}
});

/* Reaction leaderboard and inventory-aware operating materials */
function prevalentMaterial(r){
 const candidates=(r.materials||[]).filter(m=>isGas(m.name)&&Number(m.per_run)>0);
 candidates.sort((a,b)=>Number(b.per_run)-Number(a.per_run)||a.name.localeCompare(b.name,undefined,{sensitivity:'base'}));
 return candidates[0]||null;
}
function prevalentRuns(r){const m=prevalentMaterial(r);return m?Math.floor(Number(m.owned||0)/Number(m.per_run)):0;}
function completeRuns(r){const ms=r.materials||[];return ms.length?Math.min(...ms.map(m=>Number(m.per_run)>0?Math.floor(Number(m.owned||0)/Number(m.per_run)):Infinity)):0;}
function fuelBlocksPerRun(){return Math.max(0,Math.floor(Number($('rx-fuel-per-run').value)||0));}
function fuelStock(){return inventory.filter(i=>/fuel block/i.test(i.name)).reduce((n,i)=>n+Number(i.quantity||0),0);}
function perRunProfit(r){
 let input=0,output=0;
 for(const m of (r.materials||[])){const p=unitPrice(m.name,'Input');if(p===null||!Number.isFinite(p))return null;input+=p*Number(m.per_run||0);}
 for(const p of (r.products||[])){const price=unitPrice(p.name,'Output');if(price===null||!Number.isFinite(price))return null;output+=price*Number(p.quantity||0);}
 return output-output*(Number($('rx-sell-tax').value||0)/100)-input-input*(Number($('rx-job-tax').value||0)/100);
}
function filteredRecipes(){
 const q=$('rx-search').value.trim().toLowerCase();
 return recipes.filter(r=>categoryMatches(r)&&(!q||r.product.toLowerCase().includes(q)||(r.group||'').toLowerCase().includes(q)||(r.materials||[]).some(m=>m.name.toLowerCase().includes(q))||(r.products||[]).some(p=>p.name.toLowerCase().includes(q))))
 .sort((a,b)=>{const pa=perRunProfit(a),pb=perRunProfit(b);if(pa===null&&pb===null)return a.product.localeCompare(b.product);if(pa===null)return 1;if(pb===null)return -1;return pb-pa||a.product.localeCompare(b.product);});
}
function render(){
 const list=filteredRecipes();
 $('rx-count').textContent=fmt(list.length);
 $('rx-owned-count').textContent=fmt(list.filter(r=>(r.materials||[]).some(m=>m.owned>0)).length);
 $('rx-manufacturing-panel').hidden=activeTab!=='manufacturing';
 if(activeTab==='manufacturing'){$('rx-recipe-list').innerHTML='';return;}
 if(!list.length){$('rx-recipe-list').innerHTML='<div class="rx-empty">No recipes match this tab and search.</div>';return;}
 const ranked=list.filter(r=>perRunProfit(r)!==null).slice(0,8), max=Math.max(1,...ranked.map(r=>Math.abs(perRunProfit(r))));
 const chart='<section class="rx-panel"><h2>Net profit per run · Top reactions</h2><div style="display:grid;gap:7px">'+ranked.map((r,i)=>'<div style="display:grid;grid-template-columns:minmax(110px,1fr) 2fr auto;gap:8px;align-items:center"><span style="font-size:11px;overflow-wrap:anywhere">'+(i+1)+'. '+esc(r.product)+'</span><span style="height:10px;background:#0a1219;border-radius:5px;overflow:hidden"><span style="display:block;height:100%;width:'+Math.max(1,Math.abs(perRunProfit(r))/max*100)+'%;background:'+(perRunProfit(r)>=0?'#65d9f5':'#ffb36a')+'"></span></span><strong style="font-size:11px">'+fmt(perRunProfit(r))+' ISK</strong></div>').join('')+'</div><p class="rx-help">Ranked by estimated net ISK per run after input cost, sales tax and job tax. Unpriced recipes appear after priced recipes.</p></section>';
 const cards=list.map((r,i)=>{
  const key=String(r.type_id||r.product)+'-'+i, open=expanded.has(key), gas=prevalentMaterial(r), gasRuns=prevalentRuns(r), runs=completeRuns(r), profit=perRunProfit(r);
  return '<article class="rx-recipe" data-key="'+esc(key)+'"><div class="rx-recipe-head" role="button" tabindex="0" data-expand="'+esc(key)+'"><div><h3>'+(i+1)+'. '+esc(r.product)+'</h3><div class="rx-help">'+esc(r.group||'Reaction formula')+' · '+(r.materials||[]).length+' input types'+(gas?' · Prevalent: '+esc(gas.name):'')+'</div></div><div class="rx-inline"><span class="rx-pill">'+fmt(gasRuns)+' prevalent-gas runs</span><span class="rx-pill">'+fmt(runs)+' complete runs</span><span class="rx-pill '+(profit!==null&&profit>=0?'rx-positive':'rx-warning')+'">'+(profit===null?'Profit unpriced':fmt(profit)+' ISK / run')+'</span><span class="rx-pill">'+(open?'Hide details':'Expand details')+'</span></div></div><div class="rx-recipe-body" '+(open?'':'hidden')+' id="rx-body-'+esc(key)+'"><div class="rx-status">Loading material and market details…</div></div></article>';
 }).join('');
 $('rx-recipe-list').innerHTML=chart+cards;
 list.forEach((r,i)=>{const key=String(r.type_id||r.product)+'-'+i;if(expanded.has(key))renderDetails(r,key);});
}
async function renderDetails(r,key){
 const body=$('rx-body-'+CSS.escape(key));if(!body)return;
 try{
  await getPrices([...(r.materials||[]).map(m=>m.name),...(r.products||[]).map(p=>p.name)]);
  const runs=completeRuns(r), requestedRuns=prevalentRuns(r), gas=prevalentMaterial(r), fuelNeeded=requestedRuns*fuelBlocksPerRun(), fuelOwned=fuelStock(), fuelShortfall=Math.max(0,fuelNeeded-fuelOwned);
  const calc=costAndProfit(r), result={...calc,runs,requestedRuns,gas,fuelNeeded,fuelOwned,fuelShortfall};
  const materialRows=(r.materials||[]).map(m=>'<tr><td>'+esc(m.name)+(gas&&m.name===gas.name?' <span class="rx-pill">Prevalent gas</span>':'')+'</td><td>'+fmt(m.per_run)+'</td><td>'+fmt(m.owned)+'</td><td>'+fmt(Math.floor(Number(m.owned||0)/Math.max(1,Number(m.per_run||0))))+'</td><td>'+fmt(Number(m.per_run||0)*requestedRuns)+'</td><td>'+Math.max(0,Number(m.per_run||0)*requestedRuns-Number(m.owned||0))+'</td><td>'+((m.locations||[]).map(l=>esc(l.owner)+' — '+esc(l.location)+': '+fmt(l.quantity)).join('<br>')||'<span class="rx-warning">Not in cached inventory</span>')+'</td></tr>').join('');
  const calcRows=result.rows.map(row=>'<tr><td>'+row.kind+'</td><td>'+esc(row.name)+'</td><td>'+fmt(row.qty)+'</td><td>'+(row.price===null?'—':fmt(row.price))+'</td><td>'+(row.cost===null?'Unavailable':fmt(row.cost))+'</td></tr>').join('');
  body.innerHTML='<div class="rx-kpis"><div class="rx-kpi"><span>Prevalent gas</span><strong>'+ (gas?esc(gas.name):'Not identified')+'</strong></div><div class="rx-kpi"><span>Runs supported by prevalent gas</span><strong>'+fmt(requestedRuns)+'</strong></div><div class="rx-kpi"><span>Complete runs from all recipe inputs</span><strong>'+fmt(runs)+'</strong></div><div class="rx-kpi"><span>Fuel blocks required for prevalent-gas runs</span><strong>'+fmt(fuelNeeded)+'</strong></div><div class="rx-kpi"><span>Fuel blocks in tracked inventory</span><strong>'+fmt(fuelOwned)+'</strong></div><div class="rx-kpi"><span>Additional fuel blocks needed</span><strong class="'+(fuelShortfall?'rx-warning':'rx-positive')+'">'+fmt(fuelShortfall)+'</strong></div><div class="rx-kpi"><span>Input cost</span><strong>'+fmt(result.inputCost)+' ISK</strong></div><div class="rx-kpi"><span>Output value</span><strong>'+fmt(result.gross)+' ISK</strong></div><div class="rx-kpi"><span>Net profit / loss</span><strong class="'+(result.profit!==null&&result.profit>=0?'rx-positive':'rx-warning')+'">'+(result.profit===null?'Unavailable':fmt(result.profit)+' ISK')+'</strong></div><div class="rx-kpi"><span>Sales tax</span><strong>'+fmt(result.salesTax)+' ISK</strong></div></div><h3 style="margin:12px 0 8px">Materials required for the prevalent-gas run count</h3><div class="rx-scroll" style="margin-bottom:12px"><table><thead><tr><th>Material</th><th>Per run</th><th>Owned</th><th>Owned runs</th><th>Needed</th><th>Shortfall</th><th>Character / location</th></tr></thead><tbody>'+materialRows+'</tbody></table></div><div class="rx-status '+(fuelShortfall?'rx-error':'')+'">Fuel-block estimate uses '+fmt(fuelBlocksPerRun())+' block(s) per run. Change “Fuel blocks / run” to match the operating setup. Recipe inputs limit complete runs to '+fmt(runs)+'.</div><h3 style="margin:12px 0 8px">Cost breakdown</h3><div class="rx-scroll"><table><thead><tr><th>Type</th><th>Item</th><th>Qty</th><th>Jita unit value</th><th>Total</th></tr></thead><tbody>'+calcRows+'</tbody></table></div>'+(result.missing.length?'<div class="rx-status rx-error" style="margin-top:10px">Profit withheld because prices are missing: '+result.missing.map(esc).join(', ')+'.</div>':'');
 }catch(e){body.innerHTML='<div class="rx-status rx-error">Could not load live prices: '+esc(e.message)+'</div>';setStatus('Market lookup failed: '+e.message,true);}
}

setStatus('Loading Jita prices to rank all reactions by net profit…');
render();
(async()=>{try{await getPrices(recipes.flatMap(r=>[...(r.materials||[]).map(m=>m.name),...(r.products||[]).map(p=>p.name)]));setStatus('Priced reactions ranked by net profit per run.');render();}catch(e){setStatus('Some market prices unavailable; unpriced reactions remain at the bottom. '+e.message,true);render();}})();
})();
</script>
@endsection
