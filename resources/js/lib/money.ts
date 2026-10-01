import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';

/**
 * Formats amounts calculated by the server (two-decimal strings such as
 * "1250.50") in the institution's currency. Negative amounts (credit
 * balances) are shown in parentheses, as on the printed statement.
 * Formatting only: balances are never calculated in the browser.
 */
export function useMoney(): (amount: string) => string {
    const { currency } = usePage().props.app;

    return useMemo(() => {
        let formatter: Intl.NumberFormat;
        try {
            formatter = new Intl.NumberFormat('en', { style: 'currency', currency, currencyDisplay: 'narrowSymbol', currencySign: 'accounting' });
        } catch {
            // An unknown currency code in configuration: plain figures with the code.
            const plain = new Intl.NumberFormat('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            return (amount: string) => {
                const value = Number(amount);

                return value < 0 ? `(${currency} ${plain.format(-value)})` : `${currency} ${plain.format(value)}`;
            };
        }

        return (amount: string) => formatter.format(Number(amount));
    }, [currency]);
}
