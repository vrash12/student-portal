<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Nutrition\NutritionStandardsRequest;
use App\Models\NutritionStandards;
use App\Services\Nutrition\NutritionService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The nutrition standards shared by every campus (nutrition.configure,
 * institution-wide administrators only): BMI cut-offs, the waist-to-height
 * risk line and the default time to the next review.
 */
class NutritionStandardsController extends Controller
{
    public function edit(): Response
    {
        $standards = NutritionStandards::current()->loadMissing('updater:id,name');

        return Inertia::render('staff/nutrition/standards', [
            'standards' => $standards->toSummary(),
            'updatedAt' => $standards->updated_at?->toIso8601String(),
            'updatedBy' => $standards->updater?->name,
        ]);
    }

    public function update(NutritionStandardsRequest $request, NutritionService $nutrition): RedirectResponse
    {
        $nutrition->updateStandards($request->standardsData(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Nutrition standards saved.']);

        return redirect()->route('nutrition.index');
    }
}
