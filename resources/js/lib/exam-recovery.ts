export interface RecoveryAnswer { value: number | string | null; flagged: boolean }
export interface RecoveryPayload { revision: number; position: number; next_position: number; answer: number | string | null; flagged: boolean }
export interface RecoverySnapshot { attemptId: number; answers: Record<number, RecoveryAnswer>; position: number; revision: number; updatedAt: number; dirty?: boolean }
interface RecoveryRecord { attemptId: number; snapshot?: RecoverySnapshot; pending?: RecoveryPayload[] }
const DATABASE = 'academic-exam-recovery';
const STORE = 'attempts';

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
                const next = change((read.result as RecoveryRecord | undefined) ?? { attemptId });
                if (next === null) store.delete(attemptId); else store.put(next);
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
            request.onsuccess = () => resolve((request.result as RecoveryRecord | undefined) ?? { attemptId });
            request.onerror = () => reject(request.error);
        });
        return { snapshot: record.snapshot, pending: record.pending ?? [], available: true };
    } catch { return { pending: [], available: false }; } finally { database?.close(); }
}
export function saveSnapshot(snapshot: RecoverySnapshot): Promise<boolean> { return mutate(snapshot.attemptId, (record) => ({ ...record, snapshot })); }
export function enqueueRecovery(attemptId: number, payload: RecoveryPayload): Promise<boolean> { return mutate(attemptId, (record) => ({ ...record, pending: [...(record.pending ?? []), payload] })); }
export function removePending(attemptId: number, count = 1): Promise<boolean> { return mutate(attemptId, (record) => ({ ...record, pending: (record.pending ?? []).slice(count) })); }
export function clearRecovery(attemptId: number): Promise<boolean> { return mutate(attemptId, () => null); }
