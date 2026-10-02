import { EyeOff, LoaderCircle, ShieldAlert, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/cn';
import { formatCalendarDate } from '@/lib/format';
import type { MedicalDocument } from '@/types/medical';

/** Same as MedicalDocumentController::VIEWER_HEADER. */
const VIEWER_HEADER = 'X-Medical-Viewer';

/** Widest page drawn, in CSS pixels. */
const MAX_PAGE_WIDTH = 1100;

/** Body class that blanks the page when printed (see app.css). */
const PRINT_BLOCK_CLASS = 'medical-viewer-open';

interface ProtectedDocumentViewerProps {
    /** The document to show; null closes the viewer. */
    document: MedicalDocument | null;
    /** Shown in the watermark, e.g. the instructor's name. */
    viewerName: string;
    onClose: () => void;
}

/**
 * View-only display of an uploaded medical document for instructors of the
 * candidate's class (owner requests, 2026-10-02: "cannot print it", "can be
 * viewed but not downloadable, not screenshot"; a copy needs an approved
 * download request).
 *
 * The file is fetched with the viewer's header (the address does not open on
 * its own) and drawn into canvases with PDF.js, bundled locally, or as an
 * image, never in the browser's own viewer with its print and download
 * buttons. Every page carries a watermark with the viewer's name and the time.
 * While open, printing shows a blank notice and Ctrl/⌘+P, Ctrl/⌘+S and the
 * context menu are blocked.
 *
 * Screenshots: a web page cannot block them, and nothing stops a camera. The
 * viewer makes them harder and traceable: the document is hidden whenever
 * the window is not the active one (a snipping tool, another app, a screen
 * picker), and a Print Screen press (reported by browsers on Windows) blanks
 * it, replaces the clipboard with a notice and is recorded in the audit log.
 * The watermark names the viewer on every capture that still gets through.
 */
export function ProtectedDocumentViewer({ document: medicalDocument, viewerName, onClose }: ProtectedDocumentViewerProps) {
    const dialogRef = useRef<HTMLDialogElement>(null);
    const pagesRef = useRef<HTMLDivElement>(null);
    const [state, setState] = useState<{ status: 'loading' | 'ready' | 'error'; message?: string; pages?: number }>({ status: 'loading' });
    const [blockedNotice, setBlockedNotice] = useState(false);
    // Hidden while the window is not the active one, or after Print Screen until the viewer resumes.
    const [inactive, setInactive] = useState(false);
    const [printScreen, setPrintScreen] = useState(false);
    const open = medicalDocument !== null;
    const printScreenUrl = medicalDocument?.printScreenUrl ?? null;

    useEffect(() => {
        const dialog = dialogRef.current;
        if (dialog === null) {
            return;
        }
        if (open && !dialog.open) {
            dialog.showModal();
        } else if (!open && dialog.open) {
            dialog.close();
        }
    }, [open]);

    // Block printing and saving while the viewer is open.
    useEffect(() => {
        if (!open) {
            return;
        }
        window.document.body.classList.add(PRINT_BLOCK_CLASS);
        const onKey = (event: KeyboardEvent) => {
            const key = event.key.toLowerCase();
            if ((event.ctrlKey || event.metaKey) && (key === 'p' || key === 's')) {
                event.preventDefault();
                event.stopPropagation();
                setBlockedNotice(true);
            }
        };
        window.addEventListener('keydown', onKey, true);

        return () => {
            window.document.body.classList.remove(PRINT_BLOCK_CLASS);
            window.removeEventListener('keydown', onKey, true);
        };
    }, [open]);

    // Hide the document whenever the window is not the active one.
    useEffect(() => {
        if (!open) {
            return;
        }
        const hide = () => setInactive(true);
        const show = () => {
            if (window.document.visibilityState === 'visible' && window.document.hasFocus()) {
                setInactive(false);
            }
        };
        const onVisibility = () => (window.document.visibilityState === 'visible' ? show() : hide());
        setInactive(!window.document.hasFocus());
        window.addEventListener('blur', hide);
        window.addEventListener('focus', show);
        window.document.addEventListener('visibilitychange', onVisibility);

        return () => {
            window.removeEventListener('blur', hide);
            window.removeEventListener('focus', show);
            window.document.removeEventListener('visibilitychange', onVisibility);
        };
    }, [open]);

    // Print Screen: blank the document, replace the clipboard and record the attempt.
    useEffect(() => {
        if (!open) {
            return;
        }
        const onPrintScreen = (event: KeyboardEvent) => {
            if (event.key !== 'PrintScreen') {
                return;
            }
            setPrintScreen(true);
            void navigator.clipboard?.writeText('Screenshots of medical documents are not allowed.').catch(() => undefined);
            if (event.type === 'keyup' && printScreenUrl !== null) {
                const token = window.document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=');
                void fetch(printScreenUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': decodeURIComponent(token ?? '') },
                }).catch(() => undefined);
            }
        };
        window.addEventListener('keydown', onPrintScreen, true);
        window.addEventListener('keyup', onPrintScreen, true);

        return () => {
            window.removeEventListener('keydown', onPrintScreen, true);
            window.removeEventListener('keyup', onPrintScreen, true);
        };
    }, [open, printScreenUrl]);

    // Fetch and draw the document.
    useEffect(() => {
        const container = pagesRef.current;
        if (medicalDocument === null || medicalDocument.protectedUrl === null || container === null) {
            return;
        }
        const controller = new AbortController();
        let cancelled = false;
        let destroy: (() => void) | null = null;
        container.replaceChildren();
        setState({ status: 'loading' });
        setBlockedNotice(false);
        setPrintScreen(false);

        const stamp = `${viewerName} · ${new Date().toLocaleString()} · Confidential · View only`;
        const width = Math.min(MAX_PAGE_WIDTH, Math.max(320, container.clientWidth - 32));

        (async () => {
            const response = await fetch(medicalDocument.protectedUrl as string, {
                headers: { [VIEWER_HEADER]: '1', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
            });
            if (!response.ok) {
                throw new Error(
                    response.status === 403
                        ? 'Your access to this record has ended. Ask the medical staff again if you still need it.'
                        : 'The document could not be opened. It may have been removed.',
                );
            }

            if (medicalDocument.fileType === 'pdf') {
                const pdfjs = await import('pdfjs-dist/legacy/build/pdf.mjs');
                const { default: workerUrl } = await import('pdfjs-dist/legacy/build/pdf.worker.min.mjs?url');
                pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;
                const pdf = await pdfjs.getDocument({ data: new Uint8Array(await response.arrayBuffer()), isEvalSupported: false }).promise;
                destroy = () => void pdf.destroy();
                for (let number = 1; number <= pdf.numPages && !cancelled; number++) {
                    const page = await pdf.getPage(number);
                    const unscaled = page.getViewport({ scale: 1 });
                    const viewport = page.getViewport({ scale: (width / unscaled.width) * (window.devicePixelRatio || 1) });
                    const canvas = pageCanvas(viewport.width, viewport.height, width, `Page ${number} of ${pdf.numPages}`);
                    const context = canvas.getContext('2d');
                    if (context === null) {
                        continue;
                    }
                    await page.render({ canvasContext: context, viewport }).promise;
                    watermark(context, canvas.width, canvas.height, stamp);
                    container.append(canvas);
                }
                if (!cancelled) {
                    setState({ status: 'ready', pages: pdf.numPages });
                }
            } else {
                const bitmap = await createImageBitmap(await response.blob());
                const scale = Math.min(1, (width * (window.devicePixelRatio || 1)) / bitmap.width);
                const canvas = pageCanvas(bitmap.width * scale, bitmap.height * scale, Math.min(width, bitmap.width), medicalDocument.title);
                const context = canvas.getContext('2d');
                if (context !== null) {
                    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
                    watermark(context, canvas.width, canvas.height, stamp);
                }
                bitmap.close();
                container.append(canvas);
                setState({ status: 'ready', pages: 1 });
            }
        })().catch((error: unknown) => {
            if (!cancelled && !(error instanceof DOMException && error.name === 'AbortError')) {
                setState({ status: 'error', message: error instanceof Error ? error.message : 'The document could not be opened.' });
            }
        });

        return () => {
            cancelled = true;
            controller.abort();
            destroy?.();
            container.replaceChildren();
        };
    }, [medicalDocument, viewerName]);

    return (
        <dialog
            ref={dialogRef}
            onCancel={(event) => {
                event.preventDefault();
                onClose();
            }}
            onContextMenu={(event) => event.preventDefault()}
            aria-labelledby="protected-viewer-title"
            className="m-0 h-dvh max-h-none w-screen max-w-none bg-ink/95 p-0 text-white backdrop:bg-ink/80"
        >
            {medicalDocument !== null && (
                <div className="flex h-full flex-col">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-white/15 bg-auth-navy px-4 py-3 sm:px-6">
                        <div className="min-w-0">
                            <h2 id="protected-viewer-title" className="truncate font-serif text-lg font-bold">
                                {medicalDocument.title}
                            </h2>
                            <p className="text-xs text-primary-100">
                                {medicalDocument.category.label}
                                {medicalDocument.documentDate !== null && ` · ${formatCalendarDate(medicalDocument.documentDate)}`}
                                {state.status === 'ready' && state.pages !== undefined && state.pages > 1 && ` · ${state.pages} pages`}
                            </p>
                        </div>
                        <div className="flex items-center gap-3">
                            <span className="hidden items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs font-medium text-accent-200 sm:inline-flex">
                                <EyeOff className="size-3.5" aria-hidden="true" />
                                View only · no printing, downloading or screenshots
                            </span>
                            <Button variant="secondary" size="sm" onClick={onClose} icon={<X className="size-4" aria-hidden="true" />}>
                                Close
                            </Button>
                        </div>
                    </header>

                    {blockedNotice && (
                        <p role="status" className="flex items-center gap-2 bg-warning-bg px-4 py-2 text-sm font-medium text-warning-fg sm:px-6">
                            <ShieldAlert className="size-4" aria-hidden="true" />
                            Printing and saving medical documents is not allowed. They may be viewed here only.
                        </p>
                    )}

                    {printScreen && (
                        <p role="alert" className="flex items-center gap-2 bg-danger-bg px-4 py-2 text-sm font-medium text-danger-fg sm:px-6">
                            <ShieldAlert className="size-4" aria-hidden="true" />
                            Screenshots of medical documents are not allowed. This attempt was recorded.
                        </p>
                    )}

                    <div className="relative min-h-0 flex-1">
                        <div className="absolute inset-0 overflow-auto px-4 py-6 select-none" onDragStart={(event) => event.preventDefault()}>
                            {state.status === 'loading' && (
                                <p className="flex items-center justify-center gap-2 py-16 text-primary-100" role="status">
                                    <LoaderCircle className="size-5 animate-spin" aria-hidden="true" />
                                    Opening the document…
                                </p>
                            )}
                            {state.status === 'error' && (
                                <p className="mx-auto max-w-lg rounded-lg bg-danger-bg px-4 py-3 text-center text-danger-fg" role="alert">
                                    {state.message}
                                </p>
                            )}
                            <div
                                ref={pagesRef}
                                className={cn('mx-auto flex flex-col items-center gap-6', (inactive || printScreen) && 'invisible')}
                            />
                        </div>
                        {(inactive || printScreen) && state.status === 'ready' && (
                            <div className="absolute inset-0 z-10 flex items-center justify-center bg-ink/95 p-6">
                                <div className="max-w-sm text-center">
                                    <EyeOff className="mx-auto size-8 text-accent-200" aria-hidden="true" />
                                    <p className="mt-3 font-semibold">{printScreen ? 'The document is hidden.' : 'Hidden while this window is not active.'}</p>
                                    <p className="mt-1 text-sm text-primary-100">
                                        {printScreen ? 'Screenshots are not allowed. Continue only to read the document.' : 'Return to this window to keep reading.'}
                                    </p>
                                    {printScreen && (
                                        <Button className="mt-4" variant="secondary" size="sm" onClick={() => setPrintScreen(false)}>
                                            Continue Reading
                                        </Button>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </dialog>
    );
}

/** A canvas for one page, drawn at device resolution and shown at `cssWidth`. */
function pageCanvas(width: number, height: number, cssWidth: number, label: string): HTMLCanvasElement {
    const canvas = window.document.createElement('canvas');
    canvas.width = Math.round(width);
    canvas.height = Math.round(height);
    canvas.style.width = `${Math.round(cssWidth)}px`;
    canvas.style.maxWidth = '100%';
    canvas.className = 'h-auto rounded bg-white shadow-xl';
    canvas.setAttribute('role', 'img');
    canvas.setAttribute('aria-label', label);
    canvas.draggable = false;

    return canvas;
}

/** Repeated diagonal stamp across the page, part of the picture itself. */
function watermark(context: CanvasRenderingContext2D, width: number, height: number, text: string): void {
    const size = Math.max(14, Math.round(width / 45));
    context.save();
    context.globalAlpha = 0.16;
    context.fillStyle = '#0b2a3d';
    context.font = `600 ${size}px sans-serif`;
    context.translate(width / 2, height / 2);
    context.rotate(-Math.PI / 6);
    const step = size * 6;
    const span = Math.hypot(width, height);
    for (let y = -span; y < span; y += step) {
        for (let x = -span; x < span; x += context.measureText(text).width + size * 4) {
            context.fillText(text, x, y);
        }
    }
    context.restore();
}
