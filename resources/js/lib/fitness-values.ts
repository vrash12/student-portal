/**
 * Reading and writing fitness results in the browser, mirroring
 * App\Services\Fitness\FitnessValue: repetitions are whole numbers; times
 * are minutes:seconds ("12:30", "9:05.5") or seconds ("750"). Used only to
 * fill and preview points tables while typing; the server parses, stores
 * and scores every value.
 */
export type FitnessUnit = 'repetitions' | 'time';

export function parseFitnessValue(text: string, unit: FitnessUnit): number | null {
    const input = text.trim();
    if (unit === 'repetitions') {
        return /^\d{1,4}$/.test(input) ? Number(input) : null;
    }

    const parts = /^(\d{1,3}):([0-5]\d)(?:\.(\d{1,2}))?$/.exec(input);
    const seconds = parts
        ? Number(parts[1]) * 60 + Number(parts[2]) + (parts[3] ? Number(`0.${parts[3]}`) : 0)
        : /^\d{1,5}(?:\.\d{1,2})?$/.test(input)
          ? Number(input)
          : null;

    return seconds !== null && seconds > 0 ? seconds : null;
}

export function formatFitnessValue(value: number, unit: FitnessUnit): string {
    if (unit === 'repetitions') {
        return String(Math.round(value));
    }

    const hundredths = Math.round(value * 100);
    const minutes = Math.floor(hundredths / 6000);
    const rest = hundredths % 6000;
    const whole = String(Math.floor(rest / 100)).padStart(2, '0');
    const fraction = rest % 100 === 0 ? '' : `.${String(rest % 100).padStart(2, '0').replace(/0$/, '')}`;

    return `${minutes}:${whole}${fraction}`;
}
