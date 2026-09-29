<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\CandidateStatus;
use App\Enums\SystemRole;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Candidate records and their sign-in accounts. The account username is the
 * candidate number in lowercase, and the account name mirrors the candidate's
 * name; both are kept in sync here.
 */
final class CandidateService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly UserAccountService $accounts,
    ) {}

    public static function usernameFor(string $candidateNumber): string
    {
        return Str::lower($candidateNumber);
    }

    /**
     * @param  array{candidate_number: string, first_name: string, last_name: string, class_batch_id: ?int, password: string}  $data
     */
    public function create(array $data): Candidate
    {
        return DB::transaction(function () use ($data): Candidate {
            $account = new User([
                'name' => $this->accountName($data['first_name'], $data['last_name']),
                'username' => self::usernameFor($data['candidate_number']),
                'email' => null,
                'password' => $data['password'],
            ]);
            $account->role()->associate(Role::query()->where('code', SystemRole::Candidate->value)->firstOrFail());
            $account->is_active = true;
            $account->save();

            $candidate = new Candidate([
                'candidate_number' => $data['candidate_number'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
            ]);
            $candidate->user()->associate($account);
            $candidate->classBatch()->associate($this->classBatch($data['class_batch_id']));
            $candidate->status = CandidateStatus::Enrolled;
            $candidate->save();

            $this->audit->record(AuditAction::CandidateCreated, $candidate, newValues: $this->snapshot($candidate));

            return $candidate;
        });
    }

    /**
     * @param  array{candidate_number: string, first_name: string, last_name: string, class_batch_id: ?int, status: CandidateStatus, account_active: bool, password: ?string}  $data
     */
    public function update(Candidate $candidate, array $data): Candidate
    {
        return DB::transaction(function () use ($candidate, $data): Candidate {
            $candidate->loadMissing(['user', 'classBatch']);
            $before = $this->snapshot($candidate);

            $candidate->fill([
                'candidate_number' => $data['candidate_number'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
            ]);
            $candidate->classBatch()->associate($this->classBatch($data['class_batch_id']));
            $candidate->status = $data['status'];
            $candidate->save();

            $account = $candidate->user;
            $account->fill([
                'name' => $this->accountName($data['first_name'], $data['last_name']),
                'username' => self::usernameFor($data['candidate_number']),
            ]);
            $account->is_active = $data['account_active'];

            $passwordReset = $data['password'] !== null && $data['password'] !== '';
            if ($passwordReset) {
                $account->password = $data['password'];
            }
            $account->save();

            $this->audit->recordChanges(AuditAction::CandidateUpdated, $candidate, $before, $this->snapshot($candidate));

            if ($passwordReset) {
                $this->audit->record(AuditAction::CandidatePasswordReset, $candidate);
            }

            // Sign the candidate out everywhere when their access changes.
            if ($passwordReset || ! $account->is_active || $account->wasChanged('username')) {
                $this->accounts->endSessions($account);
            }

            return $candidate;
        });
    }

    private function classBatch(?int $classBatchId): ?ClassBatch
    {
        return $classBatchId === null ? null : ClassBatch::query()->findOrFail($classBatchId);
    }

    private function accountName(string $firstName, string $lastName): string
    {
        return trim("{$firstName} {$lastName}");
    }

    /**
     * @return array{candidate_number: string, first_name: string, last_name: string, class: ?string, status: string, account_active: bool}
     */
    private function snapshot(Candidate $candidate): array
    {
        $candidate->loadMissing(['user', 'classBatch']);

        return [
            'candidate_number' => $candidate->candidate_number,
            'first_name' => $candidate->first_name,
            'last_name' => $candidate->last_name,
            'class' => $candidate->classBatch?->name,
            'status' => $candidate->status->value,
            'account_active' => $candidate->user->is_active,
        ];
    }
}
