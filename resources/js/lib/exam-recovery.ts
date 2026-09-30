export interface RecoveryAnswer {
    value: number | string | null;
    flagged: boolean;
}

export interface RecoveryPayload {
    revision: number;
    position: number;
    next_position: number;
    answer: number | string | null;
    flagged: boolean;
}

export interface RecoverySnapshot {
    attemptId: number;
    answers: Record<number, RecoveryAnswer>;
    position: number;
    revision: number;
    updatedAt: number;
}

interface RecoveryRecord {
    attemptId: number;
    snapshot?: RecoverySnapshot;
    pending?: RecoveryPayload[];
}

const DATABASE = 'academic-exam-recovery';
const STORE = 'attempts';

function openDatabase(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DATABASE, 1);
        request.onupgradeneeded = () => {
            request.result.createObjectStore(STORE, { keyPath: 'attemptId' });
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error ?? new Error('Local recovery is unavailable.'));
    });
}

async function readRecord(attemptId: number): Promise<RecoveryRecord> {
    const database = await openDatabase();
    return new Promise((resolve, reject) => {
        const request = database.transaction(STORE, 'readonly').objectStore(STORE).get(attemptId);
        request.onsuccess = () => resolve(request.result ?? { attemptId });
        request.onerror = () => reject(request.error);
    });
}

async function writeRecord(record: RecoveryRecord): Promise<void> {
    const database = await openDatabase();
    return new Promise((resolve, reject) => {
        const request = database.transaction(STORE, 'readwrite').objectStore(STORE).put(record);
        request.onsuccess = () => resolve();
        request.onerror = () => reject(request.error);
    });
}

export async function loadRecovery(attemptId: number): Promise<{ snapshot?: RecoverySnapshot; pending: RecoveryPayload[] }> {
    try {
        const record = await readRecord(attemptId);
        return { snapshot: record.snapshot, pending: record.pending ?? [] };
    } catch {
        return { pending: [] };
    }
}

export async function saveSnapshot(snapshot: RecoverySnapshot): Promise<void> {
    try {
        const record = await readRecord(snapshot.attemptId);
        await writeRecord({ ...record, snapshot });
    } catch {
        // The server remains authoritative when browser storage is unavailable.
    }
}

export async function enqueueRecovery(attemptId: number, payload: RecoveryPayload): Promise<void> {
    try {
        const record = await readRecord(attemptId);
        await writeRecord({ ...record, pending: [...(record.pending ?? []), payload] });
    } catch {
        // The visible save state tells the candidate that recovery storage failed.
    }
}

export async function removePending(attemptId: number, count = 1): Promise<void> {
    try {
        const record = await readRecord(attemptId);
        await writeRecord({ ...record, pending: (record.pending ?? []).slice(count) });
    } catch {
        // A later refresh will reconcile from the server or retain the snapshot.
    }
}

export async function clearRecovery(attemptId: number): Promise<void> {
    try {
        const database = await openDatabase();
        await new Promise<void>((resolve, reject) => {
            const request = database.transaction(STORE, 'readwrite').objectStore(STORE).delete(attemptId);
            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    } catch {
        // Cleanup is best effort; no authenticated answer is sent elsewhere.
    }
}
