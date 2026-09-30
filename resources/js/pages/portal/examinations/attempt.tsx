import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { clearRecovery, enqueueRecovery, loadRecovery, removePending, saveSnapshot, type RecoveryAnswer, type RecoveryPayload } from '@/lib/exam-recovery';

type Answer = RecoveryAnswer;
interface Item { id: number; points: string; question: { prompt: string; type: { value: string }; choices: { id: number; text: string }[] } }
interface Attempt { id: number; title: string; expiresAt: string; serverNow: string; allowBackNavigation: boolean; oneQuestionAtATime: boolean; position: number; revision: number; answers: Record<number, Answer> }

export default function ExamAttempt({ attempt, questions }: { attempt: Attempt; questions: Item[] }) {
    const [position, setPosition] = useState(attempt.position);
    const [answers, setAnswers] = useState<Record<number, Answer>>(attempt.answers ?? {});
    const [state, setState] = useState('Saved');
    const [connection, setConnection] = useState(navigator.onLine ? 'online' : 'offline');
    const [busy, setBusy] = useState(false);
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
    const item = getQuestion(questions, position);
    const answer = answers[item.id] ?? { value: null, flagged: false };

    useEffect(() => { answersRef.current = answers; }, [answers]);
    useEffect(() => { positionRef.current = position; }, [position]);

    useEffect(() => {
        let mounted = true;
        void loadRecovery(attempt.id).then(({ snapshot, pending }) => {
            if (!mounted) return;
            pendingRef.current = pending;
            if (snapshot) {
                const merged = { ...answersRef.current, ...snapshot.answers };
                answersRef.current = merged;
                setAnswers(merged);
                if (pending.length > 0) {
                    setPosition(snapshot.position);
                    positionRef.current = snapshot.position;
                    setState('Offline — saved on this device');
                    if (navigator.onLine) void flushPending();
                }
            }
        });
        return () => { mounted = false; };
    }, [attempt.id]);

    useEffect(() => {
        const onOffline = () => { setConnection('offline'); setState('Offline — saved on this device'); };
        const onOnline = () => { setConnection('online'); void flushPending(); };
        window.addEventListener('offline', onOffline);
        window.addEventListener('online', onOnline);
        return () => { window.removeEventListener('offline', onOffline); window.removeEventListener('online', onOnline); };
    }, []);

    useEffect(() => {
        if (!dirty.current) return;
        const timer = window.setTimeout(() => { if (!saving.current) void save(); }, 900);
        return () => window.clearTimeout(timer);
    }, [answers]);

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
        void saveSnapshot({ attemptId: attempt.id, answers: nextAnswers, position: nextPosition, revision: revision.current, updatedAt: Date.now() });
    }

    function edit(value: Answer): void {
        const nextAnswers = { ...answersRef.current, [item.id]: value };
        answersRef.current = nextAnswers;
        dirty.current = true;
        setAnswers(nextAnswers);
        persistLocal(nextAnswers, positionRef.current);
        setState(connection === 'offline' ? 'Offline — saved on this device' : 'Not saved yet');
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
                const payload: RecoveryPayload = { ...queued, revision: revision.current };
                const result = await requestSave(payload);
                revision.current = result.revision;
                pendingRef.current.shift();
                await removePending(attempt.id);
                if (result.status !== 'in_progress') {
                    await clearRecovery(attempt.id);
                    router.visit('/portal/attempts/' + attempt.id + '/success');
                    return;
                }
            }
            persistLocal(answersRef.current, positionRef.current);
            setState('Saved');
        } catch (error) {
            setState(error instanceof Error ? error.message : 'Unable to sync. Retry when connected.');
        } finally {
            flushing.current = false;
            setBusy(false);
        }
    }

    async function save(next = positionRef.current): Promise<boolean> {
        if (saving.current) return false;
        const currentItem = getQuestion(questions, positionRef.current);
        const currentAnswer = answersRef.current[currentItem.id] ?? { value: null, flagged: false };
        const payload: RecoveryPayload = { position: positionRef.current, next_position: next, revision: revision.current, answer: currentAnswer.value, flagged: currentAnswer.flagged };
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
            if (pendingRef.current.length > 0) void flushPending();
            return result.status === 'in_progress';
        } catch (error) {
            if (error instanceof TypeError || !navigator.onLine) {
                pendingRef.current = [...pendingRef.current, payload];
                await enqueueRecovery(attempt.id, payload);
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
    const connectionLabel = connection === 'offline' ? 'Offline — saved on this device' : state;

    return <><Head title={attempt.title} /><div className="mx-auto max-w-4xl space-y-6 [&_button]:min-h-12">
        <header className="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line bg-surface p-4"><div><h1 className="font-semibold">{attempt.title}</h1><p>Question {position + 1} of {questions.length} · {completed} answered</p></div><p className="text-xl font-semibold tabular-nums" role="timer" aria-label="Time remaining">{Math.floor(seconds / 60)}:{String(seconds % 60).padStart(2, '0')}</p></header>
        {connection === 'offline' && <div role="alert" className="rounded-lg border border-warning-border bg-warning-bg p-3 text-sm text-warning-fg">Connection interrupted. Answers are stored on this device and will sync automatically when the institutional network returns.</div>}
        {!attempt.oneQuestionAtATime && attempt.allowBackNavigation && <nav aria-label="Question navigator" className="flex flex-wrap gap-2">{questions.map((q, index) => <button key={q.id} disabled={busy} className="min-h-12 min-w-12 rounded border border-line bg-surface p-2" aria-current={index === position ? 'step' : undefined} onClick={() => void save(index)}>{index + 1}{answers[q.id]?.flagged ? ' ⚑' : answers[q.id]?.value != null ? ' ✓' : ''}</button>)}</nav>}
        <fieldset disabled={busy || remaining === 0} className="rounded-xl border border-line bg-surface p-5 sm:p-8"><legend className="sr-only">Question {position + 1}</legend><p className="whitespace-pre-wrap text-xl font-semibold leading-relaxed">{item.question.prompt}</p>
            {item.question.type.value === 'essay' ? <label className="mt-6 block">Your answer<textarea className="mt-2 min-h-56 w-full rounded border border-line-strong p-4 text-base" maxLength={20000} value={typeof answer.value === 'string' ? answer.value : ''} onChange={(event) => edit({ ...answer, value: event.target.value })} /></label> : <div className="mt-6 grid gap-3">{item.question.choices.map((choice) => <label key={choice.id} className={'flex min-h-14 cursor-pointer items-center gap-3 rounded-lg border p-4 ' + (answer.value === choice.id ? 'border-primary-600 bg-primary-50' : 'border-line-strong')}><input type="radio" className="size-5" name={'question-' + item.id} checked={answer.value === choice.id} onChange={() => edit({ ...answer, value: choice.id })} /><span>{choice.text}</span></label>)}</div>}
            {attempt.allowBackNavigation && <label className="mt-6 flex min-h-12 items-center gap-3"><input type="checkbox" className="size-5" checked={answer.flagged} onChange={(event) => edit({ ...answer, flagged: event.target.checked })} />Flag for review</label>}
        </fieldset>
        <div className="flex flex-wrap items-center justify-between gap-3"><p role="status" className="max-w-xl text-sm">{connectionLabel}</p><Button variant="secondary" disabled={busy || remaining === 0} onClick={() => void save()}>Save answer</Button></div>
        <div className="flex flex-wrap justify-between gap-3"><Button variant="secondary" disabled={busy || position === 0 || !attempt.allowBackNavigation} onClick={() => void save(position - 1)}>Previous</Button>{position < questions.length - 1 ? <Button disabled={busy || remaining === 0} onClick={() => void save(position + 1)}>Save and next</Button> : <Button disabled={busy || remaining === 0} onClick={() => setConfirm(true)}>Review submission</Button>}</div>
        <ConfirmDialog open={confirm} title="Submit examination?" description={<p>{questions.length - completed} questions are unanswered. Your current answer will be saved. You cannot change answers after submitting.</p>} confirmLabel="Confirm submission" cancelLabel="Continue examination" tone="primary" processing={busy} onConfirm={() => void submit()} onCancel={() => setConfirm(false)} />
    </div></>;
}

function getQuestion(questions: Item[], position: number): Item { const item = questions[position]; if (!item) throw new Error('The examination question is unavailable.'); return item; }
