<?php
namespace Seat\Web\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class MarketController extends Controller
{
    private function authorizeMarket(): void
    {
        $user = auth()->user();
        $marketeer = $user && method_exists($user, 'roles') && $user->roles()->where('title', 'Marketeers')->exists();
        abort_unless($user && ($user->can('global.superuser') || $marketeer), 403);
    }

    public function index(Request $request): View
    {
        $this->authorizeMarket();
        $tab = (string) $request->query('tab', 'shame-list');
        if (!in_array($tab, ['shame-list', 'under-30', '18-percent', 'doctrine-health'], true)) $tab = 'shame-list';

        $run = DB::table('mkt_collection_runs')->orderByDesc('id')->first();
        $comparisons = $run ? DB::table('mkt_item_comparisons')->where('run_id', $run->id)->orderBy('type_name')->limit(1000)->get() : collect();
        $under30 = $comparisons->filter(fn($r) => $r->sell_difference_percent !== null && (float)$r->sell_difference_percent < 30)->values();
        $profit18 = $comparisons->filter(fn($r) => $r->structure_sell_min !== null && $r->jita_sell_min !== null)->values();
        $shame = DB::table('mkt_shame_entries')->orderByRaw("CASE WHEN status='open' THEN 0 ELSE 1 END")->orderByDesc('created_at')->limit(500)->get();
        $health = [
            'fits' => DB::table('fit_fits')->count(),
            'doctrines' => DB::table('fit_doctrine_assignments')->distinct()->count('doctrine'),
            'items' => DB::table('fit_items')->count(),
            'snapshots' => DB::table('mkt_doctrine_health_snapshots')->orderByDesc('captured_at')->limit(100)->get(),
        ];
        return view('web::market.index', compact('tab','run','under30','profit18','shame','health'));
    }

    public function storeShame(Request $request): RedirectResponse
    {
        $this->authorizeMarket();
        $d = $request->validate([
            'character_name'=>['required','string','max:120'],
            'character_id'=>['nullable','integer','min:1'],
            'reason'=>['required','string','max:4000'],
            'evidence_url'=>['nullable','url','max:2048'],
        ]);
        DB::table('mkt_shame_entries')->insert([
            'character_name'=>trim($d['character_name']), 'character_id'=>$d['character_id'] ?? null,
            'reason'=>trim($d['reason']), 'evidence_url'=>$d['evidence_url'] ?? null, 'status'=>'open',
            'reported_by_user_id'=>auth()->id(), 'resolved_by_user_id'=>null, 'resolved_at'=>null,
            'created_at'=>now(), 'updated_at'=>now(),
        ]);
        return redirect()->route('seatcore::market.index',['tab'=>'shame-list'])->with('success','Shame list entry added.');
    }

    public function resolveShame(int $id): RedirectResponse
    {
        $this->authorizeMarket();
        $n = DB::table('mkt_shame_entries')->where('id',$id)->where('status','open')->update([
            'status'=>'resolved','resolved_by_user_id'=>auth()->id(),'resolved_at'=>now(),'updated_at'=>now(),
        ]);
        abort_unless($n,404);
        return redirect()->route('seatcore::market.index',['tab'=>'shame-list'])->with('success','Entry resolved.');
    }
}
