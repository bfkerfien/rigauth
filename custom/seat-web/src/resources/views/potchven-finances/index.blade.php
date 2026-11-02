@extends('web::layouts.grids.12')

@section('title', 'Potchven Finances')
@section('page_header', 'Potchven Finances')
@section('page_description', 'Potchven operations financial dashboard')

@section('full')
<style>
  body:has(.trig-finance), body:has(.trig-finance) .content-wrapper,
  body:has(.trig-finance) .content, body:has(.trig-finance) .content-header {
    background-color: #17191c !important;
    color: #e8e3dc;
  }
  body:has(.trig-finance) .content-wrapper { background:linear-gradient(145deg,#25282c 0%,#17191c 48%,#0c0e10 100%) !important; }
  body:has(.trig-finance) .content { background:transparent !important; }
  body:has(.trig-finance) .content-header { background:transparent !important; }
  .trig-finance {
    --tf-bg: #101316;
    --tf-panel: #191e22;
    --tf-panel-hi: #20272c;
    --tf-line: rgba(201, 164, 112, .25);
    --tf-red: #d84b43;
    --tf-red-hi: #f06a5c;
    --tf-gold: #c9a470;
    --tf-text: #e8e3dc;
    --tf-muted: #a7a29a;
    color: var(--tf-text);
    margin: -4px 0 22px;
  }
  .trig-finance .tf-cycle {
    display:flex; align-items:center; justify-content:space-between; gap:24px;
    margin:-22px 0 22px; padding:17px 24px;
    background:linear-gradient(105deg,#24272b 0%,#171a1e 58%,#101214 100%);
    border:1px solid rgba(201,164,112,.35); border-left:5px solid var(--tf-gold);
    border-radius:4px; box-shadow:0 8px 24px rgba(0,0,0,.25);
  }
  .trig-finance .tf-cycle-title { color:#f1ece4; font-size:16px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; margin:4px 0; }
  .trig-finance .tf-cycle-count { display:flex; align-items:baseline; gap:10px; margin-left:auto; margin-right:12px; white-space:nowrap; }
  .trig-finance .tf-cycle-number { color:#f2d4a4; font-size:clamp(42px,6vw,66px); line-height:.95; font-weight:800; letter-spacing:-.05em; text-shadow:0 0 20px rgba(201,164,112,.18); font-variant-numeric:tabular-nums; }
  .trig-finance .tf-cycle-unit { color:var(--tf-gold); font-size:10px; font-weight:700; letter-spacing:.14em; }
  .trig-finance .tf-hero {
    position:relative; overflow:hidden; height:clamp(118px,13vw,176px); padding:0;
    margin-bottom:22px; border:1px solid rgba(216,75,67,.7); border-radius:5px;
    background:#08090d; box-shadow:0 12px 32px rgba(0,0,0,.3),0 0 24px rgba(216,75,67,.12);
  }
  .trig-finance .tf-hero > svg { display:block; width:100%; height:100%; }
  .trig-finance .tf-hero:after {
    content: ""; position:absolute; inset:0; pointer-events:none; opacity:.2;
    background-image: linear-gradient(30deg, transparent 49.6%, rgba(201,164,112,.35) 50%, transparent 50.4%),
      linear-gradient(150deg, transparent 49.6%, rgba(201,164,112,.22) 50%, transparent 50.4%);
    background-size: 72px 72px; mask-image: linear-gradient(90deg, transparent, #000);
  }
  .trig-finance .tf-kicker { color:var(--tf-gold); text-transform:uppercase; letter-spacing:.24em; font-size:10px; font-weight:700; }
  .trig-finance .tf-hero h2 { color:#f1ece4; font-size:27px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; margin:8px 0; }
  .trig-finance .tf-hero p { color:#b9b4ad; max-width:620px; margin:0; }
  .trig-finance .tf-sigil { width:170px; min-width:120px; height:145px; margin-left:20px; position:relative; z-index:1; filter:drop-shadow(0 0 14px rgba(216,75,67,.22)); }
  .trig-finance .tf-sigil svg { width:100%; height:100%; }
  .trig-finance .tf-metric { position:relative; min-height:118px; padding:17px 18px 15px; margin-bottom:16px;
    background:linear-gradient(145deg,var(--tf-panel-hi),var(--tf-panel)); border:1px solid var(--tf-line);
    border-top:2px solid var(--tf-red); border-radius:4px; box-shadow:0 5px 16px rgba(0,0,0,.16); }
  .trig-finance .tf-metric:nth-child(3n+2) { border-top-color:var(--tf-gold); }
  .trig-finance .tf-metric:nth-child(3n) { border-top-color:#8f7771; }
  .trig-finance .tf-metric-label { color:var(--tf-muted); text-transform:uppercase; letter-spacing:.08em; font-size:10px; font-weight:700; line-height:1.4; }
  .trig-finance .tf-metric-value { color:#f1ece4; font-size:clamp(17px,2vw,23px); font-weight:700; margin-top:10px; overflow-wrap:anywhere; }
  .trig-finance .tf-metric-value small { color:var(--tf-gold); font-size:11px; font-weight:600; }
  .trig-finance .tf-panel { background:var(--tf-panel); border:1px solid var(--tf-line); border-radius:5px; margin-bottom:18px; box-shadow:0 7px 20px rgba(0,0,0,.14); }
  .trig-finance .tf-panel-head { display:flex; align-items:center; gap:10px; padding:13px 17px; background:linear-gradient(90deg,rgba(216,75,67,.13),transparent 75%); border-bottom:1px solid var(--tf-line); }
  .trig-finance .tf-panel-head:before { content:""; width:3px; height:17px; background:var(--tf-red); }
  .trig-finance .tf-panel-head h3 { color:#eee7dc; text-transform:uppercase; letter-spacing:.1em; font-size:12px; font-weight:700; margin:0; }
  .trig-finance .tf-collapsible { overflow:hidden; }
  .trig-finance .tf-collapsible > summary { list-style:none; cursor:pointer; justify-content:space-between; user-select:none; }
  .trig-finance .tf-collapsible > summary::-webkit-details-marker { display:none; }
  .trig-finance .tf-collapsible > summary h3 { flex:1; }
  .trig-finance .tf-chevron { color:var(--tf-gold); transition:transform .18s ease; }
  .trig-finance .tf-collapsible[open] > summary .tf-chevron { transform:rotate(180deg); }

  .trig-finance .tf-panel-body { padding:16px 17px; }
  .trig-finance .tf-panel .table { color:#ddd7ce; margin:0; }
  .trig-finance .tf-panel .table thead th { color:var(--tf-gold); background:#20262a; border-top:0; border-bottom:1px solid var(--tf-line); text-transform:uppercase; letter-spacing:.07em; font-size:10px; }
  .trig-finance .tf-panel .table td { border-top:1px solid rgba(201,164,112,.12); vertical-align:middle; }
  .trig-finance .tf-panel .table-striped tbody tr:nth-of-type(odd) { background:rgba(255,255,255,.025); }
  .trig-finance .tf-panel .table tbody tr:hover { background:rgba(216,75,67,.08); }
  .trig-finance .tf-period { color:#ddd7ce; background:linear-gradient(110deg,#211f20,#191e22); border:1px solid var(--tf-line); border-left:4px solid var(--tf-gold); }
  .trig-finance .tf-note { color:#c5beb4; background:rgba(201,164,112,.07); border:1px solid rgba(201,164,112,.2); border-left:3px solid var(--tf-gold); border-radius:3px; padding:12px 15px; margin-bottom:17px; }
  .trig-finance .tf-sync { color:#d5d0c7; background:#1c2425; border:1px solid rgba(112,151,126,.35); border-left:3px solid #7caa8a; border-radius:3px; padding:11px 15px; margin-bottom:17px; }
  .trig-finance .btn-tf { color:#fff; background:linear-gradient(135deg,#b83d38,#842d2b); border:1px solid #d65b50; text-transform:uppercase; letter-spacing:.06em; font-size:11px; font-weight:700; }
  .trig-finance .btn-tf:hover { color:#fff; background:linear-gradient(135deg,#d84b43,#a83732); }
  .trig-finance .btn-tf-gold { color:#18191a; background:linear-gradient(135deg,#d3b17d,#a98552); border:1px solid #e0c08e; text-transform:uppercase; letter-spacing:.06em; font-size:11px; font-weight:700; }
  .trig-finance .btn-tf-gold:hover { color:#111; background:#e0c08e; }
  .trig-finance .tf-muted { color:var(--tf-muted); }
  .trig-finance .tf-pill { display:inline-block; padding:3px 8px; border:1px solid var(--tf-line); border-radius:2px; color:var(--tf-gold); font-size:10px; text-transform:uppercase; letter-spacing:.08em; }
  @media(max-width:767px) {\n    .trig-finance .tf-cycle { align-items:flex-start; flex-wrap:wrap; margin-top:-14px; padding:16px; }\n    .trig-finance .tf-cycle-count { margin:0; }\n  }\n  @media(max-width:575px) {
    .trig-finance .tf-hero { padding:22px 18px; min-height:150px; }
    .trig-finance .tf-hero h2 { font-size:21px; }
    .trig-finance .tf-sigil { width:92px; height:95px; }
  }
</style>

<div class="trig-finance">
  <section class="tf-hero" aria-label="Triglavian gate in deep space">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 240" role="img" aria-labelledby="title desc">
    <title id="title">Triglavian gate in deep space</title>
    <desc id="desc">A monumental angular gate with a luminous red triangular core, orbital rings, distant planets and a red nebula.</desc>
    <defs>
     <radialGradient id="space"><stop stop-color="#651b20"/><stop offset=".48" stop-color="#210e18"/><stop offset="1" stop-color="#05070c"/></radialGradient>
     <radialGradient id="nebula"><stop stop-color="#ff6544" stop-opacity=".82"/><stop offset=".28" stop-color="#a51c28" stop-opacity=".48"/><stop offset="1" stop-color="#220c18" stop-opacity="0"/></radialGradient>
     <linearGradient id="metal" x2="0" y2="1"><stop stop-color="#52505a"/><stop offset=".22" stop-color="#151820"/><stop offset=".55" stop-color="#080b12"/><stop offset=".8" stop-color="#3a171d"/><stop offset="1" stop-color="#08090e"/></linearGradient>
     <linearGradient id="red" x2="0" y2="1"><stop stop-color="#ffb08a"/><stop offset=".35" stop-color="#ff321f"/><stop offset="1" stop-color="#8c0915"/></linearGradient>
     <filter id="glow"><feGaussianBlur stdDeviation="7" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
     <filter id="soft"><feGaussianBlur stdDeviation="18"/></filter>
     <pattern id="stars" width="137" height="91" patternUnits="userSpaceOnUse"><circle cx="9" cy="13" r="1" fill="#fff"/><circle cx="72" cy="38" r=".8" fill="#ffb8a0"/><circle cx="119" cy="77" r="1" fill="#fff"/><circle cx="42" cy="82" r=".6" fill="#fff"/></pattern>
    </defs>
    <rect width="1600" height="240" fill="url(#space)"/>
    <ellipse cx="780" cy="124" rx="690" ry="190" fill="url(#nebula)"/>
    <ellipse cx="280" cy="110" rx="300" ry="150" fill="url(#nebula)" opacity=".5"/>
    <ellipse cx="1330" cy="85" rx="250" ry="145" fill="url(#nebula)" opacity=".55"/>
    <rect width="1600" height="240" fill="url(#stars)"/>
    <g opacity=".72" fill="none" stroke="#e83b2e"><path d="M0 198Q340 22 800 28T1600 198" stroke-width="1"/><path d="M0 213Q380 45 800 48T1600 213" opacity=".45"/></g>
    <!-- distant planets -->
    <circle cx="180" cy="55" r="20" fill="#17151c" stroke="#9d3c38" stroke-width="2"/><path d="M160 56q20-16 40-2" fill="none" stroke="#e56b50" opacity=".55"/>
    <circle cx="1450" cy="76" r="72" fill="#17121b" stroke="#9b3435" stroke-width="2"/><path d="M1392 35q62-36 118 8M1380 78q65-28 139 7" fill="none" stroke="#d34b48" opacity=".45" stroke-width="3"/>
    <!-- gate orbital rings -->
    <g transform="translate(800 120)" fill="none">
     <ellipse rx="240" ry="182" stroke="#080a10" stroke-width="32"/>
     <ellipse rx="240" ry="182" stroke="#8b2528" stroke-width="5"/>
     <ellipse rx="218" ry="162" stroke="#ff4a31" stroke-width="2" opacity=".85" filter="url(#glow)"/>
     <ellipse rx="276" ry="204" stroke="#27202a" stroke-width="13"/>
     <path d="M-275 0h550M0-208v416" stroke="#f44a36" stroke-width="2" opacity=".6"/>
    </g>
    <!-- lateral gate arms -->
    <g fill="url(#metal)" stroke="#81272b" stroke-width="2">
     <path d="M0 182 430 80 510 92 270 153 470 145 520 163 0 220Z"/>
     <path d="M1600 182 1170 80 1090 92 1330 153 1130 145 1080 163 1600 220Z"/>
     <path d="M420 0 590 0 520 72 300 118 350 82Z"/>
     <path d="M1180 0 1010 0 1080 72 1300 118 1250 82Z"/>
     <path d="M700 0 800 0 755 78 700 60Z"/><path d="M900 0 800 0 845 78 900 60Z"/>
    </g>
    <g fill="none" stroke="#ff432c" stroke-width="3" filter="url(#glow)">
     <path d="M35 202 458 99M1565 202 1142 99M376 79 510 51M1224 79 1090 51"/>
     <path d="M0 226 520 168M1600 226 1080 168" opacity=".8"/>
    </g>
    <!-- central angular gate and suspended core -->
    <g transform="translate(800 120)">
     <path d="M0-112 154-28 126 10 0-58-126 10-154-28Z" fill="url(#metal)" stroke="#b53a32" stroke-width="3"/>
     <path d="M-120-12 0 62 120-12 94 45 0 111-94 45Z" fill="#090b12" stroke="#ed4935" stroke-width="4"/>
     <path d="M0-70 82-20 0 125-82-20Z" fill="#100b13" stroke="#ff563b" stroke-width="4"/>
     <path d="M0-50 57-16 0 91-57-16Z" fill="#f02b1d" opacity=".3" filter="url(#glow)"/>
     <path d="M0-50 57-16 0 91-57-16Z" fill="none" stroke="#ff9b70" stroke-width="3" filter="url(#glow)"/>
     <path d="M0-112V125M-154-28 0 62 154-28" fill="none" stroke="#ff553b" stroke-width="2"/>
     <circle r="8" fill="#fff0d2" filter="url(#glow)"/>
    </g>
    <rect x="4" y="4" width="1592" height="232" rx="7" fill="none" stroke="#d43b32" stroke-width="2" opacity=".85"/>
    </svg>
  </section>

  <section class="tf-cycle">
    <div class="tf-cycle-copy">
      <div class="tf-kicker">Triglavian Consortium · Reporting cycle</div>
      <div class="tf-cycle-title">Financial reporting period</div>
      <p class="tf-muted mb-0">Started {{ $periodStart->timezone(config('app.timezone'))->format('Y-m-d') }} · Countdown refreshes daily</p>
    </div>
    <div class="tf-cycle-count">
      <span class="tf-cycle-number">{{ number_format($monthlyCountdown) }}</span>
      <span class="tf-cycle-unit">DAYS REMAINING</span>
    </div>
    <form method="POST" action="{{ route('seatcore::potchven-finances.timer.reset') }}" onsubmit="return confirm('Reset the reporting cycle to 180 days and start a new financial period? Historical wallet and site records will be preserved; outstanding SRP obligations remain payable.')">
      @csrf
      <button type="submit" class="btn btn-tf-gold"><i class="fas fa-redo-alt"></i> Reset to 180 Days</button>
    </form>
  </section>

  <div class="row">
    @foreach([
      ['Sites this period', number_format($sites), 'fa-crosshairs'],
      ['Days since financial reset', number_format($daysElapsed).' days', 'fa-stopwatch'],
      ['Average sites per day', number_format($sites / $daysElapsed, 2), 'fa-chart-line'],
      ['Site revenue this period', number_format($siteRevenue, 0).' ISK', 'fa-coins'],
      ['Corp Main Wallet · Division 3', number_format($corpWallet, 0).' ISK', 'fa-landmark'],
      ['SRP Wallet · Division 2', number_format($srpWallet, 0).' ISK', 'fa-wallet'],
    ] as $metric)
      <div class="col-lg-3 col-md-4 col-sm-6">
        <div class="tf-metric">
          <div class="tf-metric-label"><i class="fas {{ $metric[2] }} mr-1"></i> {{ $metric[0] }}</div>
          <div class="tf-metric-value">{{ $metric[1] }}</div>
        </div>
      </div>
    @endforeach
  </div>



  <section class="tf-panel">
    <div class="tf-panel-head"><h3>SRP transfers & operational controls</h3></div>
    <div class="tf-panel-body d-flex flex-wrap align-items-center">
      <form method="POST" action="{{ route('seatcore::potchven-finances.srp.add-site-without-loot') }}" class="mr-2 mb-2" onsubmit="return confirm('Add 27,000,000 ISK to pending SRP transfer for Site Without Loot?')">
        @csrf
        <button type="submit" class="btn btn-tf"><i class="fas fa-plus-circle"></i> Site Without Loot (+27,000,000 ISK)</button>
      </form>
      <form method="POST" action="{{ route('seatcore::potchven-finances.srp.transfer') }}" class="mb-2" onsubmit="return confirm('Have you completed the ISK transfer to the SRP fund? This will mark all currently pending SRP obligations as transferred in the dashboard. It will not move ISK in EVE.')">
        @csrf
        <button type="submit" class="btn btn-tf-gold"><i class="fas fa-check-circle"></i> Transfer to SRP</button>
      </form>
      <p class="tf-muted small mb-0 w-100">Use “Transfer to SRP” only after the ISK has actually been sent. The dashboard records the confirmation; it cannot transfer ISK in EVE.</p>
    </div>
  </section>

  <div class="tf-sync">
    <strong><i class="fas fa-satellite-dish mr-1"></i> Finance data last updated:</strong>
    @if($lastFinanceSync) {{ \Carbon\Carbon::parse($lastFinanceSync)->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') }} @else Never recorded @endif
    <span class="ml-2">· Automatic sync runs every 15 minutes.</span>
  </div>
  @foreach($financeSyncErrors as $syncError)
    <div class="alert alert-warning"><strong>Sync issue ({{ $syncError->resource_key }}):</strong> {{ $syncError->last_error }}</div>
  @endforeach
  @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
  @if(session('info'))<div class="alert alert-info">{{ session('info') }}</div>@endif
  @if(!$owners->count())
    <div class="alert alert-warning">Finance tables have not been imported yet. Import the standalone finance database into SeAT using the fin_ prefix, then reload this page.</div>
  @endif

  <section class="tf-panel">
    <div class="tf-panel-head"><h3>Tracked wallets</h3></div>
    <div class="table-responsive"><table class="table table-sm table-hover">
      <thead><tr><th>Owner</th><th>Type</th><th>EVE ID</th><th>Active</th></tr></thead><tbody>
      @forelse($owners as $owner)
        <tr><td>{{ $owner->display_name }}</td><td>{{ ucfirst($owner->owner_type) }}</td><td>{{ $owner->eve_id }}</td><td>{{ ($owner->is_active ?? true) ? 'Yes' : 'No' }}</td></tr>
      @empty<tr><td colspan="4" class="text-muted">No finance owners imported.</td></tr>@endforelse
      </tbody>
    </table></div>
  </section>

  <div class="tf-note"><strong><i class="fas fa-info-circle mr-1"></i> Site accounting rules</strong><br>
    The Sites This Period and Site Revenue metrics use corporation telemetry item type 89219 in Hangar Division 3 (CorpSAG3) across all stations, divided by 60. Hangar Divisions 1 and 2 and all other divisions are excluded. The recent site events below separately reconcile corporation reward payouts: three 9,000,000 ISK payouts—one to each tracked character—identify a successful payout group, while incomplete groups are flagged for review and the unpaid portion of the expected 27,000,000 ISK is listed as an SRP transfer obligation. Run the finance sync to refresh both asset and payout data.
  </div>

  <details class="tf-panel tf-collapsible">
    <summary class="tf-panel-head"><h3>Recent wallet entries</h3><i class="fas fa-chevron-down tf-chevron"></i></summary>
    <div class="tf-panel-body">
    <div class="table-responsive"><table class="table table-sm table-striped">
      <thead><tr><th>Date</th><th>Owner</th><th>Reference</th><th>Description</th><th class="text-right">Amount (ISK)</th><th>Operating expense</th></tr></thead><tbody>
      @forelse($entries as $entry)
        <tr><td>{{ $entry->occurred_at }}</td><td>{{ $entry->display_name }}</td><td>{{ $entry->reference_type }}</td><td>{{ $entry->description }}</td><td class="text-right">{{ number_format((float)$entry->amount_isk, 2) }}</td><td>{{ $entry->is_operational_expense ? 'Yes' : 'No' }}</td></tr>
      @empty<tr><td colspan="6" class="text-muted">No wallet entries imported.</td></tr>@endforelse
      </tbody>
    </table></div>
    </div>
  </details>

  <details class="tf-panel tf-collapsible">
    <summary class="tf-panel-head"><h3>Recent site events</h3><i class="fas fa-chevron-down tf-chevron"></i></summary>
    <div class="tf-panel-body">
    <div class="table-responsive"><table class="table table-sm table-striped">
      <thead><tr><th>Date</th><th>Status</th><th>Payout count</th><th>Total payouts</th><th>Revenue credit</th><th>Notes</th></tr></thead><tbody>
      @forelse($siteEvents as $event)
        <tr><td>{{ $event->occurred_at }}</td><td>{{ $event->status }}</td><td>{{ $event->payout_count }}</td><td>{{ number_format((float)$event->total_payout_isk, 0) }}</td><td>{{ number_format((float)$event->revenue_credit_isk, 0) }}</td><td>{{ $event->review_note }}</td></tr>
      @empty<tr><td colspan="6" class="text-muted">No site events imported.</td></tr>@endforelse
      </tbody>
    </table></div>
    </div>
  </details>

  <section class="tf-panel">
    <div class="tf-panel-head"><h3>Additional Financial Indicators</h3></div>
    <div class="tf-panel-body">
      <div class="row">
        @foreach([
          ['Character expenses this period', number_format($expenses, 0).' ISK', 'fa-file-invoice-dollar'],
          ['Estimated profit this period', number_format($siteRevenue - $expenses, 0).' ISK', 'fa-balance-scale'],
          ['Pending SRP transfer', number_format($pendingSrp, 0).' ISK', 'fa-shield-alt'],
          ['Estimated assets', number_format($assetValue, 0).' ISK', 'fa-cubes'],
        ] as $metric)
          <div class="col-lg-3 col-md-4 col-sm-6">
            <div class="tf-metric">
              <div class="tf-metric-label"><i class="fas {{ $metric[2] }} mr-1"></i> {{ $metric[0] }}</div>
              <div class="tf-metric-value">{{ $metric[1] }}</div>
            </div>
          </div>
        @endforeach
      </div>
    </div>
  </section>
</div>
@endsection