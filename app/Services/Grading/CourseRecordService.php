<?php

namespace App\Services\Grading;

use App\Enums\GradeStatus;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use Illuminate\Database\Eloquent\Collection;

/**
 * Phase averages and the CGPA (owner request, 2026-10-03). A class keeps
 * its candidates for the whole course; each of its subjects belongs to a
 * training phase (or none) and carries units.
 *
 * - Subject grade: GradeCalculationService (unchanged).
 * - Phase average: the unit-weighted average of the phase's subject grades
 *   (GradeCalculationService::weightedAverage); subjects without a grade
 *   are left out, never counted as zero.
 * - CGPA (Cumulative General Point Average): the same average over every
 *   subject of the class so far, whatever its phase. It is on the same
 *   0–100 scale as the subject grades.
 *
 * A phase (or the CGPA) is complete once every one of its subjects has a
 * final grade; until then it is a current result. Nothing is stored: like
 * every grade, these are calculated when read.
 *
 * Every subject of the class is included, so a course record is shown only
 * to viewers who may see all of a candidate's subjects (never to an
 * instructor limited to the subjects they teach).
 */
final class CourseRecordService
{
    public function __construct(private readonly GradeCalculationService $grades) {}

    /**
     * The candidate's course record in their current class; null without one.
     */
    public function forCandidate(Candidate $candidate): ?CourseRecord
    {
        if ($candidate->class_batch_id === null) {
            return null;
        }

        $offerings = $this->offerings((int) $candidate->class_batch_id);

        return $this->record($offerings, $this->grades->forCandidate($candidate, $offerings->modelKeys()));
    }

    /**
     * Course records of candidates of one class, in a fixed number of
     * queries however many there are.
     *
     * @param  Collection<int, Candidate>  $candidates  assigned to the class
     * @return array<int, CourseRecord> keyed by candidate id
     */
    public function forClass(ClassBatch $class, Collection $candidates): array
    {
        $offerings = $this->offerings($class->id);
        // Every offering belongs to this class; share it (and its period) rather than loading it per offering.
        $class->loadMissing('academicPeriod');
        $offerings->each(fn (ClassSubject $offering) => $offering->setRelation('classBatch', $class));

        $grades = $offerings->isEmpty() ? [] : $this->grades->forClasses($offerings, $candidates);

        $records = [];
        foreach ($candidates as $candidate) {
            $records[$candidate->id] = $this->record($offerings, $grades[$candidate->id] ?? []);
        }

        return $records;
    }

    /**
     * Groups the class's subjects by phase and averages them. No database
     * access.
     *
     * @param  Collection<int, ClassSubject>  $offerings  with subject and trainingPhase, in phase order
     * @param  array<int, SubjectGrade>  $gradesByOffering  keyed by class subject id; a missing key means no grade
     */
    public function record(Collection $offerings, array $gradesByOffering): CourseRecord
    {
        $groups = [];
        foreach ($offerings as $offering) {
            $key = $offering->training_phase_id ?? 0;
            $grade = $gradesByOffering[$offering->id] ?? null;
            $groups[$key]['phase'] = $offering->trainingPhase?->toSummary();
            $groups[$key]['subjects'][] = [
                'classSubjectId' => $offering->id,
                'name' => $offering->subject->name,
                'units' => (string) $offering->units,
                'grade' => $grade?->grade,
                'complete' => $grade?->status() === GradeStatus::Complete,
            ];
        }

        $phases = array_map(fn (array $group): PhaseAverage => new PhaseAverage(
            $group['phase'],
            $group['subjects'],
            $this->grades->weightedAverage($group['subjects']),
        ), array_values($groups));

        $all = array_merge(...array_map(fn (array $group): array => $group['subjects'], array_values($groups)));

        return new CourseRecord($phases, $this->grades->weightedAverage($all));
    }

    /**
     * The subjects of a class in phase order (subjects not in a phase last),
     * then by subject name.
     *
     * @return Collection<int, ClassSubject>
     */
    private function offerings(int $classBatchId): Collection
    {
        return ClassSubject::query()
            ->where('class_batch_id', $classBatchId)
            ->with(['subject:id,code,name', 'trainingPhase:id,number,name,starts_on,ends_on'])
            ->get()
            ->sortBy(fn (ClassSubject $offering): array => [$offering->trainingPhase?->number ?? PHP_INT_MAX, $offering->subject->name, $offering->id])
            ->values();
    }
}
