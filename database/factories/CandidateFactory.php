<?php

namespace Database\Factories;

use App\Enums\CandidateStatus;
use App\Enums\SystemRole;
use App\Models\Candidate;
use App\Models\User;
use App\Services\CandidateService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates the candidate's sign-in account too, with the candidate number as
 * username (the invariant CandidateService maintains).
 *
 * @extends Factory<Candidate>
 */
class CandidateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'candidate_number' => '2026-'.fake()->unique()->numerify('####'),
            'first_name' => 'Candidate',
            'last_name' => fake()->unique()->numerify('###'),
            'class_batch_id' => null,
            'status' => CandidateStatus::Enrolled->value,
            'user_id' => fn (array $attributes): int => User::factory()
                ->withRole(SystemRole::Candidate)
                ->create([
                    'username' => CandidateService::usernameFor($attributes['candidate_number']),
                    'name' => "{$attributes['first_name']} {$attributes['last_name']}",
                ])
                ->id,
        ];
    }
}
