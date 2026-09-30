import { withQuery } from '@/lib/url';
/** Registered examination routes; details and delivery settings share one draft form. */
export const examinationRoutes = {
    index: (query?: Record<string, string>) => withQuery('/examinations', query),
    create: (query?: Record<string, string>) => withQuery('/examinations/create', query),
    store: () => '/examinations',
    show: (id: number) => `/examinations/${id}`,
    edit: (id: number) => `/examinations/${id}/edit`,
    update: (id: number) => `/examinations/${id}`,
    questions: {
        edit: (id: number) => `/examinations/${id}/questions`,
        update: (id: number) => `/examinations/${id}/questions`,
    },
    publish: (id: number) => `/examinations/${id}/publish`,
    archive: (id: number) => `/examinations/${id}/archive`,
    results: (id: number) => `/examinations/${id}/results`,
    grading: (id: number) => `/examinations/${id}/grading`,
    gradeAttempt: (id: number) => `/examination-attempts/${id}/grading`,
    saveEssay: (id: number) => `/examination-attempts/${id}/essay-grade`,
};
