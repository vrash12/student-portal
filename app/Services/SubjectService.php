<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;

final class SubjectService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{code: string, name: string, description: ?string}  $data
     */
    public function create(array $data): Subject
    {
        return DB::transaction(function () use ($data): Subject {
            $subject = Subject::query()->create($data);

            $this->audit->record(AuditAction::SubjectCreated, $subject, newValues: $this->snapshot($subject));

            return $subject;
        });
    }

    /**
     * Deactivated subjects keep their history but cannot be added to classes.
     *
     * @param  array{code: string, name: string, description: ?string, is_active: bool}  $data
     */
    public function update(Subject $subject, array $data): Subject
    {
        return DB::transaction(function () use ($subject, $data): Subject {
            $before = $this->snapshot($subject);

            $subject->fill([
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'],
            ]);
            $subject->is_active = $data['is_active'];
            $subject->save();

            $this->audit->recordChanges(AuditAction::SubjectUpdated, $subject, $before, $this->snapshot($subject));

            return $subject;
        });
    }

    /**
     * @return array{code: string, name: string, description: ?string, is_active: bool}
     */
    private function snapshot(Subject $subject): array
    {
        return [
            'code' => $subject->code,
            'name' => $subject->name,
            'description' => $subject->description,
            'is_active' => $subject->is_active,
        ];
    }
}
