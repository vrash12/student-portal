<?php

namespace Database\Seeders;

use App\Enums\CandidateStatus;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\AccountCategory;
use App\Models\AccountEntry;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounts\AccountService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Demonstration Statements of Account: the Finance Officer account
 * `finance1` (password as in ClientDemoSeeder) and a few synthetic charges
 * and credits for the candidates of every class of the active period, so
 * balances due, settled accounts, credit balances and one voided entry can
 * be shown. The amounts are invented. Entries go through AccountService, so
 * they are audited like real ones. Safe to run again: the account is kept
 * and candidates that already have entries are skipped.
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

        $actor = $this->financeOfficer();
        $period = AcademicPeriod::query()->active()->first();
        $categories = AccountCategory::query()->active()->pluck('id', 'name');
        if ($period === null) {
            return;
        }

        $start = $period->starts_on->copy();
        $classIds = ClassBatch::query()->where('academic_period_id', $period->id)->pluck('id');
        $candidates = Candidate::query()
            ->whereIn('class_batch_id', $classIds)
            ->where('status', '!=', CandidateStatus::Withdrawn->value)
            ->orderBy('candidate_number')
            ->get();

        foreach ($candidates->values() as $index => $candidate) {
            if (AccountEntry::query()->where('candidate_id', $candidate->id)->exists()) {
                continue;
            }

            // Deterministic spread: settled, balance due (partly paid), balance due (unpaid), credit balance.
            $payment = match ($index % 4) {
                0 => '23000.00',
                1 => '10000.00',
                2 => null,
                default => '25000.00',
            };

            $entries = [
                ['Billing', 'charge', '15000.00', $start, 'Training fees, '.$period->name, 'BILL-'.$start->format('Y').'-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT)],
                ['Uniforms', 'charge', '3500.00', $start->copy()->addDays(7), 'Uniform set (two pieces)', null],
                ['Meals', 'charge', '4500.00', $start->copy()->addDays(28), 'Meals, first month', null],
                ['Meal Allowance', 'credit', '1500.00', $start->copy()->addDays(28), 'Meal allowance, first month', null],
                ['Military Fitness', 'charge', '1500.00', $start->copy()->addDays(14), 'Fitness test kit', null],
            ];
            if ($payment !== null) {
                $entries[] = ['Payment Received', 'credit', $payment, $start->copy()->addDays(35), 'Payment received at the finance office', 'OR-'.str_pad((string) (1000 + $index), 5, '0', STR_PAD_LEFT)];
            }

            foreach ($entries as [$category, $type, $amount, $postedOn, $description, $reference]) {
                if (! isset($categories[$category])) {
                    continue;
                }

                $entry = $accounts->record($candidate, [
                    'account_category_id' => (int) $categories[$category],
                    'entry_type' => $type,
                    'amount' => $amount,
                    'posted_on' => $postedOn->toDateString(),
                    'description' => $description,
                    'reference' => $reference,
                ], $actor);

                // One mistaken entry, voided with a reason, for the first candidate.
                if ($index === 0 && $category === 'Uniforms') {
                    $duplicate = $accounts->record($candidate, [
                        'account_category_id' => (int) $categories[$category],
                        'entry_type' => $type,
                        'amount' => $amount,
                        'posted_on' => $entry->posted_on->toDateString(),
                        'description' => $description,
                        'reference' => null,
                    ], $actor);
                    $accounts->void($duplicate, 'Recorded twice; duplicate of the uniform charge of the same date.', $actor);
                }
            }
        }
    }

    private function financeOfficer(): User
    {
        $user = User::query()->where('username', 'finance1')->first();
        if ($user !== null) {
            return $user;
        }

        $user = new User(['name' => 'Finance Officer One', 'username' => 'finance1', 'email' => null, 'password' => ClientDemoSeeder::PASSWORD]);
        $user->role()->associate(Role::query()->where('code', SystemRole::FinanceOfficer->value)->firstOrFail());
        $user->is_active = true;
        $user->password_change_required = false;
        $user->save();

        return $user;
    }
}
