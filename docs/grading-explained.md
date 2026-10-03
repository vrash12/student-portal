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

Three layers sit on the same subject grades:

| Layer | Answers | Set where | Applies to |
| --- | --- | --- | --- |
| **Academic standing** (steps 1–3) | "Is this candidate passing this subject?" | Weights: per subject of a class. Passing/warning grades: per academic period. | Gradebooks, Academic Monitoring, profiles, portal My Grades, reports |
| **Phase averages and CGPA** (step 3b) | "What is the candidate's average in each training phase, and overall so far?" | Training Phases of each academic year; each subject's phase and units on its class page | Profile (staff who see every subject), portal My Grades, Qualification page, Academic Record PDF |
| **Qualification** (steps 4–5) | "Has this candidate met the requirements to qualify, what is their final course grade, and where do they rank?" | Performance Areas (global, every period and class) | Records → Qualification, admin dashboard, profile panel, portal My Performance (no rank) |

How the OCS course format maps to the system (owner decision 2026-10-03: a class keeps its candidates for the whole course):

| Course format | In the system |
| --- | --- |
| Training period / phase | The **academic period is the course year** (e.g. 2026-2027, at most one year). Its **Training Phases** (Academics → Training Phases): Phase 1, 2, 3 or the official phases, each with dates inside the year. |
| Subjects / training modules under each phase | Each subject of a class has a **phase** and **units** (class page) |
| Individual grades | Subject grades (step 2) |
| Phase average | Unit-weighted average of the phase's subject grades |
| Cumulative General Point Average (CGPA) | Unit-weighted average of every subject grade so far |
| Class standing / rank | Class rank by final course grade (staff only) |
| Final course grade | The weighted performance areas (step 5), shown as **Final Course Grade** |

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

### 3b. Training phases, phase averages and the CGPA

- The whole course lasts **one year**: an **academic period** is that year (named like 2026-2027; the form fills the name from the dates) and lasts at most one year (form rule and a database CHECK).
- **Training phases** belong to one academic year (number, name, start and end dates), managed under **Academics → Training Phases** per year (permission `academic_periods.manage`). Their dates lie inside the year, follow the phase numbers and never overlap; numbers and names are unique within the year. A year's dates cannot be changed to leave out its phases. A phase that subjects are placed in cannot be deleted.
- A subject of a class can only be placed in a phase of its class's year.
- On a **class page** each subject gets a **phase** (or none) and **units** (default 1; 0.1–50). Units weight the averages, like a GWA; with every subject at 1 unit each subject counts equally. Changes are audited (`class_subject.updated`).
- The rule (`GradeCalculationService::weightedAverage`, used by `CourseRecordService`):
  - **phase average** = Σ (subject grade × units) ÷ Σ (units) over the phase's subjects that have a grade;
  - **CGPA** = the same over every subject of the class so far, whatever its phase;
  - subjects without a grade are left out, never counted as zero; half up to two decimals with exact integer arithmetic;
  - a phase (or the CGPA) is **Final** once every one of its subjects has a final grade (graded, every component assessed, nothing missing); until then it is **In progress**.
- Example: Subject 1 (Phase 1, 3 units) 84.00 and Subject 2 (Phase 2, 1 unit) 60.00 → Phase 1 84.00, Phase 2 60.00, CGPA (84 × 3 + 60) ÷ 4 = **78.00**.
- The CGPA is on the same 0–100 scale as the grades. It combines every subject, so it is shown only to staff who see all of a candidate's subjects (not to instructors limited to the subjects they teach) and to the candidate themselves.
- Subject performance areas (step 4) use the same unit-weighted average.

### 4. Performance areas

- Global list (`performance_areas`), set on **Performance Areas** (Records → Qualification → Performance Areas, permission `performance.configure`). Each area has a **source**, a **weight**, its own **passing grade** and **must pass**.
- Sources and their grade (`QualificationEngine`, `ConductLedger`, `AttendanceLedger`, `FitnessResults`):
  - **Subject grades:** unit-weighted average of the candidate's grades in the subjects ticked for the area (each subject belongs to at most one area);
  - **Military fitness:** points in the latest fitness test of the class that has results; every event standard must also be met (events and points tables: Records → Military Fitness → Events and Points);
  - **Conduct:** base rating + merit points × value − demerit points × value, kept within 0–100 (types: Records → Merits & Demerits → Types);
  - **Attendance:** (present + late) ÷ (present + late + absent) × 100.
- Weights are **relative**: an area's share of the final course grade is its weight ÷ the total of the active weights (Grading Setup and the areas list show the share).

### 5. Qualification, final course grade and class rank

- **Final course grade** (the "overall score" in the code) = Σ (weight × area grade) ÷ Σ (weights of the areas that have a grade); *Partial* while some weighted area has no grade.
- **Not Qualified** when a must-pass area is failed; **Pending** while a must-pass area has no result or is incomplete; otherwise **Qualified**.
- **Class rank**: within each class by final course grade (ties share a rank). Staff only; never sent to the portal.

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
3. **Training Phases**: add the phases of the new year, with their dates (Phase 1, 2, 3 …).
4. **Classes → Create Class**, then **Add Subject** for each subject with its **phase** and **units**, and assign instructors.
5. **Grading Setup → Step 2**: set one subject's weights, then **Copy Weights** to the rest (the table also shows each subject's phase and units).
6. Check **Steps 4–5** once (areas are global): every subject belongs to an area, weights and must-pass are right.

## Open policy questions (owner decisions; the code does not decide them)

- The official training phases and the units of each subject (placeholders: Phase 1–3, 1 unit each).
- The CGPA is on the 0–100 grade scale; a 1.00–5.00 point scale would need the official conversion table. It includes every subject of the class (academic and military skills subjects alike).
- Whether the CGPA should count only finished phases (it currently counts every graded subject, with In progress shown).

- Overall standing is the most serious subject (the AGENTS.md §17 example suggests an average).
- A subject area averages its subjects, so one failing subject can be hidden by a strong one in qualification while Academic Monitoring shows it Failing.
- Qualification can be Qualified on provisional subject grades.
- The fitness area uses the latest test with any result, so recording the first results of a new test changes the class's fitness grades at once.
- Conduct counts every entry since enrolment, while fitness and attendance use the current class.
- The area passing grade for subject areas is independent of the period's passing grade.
