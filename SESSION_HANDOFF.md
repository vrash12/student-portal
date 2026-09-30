# SESSION_HANDOFF.md

## Purpose

This file preserves the current development state between AI coding sessions.

The project may be worked on across multiple Codex, Claude Code, or other coding-agent sessions. A new session may not have access to the full reasoning, chat history, or previous context.

This file must therefore contain enough information for the next session to continue safely without re-discovering the entire project.

---

# Instructions for Coding Agents

At the beginning of every new development session:

1. Read `AGENTS.md`.
2. Read `UI_UX_DESIGN.md`.
3. Read `MILESTONES.md`.
4. Read this `SESSION_HANDOFF.md`.
5. Inspect the actual repository before assuming this file is perfectly current.
6. Continue from the latest confirmed project state.

Do not blindly trust this document if the codebase contradicts it.

The repository remains the source of truth.

---

# When to Update This File

Update `SESSION_HANDOFF.md` whenever:

- the current coding session is about to end;
- the conversation/context is becoming very long;
- the system indicates that context or token limits are approaching;
- the user says they will continue in another session;
- a major milestone has just been completed;
- a large architectural decision has been made;
- work is stopping while a feature is partially implemented;
- an unresolved bug or blocker must be carried into another session.

Do not wait until context is already lost.

If there is any reasonable chance the work will continue in another session, leave a useful handoff.

---

# Important Rule About Context Limits

An AI coding agent may not always know the exact number of tokens remaining or the exact moment a session will end.

Therefore:

- do not rely on an exact token threshold;
- update this file proactively after meaningful work;
- update it before beginning a very large new task if the current session is already long;
- update it immediately when a context-limit warning appears;
- never leave partially completed critical work undocumented.

The goal is continuity, not precise token accounting.

---

# Handoff Writing Rules

Keep this file:

- concise
- factual
- current
- implementation-focused
- easy to scan

Do not copy the entire conversation.

Do not write long explanations of decisions that are already documented in `AGENTS.md`.

Record only information needed to continue development.

Always distinguish:

- completed work
- partially completed work
- planned work
- known issues
- assumptions that still need confirmation

---


# Current Project State

## Current Milestone

Milestones 7–8 remain partially integrated and require a corrective pass. Milestones 9–11 are implemented on `main`. Next planned milestone: 12 (manual essay grading).

## Current Status

Foundation, sign-in, roles and permissions, staff accounts, academic structure, candidate records, organization branding, teaching and grading, academic monitoring, candidate examination access (M9), server-backed connection recovery (M10), and transactional submission/scoring (M11) are implemented. M7/M8 worktree code is preserved but not yet reconciled into the main implementation.

## Last Updated

**Date:** 2026-09-30

**Updated by:** Claude Code (Opus 5.5)

---

# Completed Work

### Milestone 0 — Foundation

- [x] Laravel 13 bare skeleton (not the React starter kit), React 19, strict TypeScript 7, Inertia v3, Vite 8, Tailwind CSS v4
- [x] Database: XAMPP MySQL (MariaDB 10.4.32), port 3306, `root` with no password, Laravel `mariadb` connection. Databases `academic_system` and `academic_system_testing`
- [x] Design tokens in `resources/css/app.css` (Tailwind default palette disabled). Inter bundled locally
- [x] Layouts: staff (sidebar at 1024px and wider, native-`<dialog>` drawer below), candidate portal, auth
- [x] UI components in `resources/js/components/ui/` (see UI/UX Decisions)

### Milestone 1 — Authentication, roles, authorization

- [x] Username sign-in, rate limited (5 attempts per username+IP per minute), sign-out, deactivated accounts blocked and signed out
- [x] `App\Enums\Permission` cases registered as Gate abilities. Single role per user. System roles seeded with ranks
- [x] Route protection by permission, `UserPolicy`, `CandidatePolicy`, rank-based no-escalation rule
- [x] Inertia error pages (403/404 always; 500/503 when `APP_DEBUG=false`); 419 shows a flash message
- [x] Users (staff accounts only), Roles & Permissions matrix (read-only), Change Password
- [x] Append-only audit log (`AuditLogger`, `recordChanges()` for previous/new values)

### Milestone 2 — Core academic data

- [x] Academic periods (only one active, enforced by the database), subjects, classes / batches, subject offerings, instructor assignments, instructors directory, candidates (record + sign-in account in one transaction)
- [x] Demo data: `DemoAcademicSeeder` (period, Subjects 1–4, Sample Batch A/B, 8 assignments, candidates `2026-0001`…`2026-0010`)

### Milestone 3 — Instructor dashboard

- [x] Dashboard sections by permission; teaching overview (`App\Services\TeachingOverview`); My Classes (`/my-classes`, `/my-classes/{classBatch}`); instructor access to candidate profiles of classes they teach
- [x] Organization branding: Philippine Army Officer Candidate School name and seal (configuration only)

### Milestone 4 — Grade management

- [x] Permissions `grading.configure` (Super Admin, Academic Admin) and `grades.record` (Instructor, only for assigned subjects)
- [x] Grading setup per class subject (admin): categories and weights, sum exactly 100, names unique (case- and accent-insensitive), categories with assessments cannot be removed, reason required once any assessment is finalized, audit with previous/new categories (with ids) and reason. Page: Classes → class → subject → **Grading Setup**
- [x] Gradebook per class subject (instructor): grading components, assessments, candidate grades (paginated 50, search), per-candidate grade breakdown dialog. Reached from the dashboard (Gradebook link), My Classes class page, and the candidate profile
- [x] Assessments: create/edit/delete draft, finalize (irreversible), title unique per subject, category of the same subject (also a composite FK), max score > 0, optional date
- [x] Draft score sheet: batch save of changed rows only, atomic, optimistic conflict detection (each entry carries the value it started from; a row changed by another user must be resolved with Keep My Entry / Use Saved Value), comments, unsaved-change warning, entries kept in history state (Back/Forward), connection-error message
- [x] Finalized scores: read-only; **Correct** dialog requires a reason; conflict detection
- [x] Grade change history: `assessment_score_revisions` (kind recorded/updated/corrected, previous and new score, comment, reason, actor, time) + audit entries; Change History panel on the assessment page
- [x] `GradeCalculationService` (single authority): finalized assessments only; category % = points earned / points possible; weighted score; current grade over the weight assessed so far; missing scores never counted as zero; `GradeStatus` (Not Set Up, No Grades Yet, Missing Scores, In Progress, Complete); half-up rounding at the end
- [x] Candidate profile Academic Performance: current grade and grade status per visible subject (batched queries)
- [x] Dashboard: Gradebook quick links and real Upcoming Assessments (dated today or later in the institution timezone, max 5)
- [x] A subject cannot be removed from a class once it has assessments; removal also deletes its grading setup
- [x] Demo grading data (`DemoGradingSeeder`): sample scheme, 2 finalized assessments, 1 partly scored draft, 1 upcoming examination per taught subject
- [x] Independent review: 4 parallel test writers (156 tests) + 3 reviewers + 3 adversarial verifiers. 3 test-found bugs and 18 findings, all confirmed and fixed with regression tests (`GradingReviewRegressionTest`)
- [x] Verified in the browser: dashboard, gradebook (grades checked by hand), score sheet (invalid row, save, change, conflict resolution), finalization, correction with reason, breakdown, admin class page, grading setup validation, admin profile, tablet width

