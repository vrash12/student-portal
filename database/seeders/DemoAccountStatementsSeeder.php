<?php

namespace Database\Seeders;

use App\Enums\CandidateStatus;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\AccountCategory;
use App\Models\AccountEntry;
use App\Models\AccountExpense;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\User;
use App\Services\Accounts\AccountService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Demonstration expenses, charged by the administrator (`admin`, created
 * by ClientDemoSeeder; nothing is seeded without one): four sample expenses
 * assigned to the candidates of every class of the active period, a
 * one-off charge for some candidates and one voided entry. No payments:
 * candidates are scholars. The amounts are invented. Everything goes through
 * AccountService, so it is audited like real work. Safe to run again:
 * existing expenses are kept and candidates that already have entries are
 * skipped.
 *
 * (DemoAccountsSeeder is a different seeder: it creates staff sign-in accounts.)
 */
class DemoAccountStatementsSeeder extends Seeder
{
    public function run(AccountService $accounts): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo statements of account must not be seeded in production.');
        }

        $actor = $this->administrator();
        $period = AcademicPeriod::query()->active()->first();
        $categories = AccountCategory::query()->active()->pluck('id', 'name');
        if ($period === null || $actor === null) {
            return;
        }

        $start = $period->starts_on->copy();
        $classIds = ClassBatch::query()->where('academic_period_id', $period->id)->pluck('id');
        $candidates = Candidate::query()
            ->whereIn('class_batch_id', $classIds)
            ->where('status', '!=', CandidateStatus::Withdrawn->value)
            ->whereNotIn('id', AccountEntry::query()->select('candidate_id'))
            ->orderBy('candidate_number')
            ->get();
        if ($candidates->isEmpty()) {
            return;
        }

        // [name, category, amount, assessed on (days after the start), due (days after the start)]
        $expenses = [
            ['Training Fees, '.$period->name, 'Billing', '15000.00', 0, 30],
            ['Uniform Set (two pieces)', 'Uniforms', '3500.00', 7, 14],
            ['Meals, First Month', 'Meals', '4500.00', 28, 30],
            ['Fitness Test Kit', 'Military Fitness', '1500.00', 14, 45],
        ];
        foreach ($expenses as [$name, $category, $amount, $assessedAfter, $dueAfter]) {
            if (! isset($categories[$category])) {
                continue;
            }

            $expense = AccountExpense::query()->where('name', $name)->first() ?? $accounts->createExpense([
                'name' => $name,
                'account_category_id' => (int) $categories[$category],
                'amount' => $amount,
                'due_on' => $start->copy()->addDays($dueAfter)->toDateString(),
                'description' => null,
            ], $actor);
            if ($expense->is_active) {
                $accounts->assignExpense($expense, $candidates, $start->copy()->addDays($assessedAfter)->toDateString(), $actor);
            }
        }

        // Candidates are scholars: no payments. Every third candidate also has
        // a one-off charge, and the first has a mistaken duplicate, voided.
        foreach ($candidates->values() as $index => $candidate) {
            if ($index % 3 !== 0 || ! isset($categories['Chargeable Items'])) {
                continue;
            }

            $charge = [
                'account_category_id' => (int) $categories['Chargeable Items'],
                'entry_type' => 'charge',
                'amount' => '250.00',
                'posted_on' => $start->copy()->addDays(21)->toDateString(),
                'due_on' => $start->copy()->addDays(45)->toDateString(),
                'description' => 'Replacement ID card',
                'reference' => null,
            ];
            $accounts->record($candidate, $charge, $actor);

            if ($index === 0) {
                $duplicate = $accounts->record($candidate, $charge, $actor);
                $accounts->void($duplicate, 'Recorded twice; duplicate of the ID card charge of the same date.', $actor);
            }
        }
    }

    /** The administrator who charges candidates (`admin` in the demo set, else any Super Administrator). */
    private function administrator(): ?User
    {
        return User::query()->where('username', 'admin')->first()
            ?? User::query()->whereHas('role', fn ($role) => $role->where('code', SystemRole::SuperAdministrator->value))->orderBy('id')->first();
    }
}
