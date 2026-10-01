# Contract: conduct, attendance, company/platoon, performance areas and qualification

Owner request (2026-10-01), based on a suggested military-school grading structure. Owner decisions:

- Build **Merits & Demerits**, **Attendance**, **Performance areas & qualification**, **Company/Platoon & class rank**.
- **Military fitness counts as a must-pass area**: the latest fitness test feeds the Physical area; failing it makes the candidate NOT QUALIFIED with that reason. Subject grades and academic standing are not changed.
- **Candidates see their own results, no rank**: own area results, qualification checklist, merits/demerits and attendance in the portal. No class rank and nothing about other candidates.
- **Statement of Account**: add default categories for pay and allowances, deductions and issued-item accountability (no tuition at OCS). Pay rates are never hardcoded; staff only.
- Nothing institutional is hardcoded: areas, weights, passing grades, must-pass flags, conduct rule, merit/demerit types and session hours are configurable. Seeded values are placeholders until OCS provides its official grading SOP.

Existing academic standing (Passing / At Risk / Failing / Incomplete per subject, `GradeCalculationService`, period thresholds) stays exactly as it is. Areas and qualification are a layer on top that reads it.

## Shared pieces (already in the repository — do not edit unless your module section says so)

- Permissions (`App\Enums\Permission`, mirrored in `resources/js/lib/permissions.ts`, granted by `SystemRole` defaults and migration `2026_10_01_000750_add_conduct_attendance_performance_permissions`):
  - `conduct.manage` (`ManageConduct`): record and void merits/demerits. Super/Academic Administrators and Instructors. **Scope:** users with `candidates.view_all` act on every candidate; others only on candidates whose current class they teach (`$user->teachesClass($candidate->class_batch_id)`).
  - `attendance.manage` (`ManageAttendance`): create training sessions and record attendance. Same holders and the same scope by class.
  - `performance.configure` (`ConfigurePerformance`): performance areas, subject-to-area mapping, conduct rating rule, merit/demerit types. Administrators only.
  - `performance.view` (`ViewPerformance`): qualification and class ranking of every candidate. Administrators only.
- Audit actions (`App\Enums\AuditAction`): `ConductEntryRecorded`, `ConductEntryVoided`, `ConductTypeCreated`, `ConductTypeUpdated`, `AttendanceSessionCreated`, `AttendanceSessionUpdated`, `AttendanceSessionDeleted`, `AttendanceRecorded`, `PerformanceAreaCreated`, `PerformanceAreaUpdated`. Candidate company/platoon changes use the existing `CandidateUpdated`.
- Morph map (`AppServiceProvider`): `conduct_entry`, `conduct_type`, `attendance_session`, `performance_area` → `App\Models\ConductEntry`, `ConductType`, `AttendanceSession`, `PerformanceArea`.
- Routes (`routes/web.php`, already written; implement the controllers and methods named there): conduct, attendance, qualification and performance-area groups. Frontend helpers already in `resources/js/lib/routes.ts` (`routes.conduct`, `routes.attendance`, `routes.qualification`, `routes.performanceAreas`) and sidebar items in `resources/js/lib/navigation.ts`.
- Migration file names are reserved per module (below); run migrations only against your own test database.

## Module A — Merits & Demerits (conduct)

- Migration `2026_10_01_000900_create_conduct_tables.php`:
  - `conduct_types`: `name` (100, unique), `kind` (`merit`|`demerit`, CHECK), `default_points` (unsigned small int, CHECK 1–100), `description` (255, null), `sort_order`, `is_active`, timestamps. Seed placeholder types (editable): merits "Outstanding performance" 5, "Leadership commendation" 3, "Exemplary conduct" 2; demerits "Late for formation" 2, "Improper uniform" 1, "Violation of regulations" 5.
  - `conduct_entries`: `candidate_id` FK, `conduct_type_id` FK, `kind` (copied from the type at recording; CHECK), `points` (unsigned small int, CHECK 1–100), `occurred_on` date, `reason` (255, required), `recorded_by` FK users, `voided_at` / `voided_by` / `void_reason` (all or none, CHECK), timestamps, index (`candidate_id`, `occurred_on`).
  - Entries are never edited or deleted; a mistake is voided with a reason (5–255 chars) and re-entered (same pattern as `account_entries`).
- `App\Enums\ConductKind` (`Merit`, `Demerit`, label, tone). Models `ConductType`, `ConductEntry`.
- `App\Services\Conduct\ConductService` (record, void with row lock, type create/update; all audited) and `App\Services\Conduct\ConductLedger`:
  - `totalsFor(array $candidateIds): array<int, array{merits: int, demerits: int, net: int}>` — sums of points over entries that are not voided; every requested id is present (zeros when none). **Module D depends on this exact signature.**
  - `history(Candidate $candidate, ?int $limit = null): list<array>` — newest first, including voided entries (marked), for the conduct page, the candidate profile and the portal (the portal leaves voided entries out).
