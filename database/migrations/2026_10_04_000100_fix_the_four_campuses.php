<?php

use App\Enums\CampusCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * The institution has exactly four campuses (owner decision 2026-10-04):
     * South, North, East and West. No other campus is ever added.
     *
     * - The "Main Campus" that the campuses migration created for the data
     *   that existed before campuses becomes the South Campus (owner
     *   decision), so its classes, candidates and staff stay together.
     * - A campus already named like one of the four gets that campus's code.
     * - Missing campuses of the four are created (active).
     * - Any other campus is removed when nothing refers to it. One in use is
     *   left as it is and logged, and the check constraint below is then not
     *   added (a deploy never stops here); an administrator must move its
     *   records first.
     * - `campuses_code_check` keeps every other code out; with the unique
     *   code there can never be more than four campuses.
     *
     * Every step is guarded, so the migration can run again after a deploy
     * that stopped half-way.
     */
    public function up(): void
    {
        $now = now();

        foreach (CampusCode::cases() as $campus) {
            if (DB::table('campuses')->where('code', $campus->value)->exists()) {
                continue;
            }

            $named = DB::table('campuses')->where('name', $campus->label())->value('id');
            $main = $campus === CampusCode::South ? DB::table('campuses')->where('code', 'MAIN')->value('id') : null;
            $existing = $named ?? $main;

            if ($existing !== null) {
                DB::table('campuses')->where('id', $existing)->update(['name' => $campus->label(), 'code' => $campus->value, 'updated_at' => $now]);

                continue;
            }

            DB::table('campuses')->insert([
                'name' => $campus->label(),
                'code' => $campus->value,
                'address' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $codes = array_map(fn (CampusCode $campus): string => $campus->value, CampusCode::cases());
        $others = DB::table('campuses')->whereNotIn('code', $codes)->get(['id', 'name']);
        $kept = 0;

        foreach ($others as $other) {
            if ($this->isInUse((int) $other->id)) {
                Log::warning("Campus {$other->name} is not one of the four campuses but has records; it was kept. Move its records to one of the four campuses.");
                $kept++;

                continue;
            }

            DB::table('campuses')->where('id', $other->id)->delete();
        }

        if (! $this->isMysqlFamily() || $this->hasCheck()) {
            return;
        }

        if ($kept === 0) {
            $list = implode(', ', array_map(fn (string $code): string => "'{$code}'", $codes));
            DB::statement("alter table `campuses` add constraint `campuses_code_check` check (`code` in ({$list}))");
        } else {
            Log::warning('The campuses_code_check constraint was not added because other campuses are still in use.');
        }
    }

    public function down(): void
    {
        // The campuses and their records stay; only the rule is lifted.
        if ($this->isMysqlFamily() && $this->hasCheck()) {
            $drop = DB::getDriverName() === 'mariadb' ? 'drop constraint' : 'drop check';
            DB::statement("alter table `campuses` {$drop} `campuses_code_check`");
        }
    }

    private function isInUse(int $campusId): bool
    {
        foreach (['class_batches', 'candidates', 'users', 'class_subjects', 'instructor_assignments', 'audit_logs'] as $table) {
            if (DB::table($table)->where('campus_id', $campusId)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function isMysqlFamily(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function hasCheck(): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::getDatabaseName())
            ->where('table_name', 'campuses')
            ->where('constraint_name', 'campuses_code_check')
            ->exists();
    }
};
