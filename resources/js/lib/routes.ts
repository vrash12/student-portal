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
    fitness: {
        index: () => '/fitness',
        standards: {
            index: () => '/fitness/standards',
            create: () => '/fitness/standards/create',
            store: () => '/fitness/standards',
            edit: (eventId: number) => `/fitness/standards/${eventId}/edit`,
            update: (eventId: number) => `/fitness/standards/${eventId}`,
        },
        tests: {
            create: () => '/fitness/tests/create',
            store: () => '/fitness/tests',
            show: (testId: number) => `/fitness/tests/${testId}`,
            edit: (testId: number) => `/fitness/tests/${testId}/edit`,
            update: (testId: number) => `/fitness/tests/${testId}`,
            destroy: (testId: number) => `/fitness/tests/${testId}`,
            results: (testId: number) => `/fitness/tests/${testId}/results`,
        },
    },
    accounts: {
        index: (query?: Record<string, string>) => withQuery('/accounts', query),
        show: (candidateId: number, query?: Record<string, string>) => withQuery(`/accounts/${candidateId}`, query),
        statement: (candidateId: number, query?: Record<string, string>) => withQuery(`/accounts/${candidateId}/statement`, query),
        entries: {
            store: (candidateId: number) => `/accounts/${candidateId}/entries`,
            void: (entryId: number) => `/account-entries/${entryId}/void`,
        },
        categories: {
            index: () => '/account-categories',
            create: () => '/account-categories/create',
            store: () => '/account-categories',
            edit: (categoryId: number) => `/account-categories/${categoryId}/edit`,
            update: (categoryId: number) => `/account-categories/${categoryId}`,
        },
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
