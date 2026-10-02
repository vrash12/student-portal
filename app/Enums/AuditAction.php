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
    case CandidateRecordDownloaded = 'candidate.record_downloaded';
    case GradingSchemeUpdated = 'grading_scheme.updated';
    case AssessmentCreated = 'assessment.created';
    case AssessmentUpdated = 'assessment.updated';
    case AssessmentDeleted = 'assessment.deleted';
    case AssessmentFinalized = 'assessment.finalized';
    case AssessmentScoresRecorded = 'assessment.scores_recorded';
    case AssessmentScoreCorrected = 'assessment_score.corrected';
    case GradeCorrectionRequested = 'grade_correction.requested';
    case GradeCorrectionApproved = 'grade_correction.approved';
    case GradeCorrectionRejected = 'grade_correction.rejected';
    case GradeCorrectionCancelled = 'grade_correction.cancelled';
    case MedicalFieldCreated = 'medical_field.created';
    case MedicalFieldUpdated = 'medical_field.updated';
    case MedicalFieldDeleted = 'medical_field.deleted';
    case MedicalRecordUpdated = 'medical_record.updated';
    case GradingThresholdsUpdated = 'grading_thresholds.updated';
    case QuestionCreated = 'question.created';
    case QuestionUpdated = 'question.updated';
    case QuestionActivated = 'question.activated';
    case QuestionDeactivated = 'question.deactivated';
    case ExaminationCreated = 'examination.created';
    case ExaminationUpdated = 'examination.updated';
    case ExaminationQuestionsUpdated = 'examination.questions_updated';
    case ExaminationSettingsUpdated = 'examination.settings_updated';
    case ExaminationPublished = 'examination.published';
    case ExaminationUnpublished = 'examination.unpublished';
    case ExaminationArchived = 'examination.archived';
    case ExaminationDeleted = 'examination.deleted';
    case ExaminationEssayGraded = 'examination.essay_graded';
    case ExaminationPosted = 'examination.posted_to_gradebook';

    case ExaminationAccessCodeRejected = 'examination.access_code_rejected';

    case FitnessEventCreated = 'fitness_event.created';
    case FitnessEventUpdated = 'fitness_event.updated';
    case FitnessTestCreated = 'fitness_test.created';
    case FitnessTestUpdated = 'fitness_test.updated';
    case FitnessTestDeleted = 'fitness_test.deleted';
    case FitnessResultsRecorded = 'fitness_test.results_recorded';

    case AccountEntryRecorded = 'account_entry.recorded';
    case AccountEntryVoided = 'account_entry.voided';
    case AccountCategoryCreated = 'account_category.created';
    case AccountCategoryUpdated = 'account_category.updated';
    case AccountStatementDownloaded = 'account_statement.downloaded';
    case AccountExpenseCreated = 'account_expense.created';
    case AccountExpenseUpdated = 'account_expense.updated';
    case AccountExpenseAssigned = 'account_expense.assigned';

    case ConductEntryRecorded = 'conduct_entry.recorded';
    case ConductEntryVoided = 'conduct_entry.voided';
    case ConductTypeCreated = 'conduct_type.created';
    case ConductTypeUpdated = 'conduct_type.updated';
    case AttendanceSessionCreated = 'attendance_session.created';
    case AttendanceSessionUpdated = 'attendance_session.updated';
    case AttendanceSessionDeleted = 'attendance_session.deleted';
    case AttendanceRecorded = 'attendance_session.attendance_recorded';
    case PerformanceAreaCreated = 'performance_area.created';
    case PerformanceAreaUpdated = 'performance_area.updated';

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
            self::CandidateRecordDownloaded => 'Downloaded candidate PDF record',
            self::GradingSchemeUpdated => 'Updated grading setup',
            self::AssessmentCreated => 'Created assessment',
            self::AssessmentUpdated => 'Updated assessment',
            self::AssessmentDeleted => 'Deleted draft assessment',
            self::AssessmentFinalized => 'Finalized assessment',
            self::AssessmentScoresRecorded => 'Recorded assessment scores',
            self::AssessmentScoreCorrected => 'Corrected finalized score',
            self::GradeCorrectionRequested => 'Requested grade correction',
            self::GradeCorrectionApproved => 'Approved grade correction',
            self::GradeCorrectionRejected => 'Rejected grade correction',
            self::GradeCorrectionCancelled => 'Cancelled grade correction request',
            self::MedicalFieldCreated => 'Created medical record field',
            self::MedicalFieldUpdated => 'Updated medical record field',
            self::MedicalFieldDeleted => 'Deleted unused medical record field',
            self::MedicalRecordUpdated => 'Updated candidate medical record',
            self::GradingThresholdsUpdated => 'Updated passing and warning grades',
            self::QuestionCreated => 'Created question',
            self::QuestionUpdated => 'Updated question',
            self::QuestionActivated => 'Activated question',
            self::QuestionDeactivated => 'Deactivated question',
            self::ExaminationCreated => 'Created examination',
            self::ExaminationUpdated => 'Updated examination details',
            self::ExaminationQuestionsUpdated => 'Changed examination questions',
            self::ExaminationSettingsUpdated => 'Updated examination settings',
            self::ExaminationPublished => 'Published examination',
            self::ExaminationUnpublished => 'Returned examination to draft',
            self::ExaminationArchived => 'Archived examination',
            self::ExaminationDeleted => 'Deleted draft examination',
            self::ExaminationEssayGraded => 'Graded examination essay',
            self::ExaminationPosted => 'Posted examination to gradebook',
            self::ExaminationAccessCodeRejected => 'Entered an incorrect examination access code',
            self::FitnessEventCreated => 'Created fitness event',
            self::FitnessEventUpdated => 'Updated fitness event',
            self::FitnessTestCreated => 'Created fitness test',
            self::FitnessTestUpdated => 'Updated fitness test',
            self::FitnessTestDeleted => 'Deleted fitness test',
            self::FitnessResultsRecorded => 'Recorded fitness results',
            self::AccountEntryRecorded => 'Recorded statement of account entry',
            self::AccountEntryVoided => 'Voided statement of account entry',
            self::AccountCategoryCreated => 'Created account category',
            self::AccountCategoryUpdated => 'Updated account category',
            self::AccountStatementDownloaded => 'Downloaded statement of account PDF',
            self::AccountExpenseCreated => 'Created account expense',
            self::AccountExpenseUpdated => 'Updated account expense',
            self::AccountExpenseAssigned => 'Assigned account expense to candidates',
            self::ConductEntryRecorded => 'Recorded merit or demerit',
            self::ConductEntryVoided => 'Voided merit or demerit',
            self::ConductTypeCreated => 'Created merit/demerit type',
            self::ConductTypeUpdated => 'Updated merit/demerit type',
            self::AttendanceSessionCreated => 'Created attendance session',
            self::AttendanceSessionUpdated => 'Updated attendance session',
            self::AttendanceSessionDeleted => 'Deleted attendance session',
            self::AttendanceRecorded => 'Recorded attendance',
            self::PerformanceAreaCreated => 'Created performance area',
            self::PerformanceAreaUpdated => 'Updated performance area',
        };
    }
}
