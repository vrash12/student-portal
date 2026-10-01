import { examinationRoutes } from '@/lib/examination-routes';
import { questionBankRoutes } from '@/lib/question-bank-routes';
import { withQuery } from '@/lib/url';

/**
 * Application URLs used by the frontend. Keep in sync with routes/web.php.
 */
export const routes = {
    home: () => '/',
    login: () => '/login',
    logout: () => '/logout',
    dashboard: () => '/dashboard',
    monitoring: {
        index: (query?: Record<string, string>) => withQuery('/monitoring', query),
    },
    questionBank: questionBankRoutes,
    examinations: examinationRoutes,
    users: {
        index: () => '/users',
        create: () => '/users/create',
        store: () => '/users',
        edit: (userId: number) => `/users/${userId}/edit`,
        update: (userId: number) => `/users/${userId}`,
    },
    roles: {
        index: () => '/roles',
    },
    account: {
        password: () => '/account/password',
    },
    academicPeriods: {
        index: () => '/academic-periods',
        create: () => '/academic-periods/create',
        store: () => '/academic-periods',
        show: (periodId: number) => `/academic-periods/${periodId}`,
        edit: (periodId: number) => `/academic-periods/${periodId}/edit`,
        update: (periodId: number) => `/academic-periods/${periodId}`,
        activate: (periodId: number) => `/academic-periods/${periodId}/activate`,
        thresholds: (periodId: number) => `/academic-periods/${periodId}/grading-thresholds`,
    },
    subjects: {
        index: () => '/subjects',
        create: () => '/subjects/create',
        store: () => '/subjects',
        edit: (subjectId: number) => `/subjects/${subjectId}/edit`,
        update: (subjectId: number) => `/subjects/${subjectId}`,
    },
    classes: {
        index: () => '/classes',
        create: () => '/classes/create',
        store: () => '/classes',
        show: (classId: number) => `/classes/${classId}`,
        edit: (classId: number) => `/classes/${classId}/edit`,
        update: (classId: number) => `/classes/${classId}`,
        addSubject: (classId: number) => `/classes/${classId}/subjects`,
        removeSubject: (classId: number, classSubjectId: number) => `/classes/${classId}/subjects/${classSubjectId}`,
        grading: (classId: number, classSubjectId: number) => `/classes/${classId}/subjects/${classSubjectId}/grading`,
    },
    instructors: {
        index: () => '/instructors',
        show: (instructorId: number) => `/instructors/${instructorId}`,
    },
    instructorAssignments: {
        store: () => '/instructor-assignments',
        destroy: (assignmentId: number) => `/instructor-assignments/${assignmentId}`,
    },
    teaching: {
        classes: {
            index: () => '/my-classes',
            show: (classId: number) => `/my-classes/${classId}`,
        },
        gradebook: (classId: number, classSubjectId: number) => `/my-classes/${classId}/subjects/${classSubjectId}`,
        assessments: {
            create: (classId: number, classSubjectId: number) => `/my-classes/${classId}/subjects/${classSubjectId}/assessments/create`,
            store: (classId: number, classSubjectId: number) => `/my-classes/${classId}/subjects/${classSubjectId}/assessments`,
        },
    },
    assessments: {
        show: (assessmentId: number) => `/assessments/${assessmentId}`,
        edit: (assessmentId: number) => `/assessments/${assessmentId}/edit`,
        update: (assessmentId: number) => `/assessments/${assessmentId}`,
        destroy: (assessmentId: number) => `/assessments/${assessmentId}`,
        finalize: (assessmentId: number) => `/assessments/${assessmentId}/finalize`,
        scores: (assessmentId: number) => `/assessments/${assessmentId}/scores`,
        corrections: (assessmentId: number) => `/assessments/${assessmentId}/corrections`,
    },
    candidates: {
        index: () => '/candidates',
        create: () => '/candidates/create',
        store: () => '/candidates',
        show: (candidateId: number) => `/candidates/${candidateId}`,
        edit: (candidateId: number) => `/candidates/${candidateId}/edit`,
        update: (candidateId: number) => `/candidates/${candidateId}`,
    },
    portal: {
        home: () => '/portal',
        examination: (id: number) => `/portal/examinations/${id}`,
        start: (id: number) => `/portal/examinations/${id}/start`,
        attempt: (id: number) => `/portal/attempts/${id}`,
        answers: (id: number) => `/portal/attempts/${id}/answers`,
        submit: (id: number) => `/portal/attempts/${id}/submit`,
        success: (id: number) => `/portal/attempts/${id}/success`,
    },
} as const;
