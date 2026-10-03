<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The whole course lasts one year (owner request, 2026-10-03): an academic
     * period is that year (e.g. "2026-2027") and lasts at most one year, and
     * every training phase belongs to one period, with dates inside it.
     *
     * Existing phases move to the period whose classes use them (a copy for
     * each further period); unused phases go to the active (else the latest)
     * period, or are removed when there is no period. Their dates split the
     * period evenly in phase order, for administrators to adjust.
     */
    public function up(): void
    {
        Schema::table('training_phases', function (Blueprint $table) {
            $table->foreignId('academic_period_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->date('starts_on')->nullable()->after('name');
            $table->date('ends_on')->nullable()->after('starts_on');
        });

        // Numbers and names are unique within a period from now on.
        Schema::table('training_phases', function (Blueprint $table) {
            $table->dropUnique(['number']);
            $table->dropUnique(['name']);
        });

        $this->placeExistingPhases();
        $this->dateExistingPhases();

        Schema::table('training_phases', function (Blueprint $table) {
            $table->unsignedBigInteger('academic_period_id')->nullable(false)->change();
            $table->date('starts_on')->nullable(false)->change();
            $table->date('ends_on')->nullable(false)->change();
            $table->unique(['academic_period_id', 'number']);
            $table->unique(['academic_period_id', 'name']);
        });

        if (! $this->isMysqlFamily()) {
            return;
        }

        DB::statement('alter table `training_phases` add constraint `training_phases_dates_check` check (`ends_on` >= `starts_on`)');

        // A period lasts at most one year. Added only when every existing period
        // already fits, so a deploy never stops here; the forms enforce it anyway.
        $tooLong = DB::table('academic_periods')->whereRaw('`ends_on` >= date_add(`starts_on`, interval 1 year)')->count();
        if ($tooLong === 0) {
            DB::statement('alter table `academic_periods` add constraint `academic_periods_one_year_check` check (`ends_on` < date_add(`starts_on`, interval 1 year))');
        } else {
            Log::warning("Academic periods longer than one year: {$tooLong}. The one-year check constraint was not added; shorten them on the Academic Periods page.");
        }
    }

    public function down(): void
    {
        if ($this->isMysqlFamily()) {
            $drop = DB::getDriverName() === 'mariadb' ? 'drop constraint' : 'drop check';
            if ($this->hasCheck('academic_periods', 'academic_periods_one_year_check')) {
                DB::statement("alter table `academic_periods` {$drop} `academic_periods_one_year_check`");
            }
            DB::statement("alter table `training_phases` {$drop} `training_phases_dates_check`");
        }

        // Phases were unique across the whole course: keep the first of each number or name.
        $kept = [];
        foreach (DB::table('training_phases')->orderBy('id')->get() as $phase) {
            if (isset($kept['n'.$phase->number]) || isset($kept['m'.$phase->name])) {
                DB::table('class_subjects')->where('training_phase_id', $phase->id)->update(['training_phase_id' => $kept['n'.$phase->number] ?? $kept['m'.$phase->name]]);
                DB::table('training_phases')->where('id', $phase->id)->delete();

                continue;
            }
            $kept['n'.$phase->number] = $phase->id;
            $kept['m'.$phase->name] = $phase->id;
        }

        Schema::table('training_phases', function (Blueprint $table) {
            $table->dropForeign(['academic_period_id']);
            $table->dropUnique(['academic_period_id', 'number']);
            $table->dropUnique(['academic_period_id', 'name']);
        });
        Schema::table('training_phases', function (Blueprint $table) {
            $table->dropColumn(['academic_period_id', 'starts_on', 'ends_on']);
            $table->unique('number');
            $table->unique('name');
        });
    }

    private function placeExistingPhases(): void
    {
        $fallback = DB::table('academic_periods')->orderByDesc('is_active')->orderByDesc('starts_on')->orderByDesc('id')->value('id');
        $now = now();

        foreach (DB::table('training_phases')->orderBy('number')->get() as $phase) {
            $periodIds = DB::table('class_subjects')
                ->join('class_batches', 'class_batches.id', '=', 'class_subjects.class_batch_id')
                ->where('class_subjects.training_phase_id', $phase->id)
                ->distinct()
                ->orderBy('class_batches.academic_period_id')
                ->pluck('class_batches.academic_period_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            if ($periodIds === []) {
                if ($fallback === null) {
                    DB::table('training_phases')->where('id', $phase->id)->delete();

                    continue;
                }
                $periodIds = [(int) $fallback];
            }

            DB::table('training_phases')->where('id', $phase->id)->update(['academic_period_id' => array_shift($periodIds)]);

            foreach ($periodIds as $periodId) {
                $copyId = DB::table('training_phases')->insertGetId([
                    'academic_period_id' => $periodId, 'number' => $phase->number, 'name' => $phase->name,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('class_subjects')
                    ->where('training_phase_id', $phase->id)
                    ->whereIn('class_batch_id', DB::table('class_batches')->where('academic_period_id', $periodId)->select('id'))
                    ->update(['training_phase_id' => $copyId]);
            }
        }
    }

    /** Each period's phases share its days evenly, in phase order. */
    private function dateExistingPhases(): void
    {
        $periods = DB::table('academic_periods')->get()->keyBy('id');

        DB::table('training_phases')->orderBy('number')->get()->groupBy('academic_period_id')->each(function ($phases, $periodId) use ($periods): void {
            $period = $periods->get($periodId);
            $start = CarbonImmutable::parse((string) $period->starts_on);
            $days = max(1, (int) $start->diffInDays(CarbonImmutable::parse((string) $period->ends_on)) + 1);
            $count = $phases->count();

            foreach ($phases->values() as $index => $phase) {
                $from = $start->addDays(intdiv($days * $index, $count));
                $to = $start->addDays(max(intdiv($days * ($index + 1), $count) - 1, intdiv($days * $index, $count)));
                DB::table('training_phases')->where('id', $phase->id)->update(['starts_on' => $from->toDateString(), 'ends_on' => $to->toDateString()]);
            }
        });
    }

    private function isMysqlFamily(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function hasCheck(string $table, string $name): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $name)
            ->exists();
    }
};
