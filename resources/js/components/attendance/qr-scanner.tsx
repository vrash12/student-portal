import { Camera, CameraOff, CheckCircle2, CircleAlert, Info, LoaderCircle, RefreshCw, ScanLine, UserRound, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { cn } from '@/lib/cn';
import { formatCalendarDate } from '@/lib/format';
import type { AttendanceSessionDetails, AttendanceStatusOption, RollCallCounts } from '@/types/attendance';

/**
 * Attendance by QR code (owner request, 2026-10-02): the instructor opens the
 * camera and scans each candidate's QR code (on the candidate's tablet or a
 * printed card). Each scan marks the candidate present (or late) for this
 * session and shows their photo, name and number so the instructor can check
 * the face.
 *
 * Camera frames are read on this device only (the browser's own QR reader,
 * or jsQR where there is none: Windows and iPad); nothing is recorded or
 * uploaded except the decoded code. The roll call stays available for
 * corrections and for anyone without a card.
 */

type ScanOutcome = 'recorded' | 'updated' | 'already' | 'not_in_class' | 'unknown' | 'error';

interface ScanResponse {
    result: ScanOutcome;
    message: string;
    candidate: {
        name: string;
        candidateNumber: string;
        photoUrl: string | null;
        status: AttendanceStatusOption | null;
    } | null;
    counts: RollCallCounts | null;
}

type CameraProblem = 'insecure' | 'denied' | 'none' | 'busy' | 'failed';

const PROBLEM_TEXT: Record<CameraProblem, string> = {
    insecure: 'The camera only works over a secure (https) connection. Open the system with its https address.',
    denied: 'The camera permission was refused. Allow the camera for this site in the browser settings, then start again.',
    none: 'No camera was found on this device.',
    busy: 'The camera is being used by another app. Close that app, then start again.',
    failed: 'The camera could not be started. Try again, or use the roll call.',
};

/** Same code read again within this time is ignored (the card is still in front of the camera). */
const REPEAT_MS = 4000;

/** Time between decoding attempts. */
const FRAME_MS = 180;

interface BarcodeDetectorLike {
    detect(source: CanvasImageSource): Promise<Array<{ rawValue: string }>>;
}

interface BarcodeDetectorConstructor {
    new (options?: { formats: string[] }): BarcodeDetectorLike;
    getSupportedFormats?: () => Promise<string[]>;
}

type Decoder = (video: HTMLVideoElement) => Promise<string | null>;

/** The browser's own QR reader when it has one; otherwise jsQR, loaded only then. */
async function createDecoder(): Promise<Decoder> {
    const Detector = (window as unknown as { BarcodeDetector?: BarcodeDetectorConstructor }).BarcodeDetector;
    if (Detector !== undefined) {
        try {
            const formats = Detector.getSupportedFormats === undefined ? ['qr_code'] : await Detector.getSupportedFormats();
            if (formats.includes('qr_code')) {
                const detector = new Detector({ formats: ['qr_code'] });

                return async (video) => (await detector.detect(video))[0]?.rawValue ?? null;
            }
        } catch {
            // Fall back to jsQR.
        }
    }

    const { default: jsQR } = await import('jsqr');
    const canvas = window.document.createElement('canvas');
    const context = canvas.getContext('2d', { willReadFrequently: true });

    return async (video) => {
        if (context === null || video.videoWidth === 0) {
            return null;
        }
        const scale = Math.min(1, 720 / video.videoWidth);
        canvas.width = Math.round(video.videoWidth * scale);
        canvas.height = Math.round(video.videoHeight * scale);
        context.drawImage(video, 0, 0, canvas.width, canvas.height);
        const image = context.getImageData(0, 0, canvas.width, canvas.height);

        return jsQR(image.data, image.width, image.height, { inversionAttempts: 'dontInvert' })?.data ?? null;
    };
}

function xsrfToken(): string {
    const cookie = window.document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='));

    return decodeURIComponent(cookie?.split('=').slice(1).join('=') ?? '');
}

interface QrScannerProps {
    open: boolean;
    session: AttendanceSessionDetails;
    scanUrl: string;
    counts: RollCallCounts;
    /** Today in the institution's timezone (Y-m-d). */
    today: string;
    onClose: () => void;
}

export function QrScanner({ open, session, scanUrl, counts: initialCounts, today, onClose }: QrScannerProps) {
    const dialogRef = useRef<HTMLDialogElement>(null);
    const videoRef = useRef<HTMLVideoElement>(null);
    const streamRef = useRef<MediaStream | null>(null);
    const decoderRef = useRef<Decoder | null>(null);
    const audioRef = useRef<AudioContext | null>(null);
    const busyRef = useRef(false);
    const lastRef = useRef<{ code: string; at: number }>({ code: '', at: 0 });
    // Codes recorded in this sitting: shown again without asking the server.
    const doneRef = useRef(new Map<string, ScanResponse>());
    const modeRef = useRef<'present' | 'late'>('present');

    const [mode, setMode] = useState<'present' | 'late'>('present');
    const [state, setState] = useState<'idle' | 'starting' | 'scanning'>('idle');
    const [problem, setProblem] = useState<CameraProblem | null>(null);
    const [cameras, setCameras] = useState<MediaDeviceInfo[]>([]);
    const [cameraIndex, setCameraIndex] = useState(0);
    const [sending, setSending] = useState(false);
    const [last, setLast] = useState<ScanResponse | null>(null);
    const [recent, setRecent] = useState<ScanResponse[]>([]);
    const [counts, setCounts] = useState<RollCallCounts>(initialCounts);

    useEffect(() => {
        modeRef.current = mode;
    }, [mode]);

    const stopCamera = useCallback(() => {
        streamRef.current?.getTracks().forEach((track) => track.stop());
        streamRef.current = null;
        if (videoRef.current !== null) {
            videoRef.current.srcObject = null;
        }
    }, []);

    // Open and close the dialog with the prop: a fresh start on opening, the camera off on closing.
    const wasOpen = useRef(false);
    useEffect(() => {
        const dialog = dialogRef.current;
        if (dialog === null) {
            return;
        }
        if (open && !wasOpen.current) {
            setCounts(initialCounts);
            setLast(null);
            setRecent([]);
            setProblem(null);
            lastRef.current = { code: '', at: 0 };
            doneRef.current.clear();
            if (!dialog.open) {
                dialog.showModal();
            }
        } else if (!open && wasOpen.current) {
            stopCamera();
            setState('idle');
            if (dialog.open) {
                dialog.close();
            }
        }
        wasOpen.current = open;
    }, [open, initialCounts, stopCamera]);

    useEffect(() => () => {
        stopCamera();
        void audioRef.current?.close().catch(() => undefined);
    }, [stopCamera]);

    const beep = useCallback((good: boolean) => {
        const audio = audioRef.current;
        if (audio === null) {
            return;
        }
        try {
            const oscillator = audio.createOscillator();
            const gain = audio.createGain();
            oscillator.frequency.value = good ? 880 : 220;
            gain.gain.value = 0.08;
            oscillator.connect(gain).connect(audio.destination);
            oscillator.start();
            oscillator.stop(audio.currentTime + (good ? 0.12 : 0.3));
        } catch {
            // Sound is a convenience only.
        }
        navigator.vibrate?.(good ? 60 : [80, 60, 80]);
    }, []);

    const startCamera = useCallback(async (index: number) => {
        setProblem(null);
        if (!window.isSecureContext || navigator.mediaDevices?.getUserMedia === undefined) {
            setProblem('insecure');

            return;
        }
        setState('starting');
        stopCamera();
        try {
            // Created from the tap that started the camera, so the browser allows sound.
            audioRef.current ??= new AudioContext();
            void audioRef.current.resume().catch(() => undefined);
            decoderRef.current ??= await createDecoder();

            const chosen = cameras[index];
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: false,
                video: chosen === undefined ? { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } } : { deviceId: { exact: chosen.deviceId } },
            });
            streamRef.current = stream;
            const video = videoRef.current;
            if (video !== null) {
                video.srcObject = stream;
                await video.play();
            }
            const devices = (await navigator.mediaDevices.enumerateDevices()).filter((device) => device.kind === 'videoinput');
            setCameras(devices);
            // Remember which camera is in use, so "Switch Camera" moves to another one.
            const inUse = stream.getVideoTracks()[0]?.getSettings().deviceId;
            const position = devices.findIndex((device) => device.deviceId === inUse);
            if (position >= 0) {
                setCameraIndex(position);
            }
            setState('scanning');
        } catch (error) {
            stopCamera();
            setState('idle');
            const name = error instanceof DOMException ? error.name : '';
            setProblem(
                name === 'NotAllowedError' || name === 'SecurityError'
                    ? 'denied'
                    : name === 'NotFoundError' || name === 'OverconstrainedError'
                      ? 'none'
                      : name === 'NotReadableError'
                        ? 'busy'
                        : 'failed',
            );
        }
    }, [cameras, stopCamera]);

    const submit = useCallback(async (code: string) => {
        busyRef.current = true;
        setSending(true);
        let outcome: ScanResponse;
        try {
            const response = await fetch(scanUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
                body: JSON.stringify({ code, status: modeRef.current }),
            });
            if (response.status === 419 || response.status === 401) {
                outcome = { result: 'error', message: 'Your sign-in has expired. Close the scanner and reload the page.', candidate: null, counts: null };
            } else if (!response.ok) {
                outcome = { result: 'error', message: 'This scan was not recorded. Scan again, or use the roll call.', candidate: null, counts: null };
            } else {
                outcome = (await response.json()) as ScanResponse;
            }
        } catch {
            outcome = { result: 'error', message: 'No connection to the server. This scan was not recorded; scan again when the connection is back.', candidate: null, counts: null };
        }
        if (outcome.result === 'recorded' || outcome.result === 'updated' || outcome.result === 'already') {
            doneRef.current.set(code, outcome);
        }
        setLast(outcome);
        setRecent((list) => [outcome, ...list].slice(0, 6));
        if (outcome.counts !== null) {
            setCounts(outcome.counts);
        }
        beep(outcome.result === 'recorded' || outcome.result === 'updated' || outcome.result === 'already');
        busyRef.current = false;
        setSending(false);
    }, [beep, scanUrl]);

    // Read frames while scanning; one request at a time.
    useEffect(() => {
        if (!open || state !== 'scanning') {
            return;
        }
        let stopped = false;
        let timer = 0;
        const tick = async () => {
            const video = videoRef.current;
            const decode = decoderRef.current;
            if (!stopped && !busyRef.current && video !== null && decode !== null && video.readyState >= 2) {
                try {
                    const code = await decode(video);
                    const now = Date.now();
                    if (code !== null && code !== '' && !(code === lastRef.current.code && now - lastRef.current.at < REPEAT_MS)) {
                        lastRef.current = { code, at: now };
                        const done = doneRef.current.get(code);
                        if (done === undefined) {
                            await submit(code);
                        } else {
                            setLast({ ...done, result: 'already', message: `Already scanned${done.candidate?.status ? ` as ${done.candidate.status.label}` : ''}.` });
                        }
                    }
                } catch {
                    // A frame that cannot be read; try the next one.
                }
            }
            if (!stopped) {
                timer = window.setTimeout(() => void tick(), FRAME_MS);
            }
        };
        timer = window.setTimeout(() => void tick(), FRAME_MS);

        return () => {
            stopped = true;
            window.clearTimeout(timer);
        };
    }, [open, state, submit]);

    const switchCamera = () => {
        const next = (cameraIndex + 1) % Math.max(1, cameras.length);
        setCameraIndex(next);
        void startCamera(next);
    };

    const close = () => {
        stopCamera();
        setState('idle');
        onClose();
    };

    const good = last !== null && (last.result === 'recorded' || last.result === 'updated');
    const neutral = last !== null && last.result === 'already';

    return (
        <dialog
            ref={dialogRef}
            onCancel={(event) => {
                event.preventDefault();
                close();
            }}
            aria-labelledby="qr-scanner-title"
            className="m-0 h-dvh max-h-none w-screen max-w-none bg-ink p-0 text-white backdrop:bg-ink/80"
        >
            {open && (
                <div className="flex h-full flex-col">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-white/15 bg-auth-navy px-4 py-3 sm:px-6">
                        <div className="min-w-0">
                            <h2 id="qr-scanner-title" className="truncate font-serif text-lg font-bold">
                                Scan QR Codes · {session.title}
                            </h2>
                            <p className="text-xs text-primary-100">
                                {session.classBatch.name} · {formatCalendarDate(session.heldOn)}
                                {session.heldOn !== today && ' · not today: scans are recorded for this session'}
                            </p>
                        </div>
                        <Button variant="secondary" size="sm" onClick={close} icon={<X className="size-4" aria-hidden="true" />}>
                            Done
                        </Button>
                    </header>

                    <div className="grid min-h-0 flex-1 gap-4 overflow-auto p-4 lg:grid-cols-[minmax(0,1fr)_24rem] lg:p-6">
                        <div className="flex min-h-0 flex-col gap-3">
                            <div className="relative aspect-[4/3] w-full overflow-hidden rounded-xl bg-black lg:aspect-auto lg:min-h-[28rem] lg:flex-1">
                                <video ref={videoRef} className={cn('size-full object-cover', state !== 'scanning' && 'invisible')} muted playsInline aria-label="Camera view" />
                                {state === 'scanning' && (
                                    <div className="pointer-events-none absolute inset-0 flex items-center justify-center" aria-hidden="true">
                                        <div className="aspect-square w-3/5 max-w-80 rounded-2xl border-4 border-accent-300/90 shadow-[0_0_0_9999px_rgb(0_0_0/0.35)]" />
                                    </div>
                                )}
                                {state !== 'scanning' && (
                                    <div className="absolute inset-0 flex flex-col items-center justify-center gap-4 p-6 text-center">
                                        {state === 'starting' ? (
                                            <LoaderCircle className="size-10 animate-spin text-accent-200" aria-hidden="true" />
                                        ) : problem === null ? (
                                            <Camera className="size-12 text-accent-200" aria-hidden="true" />
                                        ) : (
                                            <CameraOff className="size-12 text-danger-border" aria-hidden="true" />
                                        )}
                                        <p className="max-w-md text-sm text-primary-100">
                                            {state === 'starting'
                                                ? 'Starting the camera…'
                                                : problem === null
                                                  ? 'Point the camera at a candidate’s QR code: on their tablet (My Information) or their printed card. Each code is recorded once.'
                                                  : PROBLEM_TEXT[problem]}
                                        </p>
                                        {state === 'idle' && (
                                            <Button size="lg" onClick={() => void startCamera(cameraIndex)} icon={<ScanLine className="size-5" aria-hidden="true" />}>
                                                {problem === null ? 'Start Camera' : 'Try Again'}
                                            </Button>
                                        )}
                                    </div>
                                )}
                                {sending && (
                                    <span className="absolute top-3 left-3 inline-flex items-center gap-1.5 rounded-full bg-black/70 px-3 py-1 text-xs">
                                        <LoaderCircle className="size-3.5 animate-spin" aria-hidden="true" />
                                        Recording…
                                    </span>
                                )}
                            </div>

                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div className="flex items-center gap-2" role="group" aria-label="Record scanned candidates as">
                                    <span className="text-sm text-primary-100">Record as</span>
                                    {(['present', 'late'] as const).map((value) => (
                                        <button
                                            key={value}
                                            type="button"
                                            aria-pressed={mode === value}
                                            onClick={() => setMode(value)}
                                            className={cn(
                                                'min-h-11 rounded-lg border px-4 text-sm font-semibold',
                                                mode === value ? 'border-accent-300 bg-accent-300 text-primary-900' : 'border-white/30 text-white hover:bg-white/10',
                                            )}
                                        >
                                            {value === 'present' ? 'Present' : 'Late'}
                                        </button>
                                    ))}
                                </div>
                                {state === 'scanning' && cameras.length > 1 && (
                                    <Button variant="secondary" size="sm" onClick={switchCamera} icon={<RefreshCw className="size-4" aria-hidden="true" />}>
                                        Switch Camera
                                    </Button>
                                )}
                            </div>
                        </div>

                        <aside className="flex min-h-0 flex-col gap-4">
                            <div
                                role="status"
                                aria-live="polite"
                                className={cn(
                                    'rounded-xl border-2 p-4',
                                    last === null ? 'border-white/15 bg-white/5' : good ? 'border-success-border bg-success-bg text-success-fg' : neutral ? 'border-info-border bg-info-bg text-info-fg' : 'border-danger-border bg-danger-bg text-danger-fg',
                                )}
                            >
                                {last === null ? (
                                    <p className="text-sm text-primary-100">The last scan shows here, with the candidate’s photo to check against their face.</p>
                                ) : (
                                    <div className="flex items-start gap-4">
                                        {last.candidate?.photoUrl ? (
                                            <img src={last.candidate.photoUrl} alt={`Photo of ${last.candidate.name}`} className="size-24 shrink-0 rounded-lg border border-white object-cover" />
                                        ) : (
                                            <span className="flex size-24 shrink-0 items-center justify-center rounded-lg bg-white/60" aria-hidden="true">
                                                {last.candidate === null ? <CircleAlert className="size-10" /> : <UserRound className="size-10" />}
                                            </span>
                                        )}
                                        <div className="min-w-0">
                                            <p className="flex items-center gap-1.5 font-bold">
                                                {good ? <CheckCircle2 className="size-5" aria-hidden="true" /> : neutral ? <Info className="size-5" aria-hidden="true" /> : <CircleAlert className="size-5" aria-hidden="true" />}
                                                {last.candidate?.name ?? 'Not recorded'}
                                            </p>
                                            {last.candidate !== null && <p className="text-sm">Candidate {last.candidate.candidateNumber}</p>}
                                            {last.candidate?.status && (
                                                <p className="mt-1.5">
                                                    <StatusBadge tone={last.candidate.status.tone}>{last.candidate.status.label}</StatusBadge>
                                                </p>
                                            )}
                                            <p className="mt-1.5 text-sm">{last.message}</p>
                                        </div>
                                    </div>
                                )}
                            </div>

                            <dl className="grid grid-cols-3 gap-2 text-center">
                                {(
                                    [
                                        ['Present', counts.present],
                                        ['Late', counts.late],
                                        ['Not yet', counts.unrecorded],
                                    ] as const
                                ).map(([label, value]) => (
                                    <div key={label} className="rounded-lg bg-white/10 px-2 py-2">
                                        <dt className="text-xs text-primary-100">{label}</dt>
                                        <dd className="text-2xl font-bold tabular-nums">{value}</dd>
                                    </div>
                                ))}
                            </dl>

                            {recent.length > 1 && (
                                <div className="min-h-0">
                                    <h3 className="mb-2 text-xs font-semibold tracking-wider text-primary-100 uppercase">Earlier scans</h3>
                                    <ul className="flex flex-col gap-1.5 text-sm">
                                        {recent.slice(1).map((scan, index) => (
                                            <li key={index} className="flex items-center justify-between gap-3 rounded-lg bg-white/5 px-3 py-2">
                                                <span className="min-w-0 truncate">{scan.candidate?.name ?? scan.message}</span>
                                                <span className="shrink-0 text-xs text-primary-100">{scan.candidate?.status?.label ?? 'Not recorded'}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}

                            <p className="mt-auto text-xs text-primary-100">
                                The camera picture stays on this device; only the code read from it is sent. To correct a status, or for a candidate without a code, use the roll call after closing the scanner.
                            </p>
                        </aside>
                    </div>
                </div>
            )}
        </dialog>
    );
}
