import { withQuery } from '@/lib/url';

/**
 * Question bank URLs (Milestone 7), available as `routes.questionBank`.
 * Keep in sync with routes/question-bank.php.
 */
export const questionBankRoutes = {
    index: (query?: Record<string, string>) => withQuery('/question-bank', query),
    create: (query?: Record<string, string>) => withQuery('/question-bank/create', query),
    store: () => '/question-bank',
    show: (questionId: number) => `/question-bank/${questionId}`,
    edit: (questionId: number) => `/question-bank/${questionId}/edit`,
    update: (questionId: number) => `/question-bank/${questionId}`,
    activate: (questionId: number) => `/question-bank/${questionId}/activate`,
    deactivate: (questionId: number) => `/question-bank/${questionId}/deactivate`,
    duplicate: (questionId: number) => `/question-bank/${questionId}/duplicate`,
    import: {
        create: (query?: Record<string, string>) => withQuery('/question-bank/import', query),
        store: () => '/question-bank/import',
        template: () => '/question-bank/import/template',
    },
    media: {
        store: (questionId: number) => `/question-bank/${questionId}/media`,
        update: (questionId: number, mediaId: number) => `/question-bank/${questionId}/media/${mediaId}`,
        destroy: (questionId: number, mediaId: number) => `/question-bank/${questionId}/media/${mediaId}`,
    },
};
