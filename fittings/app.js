const sharedData={
  modules:[
    {name:'Damage Control II',needed:1,owned:0,doctrines:'Armor Fleet, Triglavian'},
    {name:'Multispectrum Energized Membrane II',needed:2,owned:1,doctrines:'Armor Fleet'},
    {name:'Small Energy Neutralizer II',needed:1,owned:0,doctrines:'Armor Fleet'},
    {name:'Nanofiber Internal Structure II',needed:2,owned:0,doctrines:'Armor Fleet, Triglavian'},
    {name:'10MN Afterburner II',needed:1,owned:0,doctrines:'Triglavian'}
  ],
  hulls:[
    {name:'Retribution',meta:'3 × Small Energy Collision Accelerator I',needed:1,owned:0,doctrines:'Armor Fleet'},
    {name:'Vedmak',meta:'2 × Medium Energy Locus Coordinator I · 1 × Medium Trimark Armor Pump I',needed:1,owned:0,doctrines:'Triglavian'}
  ],
  consumables:[
    {name:'Scorch S',needed:500,owned:120,doctrines:'Armor Fleet'},
    {name:'Warrior II',needed:20,owned:0,doctrines:'Triglavian'}
  ]
};

const deploymentData={
  modules:[
    {name:'Damage Control II',needed:18,owned:4,doctrines:'Armor Fleet, Triglavian'},
    {name:'Multispectrum Energized Membrane II',needed:12,owned:5,doctrines:'Armor Fleet'},
    {name:'Small Energy Neutralizer II',needed:8,owned:0,doctrines:'Armor Fleet'},
    {name:'Nanofiber Internal Structure II',needed:20,owned:6,doctrines:'Armor Fleet, Triglavian'},
    {name:'10MN Afterburner II',needed:6,owned:2,doctrines:'Triglavian'},
    {name:'50MN Microwarpdrive II',needed:4,owned:0,doctrines:'Logistics'}
  ],
  hulls:[
    {name:'Retribution',meta:'3 × Small Energy Collision Accelerator I',needed:6,owned:2,doctrines:'Armor Fleet'},
    {name:'Confessor',meta:'3 × Small Energy Locus Coordinator I',needed:4,owned:1,doctrines:'Armor Fleet'},
    {name:'Vedmak',meta:'2 × Medium Energy Locus Coordinator I · 1 × Medium Trimark Armor Pump I',needed:4,owned:1,doctrines:'Triglavian'}
  ],
  consumables:[
    {name:'Scorch S',needed:6000,owned:1200,doctrines:'Armor Fleet'},
    {name:'Warrior II',needed:160,owned:40,doctrines:'Triglavian'},
    {name:'Navy Cap Booster 400',needed:240,owned:0,doctrines:'Logistics'}
  ]
};

const categoryLabels={modules:'MODULES',hulls:'HULLS & RIGS',consumables:'CONSUMABLES'};
const activeCategory={shared:'modules',deployment:'modules'};
const number=value=>Number(value||0).toLocaleString();
const $=selector=>document.querySelector(selector);

function switchPanel(name){
  document.querySelectorAll('.fit-tab').forEach(button=>{
    const active=button.dataset.panel===name;
    button.classList.toggle('active',active);
    button.setAttribute('aria-selected',String(active));
  });
  document.querySelectorAll('.fit-panel').forEach(panel=>{
    const active=panel.id===`panel-${name}`;
    panel.classList.toggle('active',active);
    panel.hidden=!active;
  });
  const target=name==='fits'?'fits':name==='shopping'?'shopping-list':'deployment-shopping-list';
  document.querySelectorAll('.sub-link').forEach(link=>{
    const active=link.getAttribute('href')===`#${target}`||name==='fits'&&link.getAttribute('href')==='#fittings';
    link.classList.toggle('selected',active);
  });
  history.replaceState(null,'',`#${target}`);
}

