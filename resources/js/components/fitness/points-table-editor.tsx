import { CircleAlert, ListPlus, Plus, Trash2 } from 'lucide-react';
import { useId, useState } from 'react';
import { Button } from '@/components/ui/button';
import { FormField, TextInput } from '@/components/ui/form-field';
import { cn } from '@/lib/cn';
import { formatFitnessValue, parseFitnessValue, type FitnessUnit } from '@/lib/fitness-values';
import { formatPoints } from '@/lib/format';

/** One row as typed: a result ("42" or "12:30") and its points ("60"). */
export interface PointsRowDraft {
    value: string;
    points: string;
}

interface PointsTableEditorProps {
    rows: PointsRowDraft[];
    onChange: (rows: PointsRowDraft[]) => void;
    unit: FitnessUnit;
    higherIsBetter: boolean;
    /** Passing points as typed, for the preview. */
    passingPoints: string;
    maximumRows: number;
    /** Error of the whole table, and of each row (by its position). */
    error?: string;
    rowError: (index: number) => string | undefined;
}

const inputClasses =
    'block h-11 w-full rounded-md border border-line-strong bg-surface px-3 text-base text-ink tabular-nums placeholder:text-ink-subtle focus-visible:border-primary-600 focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-primary-600 aria-invalid:border-danger-border';

/**
 * The points table of a fitness event: each row is a result and the points
 * it earns. Rows can be typed one by one or filled in steps (for example
 * from 20 push-ups at 50 points, 2 points more for each extra push-up).
 * The preview below is only a reading aid; the server validates, orders and
 * scores the table (FitnessStandard).
 */
export function PointsTableEditor({ rows, onChange, unit, higherIsBetter, passingPoints, maximumRows, error, rowError }: PointsTableEditorProps) {
    const hintId = useId();
    const errorId = useId();
    const isTime = unit === 'time';
    const resultLabel = isTime ? 'Time' : 'Repetitions';
    const canAdd = rows.length < maximumRows;

    const change = (index: number, field: keyof PointsRowDraft, text: string) => onChange(rows.map((row, position) => (position === index ? { ...row, [field]: text } : row)));
    const remove = (index: number) => onChange(rows.filter((_, position) => position !== index));
    const add = () => onChange([...rows, { value: '', points: '' }]);

    return (
        <fieldset aria-describedby={error ? `${hintId} ${errorId}` : hintId} className="flex flex-col gap-3">
            <legend className="text-sm font-medium text-ink">
                Points table{' '}
                <span className="text-danger-fg" aria-hidden="true">
                    *
                </span>
            </legend>
            <p id={hintId} className="text-sm text-ink-muted">
                A result earns the points of the best row it reaches: {isTime ? 'that time or faster' : 'at least that many repetitions'}. A result short of the{' '}
                {isTime ? 'slowest' : 'lowest'} row earns 0 points. Rows are put in order when you save.
            </p>
            {error && (
                <p id={errorId} className="flex items-start gap-1.5 text-sm text-danger-fg">
                    <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>{error}</span>
                </p>
            )}

            {rows.length > 0 && (
                <div className="grid grid-cols-[2.5rem_minmax(0,1fr)_minmax(0,1fr)_2.75rem] gap-x-2 px-2 text-xs font-semibold uppercase tracking-wide text-ink-muted" aria-hidden="true">
                    <span>Row</span>
                    <span>{isTime ? 'Time (min:sec)' : 'Repetitions'}</span>
                    <span>Points</span>
                    <span />
                </div>
            )}
            <ol className="flex flex-col gap-2">
                {rows.map((row, index) => (
                    <PointsRow
                        key={index}
                        row={row}
                        number={index + 1}
                        resultLabel={resultLabel}
                        isTime={isTime}
                        error={rowError(index)}
                        onChange={(field, text) => change(index, field, text)}
                        onRemove={() => remove(index)}
                    />
                ))}
            </ol>

            <div className="flex flex-wrap items-center justify-between gap-3">
                <Button variant="secondary" icon={<Plus className="size-4" aria-hidden="true" />} onClick={add} disabled={!canAdd}>
                    Add Row
                </Button>
                <p className="text-sm text-ink-muted">
                    <span className="tabular-nums">{rows.length}</span> of <span className="tabular-nums">{maximumRows}</span> rows
                </p>
            </div>

            <TablePreview rows={rows} unit={unit} higherIsBetter={higherIsBetter} passingPoints={passingPoints} />
            <FillRows unit={unit} higherIsBetter={higherIsBetter} maximumRows={maximumRows} hasRows={rows.some((row) => row.value !== '' || row.points !== '')} onFill={onChange} />
        </fieldset>
    );
}

