<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\NutritionStandards;
use App\Support\NutritionPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Nutrition" (candidate portal, owner decision 2026-10-05): the signed-in
 * candidate's own assessments (measurements, BMI category, plan, next
 * review) and dietary profile. The dietitian's diet history, findings and
 * diagnosis, and the dietitian's name, are never sent (NutritionPresenter).
 */
class NutritionController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $candidate = $request->user()->candidate()->with(['classBatch:id,name', 'dietaryProfile'])->firstOrFail();
        $standards = NutritionStandards::current();

        return Inertia::render('portal/nutrition', [
            'candidate' => [
                'name' => $candidate->full_name,
                'number' => $candidate->candidate_number,
                'className' => $candidate->classBatch?->name,
            ],
            'assessments' => NutritionPresenter::history($candidate, $standards, forStaff: false),
            'dietaryProfile' => NutritionPresenter::dietaryProfile($candidate->dietaryProfile, forStaff: false),
            'standards' => $standards->toSummary(),
        ]);
    }
}
