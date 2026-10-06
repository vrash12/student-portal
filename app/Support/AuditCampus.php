<?php

namespace App\Support;

use App\Models\AccountEntry;
use App\Models\Announcement;
use App\Models\Assessment;
use App\Models\AssessmentCategory;
use App\Models\AssessmentScore;
use App\Models\AttendanceSession;
use App\Models\Candidate;
use App\Models\CandidateMedicalDocument;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\ConductEntry;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\FitnessTest;
use App\Models\GradeCorrectionRequest;
use App\Models\InstructorAssignment;
use App\Models\MedicalAccessRequest;
use App\Models\MedicalDownloadRequest;
use App\Models\NutritionAssessment;
use App\Models\ScheduleEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Which campus an audit entry is about (audit_logs.campus_id), so audit
 * history can be limited to a campus (owner decision 2026-10-03).
 *
 * Records of a campus (classes, candidates, grades, attendance, fitness
 * tests, merits/demerits, medical records, charges, examinations) give their
 * own campus. Shared settings (subjects, academic years, areas, events,
 * campuses...) give none: such entries are institution-wide, unless the
 * actor is limited to a campus (e.g. an instructor writing a question).
 */
final class AuditCampus
{
    public static function resolve(?Model $subject, ?User $actor): ?int
    {
        $campusId = self::of($subject);

        if ($campusId === null && $actor !== null && ! $actor->campusScope()->isInstitutionWide()) {
            return $actor->campusScope()->campusId;
        }

        return $campusId;
    }

    /**
     * Record types that belong to a campus (directly or through their class,
     * class subject, assessment or candidate). Staff accounts count too: a
     * campus-limited administrator only reaches staff of their own campus.
     * Everything else (subjects, academic years, questions, settings) is
     * shared by every campus.
     */
    public const CAMPUS_RECORDS = [
        ClassBatch::class, Candidate::class, ClassSubject::class, InstructorAssignment::class, User::class,
        Assessment::class, AssessmentCategory::class, Examination::class, AssessmentScore::class,
        GradeCorrectionRequest::class, ExaminationAttempt::class, ConductEntry::class, AccountEntry::class,
        CandidateMedicalDocument::class, MedicalDownloadRequest::class, MedicalAccessRequest::class,
        FitnessTest::class, AttendanceSession::class, NutritionAssessment::class, Announcement::class,
        ScheduleEntry::class,
    ];

    public static function isCampusRecord(Model $subject): bool
    {
        return in_array($subject::class, self::CAMPUS_RECORDS, true);
    }

    /** The campus of a record, or null for shared settings and records without one. */
    public static function of(?Model $subject): ?int
    {
        return match (true) {
            $subject === null => null,
            $subject instanceof ClassBatch,
            $subject instanceof Candidate,
            $subject instanceof ClassSubject,
            $subject instanceof InstructorAssignment => self::own($subject),
            $subject instanceof User => self::user($subject),
            $subject instanceof Assessment,
            $subject instanceof AssessmentCategory,
            $subject instanceof Examination => self::offering(self::key($subject, 'class_subject_id')),
            $subject instanceof AssessmentScore,
            $subject instanceof GradeCorrectionRequest => self::assessment(self::key($subject, 'assessment_id')),
            $subject instanceof ExaminationAttempt,
            $subject instanceof ConductEntry,
            $subject instanceof AccountEntry,
            $subject instanceof CandidateMedicalDocument,
            $subject instanceof MedicalDownloadRequest,
            $subject instanceof MedicalAccessRequest,
            $subject instanceof NutritionAssessment => self::candidate(self::key($subject, 'candidate_id')),
            $subject instanceof FitnessTest,
            $subject instanceof AttendanceSession => self::classBatch(self::key($subject, 'class_batch_id')),
            // Notices to every candidate have no campus: only accounts that see every campus reach them by URL.
            $subject instanceof Announcement => self::key($subject, 'campus_id'),
            $subject instanceof ScheduleEntry => self::key($subject, 'campus_id') ?? self::classBatch(self::key($subject, 'class_batch_id')),
            default => null,
        };
    }

    private static function own(Model $subject): ?int
    {
        $campusId = self::key($subject, 'campus_id');

        if ($campusId === null && $subject->exists) {
            $campusId = $subject->newQuery()->whereKey($subject->getKey())->value('campus_id');
        }

        return $campusId === null ? null : (int) $campusId;
    }

    /** Staff accounts carry their campus; a candidate's account follows the candidate record. */
    private static function user(User $user): ?int
    {
        $campusId = self::key($user, 'campus_id');
        if ($campusId !== null) {
            return (int) $campusId;
        }

        $candidateCampus = Candidate::query()->where('user_id', $user->getKey())->value('campus_id');

        return $candidateCampus === null ? null : (int) $candidateCampus;
    }

    private static function offering(?int $classSubjectId): ?int
    {
        return $classSubjectId === null ? null : self::value(ClassSubject::query()->whereKey($classSubjectId)->value('campus_id'));
    }

    private static function assessment(?int $assessmentId): ?int
    {
        if ($assessmentId === null) {
            return null;
        }

        return self::value(
            ClassSubject::query()
                ->whereKey(Assessment::query()->whereKey($assessmentId)->select('class_subject_id'))
                ->value('campus_id'),
        );
    }

    private static function candidate(?int $candidateId): ?int
    {
        return $candidateId === null ? null : self::value(Candidate::query()->whereKey($candidateId)->value('campus_id'));
    }

    private static function classBatch(?int $classBatchId): ?int
    {
        return $classBatchId === null ? null : self::value(ClassBatch::query()->whereKey($classBatchId)->value('campus_id'));
    }

    private static function value(mixed $campusId): ?int
    {
        return $campusId === null ? null : (int) $campusId;
    }

    /** An attribute already on the model, without tripping strict mode for unselected columns. */
    private static function key(Model $subject, string $attribute): ?int
    {
        $value = $subject->getAttributes()[$attribute] ?? null;

        return $value === null ? null : (int) $value;
    }
}
