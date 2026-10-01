import { Head } from '@inertiajs/react';
import { ListCharts, type ListChart } from '@/components/charts/list-charts';
import { Plus, Tags, WalletCards } from 'lucide-react';
import type { AccountExpense } from '@/components/accounts/types';
import { ButtonLink } from '@/components/ui/button';
import { ClientPagination, useClientPagination } from '@/components/ui/client-pagination';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { formatCalendarDate } from '@/lib/format';
import { useMoney } from '@/lib/money';
import { routes } from '@/lib/routes';

interface ExpenseRow extends AccountExpense {
    /** Candidates charged this expense (voided charges left out). */
    assignedCount: number;
    assignedTotal: string;
}

export default function AccountExpenses({ expenses, charts }: { expenses: ExpenseRow[]; charts: ListChart[] }) {
    const money = useMoney();
    const pagination = useClientPagination(expenses);
    const addAction = (
        <ButtonLink href={routes.accounts.expenses.create()} variant="primary" icon={<Plus className="size-4" aria-hidden="true" />}>
            New Expense
        </ButtonLink>
    );

    return (
        <>
            <Head title="Expenses" />

            <PageHeader
                title="Expenses"
                description="Define an expense once, such as a uniform set or a month of meals, then assign it to a whole class or to chosen candidates. Each candidate is charged once."
                breadcrumbs={[{ label: 'Expenses' }]}
                actions={
                    <>
                        <ButtonLink href={routes.accounts.categories.index()} icon={<Tags className="size-4" aria-hidden="true" />}>
                            Account Categories
                        </ButtonLink>
                        {addAction}
                    </>
                }
            />

            <ListCharts charts={charts} />

            <div className="rounded-lg border border-line bg-surface">
                {expenses.length === 0 ? (
                    <EmptyState
                        icon={WalletCards}
                        title="No expenses yet"
                        description="Create the first expense, then assign it to the candidates who should be charged."
                        action={addAction}
                    />
                ) : (
                    <Table caption="Expenses" className="min-w-[56rem]">
                        <TableHead>
                            <Th>Expense</Th>
                            <Th>Category</Th>
                            <Th>Due Date</Th>
                            <Th align="right">Amount</Th>
                            <Th align="right">Candidates Charged</Th>
                            <Th align="right">Total Assessed</Th>
                            <Th>Status</Th>
                            <Th align="right">
                                <span className="sr-only">Actions</span>
                            </Th>
                        </TableHead>
                        <TableBody>
                            {pagination.rows.map((expense) => (
                                <Tr key={expense.id}>
                                    <Td className="text-ink">
                                        <span className="font-medium">{expense.name}</span>
                                        {expense.description && <span className="block text-xs text-ink-muted">{expense.description}</span>}
                                    </Td>
                                    <Td className="text-ink">{expense.category.name}</Td>
                                    <Td className="whitespace-nowrap text-ink">{expense.dueOn === null ? 'Upon assessment' : formatCalendarDate(expense.dueOn)}</Td>
                                    <Td align="right" numeric className="whitespace-nowrap text-ink">
                                        {money(expense.amount)}
                                    </Td>
                                    <Td align="right" numeric>
                                        {expense.assignedCount}
                                    </Td>
                                    <Td align="right" numeric className="whitespace-nowrap">
                                        {money(expense.assignedTotal)}
                                    </Td>
                                    <Td>{expense.isActive ? <StatusBadge tone="success">Active</StatusBadge> : <StatusBadge tone="neutral">Inactive</StatusBadge>}</Td>
                                    <Td align="right">
                                        <RowAction href={routes.accounts.expenses.show(expense.id)} label={`Open ${expense.name} to assign it`}>
                                            {expense.isActive ? 'Assign' : 'View'}
                                        </RowAction>
                                    </Td>
                                </Tr>
                            ))}
                        </TableBody>
                    </Table>
                )}
                <ClientPagination pagination={pagination} noun={{ one: 'expense', other: 'expenses' }} label="Expense pages" />
            </div>
        </>
    );
}
