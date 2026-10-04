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
    backups: {
        index: () => '/backups',
        store: () => '/backups',
        verify: () => '/backups/verify',
        restore: (backupId: string) => `/backups/${backupId}/restore`,
    },
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
        thresholds: (periodId: number, query?: Record<string, string>) => withQuery(`/academic-periods/${periodId}/grading-thresholds`, query),
    },
    /** The four fixed campuses: listed and edited (address, on/off), never added or removed. */
    campuses: {
        index: () => '/campuses',
        edit: (campusId: number) => `/campuses/${campusId}/edit`,
        update: (campusId: number) => `/campuses/${campusId}`,
    },
    trainingPhases: {
        index: (query?: Record<string, string>) => withQuery('/training-phases', query),
        store: () => '/training-phases',
        update: (phaseId: number) => `/training-phases/${phaseId}`,
        destroy: (phaseId: number) => `/training-phases/${phaseId}`,
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
        /** Every QR attendance card of the class, six to a page (PDF). */
        qrCards: (classId: number) => `/classes/${classId}/qr-cards/pdf`,
        update: (classId: number) => `/classes/${classId}`,
        addSubject: (classId: number) => `/classes/${classId}/subjects`,
        /** The subject's training phase and units. */
        updateSubject: (classId: number, classSubjectId: number) => `/classes/${classId}/subjects/${classSubjectId}`,
        removeSubject: (classId: number, classSubjectId: number) => `/classes/${classId}/subjects/${classSubjectId}`,
        grading: (classId: number, classSubjectId: number, query?: Record<string, string>) =>
            withQuery(`/classes/${classId}/subjects/${classSubjectId}/grading`, query),
    },
    medical: {
        records: {
            index: (query?: Record<string, string>) => withQuery('/medical-records', query),
            edit: (candidateId: number) => `/medical-records/${candidateId}/edit`,
            update: (candidateId: number) => `/medical-records/${candidateId}`,
        },
        documents: {
            index: (query?: Record<string, string>) => withQuery('/medical-records/documents', query),
            accept: (documentId: number) => `/medical-documents/${documentId}/accept`,
            return: (documentId: number) => `/medical-documents/${documentId}/return`,
        },
        downloads: {
            index: (query?: Record<string, string>) => withQuery('/medical-records/download-requests', query),
            store: (documentId: number) => `/medical-documents/${documentId}/download-requests`,
            cancel: (requestId: number) => `/medical-download-requests/${requestId}/cancel`,
            approve: (requestId: number) => `/medical-download-requests/${requestId}/approve`,
            reject: (requestId: number) => `/medical-download-requests/${requestId}/reject`,
            revoke: (requestId: number) => `/medical-download-requests/${requestId}/revoke`,
        },
        fields: {
            index: () => '/medical-records/fields',
            create: () => '/medical-records/fields/create',
            store: () => '/medical-records/fields',
            edit: (fieldId: number) => `/medical-records/fields/${fieldId}/edit`,
            update: (fieldId: number) => `/medical-records/fields/${fieldId}`,
            destroy: (fieldId: number) => `/medical-records/fields/${fieldId}`,
        },
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
            /** The test's summary and every candidate's results (PDF). */
            pdf: (testId: number) => `/fitness/tests/${testId}/pdf`,
            edit: (testId: number) => `/fitness/tests/${testId}/edit`,
            update: (testId: number) => `/fitness/tests/${testId}`,
            destroy: (testId: number) => `/fitness/tests/${testId}`,
            results: (testId: number) => `/fitness/tests/${testId}/results`,
        },
    },
    accounts: {
        entries: {
            void: (entryId: number) => `/account-entries/${entryId}/void`,
        },
        expenses: {
            index: () => '/account-expenses',
            create: () => '/account-expenses/create',
            store: () => '/account-expenses',
            show: (expenseId: number, query?: Record<string, string>) => withQuery(`/account-expenses/${expenseId}`, query),
            edit: (expenseId: number) => `/account-expenses/${expenseId}/edit`,
            update: (expenseId: number) => `/account-expenses/${expenseId}`,
            assign: (expenseId: number) => `/account-expenses/${expenseId}/assignments`,
        },
        categories: {
            index: () => '/account-categories',
            create: () => '/account-categories/create',
            store: () => '/account-categories',
            edit: (categoryId: number) => `/account-categories/${categoryId}/edit`,
            update: (categoryId: number) => `/account-categories/${categoryId}`,
        },
    },
    conduct: {
        index: (query?: Record<string, string>) => withQuery('/conduct', query),
        show: (candidateId: number) => `/conduct/candidates/${candidateId}`,
        entries: {
            store: (candidateId: number) => `/conduct/candidates/${candidateId}/entries`,
            void: (entryId: number) => `/conduct-entries/${entryId}/void`,
        },
        types: {
            index: () => '/conduct/types',
            create: () => '/conduct/types/create',
            store: () => '/conduct/types',
            edit: (typeId: number) => `/conduct/types/${typeId}/edit`,
            update: (typeId: number) => `/conduct/types/${typeId}`,
        },
    },
    attendance: {
        index: (query?: Record<string, string>) => withQuery('/attendance', query),
        sessions: {
            create: () => '/attendance/sessions/create',
            store: () => '/attendance/sessions',
            show: (sessionId: number) => `/attendance/sessions/${sessionId}`,
            edit: (sessionId: number) => `/attendance/sessions/${sessionId}/edit`,
            update: (sessionId: number) => `/attendance/sessions/${sessionId}`,
            destroy: (sessionId: number) => `/attendance/sessions/${sessionId}`,
            records: (sessionId: number) => `/attendance/sessions/${sessionId}/records`,
        },
    },
    qualification: {
        index: (query?: Record<string, string>) => withQuery('/qualification', query),
        pdf: (query?: Record<string, string>) => withQuery('/qualification/pdf', query),
    },
    gradingSetup: {
        index: (query?: Record<string, string>) => withQuery('/grading-setup', query),
        copyWeights: () => '/grading-setup/copy-weights',
    },
    performanceAreas: {
        index: () => '/performance-areas',
        create: () => '/performance-areas/create',
        store: () => '/performance-areas',
        edit: (areaId: number) => `/performance-areas/${areaId}/edit`,
        update: (areaId: number) => `/performance-areas/${areaId}`,
    },
    portalPerformance: () => '/portal/performance',
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
        correctionRequests: (assessmentId: number) => `/assessments/${assessmentId}/correction-requests`,
    },
    gradeCorrections: {
        index: (query?: Record<string, string>) => withQuery('/grade-corrections', query),
        show: (requestId: number) => `/grade-corrections/${requestId}`,
        pdf: (requestId: number) => `/grade-corrections/${requestId}/pdf`,
        approve: (requestId: number) => `/grade-corrections/${requestId}/approve`,
        reject: (requestId: number) => `/grade-corrections/${requestId}/reject`,
        cancel: (requestId: number) => `/grade-corrections/${requestId}/cancel`,
    },
    candidates: {
        index: () => '/candidates',
        create: () => '/candidates/create',
        store: () => '/candidates',
        show: (candidateId: number) => `/candidates/${candidateId}`,
        edit: (candidateId: number) => `/candidates/${candidateId}/edit`,
        update: (candidateId: number) => `/candidates/${candidateId}`,
        background: {
            edit: (candidateId: number) => `/candidates/${candidateId}/background/edit`,
            update: (candidateId: number) => `/candidates/${candidateId}/background`,
        },
    },
    portal: {
        home: () => '/portal',
        examinations: () => '/portal/examinations',
        grades: () => '/portal/grades',
        fitness: () => '/portal/fitness',
        performance: () => '/portal/performance',
        profile: () => '/portal/profile',
        medical: () => '/portal/medical',
        medicalDocuments: {
            store: () => '/portal/medical/documents',
            destroy: (documentId: number) => `/portal/medical/documents/${documentId}`,
        },
        examination: (id: number) => `/portal/examinations/${id}`,
        start: (id: number) => `/portal/examinations/${id}/start`,
        attempt: (id: number) => `/portal/attempts/${id}`,
        answers: (id: number) => `/portal/attempts/${id}/answers`,
        submit: (id: number) => `/portal/attempts/${id}/submit`,
        success: (id: number) => `/portal/attempts/${id}/success`,
    },
} as const;
