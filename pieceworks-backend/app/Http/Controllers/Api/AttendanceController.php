<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\BiometricRecord;
use App\Models\Worker;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    /**
     * POST /api/attendance/record
     *
     * Manually record or update a worker's attendance for a given date.
     * A supervisor can mark present/absent/idle/zero_production.
     * Cannot mark present if biometric shows absent (unless source=manual override acknowledged).
     */
    public function record(Request $request): JsonResponse
    {
        $data = $request->validate([
            'worker_id'   => ['required', 'integer', 'exists:workers,id'],
            'work_date'   => ['required', 'date', 'before_or_equal:today'],
            'status'      => ['required', 'in:present,absent,idle,zero_production'],
            'idle_reason' => ['nullable', 'string', 'max:500', 'required_if:status,idle'],
            'source'      => ['nullable', 'in:biometric,manual'],
        ]);

        $source = $data['source'] ?? 'manual';

        // Guard: if marking present manually but biometric shows absent on that day,
        // warn but allow (supervisor override). We flag this inconsistency.
        $biometricConflict = false;
        if ($data['status'] === 'present' && $source === 'manual') {
            $hasBiometricPunch = DB::table('biometric_records')
                ->where('worker_id', $data['worker_id'])
                ->whereDate('punch_time', $data['work_date'])
                ->exists();

            if (!$hasBiometricPunch) {
                $biometricConflict = true;
                // We allow it but note the conflict in the response
            }
        }

        // Upsert: one attendance record per worker per day
        $attendance = AttendanceRecord::updateOrCreate(
            [
                'worker_id' => $data['worker_id'],
                'work_date' => $data['work_date'],
            ],
            [
                'status'      => $data['status'],
                'idle_reason' => $data['idle_reason'] ?? null,
                'recorded_by' => $request->user()?->id,
                'source'      => $source,
            ]
        );

        return $this->success(
            array_merge($attendance->toArray(), [
                'biometric_conflict' => $biometricConflict,
            ]),
            $biometricConflict
                ? 'Attendance recorded. WARNING: no biometric punch found for this date.'
                : 'Attendance recorded.'
        );
    }

    /**
     * GET /api/attendance/daily?date=&line_id=
     *
     * Returns all workers on the given line with their attendance status.
     * Ghost-flags workers who have production entries but no attendance record.
     */
    public function daily(Request $request): JsonResponse
    {
        $request->validate([
            'date'    => ['required', 'date'],
            'line_id' => ['nullable', 'integer', 'exists:lines,id'],
        ]);

        $date = Carbon::parse($request->date)->toDateString();

        // Load workers (scoped by line if provided)
        $workerQuery = Worker::query()->where('status', 'active');
        if ($request->line_id) {
            $workerQuery->where('default_line_id', $request->line_id);
        }
        $workers = $workerQuery->get(['id', 'name', 'grade', 'biometric_id', 'default_line_id']);

        $workerIds = $workers->pluck('id');

        // Attendance records for the day
        $attendance = AttendanceRecord::whereIn('worker_id', $workerIds)
            ->where('work_date', $date)
            ->get()
            ->keyBy('worker_id');

        // Production totals for the day
        $production = DB::table('production_records')
            ->whereIn('worker_id', $workerIds)
            ->whereDate('work_date', $date)
            ->whereNotIn('validation_status', ['rejected'])
            ->select('worker_id', DB::raw('SUM(pairs_produced) as total_pairs'), DB::raw('COUNT(*) as record_count'))
            ->groupBy('worker_id')
            ->get()
            ->keyBy('worker_id');

        // Biometric punches for the day
        $biometric = DB::table('biometric_records')
            ->whereIn('worker_id', $workerIds)
            ->whereDate('punch_time', $date)
            ->select('worker_id', 'punch_type', DB::raw('MIN(punch_time) as first_punch'), DB::raw('MAX(punch_time) as last_punch'))
            ->groupBy('worker_id', 'punch_type')
            ->get()
            ->groupBy('worker_id');

        $ghostCount = 0;
        $rows = $workers->map(function (Worker $worker) use ($attendance, $production, $biometric, &$ghostCount) {
            $att   = $attendance->get($worker->id);
            $prod  = $production->get($worker->id);
            $bio   = $biometric->get($worker->id);

            $hasProd       = $prod && $prod->total_pairs > 0;
            $hasAttendance = $att !== null;
            $hasBiometric  = $bio !== null;

            // Ghost flag: production entered but no attendance AND no biometric punch
            $ghostFlag = $hasProd && !$hasAttendance && !$hasBiometric;
            if ($ghostFlag) {
                $ghostCount++;
            }

            $bioIn  = $bio?->firstWhere('punch_type', 'in');
            $bioOut = $bio?->firstWhere('punch_type', 'out');

            return [
                'worker_id'         => $worker->id,
                'worker_name'       => $worker->name,
                'grade'             => $worker->grade,
                'biometric_id'      => $worker->biometric_id,
                'attendance_status' => $att?->status ?? 'not_recorded',
                'idle_reason'       => $att?->idle_reason,
                'attendance_source' => $att?->source,
                'biometric_punch_in'  => $bioIn?->first_punch,
                'biometric_punch_out' => $bioOut?->last_punch,
                'production_pairs'    => $prod?->total_pairs ?? 0,
                'production_records'  => $prod?->record_count ?? 0,
                'ghost_flag'          => $ghostFlag,
            ];
        });

        return $this->success([
            'date'        => $date,
            'line_id'     => $request->line_id,
            'total'       => $rows->count(),
            'present'     => $rows->where('attendance_status', 'present')->count(),
            'absent'      => $rows->where('attendance_status', 'absent')->count(),
            'idle'        => $rows->where('attendance_status', 'idle')->count(),
            'not_recorded'=> $rows->where('attendance_status', 'not_recorded')->count(),
            'ghost_flags' => $ghostCount,
            'workers'     => $rows->values(),
        ], 'Daily attendance retrieved.');
    }

    /**
     * POST /api/attendance/biometric-sync
     *
     * Bulk-receive punch records from TimeBridge middleware.
     * Each record: { biometric_id, device_id, punch_time, punch_type }
     * Matches biometric_id → workers.biometric_id → worker_id.
     * Inserts into biometric_records; updates attendance_records punch timestamps.
     */
    public function biometricSync(Request $request): JsonResponse
    {
        $request->validate([
            'punches'              => ['required', 'array', 'min:1', 'max:500'],
            'punches.*.biometric_id' => ['required', 'string'],
            'punches.*.device_id'    => ['required', 'string'],
            'punches.*.punch_time'   => ['required', 'date'],
            'punches.*.punch_type'   => ['required', 'in:in,out'],
        ]);

        // Build biometric_id → worker_id map for all incoming IDs in one query
        $biometricIds = collect($request->punches)->pluck('biometric_id')->unique()->values();
        $workerMap = DB::table('workers')
            ->whereIn('biometric_id', $biometricIds)
            ->pluck('id', 'biometric_id');

        $inserted  = 0;
        $skipped   = 0;
        $unmatched = [];
        $attendanceUpdates = []; // workerId → [date => [in => min_punch, out => max_punch]]

        foreach ($request->punches as $punch) {
            $workerId = $workerMap[$punch['biometric_id']] ?? null;

            if (!$workerId) {
                $unmatched[] = $punch['biometric_id'];
                $skipped++;
                continue;
            }

            $punchTime = Carbon::parse($punch['punch_time']);
            $dateKey   = $punchTime->toDateString();

            // Deduplicate: skip if exact punch already exists
            $exists = DB::table('biometric_records')
                ->where('worker_id', $workerId)
                ->where('punch_time', $punchTime)
                ->where('punch_type', $punch['punch_type'])
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            BiometricRecord::create([
                'worker_id'             => $workerId,
                'device_id'             => $punch['device_id'],
                'punch_time'            => $punchTime,
                'punch_type'            => $punch['punch_type'],
                'synced_from_timbridge' => true,
            ]);
            $inserted++;

            // Collect for attendance upsert
            if (!isset($attendanceUpdates[$workerId][$dateKey])) {
                $attendanceUpdates[$workerId][$dateKey] = ['in' => null, 'out' => null];
            }
            if ($punch['punch_type'] === 'in') {
                $cur = $attendanceUpdates[$workerId][$dateKey]['in'];
                if (!$cur || $punchTime->lt(Carbon::parse($cur))) {
                    $attendanceUpdates[$workerId][$dateKey]['in'] = $punchTime->toDateTimeString();
                }
            } else {
                $cur = $attendanceUpdates[$workerId][$dateKey]['out'];
                if (!$cur || $punchTime->gt(Carbon::parse($cur))) {
                    $attendanceUpdates[$workerId][$dateKey]['out'] = $punchTime->toDateTimeString();
                }
            }
        }

        // Upsert attendance records with biometric punch times
        foreach ($attendanceUpdates as $workerId => $dates) {
            foreach ($dates as $date => $punches) {
                AttendanceRecord::updateOrCreate(
                    ['worker_id' => $workerId, 'work_date' => $date],
                    [
                        'status'              => 'present',
                        'source'              => 'biometric',
                        'biometric_punch_in'  => $punches['in'],
                        'biometric_punch_out' => $punches['out'],
                    ]
                );
            }
        }

        return $this->success([
            'received'  => count($request->punches),
            'inserted'  => $inserted,
            'skipped'   => $skipped,
            'unmatched_biometric_ids' => array_values(array_unique($unmatched)),
        ], 'Biometric sync complete.');
    }
}
