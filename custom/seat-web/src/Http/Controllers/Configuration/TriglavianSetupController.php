<?php

namespace Seat\Web\Http\Controllers\Configuration;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Seat\Web\Http\Controllers\Controller;

class TriglavianSetupController extends Controller
{
    public function index()
    {
        $stations = DB::table('tc_t2_stations')->orderBy('station_name')->get();
        $fittings = DB::table('tc_fittings')->orderBy('name')->get();

        return view('web::configuration.triglavian.index', compact('stations', 'fittings'));
    }

    public function saveStation(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|integer',
            'station_name' => 'required|string|max:255',
            'solar_system_name' => 'required|string|max:255',
            'eve_station_id' => 'nullable|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        $id = $data['id'] ?? null;
        unset($data['id']);
        $data['eve_station_id'] = $data['eve_station_id'] ?: null;
        $data['updated_at'] = now();

        if ($id) {
            DB::table('tc_t2_stations')->where('id', $id)->update($data);
            $message = 'T2 station updated.';
        } else {
            $data['created_at'] = now();
            DB::table('tc_t2_stations')->insert($data);
            $message = 'T2 station added.';
        }

        return redirect()->route('seatcore::configuration.triglavian.index', ['tab' => 'stations'])
            ->with('success', $message);
    }

    public function deleteStation($id)
    {
        DB::table('tc_t2_stations')->where('id', $id)->delete();

        return redirect()->route('seatcore::configuration.triglavian.index', ['tab' => 'stations'])
            ->with('success', 'T2 station removed.');
    }

    public function saveFitting(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|integer',
            'name' => 'required|string|max:255',
            'hull' => 'required|string|max:255',
            'category' => 'required|in:hulls,modules,rigs,consumables,other',
            'fitting_text' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $id = $data['id'] ?? null;
        unset($data['id']);
        $data['updated_at'] = now();

        if ($id) {
            DB::table('tc_fittings')->where('id', $id)->update($data);
            $message = 'Fitting updated.';
        } else {
            $data['created_at'] = now();
            DB::table('tc_fittings')->insert($data);
            $message = 'Fitting added.';
        }

        return redirect()->route('seatcore::configuration.triglavian.index', ['tab' => 'fittings'])
            ->with('success', $message);
    }

    public function deleteFitting($id)
    {
        DB::table('tc_fittings')->where('id', $id)->delete();

        return redirect()->route('seatcore::configuration.triglavian.index', ['tab' => 'fittings'])
            ->with('success', 'Fitting removed.');
    }
}
