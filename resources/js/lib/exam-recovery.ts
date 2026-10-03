export interface RecoveryAnswer { value: number | string | null }
export interface RecoveryPayload { revision: number; position: number; next_position: number; answer: number | string | null }
export interface RecoverySnapshot { attemptId: number; answers: Record<number, RecoveryAnswer>; position: number; revision: number; updatedAt: number; dirty?: boolean }
interface RecoveryRecord { attemptId: number; userId?: number; savedAt?: number; snapshot?: RecoverySnapshot; pending?: RecoveryPayload[] }
const DATABASE = 'academic-exam-recovery';
const STORE = 'attempts';
/** Recovery data older than this is discarded (an attempt never lasts this long). */
const MAX_AGE_MS = 24 * 60 * 60 * 1000;
let owner: number | null = null;

/**
 * Tablets are shared: records are stamped with the signed-in candidate and
 * only that candidate's records are read back.
 */
export function setRecoveryOwner(userId: number | null): void { owner = userId; }

function readable(record: RecoveryRecord | undefined): record is RecoveryRecord {
    if (record === undefined) return false;
    if (record.userId !== undefined && record.userId !== owner) return false;
    return record.savedAt === undefined || Date.now() - record.savedAt < MAX_AGE_MS;
}
function openDatabase(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DATABASE, 1);
        request.onupgradeneeded = () => { request.result.createObjectStore(STORE, { keyPath: 'attemptId' }); };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

/** Read and write in one transaction so simultaneous snapshot/queue updates cannot erase each other. */
async function mutate(attemptId: number, change: (record: RecoveryRecord) => RecoveryRecord | null): Promise<boolean> {
    let database: IDBDatabase | undefined;
    try {
        database = await openDatabase();
        await new Promise<void>((resolve, reject) => {
            const transaction = database!.transaction(STORE, 'readwrite');
            const store = transaction.objectStore(STORE);
            const read = store.get(attemptId);
            read.onsuccess = () => {
                const current = read.result as RecoveryRecord | undefined;
                const next = change(readable(current) ? current : { attemptId });
                if (next === null) store.delete(attemptId); else store.put({ ...next, userId: owner ?? next.userId, savedAt: Date.now() });
            };
            transaction.oncomplete = () => resolve();
            transaction.onerror = () => reject(transaction.error);
            transaction.onabort = () => reject(transaction.error);
        });
        return true;
    } catch { return false; } finally { database?.close(); }
}

export async function loadRecovery(attemptId: number): Promise<{ snapshot?: RecoverySnapshot; pending: RecoveryPayload[]; available: boolean }> {
    let database: IDBDatabase | undefined;
    try {
        database = await openDatabase();
        const record = await new Promise<RecoveryRecord>((resolve, reject) => {
            const transaction = database!.transaction(STORE, 'readonly');
            const request = transaction.objectStore(STORE).get(attemptId);
            request.onsuccess = () => { const record = request.result as RecoveryRecord | undefined; resolve(readable(record) ? record : { attemptId }); };
            request.onerror = () => reject(request.error);
        });
        return { snapshot: record.snapshot, pending: record.pending ?? [], available: true };
    } catch { return { pending: [], available: false }; } finally { database?.close(); }
}
export function saveSnapshot(snapshot: RecoverySnapshot): Promise<boolean> { return mutate(snapshot.attemptId, (record) => ({ ...record, snapshot })); }
export function enqueueRecovery(attemptId: number, payload: RecoveryPayload): Promise<boolean> { return mutate(attemptId, (record) => ({ ...record, pending: [...(record.pending ?? []), payload] })); }
export function removePending(attemptId: number, count = 1): Promise<boolean> { return mutate(attemptId, (record) => ({ ...record, pending: (record.pending ?? []).slice(count) })); }
export function clearRecovery(attemptId: number): Promise<boolean> { return mutate(attemptId, () => null); }

/**
 * Removes recovery data that is no longer needed on this tablet: records
 * with no unsynced answers (everything is on the server), records of other
 * candidates that are fully synced, and expired records. Unsynced answers of
 * the candidate who stored them are kept so they can still reach the server.
 * Called when a candidate signs in (layout) and when signing out.
 */
export async function pruneRecovery(): Promise<void> {
    let database: IDBDatabase | undefined;
    try {
        database = await openDatabase();
        await new Promise<void>((resolve, reject) => {
            const transaction = database!.transaction(STORE, 'readwrite');
            const request = transaction.objectStore(STORE).openCursor();
            request.onsuccess = () => {
                const cursor = request.result;
                if (!cursor) return;
                const record = cursor.value as RecoveryRecord;
                const expired = record.savedAt !== undefined && Date.now() - record.savedAt >= MAX_AGE_MS;
                const synced = (record.pending ?? []).length === 0 && record.snapshot?.dirty !== true;
                if (expired || synced) cursor.delete();
                cursor.continue();
            };
            transaction.oncomplete = () => resolve();
            transaction.onerror = () => reject(transaction.error);
        });
    } catch { /* Storage unavailable: nothing is stored. */ } finally { database?.close(); }
}
