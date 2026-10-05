# Nutrition Monitoring

Owner request, 2026-10-05: a dietitian assesses and monitors the nutrition of the candidates. Monitoring only: nothing here changes grades, qualification or class rank.

## How it works (the Nutrition Care Process)

Dietitians document care in four steps (the Academy of Nutrition and Dietetics' Nutrition Care Process, often written as ADIME). The assessment form follows them:

| Step | In the system |
|---|---|
| **Assessment**: measurements (anthropometric), lab results (biochemical), physical findings (clinical), diet history | Height, weight, waist, body fat; activity level, meals a day, diet history (24-hour recall, usual meals); clinical findings; lab findings, read from the medical record (view only) |
| **Diagnosis**: the nutrition problem, its cause and its signs | Nutrition Diagnosis (free text, for example "inadequate energy intake related to skipped meals, shown by 3 kg weight loss") |
| **Intervention**: the plan | Goal (maintain, gain, lose), target weight, daily energy (kcal), plan |
| **Monitoring and evaluation**: follow-up | Next review date (default: the standards' interval), the next assessment, and the weight and BMI trend |

Military training adds a reason to watch closely: studies of recruits and cadets often find energy intake well below what heavy training needs, which shows up as weight loss, fatigue and injuries.

## Classification

- **BMI** = weight (kg) ÷ height (m)², to one decimal. It is computed from the stored height and weight (`NutritionStandards::bmi`), never stored, so a change of standards reclassifies every assessment at once.
- **Categories** (`NutritionStandards::classify`): Underweight below 18.5; Normal 18.5 to under 23; Overweight 23 to under 27.5; Obese from 27.5. These are the WHO Asia-Pacific cut-offs, which the Department of Health uses for Filipino adults (Asians carry more body fat and metabolic risk at the same BMI). Administrators who see every campus change them on Nutrition → Standards (`nutrition.configure`).
- **Waist**: waist ÷ height at or above 0.50 is flagged "at risk" (the waist-to-height ratio the US military adopted in 2026; it needs no sex-specific table). Configurable.
- **Needs attention** (`NutritionMonitoring`): never assessed, BMI not Normal, waist at risk, or the next review date has come. Only candidates in training (enrolled or on leave) are listed.

## Who sees what

| Viewer | Sees |
|---|---|
| Dietitian (`dietitian` role; one campus) | Every candidate of the campus: list, records, all notes; records assessments and dietary profiles; the medical record view only (same rules as instructors: protected viewer, every view audited, downloads only with an approved request) |
| Administrators of the campus (`nutrition.view`) | Everything, read-only; the dashboard panel; standards (institution-wide only) |
| Instructors of the candidate's class | On the profile: BMI category, last assessment date, food allergies and dietary restrictions only |
| The candidate (portal → Nutrition) | Own measurements, BMI category, goal, targets, plan, next review, dietary profile; not the diagnosis, findings, diet history or the dietitian's name |
| Anyone else, other campuses | Nothing ("not found" by URL) |

Audit entries record who recorded, corrected (field names only) or deleted (with a reason) an assessment, and dietary profile changes (field names only). Measurements, notes and plans never go into the audit log.

## Code map

- Migration `2026_10_05_000200_create_nutrition_tables` (`nutrition_standards`, `candidate_dietary_profiles`, `nutrition_assessments` with CHECK constraints).
- Models `NutritionStandards`, `NutritionAssessment`, `CandidateDietaryProfile`; enums `NutritionStatus`, `ActivityLevel`, `NutritionGoal`.
- `App\Services\Nutrition\NutritionService` (every change, in transactions, audited), `NutritionMonitoring` (list and dashboard rows), `App\Support\NutritionPresenter` (what each audience gets).
- Rules: `CandidatePolicy::viewNutrition`, `manageNutrition`, `viewNutritionSummary`, `viewMedicalAsDietitian` / `viewMedicalReadOnly`; `NutritionAssessmentPolicy`; `Role::requiresCampus`.
- Pages: `staff/nutrition/{index,show,assessment-form,standards}.tsx`, `portal/nutrition.tsx`, `components/nutrition/*`.
- Demo: `DemoNutritionSeeder` (`dietitian1`…`dietitian4`, one per campus; synthetic assessments from the demo medical heights and weights).
- Tests: `tests/Feature/Nutrition`, `tests/Unit/NutritionStandardsTest.php`, the dietitian case in `tests/Feature/Medical/MedicalDownloadRequestTest.php`.

## Open questions for the owner

- The institution's official body-composition standard for candidates (BMI and waist lines, or a body-fat test) and whether it differs by sex or age.
- Whether a mess hall or meal-planning view (counts of allergies and restrictions per class) is wanted.
- Whether the candidate should be told by the system when a review is due.

## Sources

- Academy of Nutrition and Dietetics, Nutrition Care Process / ADIME: https://en.wikipedia.org/wiki/ADIME
- WHO Asia-Pacific BMI cut-offs used for Filipino adults: https://www.behealthy.ph/blogs/all-about-your-health/bmi-chart-philippines-asian-standards
- How the US military measures body composition (waist-to-height ratio from 2026): https://www.hprc-online.org/physical-fitness/training-performance/how-military-measures-body-composition
- Energy intake of recruits in basic training: https://dcjournal.ca/doi/10.3148/cjdpr-2026-012