interface PointsRowProps {
    row: PointsRowDraft;
    number: number;
    resultLabel: string;
    isTime: boolean;
    error?: string;
    onChange: (field: keyof PointsRowDraft, text: string) => void;
    onRemove: () => void;
}

function PointsRow({ row, number, resultLabel, isTime, error, onChange, onRemove }: PointsRowProps) {
    const valueId = useId();
    const pointsId = useId();
    const errorId = useId();

    return (
        <li className={cn('rounded-lg border bg-surface p-2', error ? 'border-danger-border' : 'border-line')}>
            <div className="grid grid-cols-[2.5rem_minmax(0,1fr)_minmax(0,1fr)_2.75rem] items-center gap-2">
                <span className="text-center text-sm font-semibold text-ink-muted tabular-nums">{number}</span>
                <div>
                    <label htmlFor={valueId} className="sr-only">
                        Row {number}: {resultLabel}
                    </label>
                    <input
                        id={valueId}
                        value={row.value}
                        onChange={(event) => onChange('value', event.target.value)}
                        inputMode={isTime ? 'text' : 'numeric'}
                        autoComplete="off"
                        maxLength={12}
                        placeholder={isTime ? '12:30' : '30'}
                        aria-invalid={error ? true : undefined}
                        aria-describedby={error ? errorId : undefined}
                        className={inputClasses}
                    />
                </div>
                <div>
                    <label htmlFor={pointsId} className="sr-only">
                        Row {number}: points
                    </label>
                    <input
                        id={pointsId}
                        value={row.points}
                        onChange={(event) => onChange('points', event.target.value)}
                        inputMode="decimal"
                        autoComplete="off"
                        maxLength={6}
                        placeholder="60"
                        aria-invalid={error ? true : undefined}
                        aria-describedby={error ? errorId : undefined}
                        className={inputClasses}
                    />
                </div>
                <button
                    type="button"
                    onClick={onRemove}
                    aria-label={`Remove row ${number}`}
                    title={`Remove row ${number}`}
                    className="flex size-11 items-center justify-center rounded-md text-ink-muted hover:bg-danger-bg hover:text-danger-fg"
                >
                    <Trash2 className="size-4" aria-hidden="true" />
                </button>
            </div>
            {error && (
                <p id={errorId} className="mt-1.5 flex items-start gap-1.5 pl-12 text-sm text-danger-fg">
                    <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>{error}</span>
                </p>
            )}
        </li>
    );
}

/** Where the typed table passes and tops out, as a reading aid while typing. */
function TablePreview({ rows, unit, higherIsBetter, passingPoints }: { rows: PointsRowDraft[]; unit: FitnessUnit; higherIsBetter: boolean; passingPoints: string }) {
    const parsed = rows
        .map((row) => ({ value: parseFitnessValue(row.value, unit), points: row.points.trim() === '' ? Number.NaN : Number(row.points) }))
        .filter((row): row is { value: number; points: number } => row.value !== null && Number.isFinite(row.points))
        .sort((a, b) => (higherIsBetter ? a.value - b.value : b.value - a.value));
    const passing = Number(passingPoints);
    const top = parsed[parsed.length - 1];
    if (top === undefined || !Number.isFinite(passing)) {
        return null;
    }

    const reach = (value: number) => (unit === 'time' ? `${formatFitnessValue(value, unit)} or faster` : `${formatFitnessValue(value, unit)} repetitions or more`);
    const passingRow = parsed.find((row) => row.points >= passing);

    return (
        <p className="rounded-md bg-surface-muted px-3 py-2 text-sm text-ink" aria-live="polite">
            {passingRow === undefined ? (
                <span className="text-warning-fg">No row reaches the passing points ({formatPoints(passing)}) yet.</span>
            ) : (
                <>
                    Passing: <strong className="font-semibold">{reach(passingRow.value)}</strong> ({formatPoints(passingRow.points)} points).
                </>
            )}{' '}
            Best: <strong className="font-semibold">{reach(top.value)}</strong> ({formatPoints(top.points)} points).
        </p>
    );
}

