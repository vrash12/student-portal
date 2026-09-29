import { withQuery } from '@/lib/url';

/**
 * Quiz and examination builder URLs (Milestone 8), available as
 * `routes.examinations`. Steps: Details (edit), Questions, Settings,
 * Review (show). Keep in sync with routes/examinations.php.
 */
export const examinationRoutes = {
    index: (query?: Record<string, string>) => withQuery('/examinations', query),
    create: (query?: Record<string, string>) => withQuery('/examinations/create', query),
    store: () => '/examinations',
    /** Review step: summary, readiness, and lifecycle actions. */
    show: (examinationId: number) => `/examinations/${examinationId}`,
    /** Details step. */
    edit: (examinationId: number) => `/examinations/${examinationId}/edit`,
    update: (examinationId: number) => `/examinations/${examinationId}`,
    destroy: (examinationId: number) => `/examinations/${examinationId}`,
    questions: {
        /** Questions step, with the question bank picker filters in the query string. */
        edit: (examinationId: number, query?: Record<string, string>) => withQuery(`/examinations/${examinationId}/questions`, query),
        store: (examinationId: number) => `/examinations/${examinationId}/questions`,
        update: (examinationId: number) => `/examinations/${examinationId}/questions`,
        destroy: (examinationId: number, examinationQuestionId: number) =>
            `/examinations/${examinationId}/questions/${examinationQuestionId}`,
    },
    settings: {
        edit: (examinationId: number) => `/examinations/${examinationId}/settings`,
        update: (examinationId: number) => `/examinations/${examinationId}/settings`,
    },
    publish: (examinationId: number) => `/examinations/${examinationId}/publish`,
    unpublish: (examinationId: number) => `/examinations/${examinationId}/unpublish`,
    archive: (examinationId: number) => `/examinations/${examinationId}/archive`,
};
