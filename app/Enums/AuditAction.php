<?php

namespace App\Enums;

/**
 * Actions recorded in the audit log.
 */
enum AuditAction: string
{
    case Login = 'auth.login';
    case LoginFailed = 'auth.login_failed';
    case Logout = 'auth.logout';
    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserRoleChanged = 'user.role_changed';
    case UserDeactivated = 'user.deactivated';
    case UserReactivated = 'user.reactivated';
    case UserPasswordReset = 'user.password_reset';
    case OwnPasswordChanged = 'account.password_changed';
    case AcademicPeriodCreated = 'academic_period.created';
    case AcademicPeriodUpdated = 'academic_period.updated';
    case AcademicPeriodActivated = 'academic_period.activated';
    case SubjectCreated = 'subject.created';
    case SubjectUpdated = 'subject.updated';
    case ClassBatchCreated = 'class_batch.created';
    case ClassBatchUpdated = 'class_batch.updated';
    case ClassSubjectAdded = 'class_subject.added';
    case ClassSubjectRemoved = 'class_subject.removed';
    case InstructorAssigned = 'instructor_assignment.created';
    case InstructorUnassigned = 'instructor_assignment.removed';
    case CandidateCreated = 'candidate.created';
    case CandidateUpdated = 'candidate.updated';
    case CandidatePasswordReset = 'candidate.password_reset';

    public function label(): string
    {
        return match ($this) {
            self::Login => 'Signed in',
            self::LoginFailed => 'Failed sign-in attempt',
            self::Logout => 'Signed out',
            self::UserCreated => 'Created user account',
            self::UserUpdated => 'Updated user account',
            self::UserRoleChanged => 'Changed user role',
            self::UserDeactivated => 'Deactivated user account',
            self::UserReactivated => 'Reactivated user account',
            self::UserPasswordReset => 'Reset user password',
            self::OwnPasswordChanged => 'Changed own password',
            self::AcademicPeriodCreated => 'Created academic period',
            self::AcademicPeriodUpdated => 'Updated academic period',
            self::AcademicPeriodActivated => 'Changed active academic period',
            self::SubjectCreated => 'Created subject',
            self::SubjectUpdated => 'Updated subject',
            self::ClassBatchCreated => 'Created class',
            self::ClassBatchUpdated => 'Updated class',
            self::ClassSubjectAdded => 'Added subject to class',
            self::ClassSubjectRemoved => 'Removed subject from class',
            self::InstructorAssigned => 'Assigned instructor',
            self::InstructorUnassigned => 'Removed instructor assignment',
            self::CandidateCreated => 'Created candidate',
            self::CandidateUpdated => 'Updated candidate',
            self::CandidatePasswordReset => 'Reset candidate password',
        };
    }
}
