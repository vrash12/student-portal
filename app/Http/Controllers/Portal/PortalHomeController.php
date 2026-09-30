<?php

namespace App\Http\Controllers\Portal;

use App\Enums\ExaminationStatus;
use App\Http\Controllers\Controller;
use App\Models\Examination;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Candidate examination portal home. Available examinations are added in
 * Milestone 9; until then the page shows its empty state.
 */
class PortalHomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $candidate = $request->user()->candidate;
        $examinations = $candidate === null || $candidate->status->value === 'withdrawn' || $candidate->class_batch_id === null ? collect() : Examination::with('classSubject.subject')
            ->where('status', ExaminationStatus::Published)
            ->whereHas('classSubject', fn ($q) => $q->where('class_batch_id', $candidate->class_batch_id))
            ->where(fn ($q) => $q->whereNull('opens_at')->orWhere('opens_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>', now()))
            ->orderBy('opens_at')->get()->map(fn (Examination $exam) => [
                'id' => $exam->id, 'title' => $exam->title,
                'subject' => $exam->classSubject->subject->name,
                'durationMinutes' => $exam->duration_minutes,
                'closesAt' => $exam->closes_at?->toIso8601String(),
            ])->values();

        return Inertia::render('portal/home', ['examinations' => $examinations]);
    }
}
