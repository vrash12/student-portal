<?php

namespace App\Services\Performance;

use App\Enums\PerformanceSource;

/**
 * What candidates see of their own qualification (owner decision
 * 2026-10-02: military fitness is staff only). While the portal does not
 * show fitness (institution.portal.show_fitness off), fitness areas are left
 * out of the areas and results, and a fitness requirement that is not met or
 * still pending is named only as a staff-assessed requirement, so the status
 * is never shown without its reason. The status and overall score are the
 * official ones from QualificationEngine.
 */
final class PortalQualification
{
    public const STAFF_ASSESSED_REASON = 'Staff-assessed requirement not met';

    public const STAFF_ASSESSED_PENDING = 'staff-assessed requirements';

    /**
     * @return array{areas: list<array<string, mixed>>, result: array<string, mixed>|null, staffAssessedAreas: int}
     */
    public static function of(?CandidateQualification $qualification): array
    {
        if ($qualification === null) {
            return ['areas' => [], 'result' => null, 'staffAssessedAreas' => 0];
        }

        $hidden = self::hiddenAreas($qualification);
        $hiddenIds = array_map(fn (AreaDefinition $area): int => $area->id, $hidden);
        $result = $qualification->toArray(withRank: false);
        $result['areas'] = array_values(array_filter($result['areas'], fn (array $area): bool => ! in_array($area['areaId'], $hiddenIds, true)));
        $result['qualification'] = self::decision($qualification);

        return [
            'areas' => array_values(array_map(
                fn (AreaDefinition $area): array => $area->toArray(),
                array_filter($qualification->areaDefinitions(), fn (AreaDefinition $area): bool => ! in_array($area->id, $hiddenIds, true)),
            )),
            'result' => $result,
            'staffAssessedAreas' => count($hidden),
        ];
    }

    /**
     * The decision with hidden areas named only as staff-assessed requirements.
     *
     * @return array{status: array{value: string, label: string, tone: string}, reasons: list<string>, pending: list<string>}
     */
    public static function decision(CandidateQualification $qualification): array
    {
        $decision = $qualification->qualification->toArray();
        $hiddenNames = array_map(fn (AreaDefinition $area): string => $area->name, self::hiddenAreas($qualification));
        if ($hiddenNames === []) {
            return $decision;
        }

        $hiddenReasons = array_map(fn (string $name): string => QualificationEngine::reasonFor($name), $hiddenNames);
        $decision['reasons'] = self::replace($decision['reasons'], $hiddenReasons, self::STAFF_ASSESSED_REASON);
        $decision['pending'] = self::replace($decision['pending'], $hiddenNames, self::STAFF_ASSESSED_PENDING);

        return $decision;
    }

    /**
     * Areas candidates do not see: fitness areas while the portal does not show fitness.
     *
     * @return list<AreaDefinition>
     */
    private static function hiddenAreas(CandidateQualification $qualification): array
    {
        if (config('institution.portal.show_fitness')) {
            return [];
        }

        return array_values(array_filter($qualification->areaDefinitions(), fn (AreaDefinition $area): bool => $area->source === PerformanceSource::Fitness));
    }

    /**
     * @param  list<string>  $items
     * @param  list<string>  $hidden
     * @return list<string>
     */
    private static function replace(array $items, array $hidden, string $replacement): array
    {
        $kept = array_values(array_filter($items, fn (string $item): bool => ! in_array($item, $hidden, true)));

        return count($kept) === count($items) ? $items : [...$kept, $replacement];
    }
}
