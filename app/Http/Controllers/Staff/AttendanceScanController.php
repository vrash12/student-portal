<?php

namespace App\Http\Controllers\Staff;

use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Models\AttendanceSession;
use App\Services\Attendance\AttendanceLedger;
use App\Services\Attendance\QrCheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * One QR code read by the instructor's camera on a session's scanner
 * (owner request, 2026-10-02). Answers in JSON so the scanner keeps running:
 * what happened, the candidate to check against their face, and the
 * session's new counts. Authorization is on the route (manage the session).
 */
class AttendanceScanController extends Controller
{
    public function __construct(
        private readonly QrCheckInService $checkIns,
        private readonly AttendanceLedger $ledger,
    ) {}

    public function __invoke(Request $request, AttendanceSession $attendanceSession): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:500'],
            'status' => ['required', Rule::in([AttendanceStatus::Present->value, AttendanceStatus::Late->value])],
        ]);

        $outcome = $this->checkIns->checkIn($attendanceSession, $data['code'], AttendanceStatus::from($data['status']), $request->user());
        $candidate = $outcome['candidate'];

        return response()->json([
            'result' => $outcome['result'],
            'message' => $outcome['message'],
            'candidate' => $candidate === null ? null : [
                'name' => $candidate->full_name,
                'candidateNumber' => $candidate->candidate_number,
                'photoUrl' => $candidate->profile_photo_path === null ? null : route('candidates.photo', $candidate),
                'status' => $outcome['record']?->status->toArray(),
            ],
            'counts' => $this->ledger->rollCall($attendanceSession)['counts'],
        ]);
    }
}
