<?php

namespace Tests\Feature\Examinations\Builder;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Filters on the instructor's Quizzes & Examinations list (owner request
 * 2026-10-05): title search, status (the lifecycle shown in each row), kind
 * and class subject. Only the instructor's own examinations are ever listed,
 * and filter values the instructor may not use are ignored.
 */
class ExaminationListFiltersTest extends TestCase
{
    use BuildsExaminationBuilderFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildExaminationBuilderFixtures();

        $this->readyDraft(['title' => 'Draft Quiz']);
        $this->publishedExam(['title' => 'Open Examination', 'kind' => 'examination']);
        $this->publishedExam(['title' => 'Ended Quiz'])->forceFill(['opens_at' => now()->subDays(3), 'closes_at' => now()->subDay()])->save();
        $this->publishedExam(['title' => 'Upcoming Quiz'])->forceFill(['opens_at' => now()->addDay(), 'closes_at' => now()->addDays(2)])->save();
        // Another instructor's examination never appears in Alpha's list.
        $this->draft(['title' => 'Bravo Quiz'], $this->bravoOffering);
    }

    /**
     * @param  array<string, string>  $query
     * @return list<string>
     */
    private function titles(array $query = []): array
    {
        $props = $this->actingAs($this->alpha)->get('/examinations?'.http_build_query($query))->assertOk()->inertiaProps();

        return collect($props['examinations']['data'])->pluck('title')->sort()->values()->all();
    }

    public function test_the_list_filters_by_status_kind_title_and_subject(): void
    {
        $this->assertSame(['Draft Quiz', 'Ended Quiz', 'Open Examination', 'Upcoming Quiz'], $this->titles());
        $this->assertSame(['Draft Quiz'], $this->titles(['status' => 'draft']));
        $this->assertSame(['Open Examination'], $this->titles(['status' => 'active']));
        $this->assertSame(['Ended Quiz'], $this->titles(['status' => 'ended']));
        $this->assertSame(['Upcoming Quiz'], $this->titles(['status' => 'upcoming']));
        $this->assertSame(['Open Examination'], $this->titles(['kind' => 'examination']));
        $this->assertSame(['Upcoming Quiz'], $this->titles(['search' => 'quiz', 'status' => 'upcoming']));
        $this->assertSame(['Ended Quiz'], $this->titles(['search' => 'ended']));
        $this->assertSame(['Draft Quiz', 'Ended Quiz', 'Open Examination', 'Upcoming Quiz'], $this->titles(['offering' => (string) $this->alphaOffering->id]));
    }

    public function test_unknown_values_and_other_instructors_subjects_are_ignored(): void
    {
        $this->actingAs($this->alpha)->get('/examinations?'.http_build_query(['status' => 'deleted', 'kind' => 'essay', 'offering' => (string) $this->bravoOffering->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters', ['search' => '', 'status' => '', 'kind' => '', 'offering' => ''])
                ->has('examinations.data', 4)
                ->where('total', 4)
                ->where('offeringOptions', fn ($options) => collect($options)->contains('id', $this->alphaOffering->id) && ! collect($options)->contains('id', $this->bravoOffering->id)));

        // A search with no match keeps the total, so the page shows "no match" rather than "no examinations yet".
        $this->actingAs($this->alpha)->get('/examinations?search=nothing-like-this')
            ->assertInertia(fn (Assert $page) => $page->has('examinations.data', 0)->where('total', 4));
    }
}
