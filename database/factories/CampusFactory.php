<?php

namespace Database\Factories;

use App\Enums\CampusCode;
use App\Models\Campus;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * The institution has exactly four campuses (owner decision 2026-10-04),
 * created by the migrations; the database refuses any other code. Tests use
 * the existing ones through fixed() and defaultCampusId() instead of making
 * new campuses. The definition only recreates one of the four that a test
 * removed.
 *
 * @extends Factory<Campus>
 */
class CampusFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $existing = Campus::query()->pluck('code')->all();

        foreach (CampusCode::cases() as $campus) {
            if (! in_array($campus->value, $existing, true)) {
                return ['name' => $campus->label(), 'code' => $campus->value, 'address' => null, 'is_active' => true];
            }
        }

        throw new RuntimeException('The four campuses already exist; use CampusFactory::fixed() instead of creating one.');
    }

    /** One of the four campuses, created again if a test removed it. */
    public static function fixed(CampusCode $code): Campus
    {
        return Campus::query()->where('code', $code->value)->first()
            ?? Campus::factory()->create(['name' => $code->label(), 'code' => $code->value]);
    }

    /**
     * The campus records are attached to when a test does not choose one:
     * the South Campus. Using one shared campus keeps instructors and the
     * classes they teach on the same campus (a database rule).
     */
    public static function defaultCampusId(): int
    {
        return self::fixed(CampusCode::South)->id;
    }
}
