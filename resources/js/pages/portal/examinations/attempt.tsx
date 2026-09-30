import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { QuestionMediaList } from '@/components/question-bank/question-media';
import { Button } from '@/components/ui/button';
import type { QuestionMediaView } from '@/types/question-bank';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { clearRecovery, enqueueRecovery, loadRecovery, removePending, saveSnapshot, type RecoveryAnswer, type RecoveryPayload } from '@/lib/exam-recovery';

type Answer = RecoveryAnswer;
type FocusReason = 'hidden' | 'blur';
interface FocusReport { event: 'left' | 'returned'; reason: FocusReason; at: number }
interface Item { id: number; points: string; question: { prompt: string; type: { value: string }; choices: { id: number; text: string }[]; media?: QuestionMediaView[] } }
interface Attempt { id: number; title: string; expiresAt: string; serverNow: string; allowBackNavigation: boolean; oneQuestionAtATime: boolean; position: number; revision: number; answers: Record<number, Answer> }

export default function ExamAttempt({ attempt, questions }: { attempt: Attempt; questions: Item[] }) {
    const [position, setPosition] = useState(attempt.position);
    const [answers, setAnswers] = useState<Record<number, Answer>>(attempt.answers ?? {});
    const [state, setState] = useState('Saved');
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

    useEffect(() => { answersRef.current = answers; }, [answers]);
    useEffect(() => { positionRef.current = position; }, [position]);

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
                setState('Recovered on this device — awaiting server save');
            }
            setRecoveryReady(true);
            if (pending.length > 0 && navigator.onLine) void flushPending();
        });
        return () => { mounted = false; };
    }, [attempt.id]);

    useEffect(() => {
        const onOffline = () => { setConnection('offline'); setState('Connection interrupted — checking local recovery'); };
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
        setAnswers(nextAnswers);
        persistLocal(nextAnswers, positionRef.current);
        setState('Not saved yet');
    }

    async function requestSave(payload: RecoveryPayload): Promise<{ revision: number; status: string }> {
        const token = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=');
        const response = await fetch('/portal/attempts/' + attempt.id + '/answers', { method: 'PUT', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(token ?? '') }, body: JSON.stringify(payload) });
        const result = await response.json().catch(() => null) as { revision?: number; status?: string; errors?: Record<string, string[]> } | null;
        if (!response.ok) throw new Error(result?.errors ? Object.values(result.errors).flat().join(' ') : 'Unable to sync this answer. Reload before continuing.');
        return { revision: result?.revision ?? payload.revision, status: result?.status ?? 'in_progress' };
    }

    async function flushPending(): Promise<void> {
        if (flushing.current || saving.current || !navigator.onLine || pendingRef.current.length === 0) return;
        flushing.current = true;
        setBusy(true);
        setState('Syncing…');
        try {
            while (pendingRef.current.length > 0 && navigator.onLine) {
                const queued = pendingRef.current[0];
                if (!queued) break;
                // Preserve the original revision so stale recovery cannot overwrite another tab.
                const result = await requestSave(queued);
                revision.current = result.revision;
                if (!await removePending(attempt.id)) throw new Error('Saved to server, but local recovery could not be updated. Keep this page open and retry.');
                pendingRef.current.shift();
                if (result.status !== 'in_progress') {
                    await clearRecovery(attempt.id);
                    router.visit('/portal/attempts/' + attempt.id + '/success');
                    return;
                }
            }
            persistLocal(answersRef.current, positionRef.current);
            setConnection(navigator.onLine ? 'online' : 'offline');
            setState(pendingRef.current.length > 0 ? 'Offline — saved on this device' : dirty.current ? 'Recovered answer awaiting save' : 'Saved');
        } catch (error) {
            setState(error instanceof Error ? error.message : 'Unable to sync. Retry when connected.');
        } finally {
            flushing.current = false;
            setBusy(false);
        }
    }

    async function save(next = positionRef.current): Promise<boolean> {
        if (!recoveryReady || saving.current || flushing.current) return false;
        if (pendingRef.current.length > 0 && navigator.onLine) {
            await flushPending();
            if (pendingRef.current.length > 0) return false;
        }
        const currentItem = getQuestion(questions, positionRef.current);
        const currentAnswer = answersRef.current[currentItem.id] ?? { value: null, flagged: false };
        const tail = pendingRef.current.at(-1);
        const payload: RecoveryPayload = { position: positionRef.current, next_position: next, revision: tail ? tail.revision + 1 : revision.current, answer: currentAnswer.value, flagged: currentAnswer.flagged };
        saving.current = true;
        setBusy(true);
        setState('Saving…');
        try {
            if (!navigator.onLine) throw new TypeError('offline');
            const result = await requestSave(payload);
            revision.current = result.revision;
            dirty.current = false;
            setConnection('online');
            setPosition(next);
            positionRef.current = next;
            persistLocal(answersRef.current, next);
            setState('Saved');
            if (result.status !== 'in_progress') router.visit(`/portal/attempts/${attempt.id}/success`);
            return result.status === 'in_progress';
        } catch (error) {
            if (error instanceof TypeError || !navigator.onLine) {
                if (!await enqueueRecovery(attempt.id, payload)) {
                    setStorageAvailable(false);
                    setConnection('offline');
                    setState('Not saved — local recovery is unavailable. Keep this page open and reconnect.');
                    return false;
                }
                pendingRef.current = [...pendingRef.current, payload];
                dirty.current = false;
                setConnection('offline');
                setPosition(next);
                positionRef.current = next;
                persistLocal(answersRef.current, next);
                setState('Offline — saved on this device');
                return true;
            }
            setState(error instanceof Error ? error.message : 'Unable to save. Retry before continuing.');
            return false;
        } finally {
            saving.current = false;
            setBusy(false);
        }
    }

    async function submit(): Promise<void> {
        if (!await save(positionRef.current)) return;
        if (pendingRef.current.length > 0) {
            await flushPending();
            if (pendingRef.current.length > 0) { setState('Unable to sync. Reconnect before submitting.'); return; }
        }
        setBusy(true);
        router.post('/portal/attempts/' + attempt.id + '/submit', { position: positionRef.current, revision: revision.current, answer: (answersRef.current[getQuestion(questions, positionRef.current).id] ?? { value: null }).value }, { onError: (errors) => setState(Object.values(errors).join(' ')), onFinish: () => { setBusy(false); setConfirm(false); } });
    }

    const seconds = Math.ceil(remaining / 1000);
    const completed = Object.values(answers).filter((value) => value.value !== null && value.value !== '').length;
    const connectionLabel = state;

    return <><Head title={attempt.title} /><div className="mx-auto max-w-4xl space-y-6 [&_button]:min-h-12">
        <header className="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line bg-surface p-4"><div><h1 className="font-semibold">{attempt.title}</h1><p>Question {position + 1} of {questions.length} · {completed} answered</p></div><p className="w-full text-sm text-ink-muted sm:order-last">Stay on this screen until you submit. Switching to another tab, app, or window is recorded and visible to your instructor.</p><p className="text-xl font-semibold tabular-nums" role="timer" aria-label="Time remaining">{Math.floor(seconds / 60)}:{String(seconds % 60).padStart(2, '0')}</p></header>
        {awayNotice !== null && <div role="alert" className="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-warning-border bg-warning-bg p-3 text-sm text-warning-fg"><p>You left the examination screen for about {awayNotice} {awayNotice === 1 ? 'second' : 'seconds'}. This has been recorded for your instructor. Your answers were not changed.</p><Button variant="secondary" size="sm" onClick={() => setAwayNotice(null)}>Dismiss</Button></div>}
        {connection === 'offline' && <div role="alert" className="rounded-lg border border-warning-border bg-warning-bg p-3 text-sm text-warning-fg">Connection interrupted. Check the save status below. Keep this page open; saved recovery answers will sync when the institutional network returns.</div>}
        {!storageAvailable && <p role="alert" className="rounded-lg border border-warning-border bg-warning-bg p-3 text-warning-fg">Local recovery is unavailable. Server saving still works when connected. Keep this page open until the answers are saved to the server.</p>}
        {!attempt.oneQuestionAtATime && attempt.allowBackNavigation && <nav aria-label="Question navigator" className="flex flex-wrap gap-2">{questions.map((q, index) => <button key={q.id} disabled={!recoveryReady || busy} className="min-h-12 min-w-12 rounded border border-line bg-surface p-2" aria-current={index === position ? 'step' : undefined} onClick={() => void save(index)}>{index + 1}{answers[q.id]?.flagged ? ' ⚑' : answers[q.id]?.value != null ? ' ✓' : ''}</button>)}</nav>}
        <fieldset disabled={!recoveryReady || busy || remaining === 0} className="rounded-xl border border-line bg-surface p-5 sm:p-8"><legend className="sr-only">Question {position + 1}</legend><p className="whitespace-pre-wrap text-xl font-semibold leading-relaxed">{item.question.prompt}</p><QuestionMediaList className="mt-5 space-y-4" media={item.question.media ?? []} urlFor={(media) => `/portal/attempts/${attempt.id}/media/${media.id}`} />
            {item.question.type.value === 'essay' ? <label className="mt-6 block">Your answer<textarea className="mt-2 min-h-56 w-full rounded border border-line-strong p-4 text-base" maxLength={20000} value={typeof answer.value === 'string' ? answer.value : ''} onChange={(event) => edit({ ...answer, value: event.target.value })} /></label> : <div className="mt-6 grid gap-3">{item.question.choices.map((choice) => <label key={choice.id} className={'flex min-h-14 cursor-pointer items-center gap-3 rounded-lg border p-4 ' + (answer.value === choice.id ? 'border-primary-600 bg-primary-50' : 'border-line-strong')}><input type="radio" className="size-5" name={'question-' + item.id} checked={answer.value === choice.id} onChange={() => edit({ ...answer, value: choice.id })} /><span>{choice.text}</span></label>)}</div>}
            {attempt.allowBackNavigation && <label className="mt-6 flex min-h-12 items-center gap-3"><input type="checkbox" className="size-5" checked={answer.flagged} onChange={(event) => edit({ ...answer, flagged: event.target.checked })} />Flag for review</label>}
        </fieldset>
        <div className="flex flex-wrap items-center justify-between gap-3"><p role="status" className="max-w-xl text-sm">{connectionLabel}</p><Button variant="secondary" disabled={!recoveryReady || busy || remaining === 0} onClick={() => void save()}>Save answer</Button></div>
        <div className="flex flex-wrap justify-between gap-3"><Button variant="secondary" disabled={!recoveryReady || busy || position === 0 || !attempt.allowBackNavigation} onClick={() => void save(position - 1)}>Previous</Button>{position < questions.length - 1 ? <Button disabled={!recoveryReady || busy || remaining === 0} onClick={() => void save(position + 1)}>Save and next</Button> : <Button disabled={!recoveryReady || busy || remaining === 0} onClick={() => setConfirm(true)}>Review submission</Button>}</div>
        <ConfirmDialog open={confirm} title="Submit examination?" description={<p>{questions.length - completed} questions are unanswered. Your current answer will be saved. You cannot change answers after submitting.</p>} confirmLabel="Confirm submission" cancelLabel="Continue examination" tone="primary" processing={busy} onConfirm={() => void submit()} onCancel={() => setConfirm(false)} />
    </div></>;
}

function getQuestion(questions: Item[], position: number): Item { const item = questions[position]; if (!item) throw new Error('The examination question is unavailable.'); return item; }

