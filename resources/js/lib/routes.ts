/**
 * Application URLs used by the frontend. Keep in sync with routes/web.php.
 */
export const routes = {
    home: () => '/',
    login: () => '/login',
    logout: () => '/logout',
    dashboard: () => '/dashboard',
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
        edit: (periodId: number) => `/academic-periods/${periodId}/edit`,
        update: (periodId: number) => `/academic-periods/${periodId}`,
        activate: (periodId: number) => `/academic-periods/${periodId}/activate`,
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
    },
} as const;