function renderRows(group){
  const category=activeCategory[group];
  const data=(group==='shared'?sharedData:deploymentData)[category]||[];
  const query=$(`[data-filter="${group}"]`).value.trim().toLocaleLowerCase();
  const body=$(`#${group}Rows`);
  body.replaceChildren();
  const visible=data.filter(item=>`${item.name} ${item.meta||''} ${item.doctrines||''}`.toLocaleLowerCase().includes(query));
  if(!visible.length){
    const row=document.createElement('tr');
    const cell=document.createElement('td');
    cell.colSpan=5;cell.className='empty-filter';
    cell.textContent=data.length?'No items match this filter.':'No missing items in this category.';
    row.append(cell);body.append(row);return;
  }
  for(const item of visible){
    const row=document.createElement('tr');
    const name=document.createElement('td');name.className='item-name';name.textContent=item.name;
    if(item.meta){const meta=document.createElement('span');meta.className='item-meta';meta.textContent=item.meta;name.append(meta)}
    row.append(name);
    for(const [value,className] of [[item.needed,''],[item.owned,'owned'],[Math.max(0,item.needed-item.owned),item.needed>item.owned?'shortfall':'ready']]){
      const cell=document.createElement('td');cell.className=className;cell.textContent=number(value);row.append(cell);
    }
    const doctrines=document.createElement('td');doctrines.className='doctrine-list';doctrines.textContent=item.doctrines||'—';row.append(doctrines);
    body.append(row);
  }
}

function selectCategory(group,category,button){
  activeCategory[group]=category;
  document.querySelectorAll(`[data-subtabs="${group}"] .subtab`).forEach(tab=>{
    const active=tab===button;tab.classList.toggle('active',active);tab.setAttribute('aria-selected',String(active));
  });
  $(`#${group}Context`).innerHTML=`${categoryLabels[category]} <i>·</i> ${group==='shared'?'SHARED FIT STOCK':'ONE OF EACH ASSIGNED FIT'}`;
  renderRows(group);
}

document.querySelectorAll('.fit-tab').forEach(button=>button.addEventListener('click',()=>switchPanel(button.dataset.panel)));
document.querySelectorAll('.subtab').forEach(button=>button.addEventListener('click',()=>selectCategory(button.closest('[data-subtabs]').dataset.subtabs,button.dataset.category,button)));
document.querySelectorAll('[data-filter="shared"],[data-filter="deployment"]').forEach(input=>input.addEventListener('input',()=>renderRows(input.dataset.filter)));

document.querySelectorAll('[data-copy]').forEach(button=>button.addEventListener('click',async()=>{
  const group=button.dataset.copy;const rows=(group==='shared'?sharedData:deploymentData)[activeCategory[group]]||[];
  const text=rows.filter(item=>item.needed>item.owned).map(item=>`${number(item.needed-item.owned)} × ${item.name}`).join('\n');
  try{await navigator.clipboard.writeText(text);const old=button.textContent;button.textContent='Copied';setTimeout(()=>button.textContent=old,1300)}catch{button.textContent='Clipboard unavailable';setTimeout(()=>button.textContent='Copy missing items',1500)}
}));

$('#fittingsToggle').addEventListener('click',()=>{
  const button=$('#fittingsToggle');const expanded=button.getAttribute('aria-expanded')==='true';
  button.setAttribute('aria-expanded',String(!expanded));$('#fittingsNav').classList.toggle('collapsed',expanded);
  if(!expanded)switchPanel('fits');
});

document.querySelectorAll('.sub-link').forEach(link=>link.addEventListener('click',event=>{
  event.preventDefault();
  const page=link.getAttribute('href').slice(1);
  $('#fittingsToggle').setAttribute('aria-expanded','true');$('#fittingsNav').classList.remove('collapsed');
  switchPanel(page==='shopping-list'?'shopping':page==='deployment-shopping-list'?'deployment':'fits');
}));

document.querySelectorAll('[data-filter="fits"]').forEach(input=>input.addEventListener('input',()=>{
  let shown=0;
  document.querySelectorAll('.fit-row').forEach(row=>{const match=row.dataset.search.includes(input.value.trim().toLocaleLowerCase());row.hidden=!match;if(match)shown++});
  $('#fitEmpty').hidden=shown>0;
}));

renderRows('shared');renderRows('deployment');
const initial=location.hash.slice(1);
if(initial==='shopping-list')switchPanel('shopping');
else if(initial==='deployment-shopping-list')switchPanel('deployment');
else switchPanel('fits');