### Milestone 5 — Grade calculation engine: thresholds and academic standing

- [x] Passing and warning grades per academic period (`academic_periods.passing_grade`, `warning_grade`, decimal(5,2), both null = not set; CHECK both-or-neither and 0 < passing ≤ warning ≤ 100). No built-in default
- [x] Page **Academic Periods → Thresholds** (`/academic-periods/{period}/grading-thresholds`, permission `grading.configure` only): labelled range preview, reason required once thresholds are set and the period has finalized assessments, no-op saves not audited, audit `grading_thresholds.updated` (previous/new/reason). A new period's form is prefilled from the most recent other period with thresholds (saved only when the user saves). Thresholds cannot be cleared once set
- [x] `AcademicStanding` (Passing, At Risk, Failing, Incomplete) decided only by `GradeCalculationService::standing()`: below passing = Failing; passing ≤ grade < warning = At Risk; ≥ warning = Passing, or Incomplete if scores are missing; no grade + missing scores = Incomplete; otherwise no standing. Grade compared as displayed (2 decimals) in whole hundredths. Provisional grades get a current standing (`isProvisional`)
- [x] Standing only for candidates gradable in the class (not withdrawn), in both `forOffering()` and `forCandidate(Candidate, ...)`
- [x] `overallStanding(SubjectGrade[])` → `OverallStanding` (most serious, basedOnSubjects/totalSubjects, isProvisional)
- [x] Standing shown in the gradebook ("Current Standing" column; Milestone 4 "Grade Status" column kept when the period has no thresholds), grade breakdown ("How Standing Is Decided"), candidate profile (Overall Standing / "Standing in Your Subjects" for instructors, per-subject standing, honest reasons when not available, link to set thresholds for administrators), academic periods list (thresholds column), class grading setup (thresholds note with link)
- [x] Dashboard: administrators get a "Set Passing and Warning Grades" link when the active period has none; instructor copy states whether the active period has thresholds (`teaching.period.hasThresholds`)
- [x] Demo: `DemoGradingSeeder` sets 75/80 on the demo period through the service, only when not set (gives Passing, At Risk, Failing and Incomplete examples)
- [x] Independent review: design critique (3 lenses, ~25 findings applied before coding), 3 parallel test writers (129 test methods, no bugs found), 3 reviewers + adversarial verifiers: 5 confirmed findings, all fixed (instructor dashboard copy without thresholds, admin overview copy without an active period, thresholds preview no longer a live region, form state after a save that returns to the same page, also in Grading Setup, and invalid UTF-8 input now cleaned by the global `ReplaceInvalidUtf8` middleware), with regression tests in `StandingReviewRegressionTest`
- [x] Verified in the browser (page text): thresholds form (preview, equal values, order error, reason rule, save + audit), periods list, admin and instructor profiles, gradebook, instructor dashboard

### Milestone 6 — Academic monitoring and early warning