- Authorization: route middleware `conduct.manage` / `performance.configure`, plus the class scope above (a `ConductPolicy` or checks in the controller via a shared helper); a candidate outside the user's scope is 403 on show/store/void.
- Pages: `/conduct` (scoped candidate list: number, name, class, merits, demerits, net; search and class filters; paginated), `/conduct/candidates/{candidate}` (totals, record form — type select grouped Merits/Demerits pre-filling kind and points, points editable 1–100, date, reason —, ledger table with voided rows struck through and the reason, Void dialog with a required reason), `/conduct/types` (+ create/edit). Reusable components in `resources/js/components/conduct/`. Types in `resources/js/types/conduct.ts`.
- Tests `tests/Feature/Conduct/ConductTest.php` (+ unit tests where useful): record, totals, void excluded and audited, cannot void twice, validation, scope (instructor of another class 403, own class OK), types CRUD admin-only, candidates/finance forbidden.

## Module B — Attendance

- Migration `2026_10_01_001000_create_attendance_tables.php`:
  - `attendance_sessions`: `class_batch_id` FK, `held_on` date, `title` (150), `hours` decimal(4,2) CHECK > 0 and ≤ 24, `notes` (500, null), `created_by` FK users, timestamps, index (`class_batch_id`, `held_on`).
  - `attendance_records`: `attendance_session_id` FK cascade, `candidate_id` FK, `status` (`present`|`late`|`excused`|`absent`, CHECK), `remarks` (255, null), `recorded_by` FK users, timestamps, unique (`attendance_session_id`, `candidate_id`).
- `App\Enums\AttendanceStatus` (label, tone). Models `AttendanceSession`, `AttendanceRecord`.
- `App\Services\Attendance\AttendanceService` (create/update/delete session — delete only without records —, record statuses in one transaction with a row lock on the session; every change audited with previous/new values per "candidate number") and `App\Services\Attendance\AttendanceLedger`:
  - `summariesFor(int $classBatchId, array $candidateIds): array<int, array{sessions: int, present: int, late: int, excused: int, absent: int, unrecorded: int, hours: float, rate: ?float}>` over the sessions of that class. **rate** = (present + late) ÷ (present + late + absent) × 100, rounded half-up to 2 decimals; excused and unrecorded are left out; null when the divisor is 0. **hours** = sum of session hours where present or late. Every requested id is present. **Module D depends on this exact signature and rule.**
  - `candidateHistory(Candidate $candidate, ?int $limit = null): list<array>` — sessions of the candidate's current class, newest first, with the candidate's status.
- Recording is allowed only for candidates of the session's class who are not withdrawn.
- Pages: `/attendance` (scoped session list: date, title, class, hours, present/late/excused/absent counts, recorded n of roster; period and class filters), `/attendance/sessions/create` (class, date, title, hours, notes), `/attendance/sessions/{session}` (roll call: each candidate with Present / Late / Excused / Absent as large touch-friendly radio buttons plus optional remarks, "Mark all unrecorded as Present", save changed rows only, unsaved-changes guard, per-session counts), edit details, delete (only without records). Components in `resources/js/components/attendance/`, types in `resources/js/types/attendance.ts`.
- Tests `tests/Feature/Attendance/AttendanceTest.php`: rate rule (deterministic), record/update audited, withdrawn and other-class candidates refused, scope, delete rule, forbidden roles.

## Module C — Company/Platoon and Statement of Account categories

- Migration `2026_10_01_000800_add_company_and_platoon_to_candidates.php`: `company` (50, null), `platoon` (50, null) on `candidates`; index (`class_batch_id`, `company`, `platoon`).
- Candidate create/edit forms and requests (trimmed, optional, max 50), `CandidatePresenter::details` (`company`, `platoon`), candidate profile information panel, candidate list column(s) and filters (company and platoon selects built from distinct existing values), the candidate's audit snapshot (`CandidateUpdated` old/new values), and the registration/academic PDF information band when simple. Types in `resources/js/types/candidates.ts`.
- `App\Support\CandidateGroups::companies()` / `platoons()` (distinct non-empty values, sorted) for filters used by other modules.
- Migration `2026_10_01_000810_add_service_account_categories.php`: insert (if missing by name) "Pay & Allowances" (credit), "Deductions" (charge), "Issued Items / Accountability" (charge) after the existing ones. Update the account seeder only if it lists categories by name.
- Tests in `tests/Feature/Candidates/` for the new fields (store, update, audit, filters, validation) and a test that the new categories exist.

## Module D — Performance areas, qualification and class rank

- Migration `2026_10_01_001100_create_performance_areas_table.php`:
  - `performance_areas`: `name` (100, unique), `description` (255, null), `source` (`subjects`|`fitness`|`conduct`|`attendance`, CHECK), `weight` decimal(5,2) CHECK 0–100 (share in the overall score), `passing_grade` decimal(5,2) CHECK > 0 and ≤ 100, `must_pass` boolean, `base_rating` / `merit_value` / `demerit_value` decimal(5,2) null (conduct areas only), `sort_order`, `is_active`, timestamps.
  - `subjects.performance_area_id` nullable FK (restrict on delete). Only `subjects` areas may hold subjects.
  - At most one **active** area each for `fitness`, `conduct` and `attendance` (enforced in the service with a lock, and validated).
