<?php

namespace App\Services\Monitoring;

final class SubjectPerformance
{
    /** Summarize grades already calculated by the authoritative grade engine. */
    public function summarize(array $monitored): array
    {
        $rows = [];
        foreach ($monitored as $candidate) {
            foreach ($candidate->subjects as $subject) {
                $offering = $subject['offering'];
                $grade = $subject['grade'];
                $rows[$offering->id] ??= [
                    'id' => $offering->id, 'subjectId' => $offering->subject_id,
                    'classId' => $offering->class_batch_id, 'classBatch' => $offering->classBatch->name,
                    'subject' => $offering->subject->name, 'candidateCount' => 0,
                    'gradedCount' => 0, 'sum' => 0, 'provisionalCount' => 0,
                    'passing' => 0, 'at_risk' => 0, 'failing' => 0, 'incomplete' => 0, 'none' => 0,
                ];
                $row = &$rows[$offering->id];
                $row['candidateCount']++;
                $row[$grade->standing?->value ?? 'none']++;
                if ($grade->grade !== null) {
                    $row['sum'] += $grade->grade;
                    $row['gradedCount']++;
                }
                $row['provisionalCount'] += (int) $grade->isProvisional();
                unset($row);
            }
        }

        return collect($rows)->map(function (array $row) {
            $row['average'] = $row['gradedCount'] > 0 ? round($row['sum'] / $row['gradedCount'], 2) : null;
            unset($row['sum']);

            return $row;
        })->sortBy([['failing', 'desc'], ['at_risk', 'desc'], ['subject', 'asc']])->values()->all();
    }
}