- [x] Permission `academic_monitoring.view` (Super Admin, Academic Admin, Instructor)
- [x] `app/Services/Monitoring/`: `MonitoringScope` (only entry point: `candidates.view_all` → every class subject of the period; active teaching staff → only their assigned class subjects, checked after `canTeach()`; anyone else → empty), `AcademicMonitoring` (evaluate, counts, filter, sort, subjects requiring attention), `MonitoredCandidate`, `SubjectConcerns`, `MonitoringPresenter`, `CandidateAcademicRecord`
- [x] Bulk engine `GradeCalculationService::forClasses(offerings, candidates)` in a fixed number of queries
- [x] Page `GET /monitoring` (sidebar "Academic Monitoring"): counts that reconcile (Monitored, Failing, At Risk, Incomplete, Passing, No Standing Yet), filters class / subject / standing / search, sorts, overall vs subject view, PHP pagination (25), labels "Standing in Your Subjects" for the taught scope
- [x] Dashboard: administrator Academic Overview and instructor Academic Alerts with real data (up to 5 candidates requiring attention)
- [x] Candidate profile: Academic Summary, Current Warnings, Assessment Results, Recent Academic Activity
- [x] Independent review (results recovered from the previous session's workflow journal): 3 test writers (99 tests: `MonitoringPage`, `MonitoringDashboardAndProfile`, `MonitoringEngine`) and 4 reviewers with adversarial verifiers. The 3 bugs found by tests are fixed: array query values (`?class[]=1`) no longer cause a 500 in `App\Support\QueryFilters` (shared by every list page); huge page numbers on /monitoring return an empty page; Assessment Results are sorted by the displayed (institution-timezone) date. The other confirmed findings were deferred at the owner's request (see Carried forward)

### Carried forward

- **Milestone 6 review findings, confirmed but not fixed** (the owner asked on 2026-09-30 to commit Milestone 6 and move on; all low except the first, which is medium):
  1. `CandidateAcademicRecord::recentActivity()`: "Result finalized" entries show the current (corrected) score instead of the score at finalization, contradicting the correction entry.
  2. Comment-only corrections of finalized scores appear as score corrections ("6.00 → 6.00").
  3. Dashboard Academic Alerts links open /monitoring in the all-candidates scope for custom roles holding `candidates.view_all` + `classes.teach` (counts do not match the panel).
  4. /monitoring echoes `standing` back as active when the period has no thresholds (Reset enabled for a hidden filter).
  5. /monitoring counts area says "No standings yet for the 0 monitored candidates" when nobody is monitored.
  6. Standing count links: small tap targets, no visible link affordance on touch, current card marked by border colour only.
  7. Class and Lowest Grade columns are hidden below 1280px although the list may be sorted by lowest grade.
  8. "View candidates" links under Subjects Requiring Attention keep the current search.
  9. Withdrawn candidates: Recent Academic Activity takes the 8 newest assessments before dropping those without a result, so it can say "No academic activity yet"; a subject can untruthfully say "No finalized assessments yet".
  10. New Milestone 6 copy hardcodes "class"/"classes" instead of `terms.classBatch`.
  11. The empty ("none") monitoring scope shows the administrator empty-state text.
  12. Academic Alerts renders "0 failing, 0 at risk" as links to empty lists.

- Quick navigation from the instructor dashboard to the question bank (Milestone 7) and examinations (Milestone 8), once those modules exist (AGENTS.md §73).
- Academic Alerts (at-risk/failing counts) on the instructor dashboard and the administrator Academic Overview: still honest "not available yet" panels; Milestone 6 fills them.

---

# Work In Progress

M9, M10, and M11 are implemented on main. M7/M8 require a corrective integration pass:
- main contains 536a0ca, 40e6b04 and cd9197b, but cd9197b did NOT complete M7/M8 despite its title and the prior chat report.
- `.worktrees/m7` contains substantial uncommitted Question Bank services, requests, policy, forms, pages, seeder and tests.
- `.worktrees/m8` contains substantial uncommitted builder/lifecycle/settings services, policies, controllers, forms, migrations, seeders and tests.
- Neither worktree was modified or cleaned up during M9. Preserve them; inspect their Git status before integration. Do not recreate either module.
- Main's M7/M8 pages remain skeletons. Existing services have mass-assignment/lifecycle/locking gaps. M9 was verified against independently constructed synthetic published examinations, not through the unfinished builder.
- Fixed the duplicate Subject::questions()/questionTopics() methods in main, which previously caused a fatal PHP error.

# Next Recommended Task

Reconcile the preserved M7/M8 worktrees with main and the M9/M10 attempt contract, then continue with Milestone 12 manual essay grading. Preserve the attempt delivery snapshot, revision, deadline, recovery queue, and scoring fields. Do not drop development records or overwrite migrations already applied.

---
# Files Recently Changed

Milestone 6:

```text
new:      app/Http/Controllers/Staff/AcademicMonitoringController.php
          app/Services/Monitoring/{MonitoringScope,AcademicMonitoring,MonitoredCandidate,SubjectConcerns,MonitoringPresenter,CandidateAcademicRecord}.php
          resources/js/pages/staff/monitoring/index.tsx, resources/js/components/monitoring/*, resources/js/types/monitoring.ts
          tests/Feature/Monitoring/{AcademicMonitoring,MonitoringPage,MonitoringDashboardAndProfile,MonitoringEngine}Test.php
modified: app/Enums/{Permission,SystemRole}.php, app/Services/Grading/GradeCalculationService.php (forClasses), app/Support/QueryFilters.php
          app/Http/Controllers/Staff/{Candidate,Dashboard}Controller.php, routes/web.php
          resources/js/pages/staff/{dashboard,candidates/show}.tsx, resources/js/components/grading/standing.tsx
          resources/js/lib/{navigation,permissions,routes,use-query-filters}.ts, resources/js/types/grading.ts
          tests/Feature/Auth/AreaAccessTest.php, tests/Feature/Teaching/InstructorDashboardTest.php, SESSION_HANDOFF.md
```

Milestone 5 (earlier milestones: see `git log`):

```text
app/Enums/AcademicStanding.php (new); AuditAction (GradingThresholdsUpdated), Permission (grading.configure description), GradeStatus, CandidateStatus (docs)
app/Services/Grading/{GradingThresholds,GradingThresholdService,OverallStanding}.php (new); GradeCalculationService (standing, overallStanding,
    thresholdsFor, forOffering/forCandidate standing rules, forCandidate now takes a Candidate), SubjectGrade (standing, isProvisional), Gradebook (thresholds)
app/Services/TeachingOverview.php (period.hasThresholds)
app/Http/Middleware/ReplaceInvalidUtf8.php (new, global middleware in bootstrap/app.php)
app/Http/Controllers/Staff/GradingThresholdController.php (new), app/Http/Requests/Grading/GradingThresholdsRequest.php (new)
app/Http/Controllers/Staff/{AcademicPeriod, Candidate, Dashboard, Gradebook, GradingScheme}Controller.php; app/Models/AcademicPeriod.php (casts, docs)
database/migrations/2026_09_29_000800_add_grading_thresholds_to_academic_periods.php (new); database/seeders/DemoGradingSeeder.php
routes/web.php
resources/js/components/grading/standing.tsx (new), grade-breakdown-dialog.tsx; resources/js/pages/staff/academic-periods/thresholds.tsx (new)
resources/js/pages/staff/{academic-periods/index, candidates/show, classes/grading, dashboard, teaching/gradebook/show}.tsx
resources/js/lib/routes.ts, resources/js/types/grading.ts
tests/Unit/AcademicStandingTest.php (new), tests/Feature/Grading/{GradingThresholdConfiguration, AcademicStandingIntegration,
    StandingEngineDatabase, StandingReviewRegression}Test.php (new); GradeCalculationServiceTest, GradebookAndIntegrationTest updated
.claude/launch.json (third preview config laravel-alt2 on port 8002), README.md
```

---

# Database Changes

```text
roles            id, code (unique), name, description, rank, is_system, timestamps
permissions      id, code (unique), name, description, group, timestamps
permission_role  role_id FK cascade, permission_id FK cascade, PK (role_id, permission_id)
users            id, name, username (unique), email (nullable, unique), password,
                 role_id FK restrict, is_active, last_login_at, remember_token, timestamps
audit_logs       id, actor_id FK users restrict (nullable), action, auditable_type, auditable_id,
                 old_values json, new_values json, reason, ip_address, user_agent, created_at

academic_periods id, name (unique), starts_on, ends_on, is_active,
                 active_marker (stored generated: 1 when active, else NULL; UNIQUE),
                 CHECK (ends_on >= starts_on)
subjects         id, code (unique), name, description (500), is_active, timestamps, index (is_active, name)
class_batches    id, academic_period_id FK restrict, name, timestamps, unique (academic_period_id, name)
class_subjects   id, class_batch_id FK restrict, subject_id FK restrict, timestamps,
                 unique (class_batch_id, subject_id)
instructor_assignments  id, class_subject_id FK cascade, instructor_id FK users restrict, timestamps,
                 unique (class_subject_id, instructor_id), index instructor_id
candidates       id, user_id FK unique restrict, candidate_number (unique), first_name, last_name,
                 class_batch_id FK nullable restrict, status, timestamps,
                 index (class_batch_id, status), index (last_name, first_name),
                 CHECK status IN ('enrolled','on_leave','withdrawn','completed')

Milestone 4 (2026_09_29_000700_create_grading_tables):
assessment_categories  id, class_subject_id FK restrict, name (100), weight decimal(5,2), position, timestamps,
                 unique (class_subject_id, name), unique (id, class_subject_id), CHECK 0 < weight <= 100
assessments      id, class_subject_id FK restrict, assessment_category_id, title (150), max_score decimal(6,2),
                 assessed_on date null, status (draft|finalized), finalized_at, finalized_by FK users, created_by FK users,
                 timestamps, unique (class_subject_id, title), index (class_subject_id, status), index assessed_on,
                 composite FK (assessment_category_id, class_subject_id) -> assessment_categories (id, class_subject_id),
                 CHECK max_score > 0, CHECK status, CHECK finalized <=> finalized_at and finalized_by set
assessment_scores  id, assessment_id FK restrict, candidate_id FK restrict, score decimal(6,2) null, comment (500) null,
                 recorded_by FK users, timestamps, unique (assessment_id, candidate_id), index candidate_id,
                 CHECK score IS NULL OR score >= 0   (score <= max_score is enforced by the service under a row lock)
assessment_score_revisions  id, assessment_score_id FK restrict, kind (recorded|updated|corrected), previous_score,
                 new_score, comment, reason, changed_by FK users, created_at; CHECK kind; CHECK corrected => reason

Milestone 5 (2026_09_29_000800_add_grading_thresholds_to_academic_periods):
academic_periods + passing_grade decimal(5,2) null, warning_grade decimal(5,2) null
                 CHECK academic_periods_grading_thresholds_check: both null, or both set with 0 < passing <= warning <= 100
                 (explicit IS NOT NULL tests: an unknown CHECK result would pass). down() drops the constraint (DROP CONSTRAINT) first.
sessions, cache, cache_locks, jobs, job_batches, failed_jobs   Laravel defaults
```

---

# Routes Added or Changed

```text
GET  /login, POST /login (login.attempt), POST /logout, GET / (home redirect)
GET  /dashboard                                  staff_area.access
     /users (index, create, store, edit, update)  UserPolicy
GET  /roles                                       roles.view
     /account/password (edit, update)             staff_area.access
     /academic-periods (index, create, store, edit, update)      academic_periods.manage
POST /academic-periods/{academicPeriod}/activate                 academic_periods.manage
     /subjects (index, create, store, edit, update)              subjects.manage
     /classes (index, create, store, show, edit, update)         class_batches.manage
POST   /classes/{classBatch}/subjects                            class_batches.manage
DELETE /classes/{classBatch}/subjects/{classSubject} (scoped)    class_batches.manage (blocked with assessments)
GET  /instructors, /instructors/{instructor}                     instructor_assignments.manage
POST /instructor-assignments, DELETE /instructor-assignments/{instructorAssignment}
     /candidates (index, create, store, show, edit, update)      CandidatePolicy
GET  /my-classes, /my-classes/{classBatch}        classes.teach; show also ClassBatchPolicy::viewTeaching
GET  /portal                                      exam_portal.access
     fallback                                     404 inside the web middleware group

Milestone 4:
GET|PUT /classes/{classBatch}/subjects/{classSubject}/grading              classes.grading.edit|update (scoped; ClassSubjectPolicy::configureGrading)
GET  /my-classes/{classBatch}/subjects/{classSubject}                      teaching.gradebooks.show (scoped; viewGradebook)
GET  /my-classes/{classBatch}/subjects/{classSubject}/assessments/create   teaching.assessments.create (recordGrades)
POST /my-classes/{classBatch}/subjects/{classSubject}/assessments          teaching.assessments.store (recordGrades)
GET  /assessments/{assessment}                   assessments.show (AssessmentPolicy::view)
GET  /assessments/{assessment}/edit, PUT /assessments/{assessment}, DELETE /assessments/{assessment},
POST /assessments/{assessment}/finalize, PUT /assessments/{assessment}/scores, POST /assessments/{assessment}/corrections
                                                 AssessmentPolicy::manage (all under classes.teach)

Milestone 5:
GET|PUT /academic-periods/{academicPeriod}/grading-thresholds   academic-periods.thresholds.edit|update (can:grading.configure only)
```

Frontend URL helpers: `resources/js/lib/routes.ts` (keep in sync with `routes/web.php`).

---

# Important Models and Relationships

```text
User                 belongsTo Role; hasOne Candidate; hasMany InstructorAssignment (teachingAssignments)
                     scopes: teachingStaff(), eligibleToTeach(); teachesClass(), teachesOffering()
Role                 belongsToMany Permission; hasMany User; rank decides who may assign it
AcademicPeriod       hasMany ClassBatch; scope active(); passing_grade / warning_grade (decimal:2 casts, not fillable;
                     change only through GradingThresholdService)
ClassBatch           belongsTo AcademicPeriod; hasMany Candidate; hasMany ClassSubject
ClassSubject         belongsTo ClassBatch, Subject; hasMany InstructorAssignment, AssessmentCategory (ordered), Assessment
InstructorAssignment belongsTo ClassSubject, User (instructor)
Candidate            belongsTo User (account), ClassBatch; hasMany AssessmentScore; scope gradableIn(class); isGradableIn()
AssessmentCategory   belongsTo ClassSubject; hasMany Assessment
Assessment           belongsTo ClassSubject, AssessmentCategory (category), User (creator, finalizer); hasMany AssessmentScore; scope finalized()
AssessmentScore      belongsTo Assessment, Candidate, User (recorder); hasMany AssessmentScoreRevision
AssessmentScoreRevision  belongsTo AssessmentScore, User (changer); cannot be updated
AuditLog             belongsTo User (actor); morphTo auditable
Morph map: user, role, academic_period, subject, class_batch, class_subject, instructor_assignment, candidate,
           assessment_category, assessment, assessment_score
```

---

# Important Architectural Decisions

Project-level decisions (in addition to AGENTS.md):

- Backend: Laravel. Frontend: React + TypeScript through Inertia.js.
- Database: MySQL 8.4+ or MariaDB. PostgreSQL was replaced at the owner's request on 2026-09-29. Local development uses XAMPP's MariaDB 10.4. Keep SQL portable between MySQL and MariaDB.
- The candidate examination experience is a PWA for organization-issued tablets. Public internet access is not assumed.
- Grades are entered manually. Imports, native Android, and advanced infrastructure are deferred.
- Commits: one commit per milestone (confirmed by the project owner on 2026-09-29).

Earlier sessions:

- Bare Laravel skeleton instead of the React starter kit. Laravel Boost is not installed.
- Username sign-in; email optional; no "remember me"; no email password reset (administrators reset passwords).
- **Single role per user.** Permissions are defined in `App\Enums\Permission` and mirrored into the database by `AccessControlSeeder`, which **resets system roles to their defaults** (revisit before any role-editing UI).
- **No privilege escalation by rank:** a user may only assign roles, and edit accounts holding roles, ranked below their own. Ranks: Super Administrator 100, Academic Administrator 80, Instructor 40, Candidate 10.
- The area a user enters is decided by permissions (`staff_area.access`, `exam_portal.access`), never by role names.
- **Instructors are teaching staff identified by the `classes.teach` permission**; there is no separate instructor table.
- **Candidates:** record and sign-in account are created and updated together by `CandidateService`. Username = candidate number in lowercase.
- **Only one active academic period**, enforced by a unique index on a stored generated column. A class's academic period is fixed at creation.
- Accounts are deactivated, never deleted. Role changes, deactivation, password resets, and candidate username changes end stored sessions immediately.
- Audit logs are append-only; `AuditLogger::recordChanges()` stores only changed values.
- Toasts use Inertia v3 flash data; default layouts are chosen by page-name prefix in `resources/js/app.tsx`. No SSR, `QUEUE_CONNECTION=sync`.
- **Branding (confirmed 2026-09-29):** Philippine Army Officer Candidate School; seal supplied by the owner; configured through `ORGANIZATION_*` env values; `public/branding/logo-512.png` reserved for PWA icons (Milestone 9).
- Timestamps stored in UTC, displayed in `INSTITUTION_TIMEZONE` (local: Asia/Manila). Calendar dates use `formatCalendarDate()`.
- `Model::shouldBeStrict()` outside production. Tests call `withoutVite()`.

Milestone 4:

- **Grading scheme per class subject** (not per subject or per period): each subject of each class has its own categories and weights, so history stays with the offering. Administrators set them (`grading.configure`); instructors cannot change weights.
- **Only finalized assessments count toward grades.** Drafts are work in progress. Finalization is irreversible; afterwards a score changes only through a correction with a reason. Assigned instructors may correct finalized scores (with a reason); there is no administrator approval step yet.
- **Calculation method:** within a category, points earned / points possible (larger assessments weigh more); grade = weighted scores over the weight assessed so far. **Missing scores are never zero**; they are reported (Missing Scores) so a grade is never silently lowered. Instructors can record 0 explicitly.
- Grades are **computed on read** (no stored grades), so any change is reflected immediately. Floats with a 10-decimal pre-round, then half-up to 2 decimals.
- **Gradable candidates** = assigned to the class and not withdrawn. Candidates who leave keep their scores (shown read-only on the score sheet) but get no new ones.
- Concurrency: every state change locks the class subject row, then the assessment row (same order everywhere). Score saves and corrections carry the value the user started from (optimistic check); a row changed by someone else is rejected, and the UI makes the user choose explicitly.
- A draft assessment can be deleted with its scores and revisions; the audit entry keeps the scores (by candidate number). Finalized assessments cannot be deleted.
- A subject cannot be removed from a class once it has assessments.
- Grading is not locked when a period ends (instructors keep access to past-period subjects they taught).

Milestone 5:

- **Thresholds per academic period** (not per class subject): one institutional policy per period; changing it for a new period never rewrites past standings. No built-in default; a new period's form is prefilled from the most recent other period (explicit save).
- **Standing is computed on read** by `GradeCalculationService` only (nothing stored), so any score, finalization, correction, weight, or threshold change shows immediately.
- **Standing rules:** see Completed Work. Missing scores never hide a warning (Failing / At Risk kept), and Passing is never claimed while scores are missing (Incomplete). Comparison uses the displayed grade in whole hundredths.
- **Overall standing = most serious subject standing** over the subjects shown (instructors: only the subjects they teach). Needs owner confirmation (see Requirements).
- **Withdrawn candidates keep grades but have no standing** (every later assessment would count as missing). Candidates are judged only in their current class.
- Once thresholds are set and the period has finalized assessments, a change requires a reason (audited). Thresholds cannot be cleared.
- `subjectGrade()` and `SubjectGrade` take thresholds / standing as required nullable arguments so no caller drops standing by omission.

---

# UI/UX Decisions

- Staff sidebar sections: Dashboard; Teaching (My Classes); Academics (Candidates, Classes, Subjects, Academic Periods); Administration (Instructors, Users, Roles & Permissions). Items are filtered by permission and only implemented modules appear. Gradebooks have no sidebar item; they are reached from the dashboard, My Classes, and candidate profiles.
- "Class / Batch" is displayed as "Class" through `resources/js/lib/terminology.ts`.
- `pointer-coarse:` variants give 44px touch targets and 16px input text on tablets.
- Status badges always pair an icon with text. Server enums send `{value, label, tone}`.
- Shared building blocks: `Table`/`Th`/`Td`/`RowAction`, `FilterBar` + `SearchField`, `FormSection` + `FormActions`, `ConfirmAction` (now with `disabled`), `ConfirmDialog`, `Dialog` (native `<dialog>`, `busy` blocks closing), `Pagination`, `MetricCard`.
- Grading components in `resources/js/components/grading/`: `ScoreSheet`, `CorrectionDialog`, `ScoreHistory`, `GradeBreakdownDialog`, `AssessmentForm`, `offering-context` (breadcrumbs, `WeightSummary`).
- The browser never calculates grades; `formatGrade`/`formatPercent` only format server values. The grading-setup total is a labelled preview; the server validates.
- Destructive or significant actions require confirmation: deactivating accounts, setting the active period, removing subjects and assignments, finalizing and deleting assessments.
- Heading levels follow nesting; link accessible names start with their visible text (WCAG 2.5.3).
- Dashboards and profiles show honest empty states where later milestones will add data.
- Standing components in `resources/js/components/grading/standing.tsx`: `StandingBadge` (dash + sr-only "No standing yet"), `StandingCell` (badge + "Grade: <status> · N% of weight assessed"), `GradeStatusBadge` (used when the period has no thresholds), `ThresholdSummary`, `StandingRanges` (shared by the thresholds preview and the breakdown), `OverallStandingValue` ("Most serious subject standing · based on X of Y subjects"), `ROUNDING_NOTE`.
- Thresholds are always displayed with two decimals (like grades); form inputs use the compact form ("75").

---

# Security / Authorization Notes

```text
Gate:    every Permission enum case is a Gate ability (AppServiceProvider)
UserPolicy: viewAny users.view; create users.manage; update users.manage AND staff AND (self OR lower rank)
CandidatePolicy: viewAny candidates.view_all; view candidates.view_all OR User::teachesClass; create/update candidates.manage
ClassBatchPolicy::viewTeaching: User::teachesClass(class)
Thresholds: routes gated by grading.configure only; values not mass assignable; service locks the period row; DB CHECK is final
ClassSubjectPolicy: viewGradebook = teachesOffering; recordGrades = grades.record AND teachesOffering; configureGrading = grading.configure
AssessmentPolicy: view = teachesOffering(assessment's subject); manage = grades.record AND teachesOffering
teachesOffering = active + classes.teach + an assignment to that class subject (administrators do not open gradebooks)
Services re-check class membership, max score, and draft/finalized state under row locks; candidate ids from the client are never trusted
Form Requests only trim strings (arrays are rejected by the rules, not turned into 500s)
ReplaceInvalidUtf8 (global): invalid UTF-8 in any input is replaced with "?" so crafted requests cannot cause database errors
Audit entries never contain secrets; score audit values contain candidate numbers, not names
Inactive users: hold no permissions; EnsureAccountIsActive signs them out
Shared props: id, name, username, role (code, name), the user's own permission codes
```

---

# Tests Added

```text
tests/Feature/Auth/LoginTest.php, AreaAccessTest.php
tests/Feature/Users/UserManagementTest.php, tests/Feature/Account/PasswordUpdateTest.php
tests/Feature/AuditLogTest.php, SeederTest.php
tests/Feature/Academic/{AcademicPeriod,Subject,ClassBatch,InstructorAssignment}Test.php
tests/Feature/Candidates/CandidateManagementTest.php
tests/Feature/Teaching/{InstructorDashboard,TeachingClass,InstructorCandidateAccess}Test.php
tests/Unit/PermissionCatalogueTest.php              TS/PHP permission sync, role invariants (incl. grading split)

Milestone 4:
tests/Unit/GradeCalculationServiceTest.php          deterministic calculation, rounding, missing scores
tests/Feature/Grading/BuildsGradingFixtures.php     shared fixture (Quizzes 40 / Examinations 60 on Batch A Subject 1)
tests/Feature/Grading/GradingSchemeTest.php         setup rules, authorization, audit, subject removal, DB checks
tests/Feature/Grading/AssessmentLifecycleTest.php   create/edit/delete/finalize, authorization, validation, DB checks
tests/Feature/Grading/ScoreRecordingTest.php        draft scores, conflicts, corrections, history, DB checks
tests/Feature/Grading/GradebookAndIntegrationTest.php  gradebook, profile, dashboard, seeder
tests/Feature/Grading/GradingReviewRegressionTest.php  fixes from the review

Milestone 5:
tests/Unit/AcademicStandingTest.php                         boundaries, rounding, missing scores, provisional, overall rule, §17 example
tests/Feature/Grading/GradingThresholdConfigurationTest.php  page auth, props, validation, reason rule, no-op, audit, CHECK, mass assignment, index, dashboard
tests/Feature/Grading/AcademicStandingIntegrationTest.php    gradebook/profile standing, recalculation, withdrawn, instructor privacy, periods, seeder
tests/Feature/Grading/StandingEngineDatabaseTest.php         engine with the database: periods, boundaries, query counts, locking
tests/Feature/Grading/StandingReviewRegressionTest.php       fixes from the review

Milestone 6:
tests/Feature/Monitoring/AcademicMonitoringTest.php             scope, counts, filters, dashboards, profile (author's tests)
tests/Feature/Monitoring/MonitoringPageTest.php                 /monitoring authorization, tampering, counts, filters, sorts, pagination edge cases
tests/Feature/Monitoring/MonitoringDashboardAndProfileTest.php  dashboard panels by permission, attention list, profile additions, query counts
tests/Feature/Monitoring/MonitoringEngineTest.php               scope and bulk engine with the database
```

Status at the Milestone 6 commit: **605 passed, 0 failed**.

`tests/TestCase.php` uses RefreshDatabase, seeds `AccessControlSeeder` once, calls `withoutVite()`, and **refuses to refresh any database whose name does not end in `_testing`**. Parallel test runs can use separate databases: `DB_DATABASE=academic_system_x_testing php artisan test` (phpunit.xml does not override an existing `DB_DATABASE`).

---

# Commands Used

```bash
php artisan migrate:fresh --seed   # development database only
php artisan test
npm run types
npm run build
vendor/bin/pint
php artisan serve
```

Claude Code preview configurations are in `.claude/launch.json`: `laravel` (port 8000), `laravel-alt` (8001) and `laravel-alt2` (8002), for when other sessions already use a port.

If tests fail with "No connection could be made" on port 3306, XAMPP's MySQL is not running: start it in the XAMPP Control Panel (or run `C:/xampp-new/mysql_start.bat`).

---

# Known Issues

### Issue: XAMPP MariaDB 10.4 is end-of-life

Status: Open (acceptable for local development)

Details: The production database server and version are not decided. Keep the code portable between MySQL 8.4+ and MariaDB.

### Issue: A second database server exists on the development machine

Status: Informational

Details: A MySQL 9.4 Windows service (`MySQL94`, port 3308) is installed but not used by this project.

### Issue: `php artisan serve` is single-threaded

Status: Informational

Details: Pages load slowly when requests overlap during browser testing. Production uses a real web server.

### Issue: Changing an instructor's role keeps their teaching assignments

Status: Open (low)

Details: Access ends immediately (every teaching check requires classes.teach), but the old assignments still appear on the administrator pages.

### Issue: CHECK constraints on status strings are case-insensitive

Status: Open (low)

Details: `candidates.status` and `assessments.status` use the case-insensitive utf8mb4 collation, so the CHECK would accept 'DRAFT'. The application always writes enum values, so this matters only for manual SQL.

---

# Blockers

None.

---

# Requirements Still Needing Confirmation

Do not hardcode these until confirmed:

- production database server (MySQL or MariaDB) and version;
- actual subject names;
- official grading formula (currently: points-based categories, weights per class subject set by administrators), passing grade, and warning threshold;
- missing-score policy (currently: never zero, reported as Missing Scores; instructors may record 0);
- whether corrections of finalized scores need administrator approval (currently: assigned instructors, with a reason);
- whether grading locks when an academic period ends (currently: not locked);
- overall candidate standing rule (implemented: most serious subject standing; the AGENTS.md §17 example suggests an average);
- actual passing and warning grades per period (demo data uses 75 / 80);
- whether Incomplete should outrank At Risk / Failing when scores are missing (implemented: warnings are kept, Incomplete replaces only Passing);
- whether a Passing standing on a provisional grade (not every category assessed) is acceptable (implemented: yes, labelled with the weight assessed);
- whether withdrawn candidates should have any standing (implemented: none; grades are kept);
- whether thresholds may ever be cleared once set (implemented: no);
- candidate identifier format (letters, numbers, `.`, `-`, `_`, up to 30 characters);
- candidate enrollment statuses (Enrolled, On Leave, Withdrawn, Completed);
- exact Class / Batch terminology;
- number of academic periods;
- whether Academic Administrators may create other Academic Administrator accounts (currently not allowed);
- whether Academic Administrators also teach (currently only the Instructor role holds `classes.teach`);
- candidate password rules on shared tablets;
- session timeout behaviour during examinations;
- exam navigation restrictions and whether candidates may see scores immediately;
- brand colors (placeholder institutional green);
- internal deployment environment (hostname, HTTPS certificate).

---

# Temporary Development Assumptions

```text
ASSUMPTION: Sign-in uses a username; candidates use their candidate number.
Reason: candidates may not have email; the identifier format is unconfirmed.

ASSUMPTION: Display timezone is Asia/Manila in the local .env (INSTITUTION_TIMEZONE, configurable).
Reason: the institution's timezone has not been confirmed.

ASSUMPTION: Minimum password length is 10 characters for all accounts.
Reason: no official password policy has been supplied.

ASSUMPTION: Role ranks 100/80/40/10 and "assign only below your own rank".
Reason: the split between the administrator roles is not specified.

ASSUMPTION: A class belongs to exactly one academic period, fixed at creation.
Reason: simplest consistent model until requirements say otherwise.

ASSUMPTION: Brand colors use the placeholder institutional green.
Reason: official colors have not been supplied (name and seal are confirmed).

ASSUMPTION: Demo grading weights (Quizzes 20, Examinations 30, Practical Exercises 30, Other Requirements 20) are sample data only.
Reason: real weights are configured by administrators; nothing in code depends on them.
```

---

# Git / Version Control State

```text
Branch: main (tracks origin/main)
Remote: origin https://github.com/vrash12/student-portal.git
Commits: 0ff5cc3 first commit (README only)
         ad168a7 Milestones 0–1
         c5b0f40 Milestone 2
         98dbcba Milestone 3
         7e45a45 Milestone 4
         4f95774 Milestone 5
         9564708 Milestone 6
main is ahead of origin/main; M9/M10 remain uncommitted in the working tree.
Work branches (local only, deleted after integration): m7-question-bank, m8-examination-builder (see Work In Progress).
Push only when the owner asks.
```

---

# Uncommitted or Incomplete Code

M9/M10 implementation files are currently uncommitted on `main`. M7/M8 also have substantial uncommitted work in `.worktrees/m7` and `.worktrees/m8`; preserve those worktrees for the next corrective integration pass. The M9/M10 files are listed in the implementation sections below.

---

# Validation Before Continuing

At the start of the next session, the coding agent should verify:

```text
1. Repository status (git status, git log)
2. XAMPP MySQL is running (port 3306)
3. php artisan migrate:status shows no pending migrations
4. php artisan test passes
5. npm run types and npm run build pass
6. Current milestones (M9/M10 complete on main; M7/M8 integration still needs reconciliation)
```

Do not immediately start generating new code before checking the existing state.

---

# End-of-Session Checklist

Before ending a development session:

- [ ] Update current milestone.
- [ ] Update completed work.
- [ ] Record work in progress.
- [ ] State the exact next task.
- [ ] Record important changed files.
- [ ] Record database/schema changes.
- [ ] Record routes added or changed.
- [ ] Record important architectural decisions.
- [ ] Record tests added and current test status.
- [ ] Record unresolved issues.
- [ ] Record blockers.
- [ ] Record temporary assumptions.
- [ ] Record Git state if available.
- [ ] Clearly describe any incomplete code.
- [ ] Remove obsolete information from this handoff.
- [ ] Ensure the next coding agent can continue without the previous chat.

---

# Start-of-Session Checklist

Every new coding session should:

- [ ] Read `AGENTS.md`.
- [ ] Read `UI_UX_DESIGN.md`.
- [ ] Read `MILESTONES.md`.
- [ ] Read `SESSION_HANDOFF.md`.
- [ ] Inspect repository status.
- [ ] Inspect recent changes.
- [ ] Verify the current milestone.
- [ ] Run relevant tests if appropriate.
- [ ] Continue the documented next task.


## Milestone 9 implementation and validation — 2026-09-30

Implemented:
- Candidate listing, start review, access-code and attempt-limit checks, resume, timed tablet attempt, MCQ/true-false/essay, review flags, navigation, save state, confirmation and terminal receipt.
- CandidateAttemptService owns transactional attempt starts/saves/submission/expiry. Candidate row locking serializes double starts; revision checking protects against stale-tab overwrites; terminal submission is idempotent.
- Deadline is fixed at start, capped by closing time. Browser uses server time plus monotonic elapsed time; backend rejects late changes. Expiry is reconciled on access/save. Auto-submit enabled produces submitted; otherwise expired. Background expiry processing/scoring remains later work.
- Stable question/choice delivery snapshots contain no correct answers or explanations. Snapshot duplication preserves presentation/order for refresh and future recovery. Answers are JSON keyed by examination-question ID within the attempt, updated under an attempt row lock; no scoring or grade integration.
- Attempt policy checks candidate ownership. Eligibility checks class assignment, publication and non-withdrawn status. No administrative navigation appears in the portal.
- Forward-only navigation is enforced by the backend; free navigation and flags only when configured. One-question mode hides the question grid.
- Debounced server saving plus Save answer/Save and next. IndexedDB snapshots preserve the candidate's local answer state; ordered retry payloads synchronize automatically after reconnection. Server revisions and idempotent retry handling prevent duplicate answer corruption.
- PWA manifest, home-screen icon, standalone mode, scoped worker, public-asset caching and offline fallback. Authenticated portal responses are no-store; no questions, answers or authenticated HTML are cached by the worker. Trusted HTTPS required on actual LAN tablets.

Important files:
- app/Services/Examinations/CandidateAttemptService.php
- app/Policies/ExaminationAttemptPolicy.php
- app/Models/ExaminationAttempt.php
- app/Http/Controllers/Portal/{PortalHomeController,ExaminationController}.php
- app/Http/Requests/Portal/SaveAttemptRequest.php
- resources/js/pages/portal/examinations/{show,attempt,success}.tsx
- public/{portal.webmanifest,portal-sw.js,portal-offline.html}
- tests/Feature/Portal/CandidateExaminationTest.php

Schema: 000300 creates examination_attempts with candidate/examination FKs, unique attempt number and status CHECK; 000400 adds deadline, delivery/answers JSON, current position and revision. Both applied to local MariaDB. Initial 000300 attempt failed on an overly long generated index name; repaired to attempt_number_unique and recreated ONLY the empty, unrecorded partial table. No existing institutional data was dropped.

Routes: GET portal/examinations/{examination}; POST .../start; GET portal/attempts/{attempt}; PUT .../answers; POST .../submit; GET .../success. Existing login/portal permission checks retained.

Validation:
- Full suite: 614 passed, 10,332 assertions (includes initial 7 M9 tests).
- Added three more focused tests for attempt limits/availability, stable randomization/nonautomatic expiry, withdrawn access; final targeted result recorded below.
- TypeScript and production build pass; Pint applied to changed PHP files.
- Browser: synthetic candidate completed MCQ, true/false and essay; save state confirmed; reload preserved selected answer and deadline; confirmation defaulted to Continue; submit reached receipt. Tablet portrait 768x1024 and landscape 1024x768 inspected; landscape DOM had no horizontal overflow. Actual OS installation was not tested.
- Synthetic local smoke exam `M9 Tablet Smoke Test` archived after verification; candidate `m9-smoke-001` and its synthetic instructor deactivated. Records and submitted answers retained as test evidence. Temporary helper is ignored under storage/app/m9-smoke.php.

Git: M9/M10/M11 changes are committed on main; no push and no worktree cleanup. Prior statements claiming M7/M8 completion are superseded by this corrective handoff.

## Milestone 10 implementation — 2026-09-30

Implemented IndexedDB recovery in `resources/js/lib/exam-recovery.ts`. Each attempt stores a local answer snapshot and an ordered pending-save queue keyed by attempt ID. The candidate page displays `Saved`, `Saving…`, `Offline — saved on this device`, `Syncing…`, and sync failure states, listens for browser connection changes, retries queued writes in order, and clears recovery data after terminal submission/expiry.

The backend remains authoritative. Each retry carries the server revision; identical retries after a lost response are idempotent, while conflicting stale-tab writes remain rejected. Local storage is temporary and scoped to the attempt. Authenticated HTML, question payloads, and answers are never cached by the service worker. Full offline application startup is intentionally not claimed: the app requires the institutional server to open an attempt, then protects short interruptions and refreshes through IndexedDB until connectivity returns.

Validation focused on implementation flow rather than broad regression work: TypeScript compilation passes, Pint passes, migrations 000300/000400 are applied, and the browser flow has been exercised through MCQ, true/false, essay, refresh recovery, timer continuation, explicit submission confirmation, and portrait/landscape tablet layouts. A focused test command was started after the M10 changes but did not complete in the local environment; broad test execution is intentionally left to the owner.

## Milestone 11 implementation — 2026-09-30

Implemented transactional examination submission and scoring. `ExaminationScoringService` creates a private scoring key from the locked question bank at attempt start, scores multiple-choice and true/false answers from server-side correct choices, and records per-item scores without exposing the key. Essay items produce a `pending_review` result until Milestone 12 grading; objective-only attempts become `graded` immediately. Automatic expiry submission and manual submission both use the same locked, idempotent path, record submission kind/timestamp, and make the attempt immutable to candidates after terminal state.

Added examination-level `release_results` configuration. Candidate result pages and attempt history expose scores only when enabled; pending essay attempts show the objective subtotal and review state. Added persisted objective/total/earned points, percentage, passing threshold and pass flag, score status, and item-score snapshots to `examination_attempts`. Migration `000500_add_examination_scoring` is applied locally.

Validation: TypeScript check, production Vite build, PHP syntax checks, Pint, and `git diff --check` pass. The focused PHPUnit command was attempted but hangs in the local environment; broad test execution remains intentionally with the owner. Next work is M12 manual essay grading, followed by reconciling the preserved M7/M8 worktrees before later milestones.