- Enums `App\Enums\PerformanceSource`, `App\Enums\AreaStatus` (`Passed`, `Failed`, `Incomplete`, `NotYet` "No results yet"; label, tone), `App\Enums\QualificationStatus` (`Qualified`, `NotQualified` "Not Qualified", `Pending`; label, tone).
- **Single engine** `App\Services\Performance\QualificationEngine` (AGENTS.md §15 — no area or qualification math anywhere else):
  - `forClass(ClassBatch $class): list<CandidateQualification>` — every candidate of the class who is not withdrawn, with class rank; batched (no N+1): subject grades through the existing grade engine / `AcademicMonitoring::evaluate`, `ConductLedger::totalsFor`, `AttendanceLedger::summariesFor`, and the class's latest fitness test that has results.
  - `forCandidate(Candidate $candidate): ?CandidateQualification` (null without a class); rank included for staff use only.
  - Area result per candidate, for each **active** area:
    - `subjects`: the candidate's class subjects whose subject belongs to the area. grade = mean of the non-null current subject grades (2 decimals, half-up). NotYet when there are no such subjects or no grade; Failed when grade < passing grade; Incomplete when grade ≥ passing grade but any of those subjects is incomplete/has missing scores (same meaning as academic standing); otherwise Passed.
    - `fitness`: the latest fitness test of the candidate's class that has results. Outcome NotTested → NotYet; Failed → Failed (grade = points when complete, else null); Incomplete → Incomplete; Passed → Passed when points ≥ passing grade, else Failed.
    - `conduct`: rating = clamp(base_rating + merits × merit_value − demerits × demerit_value, 0, 100), 2 decimals. Passed when rating ≥ passing grade, else Failed.
    - `attendance`: grade = attendance rate. NotYet when null; Passed when ≥ passing grade, else Failed.
  - **Overall score** = Σ(weight × grade) ÷ Σ(weight) over active areas with weight > 0 and a grade; null when none. `overallComplete` = every weighted area has a grade.
  - **Qualification**: NotQualified when any must-pass area is Failed — reasons "{Area} requirement not met" in area order; otherwise Pending when any must-pass area is Incomplete or NotYet; otherwise Qualified.
  - **Rank** within the class by overall score (descending; ties share a rank: 1, 2, 2, 4); candidates without an overall score are unranked. Rank is staff-only (`performance.view`); never send it to the portal.
- Pages: `/performance-areas` (list with source, weight — and the total —, passing grade, must-pass, subjects; warning for subjects mapped to no area), create/edit (source-specific fields: subject checkboxes for `subjects` areas, base/merit/demerit values for `conduct`), `/qualification` (class select — active period first —, company and platoon filters, summary counts per qualification status with a chart, table: rank, candidate → profile, company/platoon, one column per area with grade and status text, overall, qualification with reasons; printable via the browser). Components in `resources/js/components/performance/`, types in `resources/js/types/performance.ts`.
- Tests: deterministic unit tests for every area rule, overall, qualification and rank (ties, nulls), feature tests for pages, configuration validation (one active fitness/conduct/attendance area, subject mapping only for subject areas), forbidden roles.

## Module E — Integration

- Candidate profile (`CandidateController::show`, `staff/candidates/show.tsx`): "Performance & Qualification" panel (areas, overall, qualification with reasons, rank — staff with `performance.view`; others see the areas without rank), "Conduct" panel (totals + latest entries + link to the conduct page; only for users allowed by the conduct scope), "Attendance" panel (summary + latest sessions; same scope rule with `attendance.manage` or `candidates.view_all`).
- Candidate portal: `/portal/performance` "My Performance" (`Portal\PerformanceController`): areas with grade and status, qualification checklist (✓ passed / ⚠ pending / ✗ not met, always with text), own merits/demerits (voided left out), attendance summary and recent sessions. **No rank, no other candidates.** A card on the portal home links to it with the qualification status.
- Administrator dashboard: a "Qualification" panel for the active period (counts per status across classes, most common unmet requirement) for users with `performance.view`.
- Demo data `DemoPerformanceSeeder` (called by `ClientDemoSeeder`): company/platoon for the 20 demo students (Alpha/Bravo Company, 1st/2nd Platoon), placeholder areas — Academic (subjects: Subject 1; weight 40; pass 75; must pass), Military Skills (subjects: Subject 2; 20; 75; must pass), Physical Fitness (fitness; 20; 60; must pass), Conduct (conduct; 10; 75; base 85, merit 1, demerit 1; must pass), Attendance (attendance; 10; 90; must pass) —, a spread of merits/demerits and about six attendance sessions with varied statuses.
- `RouteAccessMatrixTest` values for `conductEntry`, `conductType`, `attendanceSession`, `performanceArea`.
- Documentation: `docs/performance-and-qualification.md` (rules in plain language, configuration steps, and the questions to confirm with OCS), README demo-data notes.
