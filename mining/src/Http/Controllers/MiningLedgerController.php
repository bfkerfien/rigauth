<?php

namespace Seat\Web\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MiningLedgerController extends Controller
{
    private const JITA_STATION_ID = 60003760;
    private const THE_FORGE_REGION_ID = 10000002;

    private function canViewCorporation(): bool
    {
        $user = auth()->user();

        return $user && ($user->can('global.superuser') || $user->can('mining.corporation.view'));
    }

    private function ownedCharacters()
    {
        return auth()->user()->characters ?? collect();
    }

    private function ownedCharacterIds(): array
    {
        return $this->ownedCharacters()
            ->map(fn ($character) => (int) ($character->character_id ?? $character->getKey()))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function visibleCorporationIds(): array
    {
        return $this->ownedCharacters()
            ->map(fn ($character) => (int) ($character->corporation_id ?? 0))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function visibleOperationQuery()
    {
        $query = DB::table('mining_operations');
        if ($this->canViewCorporation()) {
            $corporationIds = $this->visibleCorporationIds();
            $query->where(function ($scope) use ($corporationIds) {
                if ($corporationIds) {
                    $scope->whereIn('corporation_id', $corporationIds);
                }
                $scope->orWhere('created_by_user_id', auth()->id());
            });
        } else {
            $ids = DB::table('mining_operation_members')
                ->whereIn('character_id', $this->ownedCharacterIds())
                ->pluck('operation_id')
                ->all();
            $query->where(function ($scope) use ($ids) {
                $scope->whereIn('id', $ids)->orWhere('created_by_user_id', auth()->id());
            });
        }

        return $query;
    }

    private function visibleYieldQuery()
    {
        $query = DB::table('mining_yields');
        if ($this->canViewCorporation()) {
            $corporationIds = $this->visibleCorporationIds();
            $query->where(function ($scope) use ($corporationIds) {
                if ($corporationIds) {
                    $scope->whereIn('corporation_id', $corporationIds);
                }
                $scope->orWhere('created_by_user_id', auth()->id());
            });
        } else {
            $query->whereIn('character_id', $this->ownedCharacterIds());
        }

        return $query;
    }

    public function index(): View
    {
        abort_unless(auth()->check(), 401);

        $operations = $this->visibleOperationQuery()->orderByDesc('started_at')->orderByDesc('id')->limit(100)->get();
        $yields = $this->visibleYieldQuery()->orderByDesc('recorded_at')->limit(100)->get();
        $characters = $this->ownedCharacters()->map(function ($character) {
            return [
                'id' => (int) ($character->character_id ?? $character->getKey()),
                'name' => (string) ($character->name ?? $character->character_name ?? 'Unknown character'),
            ];
        })->values();

        $summaryQuery = $this->visibleYieldQuery();
        $summary = [
            'rows' => (clone $summaryQuery)->count(),
            'quantity' => (clone $summaryQuery)->sum('quantity'),
            'value' => (clone $summaryQuery)->sum('estimated_value_isk'),
        ];

        $canViewCorporation = $this->canViewCorporation();
        $corporationIds = $this->visibleCorporationIds();
        $timerQuery = DB::table('mining_timers')->where(function ($scope) use ($corporationIds) {
            if ($corporationIds) $scope->whereIn('corporation_id', $corporationIds);
            $scope->orWhere('created_by_user_id', auth()->id());
        });
        $miningTimers = $timerQuery->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")->orderBy('expires_at')->limit(200)->get();
        $anomalyQuery = DB::table('mining_anomalies')->where(function ($scope) use ($corporationIds) {
            if ($corporationIds) $scope->whereIn('corporation_id', $corporationIds);
            $scope->orWhere('created_by_user_id', auth()->id());
        });
        $miningAnomalies = $anomalyQuery->orderByDesc('last_seen_at')->limit(200)->get();

        return view('web::mining.index', compact(
            'operations', 'yields', 'characters', 'summary', 'canViewCorporation',
            'miningTimers', 'miningAnomalies'
        ));
    }

    public function storeOperation(Request $request)
    {
        abort_unless(auth()->check(), 401);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'system_name' => ['required', 'string', 'max:120'],
            'location_name' => ['nullable', 'string', 'max:190'],
            'started_at' => ['nullable', 'date'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'character_ids' => ['nullable', 'array'],
            'character_ids.*' => ['integer'],
        ]);

        $owned = $this->ownedCharacters()->keyBy(fn ($character) => (int) ($character->character_id ?? $character->getKey()));
        $selectedIds = collect($data['character_ids'] ?? [])->map(fn ($id) => (int) $id)->unique();
        foreach ($selectedIds as $id) {
            abort_unless($owned->has($id), 403, 'You can only assign characters linked to your SeAT account.');
        }

        $selectedCorporations = $selectedIds->map(function ($characterId) use ($owned) {
            return (int) ($owned->get($characterId)->corporation_id ?? 0);
        })->filter()->unique()->values();
        abort_if($selectedCorporations->count() > 1, 422, 'A mining operation must belong to one corporation. Create separate operations for different corporations.');
        $operationCorporationId = $selectedCorporations->first() ?: ($this->visibleCorporationIds()[0] ?? null);

        $id = DB::table('mining_operations')->insertGetId([
            'corporation_id' => $operationCorporationId,
            'name' => $data['name'],
            'system_name' => $data['system_name'],
            'location_name' => $data['location_name'] ?? null,
            'started_at' => $data['started_at'] ?? now(),
            'ended_at' => $data['ended_at'] ?? null,
            'status' => 'open',
            'created_by_user_id' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($selectedIds as $characterId) {
            $character = $owned->get($characterId);
            $characterName = $character
                ? (string) ($character->name ?? $character->character_name ?? 'Unknown character')
                : 'Corporation-assigned character';

            DB::table('mining_operation_members')->insertOrIgnore([
                'operation_id' => $id,
                'character_id' => $characterId,
                'character_name' => $characterName,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->audit('operation.created', 'operation', $id, [
            'name' => $data['name'],
            'system_name' => $data['system_name'],
        ]);

        return redirect()->route('seatcore::mining.index')->with('success', 'Mining operation created.');
    }

    public function storeYield(Request $request)
    {
        abort_unless(auth()->check(), 401);
        $data = $request->validate([
            'operation_id' => ['nullable', 'integer'],
            'character_id' => ['required', 'integer'],
            'type_id' => ['required', 'integer', 'min:1'],
            'type_name' => ['nullable', 'string', 'max:190'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'recorded_at' => ['required', 'date'],
            'source_reference' => ['nullable', 'string', 'max:255'],
        ]);

        $character = $this->ownedCharacters()->first(function ($candidate) use ($data) {
            return (int) ($candidate->character_id ?? $candidate->getKey()) === (int) $data['character_id'];
        });
        abort_unless($character, 403, 'You can only enter yields for characters linked to your SeAT account.');

        $operation = null;
        if (!empty($data['operation_id'])) {
            $operation = $this->visibleOperationQuery()->where('id', $data['operation_id'])->first();
            abort_unless($operation, 404, 'Operation not found or not visible to you.');
        }

        $type = null;
        if (Schema::hasTable('invTypes')) {
            $type = DB::table('invTypes')->where('typeID', $data['type_id'])->first();
            abort_unless($type, 422, 'The type ID was not found in SeAT inventory type data.');
        }
        $typeName = (string) ($type->typeName ?? $data['type_name'] ?? ('Type ID ' . $data['type_id']));

        $price = $this->jitaSellPrice((int) $data['type_id']);
        $quantity = (int) $data['quantity'];
        $yieldId = DB::table('mining_yields')->insertGetId([
            'operation_id' => $operation->id ?? null,
            'corporation_id' => $operation->corporation_id ?? ($character->corporation_id ?? null),
            'character_id' => (int) $data['character_id'],
            'character_name' => (string) ($character->name ?? $character->character_name ?? 'Character ' . $data['character_id']),
            'type_id' => (int) $data['type_id'],
            'type_name' => $typeName,
            'quantity' => $quantity,
            'source_method' => 'manual',
            'source_reference' => $data['source_reference'] ?? null,
            'import_id' => null,
            'recorded_at' => $data['recorded_at'],
            'unit_price_isk' => $price,
            'estimated_value_isk' => $price === null ? null : $price * $quantity,
            'verification_status' => 'reported_manual',
            'created_by_user_id' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit('yield.created', 'yield', $yieldId, [
            'source_method' => 'manual',
            'character_id' => (int) $data['character_id'],
            'type_id' => (int) $data['type_id'],
            'quantity' => $quantity,
        ]);

        return redirect()->route('seatcore::mining.index')->with('success', 'Yield recorded. It is labelled as manually reported, not ESI-verified.');
    }

    public function importCsv(Request $request)
    {
        abort_unless(auth()->check(), 401);
        $request->validate(['csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        $file = $request->file('csv_file');
        $hash = hash_file('sha256', $file->getRealPath());
        if (DB::table('mining_imports')->where('sha256', $hash)->exists()) {
            return redirect()->route('seatcore::mining.index')->withErrors([
                'csv_file' => 'This exact file was already imported. Duplicate upload rejected.',
            ]);
        }

        $handle = fopen($file->getRealPath(), 'rb');
        if (!$handle) {
            return back()->withErrors(['csv_file' => 'Could not read the uploaded file.']);
        }

        $header = fgetcsv($handle);
        $required = ['character_id', 'type_id', 'quantity', 'recorded_at', 'operation_id', 'source_reference'];
        if (!$header || array_diff(['character_id', 'type_id', 'quantity', 'recorded_at'], $header)) {
            fclose($handle);
            return back()->withErrors(['csv_file' => 'CSV must include character_id,type_id,quantity,recorded_at columns.']);
        }

        $columns = array_flip($header);
        $ownedIds = $this->ownedCharacterIds();
        $importId = DB::table('mining_imports')->insertGetId([
            'filename' => Str::limit(basename($file->getClientOriginalName()), 255, ''),
            'sha256' => $hash,
            'uploaded_by_user_id' => auth()->id(),
            'rows_imported' => 0,
            'status' => 'processing',
            'error_summary' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $count = 0;
        $errors = [];
        $line = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            if (count(array_filter($row, fn ($value) => $value !== null && trim((string) $value) !== '')) === 0) {
                continue;
            }
            $value = fn ($key) => isset($columns[$key]) ? trim((string) ($row[$columns[$key]] ?? '')) : '';
            $characterId = (int) $value('character_id');
            $typeId = (int) $value('type_id');
            $quantity = filter_var($value('quantity'), FILTER_VALIDATE_INT);
            $recordedAt = $value('recorded_at');
            if (!$characterId || !$typeId || !$quantity || $quantity < 1 || !$recordedAt || !in_array($characterId, $ownedIds, true)) {
                $errors[] = "Line {$line}: invalid values or character outside your access scope.";
                continue;
            }
            try {
                $recordedAt = \Illuminate\Support\Carbon::parse($recordedAt)->toDateTimeString();
            } catch (\Throwable $e) {
                $errors[] = "Line {$line}: invalid recorded_at date.";
                continue;
            }

            $operationId = $value('operation_id') !== '' ? (int) $value('operation_id') : null;
            $operation = null;
            if ($operationId) {
                $operation = $this->visibleOperationQuery()->where('id', $operationId)->first();
                if (!$operation) {
                    $errors[] = "Line {$line}: operation is not visible or does not exist.";
                    continue;
                }
            }

            $type = Schema::hasTable('invTypes')
                ? DB::table('invTypes')->where('typeID', $typeId)->first()
                : null;
            if (Schema::hasTable('invTypes') && !$type) {
                $errors[] = "Line {$line}: type_id {$typeId} is not in SeAT inventory type data.";
                continue;
            }

            $character = $this->ownedCharacters()->first(function ($candidate) use ($characterId) {
                return (int) ($candidate->character_id ?? $candidate->getKey()) === $characterId;
            });
            $typeName = (string) ($type->typeName ?? ('Type ID ' . $typeId));
            $price = $this->jitaSellPrice($typeId);
            DB::table('mining_yields')->insert([
                'operation_id' => $operationId,
                'corporation_id' => $operation->corporation_id ?? ($character->corporation_id ?? null),
                'character_id' => $characterId,
                'character_name' => (string) ($character->name ?? $character->character_name ?? 'Character ' . $characterId),
                'type_id' => $typeId,
                'type_name' => $typeName,
                'quantity' => $quantity,
                'source_method' => 'import',
                'source_reference' => $value('source_reference') ?: 'CSV import ' . $file->getClientOriginalName(),
                'import_id' => $importId,
                'recorded_at' => $recordedAt,
                'unit_price_isk' => $price,
                'estimated_value_isk' => $price === null ? null : $price * $quantity,
                'verification_status' => 'reported_import',
                'created_by_user_id' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $count++;
        }
        fclose($handle);

        DB::table('mining_imports')->where('id', $importId)->update([
            'rows_imported' => $count,
            'status' => count($errors) ? ($count ? 'partial' : 'failed') : 'completed',
            'error_summary' => $errors ? Str::limit(implode("\n", $errors), 60000, '') : null,
            'updated_at' => now(),
        ]);
        $this->audit('import.processed', 'import', $importId, ['rows_imported' => $count, 'rejected_rows' => count($errors)]);

        $message = "Imported {$count} reported yield row(s). Imported records are not ESI-verified.";
        if ($errors) {
            $message .= ' ' . count($errors) . ' row(s) were rejected; review the import record and correct the source file before retrying.';
        }

        return redirect()->route('seatcore::mining.index')->with(count($errors) ? 'warning' : 'success', $message);
    }


    public function importAnomalies(Request $request)
    {
        abort_unless(auth()->check(), 401);
        $data = $request->validate([
            'system_name' => ['required', 'string', 'max:120'],
            'scan_paste' => ['required', 'string', 'max:30000'],
        ]);
        $lines = preg_split('/\r\n|\r|\n/', trim($data['scan_paste']));
        $seen = [];
        $inserted = 0;
        $skipped = 0;
        $corporationId = $this->visibleCorporationIds()[0] ?? null;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $columns = array_map('trim', preg_split('/\t+/', $line));
            $candidate = trim($columns[0] ?? '');
            if ($candidate === '' || preg_match('/^(name|type|group|signal strength|distance|solar system)$/i', $candidate)) {
                $skipped++;
                continue;
            }
            $key = mb_strtolower($data['system_name'] . '|' . $candidate);
            if (isset($seen[$key])) { $skipped++; continue; }
            $seen[$key] = true;
            $existing = DB::table('mining_anomalies')->where('system_name', $data['system_name'])
                ->where('anomaly_name', $candidate)->where('status', 'present')->first();
            if ($existing) {
                DB::table('mining_anomalies')->where('id', $existing->id)->update([
                    'last_seen_at' => now(), 'scan_text' => mb_substr($line, 0, 1000), 'updated_at' => now(),
                ]);
                $skipped++;
                continue;
            }
            DB::table('mining_anomalies')->insert([
                'corporation_id' => $corporationId, 'system_name' => $data['system_name'],
                'anomaly_name' => mb_substr($candidate, 0, 190), 'scan_text' => mb_substr($line, 0, 1000),
                'status' => 'present', 'first_seen_at' => now(), 'last_seen_at' => now(),
                'created_by_user_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $inserted++;
        }
        $this->audit('anomalies.pasted', 'anomaly_scan', null, [
            'system_name' => $data['system_name'], 'inserted' => $inserted, 'skipped' => $skipped,
        ]);
        return redirect()->route('seatcore::mining.index')
            ->with('success', "Scan paste processed: {$inserted} new anomaly row(s), {$skipped} skipped or refreshed.");
    }

    public function storeTimer(Request $request)
    {
        abort_unless(auth()->check(), 401);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'system_name' => ['required', 'string', 'max:120'],
            'anomaly_name' => ['nullable', 'string', 'max:190'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'started_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $startedAt = \Illuminate\Support\Carbon::parse($data['started_at']);
        $expiresAt = $startedAt->copy()->addMinutes((int) $data['duration_minutes']);
        $id = DB::table('mining_timers')->insertGetId([
            'corporation_id' => $this->visibleCorporationIds()[0] ?? null,
            'title' => $data['title'], 'system_name' => $data['system_name'],
            'anomaly_name' => $data['anomaly_name'] ?? null,
            'started_at' => $startedAt->toDateTimeString(), 'expires_at' => $expiresAt->toDateTimeString(),
            'status' => 'active', 'notes' => $data['notes'] ?? null, 'created_by_user_id' => auth()->id(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit('timer.created', 'timer', $id, ['system_name' => $data['system_name'], 'expires_at' => $expiresAt->toDateTimeString()]);
        return redirect()->route('seatcore::mining.index')->with('success', 'Mining timer added to the board.');
    }

    public function updateAnomalyStatus(Request $request, int $id)
    {
        abort_unless(auth()->check(), 401);
        $data = $request->validate(['status' => ['required', 'in:present,mined,despawned']]);
        $query = DB::table('mining_anomalies')->where('id', $id)->where(function ($scope) {
            $corporationIds = $this->visibleCorporationIds();
            if ($corporationIds) $scope->whereIn('corporation_id', $corporationIds);
            $scope->orWhere('created_by_user_id', auth()->id());
        });
        abort_unless($query->exists(), 404);
        $query->update(['status' => $data['status'], 'updated_at' => now()]);
        $this->audit('anomaly.status_changed', 'anomaly', $id, ['status' => $data['status']]);
        return redirect()->route('seatcore::mining.index')->with('success', 'Anomaly status updated.');
    }

    private function jitaSellPrice(int $typeId): ?float
    {
        $cached = DB::table('mining_prices')->where('type_id', $typeId)->first();
        if ($cached && $cached->retrieved_at && \Illuminate\Support\Carbon::parse($cached->retrieved_at)->gt(now()->subMinutes(30))) {
            return $cached->jita_sell_isk === null ? null : (float) $cached->jita_sell_isk;
        }

        try {
            $response = Http::timeout(8)->retry(1, 200)->get(
                'https://esi.evetech.net/latest/markets/' . self::THE_FORGE_REGION_ID . '/orders/',
                ['order_type' => 'sell', 'type_id' => $typeId, 'page' => 1]
            );
            if (!$response->successful()) {
                throw new \RuntimeException('ESI market orders returned HTTP ' . $response->status());
            }

            $orders = collect($response->json() ?: [])
                ->filter(fn ($order) => (int) ($order['location_id'] ?? 0) === self::JITA_STATION_ID)
                ->pluck('price');
            $price = $orders->isEmpty() ? null : (float) $orders->min();

            DB::table('mining_prices')->updateOrInsert(
                ['type_id' => $typeId],
                [
                    'jita_sell_isk' => $price,
                    'retrieved_at' => now(),
                    'source' => 'esi_region_orders',
                    'last_error' => null,
                    'created_at' => $cached->created_at ?? now(),
                    'updated_at' => now(),
                ]
            );

            return $price;
        } catch (\Throwable $e) {
            DB::table('mining_prices')->updateOrInsert(
                ['type_id' => $typeId],
                [
                    'jita_sell_isk' => $cached->jita_sell_isk ?? null,
                    'retrieved_at' => $cached->retrieved_at ?? null,
                    'source' => 'esi_region_orders',
                    'last_error' => Str::limit($e->getMessage(), 1000, ''),
                    'created_at' => $cached->created_at ?? now(),
                    'updated_at' => now(),
                ]
            );

            return $cached && $cached->jita_sell_isk !== null ? (float) $cached->jita_sell_isk : null;
        }
    }

    private function audit(string $action, string $entityType, ?int $entityId, array $details = []): void
    {
        DB::table('mining_audit_logs')->insert([
            'user_id' => auth()->id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => json_encode($details),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
