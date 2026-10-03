<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\CandidateStatus;
use App\Enums\SystemRole;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\Role;
use App\Models\User;
use App\Support\CandidateGroups;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Candidate identity and account changes are kept together and audited. */
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

    /** @param array<string, mixed> $data */
    public function create(array $data): Candidate
    {
        return $this->withPhoto($data, function (?string $photoPath) use ($data): Candidate {
            return DB::transaction(function () use ($data, $photoPath): Candidate {
                $candidate = new Candidate($this->identity($data));
                $account = new User([
                    'name' => $this->accountName($candidate),
                    'username' => self::usernameFor($data['candidate_number']),
                    'email' => null,
                    'password' => $data['password'],
                ]);
                $account->role()->associate(Role::query()->where('code', SystemRole::Candidate->value)->firstOrFail());
                $account->is_active = true;
                $account->save();

                $candidate->user()->associate($account);
                $this->place($candidate, $data);
                $candidate->status = CandidateStatus::Enrolled;
                $candidate->profile_photo_path = $photoPath;
                $candidate->save();
                $this->audit->record(AuditAction::CandidateCreated, $candidate, newValues: $this->snapshot($candidate));

                return $candidate;
            });
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Candidate $candidate, array $data): Candidate
    {
        return $this->withPhoto($data, function (?string $photoPath) use ($candidate, $data): Candidate {
            return DB::transaction(function () use ($candidate, $data, $photoPath): Candidate {
                $candidate = Candidate::query()->lockForUpdate()->findOrFail($candidate->id);
                $candidate->load(['user', 'classBatch']);
                $before = $this->snapshot($candidate);
                $oldPhoto = $candidate->profile_photo_path;

                $candidate->fill($this->identity($data));
                $this->place($candidate, $data);
                $candidate->status = $data['status'];
                if ($photoPath !== null || ($data['remove_photo'] ?? false)) {
                    $candidate->profile_photo_path = $photoPath;
                }
                $candidate->save();

                $account = $candidate->user;
                $account->fill([
                    'name' => $this->accountName($candidate),
                    'username' => self::usernameFor($data['candidate_number']),
                ]);
                $account->is_active = $data['account_active'];
                $passwordReset = ($data['password'] ?? '') !== '' && $data['password'] !== null;
                if ($passwordReset) {
                    $account->password = $data['password'];
                }
                $account->save();

                $after = $this->snapshot($candidate);
                if ($oldPhoto !== $candidate->profile_photo_path) {
                    // Audit the change, never the file or storage path.
                    $before['profile_photo_changed'] = false;
                    $after['profile_photo_changed'] = true;
                    if ($oldPhoto !== null) {
                        DB::afterCommit(fn () => Storage::disk('local')->delete($oldPhoto));
                    }
                }
                $this->audit->recordChanges(AuditAction::CandidateUpdated, $candidate, $before, $after);
                if ($passwordReset) {
                    $this->audit->record(AuditAction::CandidatePasswordReset, $candidate);
                }
                if ($passwordReset || ! $account->is_active || $account->wasChanged('username')) {
                    $this->accounts->endSessions($account);
                }

                return $candidate;
            });
        });
    }

    /**
     * Sets only the company and platoon (demo data, or a later bulk
     * assignment), normalized like the form and audited as a candidate
     * change. Blank names clear the assignment.
     */
    public function assignCompanyAndPlatoon(Candidate $candidate, ?string $company, ?string $platoon): Candidate
    {
        $names = ['company' => $this->groupName($company), 'platoon' => $this->groupName($platoon)];
        foreach ($names as $field => $name) {
            if ($name !== null && mb_strlen($name) > CandidateGroups::MAX_LENGTH) {
                throw ValidationException::withMessages([$field => "The {$field} must not be greater than ".CandidateGroups::MAX_LENGTH.' characters.']);
            }
        }

        return DB::transaction(function () use ($candidate, $names): Candidate {
            $candidate = Candidate::query()->lockForUpdate()->findOrFail($candidate->id);
            $before = $this->snapshot($candidate);

            $candidate->fill($names)->save();
            $this->audit->recordChanges(AuditAction::CandidateUpdated, $candidate, $before, $this->snapshot($candidate));

            return $candidate;
        });
    }

    private function groupName(?string $name): ?string
    {
        $name = $name === null ? '' : Str::squish($name);

        return $name === '' ? null : $name;
    }

    /** @param array<string, mixed> $data */
    private function withPhoto(array $data, Closure $save): Candidate
    {
        $path = null;
        if (isset($data['profile_photo'])) {
            $path = $data['profile_photo']->store('candidate-photos', 'local');
            if ($path === false) {
                throw ValidationException::withMessages(['profile_photo' => 'The photo could not be saved. Please try again.']);
            }
        }
        try {
            return $save($path);
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function identity(array $data): array
    {
        return array_intersect_key($data, array_flip(['candidate_number', 'first_name', 'middle_name', 'last_name', 'suffix', 'training_group', 'company', 'platoon']));
    }

    private function accountName(Candidate $candidate): string
    {
        if (mb_strlen($candidate->full_name) > 255) {
            throw ValidationException::withMessages(['last_name' => 'The combined name must be 255 characters or fewer.']);
        }

        return $candidate->full_name;
    }

    private function classBatch(?int $classBatchId): ?ClassBatch
    {
        return $classBatchId === null ? null : ClassBatch::query()->findOrFail($classBatchId);
    }

    /**
     * Class and campus (owner decision 2026-10-03): a candidate in a class is
     * on the class's campus; without a class, on the campus given (or the
     * one they are already on). A database key checks the pair.
     *
     * @param  array<string, mixed>  $data
     */
    private function place(Candidate $candidate, array $data): void
    {
        $classBatch = $this->classBatch($data['class_batch_id']);
        $candidate->classBatch()->associate($classBatch);

        if ($classBatch !== null) {
            $candidate->campus_id = $classBatch->campus_id;

            return;
        }

        $campusId = $data['campus_id'] ?? null;
        if ($campusId !== null) {
            $candidate->campus_id = (int) $campusId;
        }

        if ($candidate->getAttribute('campus_id') === null) {
            throw ValidationException::withMessages(['campus_id' => 'Choose the campus of a candidate without a class.']);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Candidate $candidate): array
    {
        $candidate->loadMissing(['user', 'classBatch']);
        $candidate->load('campus');

        return [
            'candidate_number' => $candidate->candidate_number,
            'first_name' => $candidate->first_name,
            'middle_name' => $candidate->middle_name,
            'last_name' => $candidate->last_name,
            'suffix' => $candidate->suffix,
            'training_group' => $candidate->training_group,
            'company' => $candidate->company,
            'platoon' => $candidate->platoon,
            'has_profile_photo' => $candidate->profile_photo_path !== null,
            'class' => $candidate->classBatch?->name,
            'campus' => $candidate->campus?->name,
            'status' => $candidate->status->value,
            'account_active' => $candidate->user->is_active,
        ];
    }
}
