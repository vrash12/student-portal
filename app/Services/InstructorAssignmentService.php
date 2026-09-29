<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\ClassSubject;
use App\Models\InstructorAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class InstructorAssignmentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Eligibility is validated in the Form Request; it is checked again here
     * because assignments control instructor access to candidate records.
     */
    public function assign(ClassSubject $offering, User $instructor): InstructorAssignment
    {
        if (! $instructor->canTeach()) {
            throw new InvalidArgumentException('The selected account cannot be assigned to teach.');
        }

        return DB::transaction(function () use ($offering, $instructor): InstructorAssignment {
            $assignment = new InstructorAssignment;
            $assignment->classSubject()->associate($offering);
            $assignment->instructor()->associate($instructor);
            $assignment->save();

            $offering->loadMissing(['classBatch', 'subject']);

            $this->audit->record(AuditAction::InstructorAssigned, $assignment, newValues: $this->snapshot($assignment));

            return $assignment;
        });
    }

    public function unassign(InstructorAssignment $assignment): void
    {
        DB::transaction(function () use ($assignment): void {
            $assignment->loadMissing(['instructor', 'classSubject.classBatch', 'classSubject.subject']);

            $this->audit->record(AuditAction::InstructorUnassigned, $assignment, oldValues: $this->snapshot($assignment));

            $assignment->delete();
        });
    }

    /**
     * @return array{instructor: string, class: string, subject: string}
     */
    private function snapshot(InstructorAssignment $assignment): array
    {
        $assignment->loadMissing(['instructor', 'classSubject.classBatch', 'classSubject.subject']);

        return [
            'instructor' => $assignment->instructor->name,
            'class' => $assignment->classSubject->classBatch->name,
            'subject' => $assignment->classSubject->subject->name,
        ];
    }
}