interface FillRowsProps {
    unit: FitnessUnit;
    higherIsBetter: boolean;
    maximumRows: number;
    hasRows: boolean;
    onFill: (rows: PointsRowDraft[]) => void;
}

/** Fills the table in equal steps, e.g. 20 push-ups = 50 points, then 2 points more for each extra push-up up to 100. */
function FillRows({ unit, higherIsBetter, maximumRows, hasRows, onFill }: FillRowsProps) {
    const isTime = unit === 'time';
    const [fill, setFill] = useState({ start: '', startPoints: '', step: isTime ? '10' : '1', pointsStep: '2', topPoints: '100' });
    const [problem, setProblem] = useState<string | null>(null);
    const towards = higherIsBetter ? 'more' : isTime ? 'faster' : 'fewer';

    const run = () => {
        const start = parseFitnessValue(fill.start, unit);
        const startPoints = Number(fill.startPoints);
        const step = Number(fill.step);
        const pointsStep = Number(fill.pointsStep);
        const topPoints = Number(fill.topPoints);
        if (start === null || fill.startPoints.trim() === '' || !Number.isFinite(startPoints) || !(step > 0) || !(pointsStep > 0) || !Number.isFinite(topPoints) || topPoints > 100 || topPoints < startPoints || startPoints < 0) {
            setProblem(`Enter the first ${isTime ? 'time' : 'result'} and its points, steps above zero, and top points from the first points up to 100.`);

            return;
        }

        const rows: PointsRowDraft[] = [];
        for (let index = 0; rows.length < maximumRows; index++) {
            const value = higherIsBetter ? start + index * step : start - index * step;
            const points = Math.min(topPoints, startPoints + index * pointsStep);
            if (value < 0 || (isTime && value <= 0)) {
                break;
            }
            rows.push({ value: formatFitnessValue(value, unit), points: formatPoints(points) });
            if (points >= topPoints) {
                break;
            }
        }

        setProblem(null);
        onFill(rows);
    };

    return (
        <details className="rounded-lg border border-line-box bg-surface-muted/40 open:bg-surface">
            <summary className="flex min-h-11 cursor-pointer items-center gap-2 px-3 text-sm font-semibold text-primary-800">
                <ListPlus className="size-4" aria-hidden="true" />
                Fill the table in steps
            </summary>
            <div className="flex flex-col gap-4 border-t border-line p-3">
                <p className="text-sm text-ink-muted">
                    For example: {isTime ? '16:00 earns 60 points, then 2 points more for every 10 seconds faster' : '20 repetitions earn 50 points, then 2 points more for each extra repetition'}, up to 100 points.
                    {hasRows && ' This replaces the rows above.'}
                </p>
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <FormField label={isTime ? 'First time' : 'First result'} hint={isTime ? 'e.g. 16:00' : 'Repetitions'}>
                        <TextInput value={fill.start} onChange={(event) => setFill({ ...fill, start: event.target.value })} inputMode={isTime ? 'text' : 'numeric'} autoComplete="off" className="tabular-nums" />
                    </FormField>
                    <FormField label="Its points">
                        <TextInput value={fill.startPoints} onChange={(event) => setFill({ ...fill, startPoints: event.target.value })} inputMode="decimal" autoComplete="off" className="tabular-nums" />
                    </FormField>
                    <FormField label={isTime ? 'Seconds per step' : 'Repetitions per step'} hint={`Each step is ${towards}.`}>
                        <TextInput value={fill.step} onChange={(event) => setFill({ ...fill, step: event.target.value })} inputMode="decimal" autoComplete="off" className="tabular-nums" />
                    </FormField>
                    <FormField label="Points per step">
                        <TextInput value={fill.pointsStep} onChange={(event) => setFill({ ...fill, pointsStep: event.target.value })} inputMode="decimal" autoComplete="off" className="tabular-nums" />
                    </FormField>
                    <FormField label="Up to points">
                        <TextInput value={fill.topPoints} onChange={(event) => setFill({ ...fill, topPoints: event.target.value })} inputMode="decimal" autoComplete="off" className="tabular-nums" />
                    </FormField>
                </div>
                {problem && (
                    <p role="alert" className="flex items-start gap-1.5 text-sm text-danger-fg">
                        <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        {problem}
                    </p>
                )}
                <div>
                    <Button variant="secondary" icon={<ListPlus className="size-4" aria-hidden="true" />} onClick={run}>
                        Fill Table
                    </Button>
                </div>
            </div>
        </details>
    );
}
