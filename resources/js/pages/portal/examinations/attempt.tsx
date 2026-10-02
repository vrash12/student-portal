import { Head, router, usePage } from '@inertiajs/react';
import { Check, ChevronLeft, ChevronRight, CircleAlert, CircleCheck, CircleHelp, Clock, CloudOff, Flag, ListOrdered, LoaderCircle, RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { QuestionMediaList } from '@/components/question-bank/question-media';
import { Button } from '@/components/ui/button';
import { useDateFormatter } from '@/lib/format';
import type { QuestionMediaView } from '@/types/question-bank';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { clearRecovery, enqueueRecovery, loadRecovery, removePending, saveSnapshot, type RecoveryAnswer, type RecoveryPayload } from '@/lib/exam-recovery';

type Answer = RecoveryAnswer;
type FocusReason = 'hidden' | 'blur';
interface FocusReport { event: 'left' | 'returned'; reason: FocusReason; at: number }
interface Item { id: number; points: string; question: { prompt: string; type: { value: string }; choices: { id: number; text: string; image?: QuestionMediaView | null }[]; media?: QuestionMediaView[] } }
/**
 * Save state shown to the candidate (UI_UX_DESIGN.md §26–27). A fixed set of
 * states, each with an icon and text; details go on a second line.
 */
type SaveStatus = 'loading' | 'saved' | 'saving' | 'unsaved' | 'offline' | 'syncing' | 'failed';
const SAVE_STATUS: Record<SaveStatus, { label: string; icon: ReactNode; tone: string }> = {
    loading: { label: 'Loading saved answers…', icon: <LoaderCircle className="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />, tone: 'text-ink-muted' },
    saved: { label: 'Saved', icon: <Check className="size-4" aria-hidden="true" />, tone: 'text-success-fg' },
    saving: { label: 'Saving…', icon: <LoaderCircle className="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />, tone: 'text-ink-muted' },
    unsaved: { label: 'Not saved yet', icon: <CircleAlert className="size-4" aria-hidden="true" />, tone: 'text-warning-fg' },
    offline: { label: 'Offline – saved on this device', icon: <CloudOff className="size-4" aria-hidden="true" />, tone: 'text-warning-fg' },
    syncing: { label: 'Syncing…', icon: <RefreshCw className="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />, tone: 'text-ink-muted' },
    failed: { label: 'Unable to sync', icon: <CircleAlert className="size-4" aria-hidden="true" />, tone: 'text-danger-fg' },
};
const LOW_TIME_MS = 5 * 60 * 1000;
const CHOICE_LETTERS = 'ABCDEF';

function isAnswered(answer: Answer | undefined): boolean {
    return answer !== undefined && answer.value !== null && answer.value !== '';
}

function plural(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

/** The server refused a save (not a connection failure). */
class SaveRejected extends Error { constructor(message: string, readonly status: number) { super(message); } }
/**
 * Failures that say nothing about the answer itself: no connection, the
 * server or its gateway unavailable (5xx), a timeout, or rate limiting. The
 * answer is kept on the tablet and sent again later.
 */
function isTransient(error: unknown): boolean {
    return error instanceof TypeError || (error instanceof SaveRejected && (error.status >= 500 || error.status === 408 || error.status === 429));
}
type FlushOutcome = 'synced' | 'offline' | 'failed';
interface Attempt { id: number; title: string; expiresAt: string; serverNow: string; allowBackNavigation: boolean; oneQuestionAtATime: boolean; position: number; revision: number; answers: Record<number, Answer> }

export default function ExamAttempt({ attempt, questions }: { attempt: Attempt; questions: Item[] }) {
    const [position, setPosition] = useState(attempt.position);
    const [answers, setAnswers] = useState<Record<number, Answer>>(attempt.answers ?? {});
    const [status, setStatus] = useState<SaveStatus>('loading');
    const [detail, setDetail] = useState('');
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [navigating, setNavigating] = useState(false);
    const [timeNotice, setTimeNotice] = useState('');
    const [timeHidden, setTimeHidden] = useState(false);
    const [showList, setShowList] = useState(false);
    const { dateTime } = useDateFormatter();
    const candidateName = usePage().props.auth.user?.name ?? '';
    const editVersion = useRef(0);
    const questionHeading = useRef<HTMLHeadingElement>(null);
    const firstQuestion = useRef(true);
    const announced = useRef<Set<number>>(new Set());
    const [connection, setConnection] = useState(navigator.onLine ? 'online' : 'offline');
    const [busy, setBusy] = useState(false);
    const [recoveryReady, setRecoveryReady] = useState(false);
    const [storageAvailable, setStorageAvailable] = useState(true);
    const [confirm, setConfirm] = useState(false);
    const [remaining, setRemaining] = useState(Math.max(0, Date.parse(attempt.expiresAt) - Date.parse(attempt.serverNow)));
    const revision = useRef(attempt.revision);
    const answersRef = useRef(answers);
    const positionRef = useRef(position);
    const pendingRef = useRef<RecoveryPayload[]>([]);
    const saving = useRef(false);
    const flushing = useRef(false);
    const dirty = useRef(false);
    const clock = useRef({ at: performance.now(), remaining: Math.max(0, Date.parse(attempt.expiresAt) - Date.parse(attempt.serverNow)) });
    const [awayNotice, setAwayNotice] = useState<number | null>(null);
    const away = useRef<{ since: number; reason: FocusReason } | null>(null);
    const focusQueue = useRef<FocusReport[]>([]);
    const focusSending = useRef(false);
    const item = getQuestion(questions, position);
    const answer = answers[item.id] ?? { value: null, flagged: false };

    function report(next: SaveStatus, message = ''): void {
        setStatus(next);
        setDetail(message);
    }

    useEffect(() => { answersRef.current = answers; }, [answers]);
    useEffect(() => { positionRef.current = position; }, [position]);

    // Moving to another question puts focus on its heading, so keyboard and
    // screen-reader users start at the new question (not on the first load).
    useEffect(() => {
        if (firstQuestion.current) { firstQuestion.current = false; return; }
        questionHeading.current?.focus({ preventScroll: false });
    }, [position]);

    // Announced once each, politely; the timer itself is not read every second.
    useEffect(() => {
        const minutes = Math.ceil(remaining / 60000);
        for (const mark of [10, 5, 1]) {
            if (remaining > 0 && minutes <= mark && !announced.current.has(mark)) {
                announced.current.add(mark);
                setTimeNotice(`${plural(mark, 'minute', 'minutes')} or less remaining.`);
            }
        }
    }, [remaining]);

    useEffect(() => {
        const controller = new AbortController();
        const timer = window.setInterval(() => {
            if (!navigator.onLine) return;
            const token = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=');
            void fetch(`/portal/attempts/${attempt.id}/activity`, { method: 'POST', credentials: 'same-origin', signal: controller.signal, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(token ?? '') } }).then(async (response) => {
                if (!response.ok || controller.signal.aborted) return;
                const activity = await response.json() as { status: string; serverNow: string; expiresAt: string };
                if (activity.status !== 'in_progress') { router.visit(`/portal/attempts/${attempt.id}/success`); return; }
                clock.current = { at: performance.now(), remaining: Math.max(0, Date.parse(activity.expiresAt) - Date.parse(activity.serverNow)) };
            }).catch(() => { /* Answer saving reports connection failures independently. */ });
        }, 45000);
        return () => { window.clearInterval(timer); controller.abort(); };
    }, [attempt.id]);

    // Leaving the examination screen (tab/app switch, minimized browser, another
    // window focused) is reported to the server, which records it with its own
    // clock for the instructor. It never changes answers or scores.
    useEffect(() => {
        let blurTimer: number | undefined;

        async function sendReports(): Promise<void> {
            if (focusSending.current) return;
            focusSending.current = true;
            try {
                while (focusQueue.current.length > 0 && navigator.onLine) {
                    const report = focusQueue.current[0];
                    if (!report) break;
                    const token = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=');
                    // keepalive lets the report leave even while the page is being hidden.
                    const response = await fetch(`/portal/attempts/${attempt.id}/focus`, { method: 'POST', keepalive: true, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(token ?? '') }, body: JSON.stringify({ event: report.event, reason: report.reason, delay_ms: Math.max(0, Math.round(performance.now() - report.at)) }) });
                    if (response.status >= 500 || response.status === 429) break;
                    focusQueue.current.shift();
                }
            } catch {
                // Offline: the reports stay queued and are sent when the connection returns.
            } finally {
                focusSending.current = false;
            }
        }

        function report(event: FocusReport['event'], reason: FocusReason, at = performance.now()): void {
            focusQueue.current.push({ event, reason, at });
            void sendReports();
        }

        function leave(reason: FocusReason, at = performance.now()): void {
            if (away.current !== null) return;
            away.current = { since: at, reason };
            report('left', reason, at);
        }

        function back(): void {
            window.clearTimeout(blurTimer);
            const departure = away.current;
            if (departure === null) return;
            away.current = null;
            report('returned', departure.reason);
            setAwayNotice(Math.max(1, Math.round((performance.now() - departure.since) / 1000)));
        }

        function onVisibility(): void {
            if (document.hidden) leave('hidden');
            else if (document.hasFocus()) back();
        }

        function onBlur(): void {
            const at = performance.now();
            // Brief focus changes (system pop-ups) are ignored; a hidden page is recorded at once by onVisibility.
            blurTimer = window.setTimeout(() => { if (!document.hasFocus()) leave(document.hidden ? 'hidden' : 'blur', at); }, 1000);
        }

        function onFocus(): void {
            if (!document.hidden) back();
        }

        const onOnline = () => void sendReports();
        document.addEventListener('visibilitychange', onVisibility);
        window.addEventListener('blur', onBlur);
        window.addEventListener('focus', onFocus);
        window.addEventListener('online', onOnline);
        if (document.hidden) leave('hidden');

        return () => {
            window.clearTimeout(blurTimer);
            document.removeEventListener('visibilitychange', onVisibility);
            window.removeEventListener('blur', onBlur);
            window.removeEventListener('focus', onFocus);
            window.removeEventListener('online', onOnline);
        };
    }, [attempt.id]);

    useEffect(() => {
        let mounted = true;
        void loadRecovery(attempt.id).then(({ snapshot, pending, available }) => {
            if (!mounted) return;
            setStorageAvailable(available);
            pendingRef.current = pending;
            if (snapshot && (pending.length > 0 || (snapshot.dirty && snapshot.revision === attempt.revision))) {
                const merged = { ...answersRef.current, ...snapshot.answers };
                answersRef.current = merged;
                setAnswers(merged);
                setPosition(snapshot.position);
                positionRef.current = snapshot.position;
                dirty.current = snapshot.dirty ?? false;
                report(pending.length > 0 ? 'offline' : 'unsaved', 'Answers kept on this tablet will be saved to the server.');
            } else {
                report('saved');
            }
            setRecoveryReady(true);
            if (pending.length > 0 && navigator.onLine) void flushPending();
        });
        return () => { mounted = false; };
    }, [attempt.id]);

    useEffect(() => {
        const onOffline = () => setConnection('offline');
        const onOnline = () => { setConnection('online'); if (pendingRef.current.length > 0) void flushPending(); };
        window.addEventListener('offline', onOffline);
        window.addEventListener('online', onOnline);
        return () => { window.removeEventListener('offline', onOffline); window.removeEventListener('online', onOnline); };
    }, []);

    useEffect(() => {
        if (!recoveryReady || !dirty.current) return;
        const timer = window.setTimeout(() => { if (!saving.current) void save(); }, 900);
        return () => window.clearTimeout(timer);
    }, [answers, recoveryReady]);

    useEffect(() => {
        const timer = window.setInterval(() => { if (recoveryReady && navigator.onLine && !saving.current && !flushing.current) { if (pendingRef.current.length > 0) void flushPending(); else if (dirty.current) void save(); } }, 10000);
        return () => window.clearInterval(timer);
    }, [recoveryReady]);

    useEffect(() => {
        const timer = window.setInterval(() => setRemaining(Math.max(0, clock.current.remaining - (performance.now() - clock.current.at))), 1000);
        return () => window.clearInterval(timer);
    }, []);

    useEffect(() => {
        if (remaining === 0 && !saving.current) router.visit('/portal/attempts/' + attempt.id);
    }, [remaining, attempt.id]);

    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => { if (dirty.current || pendingRef.current.length > 0) { event.preventDefault(); event.returnValue = ''; } };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, []);

    function persistLocal(nextAnswers: Record<number, Answer>, nextPosition: number): void {
        void saveSnapshot({ attemptId: attempt.id, answers: nextAnswers, position: nextPosition, revision: revision.current, updatedAt: Date.now(), dirty: dirty.current }).then((saved) => setStorageAvailable(saved));
    }

    function edit(value: Answer): void {
        const nextAnswers = { ...answersRef.current, [item.id]: value };
        answersRef.current = nextAnswers;
        dirty.current = true;
        editVersion.current += 1;
        setAnswers(nextAnswers);
        persistLocal(nextAnswers, positionRef.current);
        report('unsaved');
    }

    async function requestSave(payload: RecoveryPayload): Promise<{ revision: number; status: string }> {
        const token = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=');
        const response = await fetch('/portal/attempts/' + attempt.id + '/answers', { method: 'PUT', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(token ?? '') }, body: JSON.stringify(payload) });
        const result = await response.json().catch(() => null) as { revision?: number; status?: string; errors?: Record<string, string[]> } | null;
        if (!response.ok) throw new SaveRejected(result?.errors ? Object.values(result.errors).flat().join(' ') : 'Unable to sync this answer. Reload before continuing.', response.status);
        return { revision: result?.revision ?? payload.revision, status: result?.status ?? 'in_progress' };
    }

    async function flushPending(): Promise<FlushOutcome> {
        if (pendingRef.current.length === 0) return 'synced';
        if (!navigator.onLine) return 'offline';
        if (flushing.current || saving.current) return 'failed';
        flushing.current = true;
        // Background sync keeps the buttons usable; a tap waits for it (see waitForIdle).
        report('syncing');
        try {
            while (pendingRef.current.length > 0 && navigator.onLine) {
                const queued = pendingRef.current[0];
                if (!queued) break;
                // Preserve the original revision so stale recovery cannot overwrite another tab.
                const result = await requestSave(queued);
                revision.current = result.revision;
                if (!await removePending(attempt.id)) throw new Error('Saved to the server, but this tablet could not update its copy. Keep this page open.');
                pendingRef.current.shift();
                if (result.status !== 'in_progress') {
                    await clearRecovery(attempt.id);
                    router.visit('/portal/attempts/' + attempt.id + '/success');
                    return 'failed';
                }
            }
            persistLocal(answersRef.current, positionRef.current);
            setConnection(navigator.onLine ? 'online' : 'offline');
            report(pendingRef.current.length > 0 ? 'offline' : dirty.current ? 'unsaved' : 'saved');

            return pendingRef.current.length > 0 ? 'offline' : 'synced';
        } catch (error) {
            if (error instanceof SaveRejected && error.status === 422) {
                // The server refused a queued answer (the attempt changed on another
                // tab or device). Retrying cannot succeed, so the queue is dropped
                // and the answers saved on the server are loaded again.
                pendingRef.current = [];
                await clearRecovery(attempt.id);
                report('failed', `${error.message} The answers saved on the server are being loaded again.`);
                window.setTimeout(() => window.location.reload(), 2500);
                return 'failed';
            }
            if (isTransient(error)) {
                // The server is still unreachable: the queue stays on the tablet and is sent again later.
                setConnection('offline');
                report('offline');

                return 'offline';
            }
            report('failed', error instanceof Error ? error.message : 'Try again when the connection returns.');

            return 'failed';
        } finally {
            flushing.current = false;
        }
    }

    /**
     * Waits (up to 15 s) for a save or sync already in progress. A tap on Save
     * and Next, a question in the list, or Submit while an autosave is running
     * then continues instead of being lost or reported as a connection failure.
     */
    async function waitForIdle(): Promise<void> {
        for (let waited = 0; (saving.current || flushing.current) && waited < 15000; waited += 100) {
            await new Promise((resolve) => window.setTimeout(resolve, 100));
        }
    }

    async function save(next = positionRef.current): Promise<boolean> {
        if (!recoveryReady) return false;
        await waitForIdle();
        if (saving.current || flushing.current) return false;
        // Answers already queued on the tablet are sent first, in order. While the
        // server stays unreachable this answer joins the queue, so the candidate
        // can keep working through the examination.
        let queueOnly = !navigator.onLine;
        if (pendingRef.current.length > 0 && navigator.onLine) {
            const outcome = await flushPending();
            if (outcome === 'failed') return false;
            queueOnly = outcome === 'offline';
        }
        const currentItem = getQuestion(questions, positionRef.current);
        const currentAnswer = answersRef.current[currentItem.id] ?? { value: null, flagged: false };
        const tail = pendingRef.current.at(-1);
        const version = editVersion.current;
        const moving = next !== positionRef.current;
        const payload: RecoveryPayload = { position: positionRef.current, next_position: next, revision: tail ? tail.revision + 1 : revision.current, answer: currentAnswer.value, flagged: currentAnswer.flagged };
        saving.current = true;
        // Background autosaves keep the buttons usable; moving to another question disables them.
        setBusy(moving);
        setNavigating(moving);
        report('saving');
        try {
            if (queueOnly || !navigator.onLine) throw new TypeError('offline');
            const result = await requestSave(payload);
            revision.current = result.revision;
            // Answers typed while this save was in flight are still unsaved.
            dirty.current = editVersion.current !== version;
            setConnection('online');
            setPosition(next);
            positionRef.current = next;
            persistLocal(answersRef.current, next);
            report(dirty.current ? 'unsaved' : 'saved');
            // Typing continued during the save: save the newer answer shortly.
            if (dirty.current) window.setTimeout(() => { if (!saving.current && !flushing.current) void save(); }, 900);
            if (result.status !== 'in_progress') router.visit(`/portal/attempts/${attempt.id}/success`);
            return result.status === 'in_progress';
        } catch (error) {
            if (isTransient(error) || !navigator.onLine) {
                if (!await enqueueRecovery(attempt.id, payload)) {
                    setStorageAvailable(false);
                    setConnection('offline');
                    report('failed', 'This tablet cannot keep answers while offline. Keep this page open and reconnect.');
                    return false;
                }
                pendingRef.current = [...pendingRef.current, payload];
                dirty.current = editVersion.current !== version;
                setConnection('offline');
                setPosition(next);
                positionRef.current = next;
                persistLocal(answersRef.current, next);
                report('offline');
                return true;
            }
            report('failed', error instanceof Error ? error.message : 'Try again before continuing.');
            return false;
        } finally {
            saving.current = false;
            setBusy(false);
            setNavigating(false);
        }
    }

    const CONNECTION_FAILED = 'The examination could not be submitted because the connection was interrupted. Your answers are saved on this tablet. Reconnect and select Submit Examination again.';

    async function submit(): Promise<void> {
        setSubmitError(null);
        if (!await save(positionRef.current) || pendingRef.current.length > 0) {
            if (pendingRef.current.length > 0 && navigator.onLine) await flushPending();
            if (pendingRef.current.length > 0 || dirty.current) {
                setConfirm(false);
                setSubmitError(CONNECTION_FAILED);
                return;
            }
        }
        setBusy(true);
        router.post('/portal/attempts/' + attempt.id + '/submit', { position: positionRef.current, revision: revision.current, answer: (answersRef.current[getQuestion(questions, positionRef.current).id] ?? { value: null }).value }, {
            onError: (errors) => setSubmitError(`The examination was not submitted: ${Object.values(errors).join(' ')}`),
            onNetworkError: () => { setSubmitError(CONNECTION_FAILED); return false; },
            onFinish: () => { setBusy(false); setConfirm(false); },
        });
    }

    const lowTime = remaining > 0 && remaining <= LOW_TIME_MS;
    const answeredCount = questions.filter((question) => isAnswered(answers[question.id])).length;
    const flaggedCount = questions.filter((question) => answers[question.id]?.flagged).length;
    const unansweredCount = questions.length - answeredCount;
    const isLast = position === questions.length - 1;
    const canJump = !attempt.oneQuestionAtATime && attempt.allowBackNavigation;
    const statusView = SAVE_STATUS[status];
    const promptId = `question-${item.id}-prompt`;
    const progressPercent = questions.length === 0 ? 0 : Math.round((answeredCount / questions.length) * 100);
    const openSubmit = () => { setSubmitError(null); setConfirm(true); };

    const questionList = (compact: boolean) => <QuestionList questions={questions} answers={answers} position={position} canJump={canJump} allowBack={attempt.allowBackNavigation} disabled={!recoveryReady || busy} compact={compact} onJump={(index) => { setShowList(false); void save(index); }} />;

    return <><Head title={attempt.title} /><div className="mx-auto grid max-w-6xl gap-6 lg:grid-cols-[minmax(0,1fr)_19rem] [&_button]:min-h-12">
        <div className="min-w-0 space-y-5">
            <header className="rounded-xl border border-line-box bg-surface p-5 shadow-sm">
                <p className="text-sm font-medium text-primary-700">{candidateName}</p>
                <h1 className="mt-1 text-xl font-bold break-words sm:text-2xl">{attempt.title}</h1>
                <div className="mt-4 flex flex-wrap items-center justify-between gap-2 text-sm">
                    <span className="font-medium">Question {position + 1} of {questions.length}</span>
                    <span className="text-ink-muted">{answeredCount} of {questions.length} answered</span>
                </div>
                <div className="mt-2 h-2 overflow-hidden rounded-full bg-surface-muted" role="progressbar" aria-label="Questions answered" aria-valuemin={0} aria-valuemax={questions.length} aria-valuenow={answeredCount}>
                    <div className="h-full rounded-full bg-primary-600 transition-[width] motion-reduce:transition-none" style={{ width: `${progressPercent}%` }} />
                </div>
                <p className="mt-3 text-xs text-ink-muted">Leaving this screen is recorded for your instructor.</p>
            </header>

            {/* Tablets in portrait: time and the question list in a sticky bar. */}
            <div className="sticky top-0 z-10 rounded-xl border border-line-box bg-surface p-3 shadow-sm lg:hidden">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <TimeDisplay remaining={remaining} lowTime={lowTime} hidden={timeHidden} onToggle={() => setTimeHidden((value) => !value)} compact />
                    <Button variant="secondary" size="sm" aria-expanded={showList} aria-controls="question-list-compact" onClick={() => setShowList((value) => !value)}>
                        <ListOrdered className="size-4" aria-hidden="true" />
                        {showList ? 'Hide Questions' : 'Questions'}
                    </Button>
                </div>
                {showList && <div id="question-list-compact" className="mt-3 border-t border-line pt-3">{questionList(true)}</div>}
            </div>

            <p className="sr-only" aria-live="polite">{timeNotice}</p>
            {awayNotice !== null && <div role="alert" className="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-warning-border bg-warning-bg p-3 text-sm text-warning-fg"><p>You left the examination screen for about {plural(awayNotice, 'second', 'seconds')}. This has been recorded for your instructor. Your answers were not changed.</p><Button variant="secondary" size="sm" onClick={() => setAwayNotice(null)}>Dismiss</Button></div>}
            {connection === 'offline' && <div role="alert" className="flex items-start gap-3 rounded-lg border border-warning-border bg-warning-bg p-3 text-sm text-warning-fg"><CloudOff className="mt-0.5 size-5 shrink-0" aria-hidden="true" /><p>Connection interrupted. Keep this page open. Your answers are being kept on this tablet and will be saved to the server when the connection returns.</p></div>}
            {!storageAvailable && <p role="alert" className="rounded-lg border border-warning-border bg-warning-bg p-3 text-sm text-warning-fg">This tablet cannot keep a copy of your answers. Answers are still saved to the server while connected; keep this page open.</p>}
            {submitError && <p role="alert" className="rounded-lg border border-danger-border bg-danger-bg p-3 text-danger-fg">{submitError}</p>}

            <fieldset disabled={!recoveryReady || navigating || remaining === 0} aria-describedby={promptId} className="overflow-hidden rounded-xl border border-line-box bg-surface shadow-sm">
                <legend className="sr-only">Question {position + 1} of {questions.length}</legend>
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line bg-surface-muted px-5 py-3 sm:px-8">
                    <h2 ref={questionHeading} tabIndex={-1} className="text-base font-semibold focus:outline-none">Question {position + 1}</h2>
                    <div className="flex items-center gap-3">
                        <span className="rounded-full bg-surface px-3 py-1 text-sm font-medium text-ink-muted ring-1 ring-line">{plural(Number(item.points), 'point', 'points')}</span>
                        {attempt.allowBackNavigation && <button type="button" aria-pressed={answer.flagged} onClick={() => edit({ ...answer, flagged: !answer.flagged })} className={'inline-flex min-h-11 items-center gap-2 rounded-lg border px-3 text-sm font-medium ' + (answer.flagged ? 'border-warning-border bg-warning-bg text-warning-fg' : 'border-line-strong bg-surface text-ink hover:bg-surface-muted')}>
                            <Flag className="size-4" aria-hidden="true" fill={answer.flagged ? 'currentColor' : 'none'} />
                            {answer.flagged ? 'Flagged for review' : 'Flag for review'}
                        </button>}
                    </div>
                </div>
                <div className="p-5 sm:p-8">
                    <p id={promptId} className="whitespace-pre-wrap break-words text-xl font-semibold leading-relaxed">{item.question.prompt}</p>
                    <QuestionMediaList className="mt-5 space-y-4" media={item.question.media ?? []} urlFor={(media) => `/portal/attempts/${attempt.id}/media/${media.id}`} />
                    {item.question.type.value === 'essay'
                        ? <label className="mt-6 block font-medium">Your answer<textarea className="mt-2 min-h-64 w-full rounded-lg border border-line-strong p-4 text-base font-normal leading-relaxed focus-visible:outline-2 focus-visible:outline-primary-600" maxLength={20000} value={typeof answer.value === 'string' ? answer.value : ''} onChange={(event) => edit({ ...answer, value: event.target.value })} /><span className="mt-1 block text-right text-xs font-normal text-ink-muted">{(typeof answer.value === 'string' ? answer.value.length : 0).toLocaleString()} / 20,000 characters</span></label>
                        : <div className="mt-6 grid gap-3">{item.question.choices.map((choice, index) => {
                            const selected = answer.value === choice.id;
                            return <label key={choice.id} className={'relative flex min-h-14 cursor-pointer items-center gap-3 rounded-lg p-4 transition-colors focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-primary-600 motion-reduce:transition-none ' + (selected ? 'border-2 border-primary-600 bg-primary-50 ring-2 ring-primary-600/30' : 'border border-line-strong hover:border-primary-600 hover:bg-primary-50/40')}>
                                <input type="radio" className="sr-only" name={'question-' + item.id} checked={selected} onChange={() => edit({ ...answer, value: choice.id })} />
                                <span aria-hidden="true" className={'flex size-9 shrink-0 items-center justify-center rounded-full border-2 text-sm font-bold ' + (selected ? 'border-primary-600 bg-primary-600 text-white' : 'border-line-strong text-ink-muted')}>{CHOICE_LETTERS[index] ?? index + 1}</span>
                                <span className="min-w-0 flex-1 break-words text-base"><span>{choice.text}</span>{choice.image && <img src={`/portal/attempts/${attempt.id}/media/${choice.image.id}`} alt={choice.image.description} width={choice.image.width ?? undefined} height={choice.image.height ?? undefined} className="mt-2 block h-auto max-h-56 w-auto max-w-full rounded-md border border-line object-contain" />}</span>
                                {selected && <span className="flex shrink-0 items-center gap-1 text-sm font-semibold text-primary-800"><Check className="size-5" aria-hidden="true" />Selected</span>}
                            </label>;
                        })}</div>}
                </div>
            </fieldset>

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div role="status" className="max-w-xl text-sm">
                    <p className={'flex items-center gap-2 font-medium ' + statusView.tone}>{statusView.icon}{statusView.label}</p>
                    {detail && status !== 'failed' && <p className="text-ink-muted">{detail}</p>}
                </div>
                <Button variant="ghost" disabled={!recoveryReady || busy || remaining === 0} onClick={() => void save()}>Save Answer</Button>
            </div>
            {status === 'failed' && detail && <p role="alert" className="rounded-lg border border-danger-border bg-danger-bg p-3 text-sm text-danger-fg">{detail}</p>}

            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-5">
                {attempt.allowBackNavigation
                    ? <Button variant="secondary" size="lg" disabled={!recoveryReady || busy || position === 0} onClick={() => void save(position - 1)}><ChevronLeft className="size-5" aria-hidden="true" />Previous</Button>
                    : <p className="max-w-sm text-sm text-ink-muted">This examination does not allow returning to earlier questions.</p>}
                <div className="flex flex-wrap gap-3">
                    {!isLast && <Button size="lg" disabled={!recoveryReady || busy || remaining === 0} onClick={() => void save(position + 1)}>Save and Next<ChevronRight className="size-5" aria-hidden="true" /></Button>}
                    {isLast && <Button size="lg" disabled={!recoveryReady || busy || remaining === 0} onClick={openSubmit}>Submit Examination</Button>}
                    {!isLast && canJump && <Button variant="secondary" size="lg" className="lg:hidden" disabled={!recoveryReady || busy || remaining === 0} onClick={openSubmit}>Submit Examination</Button>}
                </div>
            </div>
        </div>

        <aside className="hidden lg:block" aria-label="Examination progress">
            <div className="sticky top-4 space-y-4">
                <section className="rounded-xl border border-line-box bg-surface p-4 shadow-sm" aria-labelledby="questions-heading">
                    <h2 id="questions-heading" className="text-lg font-semibold">Questions</h2>
                    <div className="mt-3 max-h-[45vh] overflow-y-auto pr-1">{questionList(false)}</div>
                </section>
                <section className="rounded-xl border border-line-box bg-surface p-4 shadow-sm">
                    <TimeDisplay remaining={remaining} lowTime={lowTime} hidden={timeHidden} onToggle={() => setTimeHidden((value) => !value)} />
                    <p className="mt-3 text-sm text-ink-muted">Attempt ends: <span className="font-medium text-ink">{dateTime(attempt.expiresAt)}</span></p>
                </section>
                <section className="space-y-3 rounded-xl border border-line-box bg-surface p-4 shadow-sm">
                    <dl className="grid grid-cols-3 gap-2 text-center text-sm">
                        <div><dt className="text-ink-muted">Answered</dt><dd className="text-lg font-semibold">{answeredCount}</dd></div>
                        <div><dt className="text-ink-muted">Remaining</dt><dd className="text-lg font-semibold">{unansweredCount}</dd></div>
                        <div><dt className="text-ink-muted">Flagged</dt><dd className="text-lg font-semibold">{flaggedCount}</dd></div>
                    </dl>
                    {(isLast || canJump) && <Button className="w-full" disabled={!recoveryReady || busy || remaining === 0} onClick={openSubmit}>Submit Examination</Button>}
                </section>
            </div>
        </aside>

        <ConfirmDialog open={confirm} title="Submit examination?" description={<div className="space-y-2">
            <p>You have answered {answeredCount} of {questions.length} questions.</p>
            {unansweredCount > 0 && <p className="font-medium">{plural(unansweredCount, 'question is', 'questions are')} unanswered.</p>}
            {flaggedCount > 0 && <p>{plural(flaggedCount, 'question is', 'questions are')} flagged for review.</p>}
            <p>Your current answer will be saved. You cannot change answers after submitting.</p>
        </div>} confirmLabel="Submit Examination" cancelLabel="Continue Examination" tone="primary" processing={busy} onConfirm={() => void submit()} onCancel={() => setConfirm(false)} />
    </div></>;
}

/** "9 Minutes, 53 Seconds" / "1 Hour, 5 Minutes" (UI_UX_DESIGN.md §25). */
function durationInWords(milliseconds: number): string {
    const total = Math.max(0, Math.ceil(milliseconds / 1000));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = total % 60;
    const unit = (count: number, one: string) => `${count} ${count === 1 ? one : `${one}s`}`;
    if (hours > 0) return `${unit(hours, 'Hour')}, ${unit(minutes, 'Minute')}`;
    if (minutes > 0) return `${unit(minutes, 'Minute')}, ${unit(seconds, 'Second')}`;
    return unit(seconds, 'Second');
}

function clock(milliseconds: number): string {
    const total = Math.max(0, Math.ceil(milliseconds / 1000));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = String(total % 60).padStart(2, '0');
    return hours > 0 ? `${hours}:${String(minutes).padStart(2, '0')}:${seconds}` : `${minutes}:${seconds}`;
}

/**
 * Remaining time. Candidates may hide the countdown (it can be distracting);
 * the low-time warning and spoken announcements still appear when hidden.
 */
function TimeDisplay({ remaining, lowTime, hidden, onToggle, compact = false }: { remaining: number; lowTime: boolean; hidden: boolean; onToggle: () => void; compact?: boolean }) {
    return <div className={compact ? 'flex flex-wrap items-center gap-x-3 gap-y-1' : ''}>
        <p className="flex items-center gap-2 text-sm font-medium">
            <Clock className="size-4 text-ink-muted" aria-hidden="true" />
            <span id={compact ? 'time-label-compact' : 'time-label'}>Time Running:</span>
            <button type="button" onClick={onToggle} aria-pressed={hidden} className="inline-flex items-center rounded px-2 font-semibold text-primary-700 underline underline-offset-2 hover:text-primary-900">{hidden ? 'Show' : 'Hide'}</button>
        </p>
        {!hidden && <p role="timer" aria-labelledby={compact ? 'time-label-compact' : 'time-label'} className={(compact ? 'text-lg' : 'mt-1 text-xl') + ' font-semibold tabular-nums ' + (lowTime ? 'text-warning-fg' : 'text-ink')}>
            {compact ? clock(remaining) : durationInWords(remaining)}
        </p>}
        {lowTime && <p className={'flex items-center gap-1.5 rounded-md border border-warning-border bg-warning-bg px-2 py-1 text-xs font-semibold text-warning-fg ' + (compact ? '' : 'mt-2')}><CircleAlert className="size-4" aria-hidden="true" />5 minutes or less left</p>}
    </div>;
}

/**
 * Every question with its state: answered, not answered, or flagged. Jumping
 * is allowed only when the examination permits free navigation.
 */
function QuestionList({ questions, answers, position, canJump, allowBack, disabled, compact, onJump }: { questions: Item[]; answers: Record<number, Answer>; position: number; canJump: boolean; allowBack: boolean; disabled: boolean; compact: boolean; onJump: (index: number) => void }) {
    return <>
        <ol className={compact ? 'grid grid-cols-[repeat(auto-fill,minmax(7.5rem,1fr))] gap-2' : 'space-y-1'}>
            {questions.map((question, index) => {
                const done = isAnswered(answers[question.id]);
                const flagged = answers[question.id]?.flagged ?? false;
                const current = index === position;
                const Icon = flagged ? Flag : done ? CircleCheck : CircleHelp;
                const label = `Question ${index + 1}${current ? ', current' : ''}, ${done ? 'answered' : 'not answered'}${flagged ? ', flagged for review' : ''}`;
                const content = <>
                    <Icon className={'size-5 shrink-0 ' + (flagged ? 'text-warning-fg' : done ? 'text-success-fg' : 'text-ink-subtle')} aria-hidden="true" fill={flagged ? 'currentColor' : 'none'} />
                    <span aria-hidden="true">Question {index + 1}</span>
                </>;
                const classes = 'flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm ' + (current ? 'bg-primary-50 font-semibold text-primary-900 ring-2 ring-primary-600' : 'text-ink');
                return <li key={question.id}>
                    {canJump
                        ? <button type="button" disabled={disabled} aria-current={current ? 'step' : undefined} aria-label={label} onClick={() => onJump(index)} className={classes + (current ? '' : ' font-medium text-primary-700 hover:bg-surface-muted')}>{content}</button>
                        : <span aria-label={label} aria-current={current ? 'step' : undefined} className={classes + ' min-h-11'}>{content}</span>}
                </li>;
            })}
        </ol>
        <p className="mt-3 flex flex-wrap gap-x-3 gap-y-1 text-xs text-ink-muted" aria-hidden="true">
            <span className="flex items-center gap-1"><CircleCheck className="size-3.5 text-success-fg" />Answered</span>
            <span className="flex items-center gap-1"><CircleHelp className="size-3.5" />Not answered</span>
            <span className="flex items-center gap-1"><Flag className="size-3.5 text-warning-fg" fill="currentColor" />Flagged</span>
        </p>
        {!canJump && <p className="mt-2 text-xs text-ink-muted">{allowBack ? 'Use Previous and Save and Next to move between questions.' : 'Questions are answered in order. You cannot return to earlier questions.'}</p>}
    </>;
}

function getQuestion(questions: Item[], position: number): Item { const item = questions[position]; if (!item) throw new Error('The examination question is unavailable.'); return item; }

