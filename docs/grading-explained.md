# How Grading Works (for developers and administrators)

This is the short version of how a candidate's result is decided, where each rule lives in the code, and where an administrator changes it. The details are in `docs/performance-and-qualification.md` (areas, qualification, rank) and in the docblocks of the classes named below.

**In the application:** Academics → **Grading Setup** (`/grading-setup`, permission `grading.configure`) shows all of this for one academic period: what is set, what is missing, a worked example calculated by the grade engine, and links to the page that changes each setting.

## The pipeline

```text
 1. SCORES                2. SUBJECT GRADE          3. SUBJECT STANDING          4. PERFORMANCE AREAS            5. QUALIFICATION & RANK
 instructor records    →  components × weights   →  period passing/warning   →  subject grades, fitness,     →  must-pass areas: Qualified /
 raw scores, then         (per subject of a         grades:                     conduct, attendance,            Not Qualified / Pending;
 FINALIZES the            class), 0–100             Passing / At Risk /         each with a weight and          overall score ranks each class
 assessment                                         Failing / Incomplete        its own passing grade           (staff only)
```

Two layers sit on the same subject grades:

| Layer | Answers | Set where | Applies to |
| --- | --- | --- | --- |
| **Academic standing** (steps 1–3) | "Is this candidate passing this subject?" | Weights: per subject of a class. Passing/warning grades: per academic period. | Gradebooks, Academic Monitoring, profiles, portal My Grades, reports |
| **Qualification** (steps 4–5) | "Has this candidate met the requirements to qualify, and where do they rank?" | Performance Areas (global, every period and class) | Records → Qualification, admin dashboard, profile panel, portal My Performance (no rank) |

Nothing is stored: every grade, standing, area result and rank is calculated when a page is opened, so a changed score or setting shows everywhere at once.

## Step by step

### 1. Scores (instructors)

- An instructor creates an **assessment** in one **component** of a subject (for example "Quiz 2" in *Quizzes*, maximum 25), records raw scores while it is a **draft**, then **finalizes** it. Only finalized assessments count. Finalizing cannot be undone.
- After finalization a score changes only through a **grade correction request** with an incident report, approved by an administrator (AGENTS.md §36).
- A quiz or examination taken on the tablets becomes one finalized assessment when the instructor uses **Post to Gradebook** (`ExaminationGradebookService`), choosing the component.
- Code: `AssessmentService`, `ScoreRecordingService`, `GradeCorrectionService` (all in `app/Services/Grading`).

### 2. Subject grade: components and weights

- Each **subject of each class** (a `class_subjects` row) has its own components and weights (`assessment_categories`; called "categories" in the code, "components" on screen). The weights add up to exactly 100.
- Administrators set them on **Subject Weights** (`/classes/{class}/subjects/{classSubject}/grading`), reached from Grading Setup or the class page. **Copy Weights** on Grading Setup gives every subject without weights the weights of one that is set up; an empty subject can also **start from** another subject's weights.
- The rule (`GradeCalculationService`, the only place grades are calculated):
  - component % = points earned ÷ points possible over the candidate's finalized assessments in that component (larger assessments count more);
  - subject grade = Σ (component % × weight) ÷ Σ (weights of the components that have a score) × 100;
  - while some components have no finalized assessment yet the grade is **provisional**: it is worked out over the weight assessed so far ("based on 50% of the weight");
  - a missing score is **never zero**: it is left out and reported (Missing Scores);
  - rounding: half up to two decimals, only at the end.
- Example (Quizzes 20, Examinations 30, Practical Exercises 30, Other Requirements 20 with 90, 80, 85, 70): 18 + 24 + 25.5 + 14 = **81.50**. With only Quizzes and Examinations assessed: (18 + 24) ÷ 50 × 100 = **84.00**.

### 3. Subject standing: passing and warning grades

- Each **academic period** has a passing grade and a warning grade (`academic_periods.passing_grade`, `warning_grade`), set on **Passing and Warning Grades** (`/academic-periods/{period}/grading-thresholds`). There is no built-in default; without them grades are calculated but no standing is shown.
- `GradeCalculationService::standing()`: below passing → **Failing**; passing up to (not including) warning → **At Risk**; warning and above → **Passing**, or **Incomplete** when scores are missing (Failing and At Risk are kept even with missing scores, so a warning is never hidden). Compared as shown, to two decimals.
- A candidate's overall standing is their **most serious** subject standing (`overallStanding()`), not an average.
- Withdrawn candidates keep their grades but get no standing.

### 4. Performance areas

- Global list (`performance_areas`), set on **Performance Areas** (Records → Qualification → Performance Areas, permission `performance.configure`). Each area has a **source**, a **weight**, its own **passing grade** and **must pass**.
- Sources and their grade (`QualificationEngine`, `ConductLedger`, `AttendanceLedger`, `FitnessResults`):
  - **Subject grades:** average of the candidate's grades in the subjects ticked for the area (each subject belongs to at most one area);
  - **Military fitness:** points in the latest fitness test of the class that has results; every event standard must also be met (events and points tables: Records → Military Fitness → Events and Points);
  - **Conduct:** base rating + merit points × value − demerit points × value, kept within 0–100 (types: Records → Merits & Demerits → Types);
  - **Attendance:** (present + late) ÷ (present + late + absent) × 100.
- Weights are **relative**: an area's share of the overall score is its weight ÷ the total of the active weights (Grading Setup and the areas list show the share).

### 5. Qualification and class rank

- **Overall score** = Σ (weight × area grade) ÷ Σ (weights of the areas that have a grade); *Partial* while some weighted area has no grade.
- **Not Qualified** when a must-pass area is failed; **Pending** while a must-pass area has no result or is incomplete; otherwise **Qualified**.
- **Class rank**: within each class by overall score (ties share a rank). Staff only; never sent to the portal.

## The three "passing" settings

| Setting | Set | Decides | Does not change |
| --- | --- | --- | --- |
| Passing and warning grades | per academic period | Passing / At Risk / Failing in each subject | qualification |
| Area passing grade | per performance area | whether the area is passed → Qualified / Not Qualified | subject standing |
| Examination passing score | per quiz or examination | that attempt's Passed / Failed | subject standing, qualification |

They are independent on purpose; Grading Setup shows them side by side.

## Setting up a new period (administrator checklist)

1. **Academic Periods → Create Period**, then **Set Active**.
2. **Grading Setup → Step 3**: set the passing and warning grades (prefilled from the previous period).
3. **Classes → Create Class**, then **Add Subject** for each subject and assign instructors.
4. **Grading Setup → Step 2**: set one subject's weights, then **Copy Weights** to the rest.
5. Check **Steps 4–5** once (areas are global): every subject belongs to an area, weights and must-pass are right.

## Open policy questions (owner decisions; the code does not decide them)

- Overall standing is the most serious subject (the AGENTS.md §17 example suggests an average).
- A subject area averages its subjects, so one failing subject can be hidden by a strong one in qualification while Academic Monitoring shows it Failing.
- Qualification can be Qualified on provisional subject grades.
- The fitness area uses the latest test with any result, so recording the first results of a new test changes the class's fitness grades at once.
- Conduct counts every entry since enrolment, while fitness and attendance use the current class.
- The area passing grade for subject areas is independent of the period's passing grade.
