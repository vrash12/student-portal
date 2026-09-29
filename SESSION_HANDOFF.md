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

**Milestones 0, 1, and 2: complete.**
**Next: Milestone 3 (Instructor Dashboard): not started.**

## Current Status

Foundation, sign-in, roles and permissions, staff accounts, academic structure (periods, subjects, classes/batches, subject offerings, instructor assignments), and candidate records with sign-in accounts are implemented. The full test suite passes (132 tests, 770 assertions).

## Last Updated

**Date:** 2026-09-29

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

- [x] Academic periods: list, create, edit, set active (confirmation dialog). Only one active period, enforced by the database
- [x] Subjects: list with search/status filter, create, edit, activate/deactivate
- [x] Classes / batches: list by period (active period by default), create (period fixed at creation), rename, detail page
- [x] Subject offerings: add active subjects to a class, remove (also removes its instructor assignments, with confirmation)
- [x] Instructor assignments: assign/remove from the class page and from the instructor page; only active teaching staff are eligible
- [x] Instructors: directory of teaching staff with assignment counts, detail page with assignments
- [x] Candidates: list with search (number, name, full name), class and status filters; create (record + sign-in account in one transaction); edit (details, class, status, account active, password reset); profile (details, account, subjects with instructors)
- [x] Demo data: `DemoAcademicSeeder` (period, Subjects 1–4, Sample Batch A/B, 8 assignments, candidates `2026-0001`…`2026-0010`)
- [x] Verified in the browser: classes list, class detail, assigning an instructor, candidate search and profile, permission-aware sidebar

---

# Work In Progress

None. There is no partially completed code.

---

# Next Recommended Task

Start **Milestone 3 (Instructor Dashboard)**:

1. Instructor dashboard (`staff/dashboard` already exists; branch the content by permission, not role): "My Subjects" from `instructor_assignments` in the active period (subject, class, candidate count), with quick links.
2. Instructor-scoped candidate access: extend `CandidatePolicy::view` so a user with `classes.teach` can open candidates in classes they are assigned to. Add an "assigned classes" list for instructors (read-only class detail scoped to their assignments, or a dedicated "My Classes" page). Never widen `candidates.view_all`.
3. Upcoming assessments and academic alerts depend on Milestones 4–5. Show honest empty states until then; do not fake numbers.
4. Tests: instructor sees only their own assignments; instructor cannot open unassigned classes or candidates; dashboard counts come from the database.

Start by inspecting:

- `app/Http/Controllers/Staff/DashboardController.php`, `resources/js/pages/staff/dashboard.tsx`
- `app/Policies/CandidatePolicy.php`, `app/Models/User.php` (`teachingAssignments`, `canTeach`)
- `app/Models/InstructorAssignment.php`, `app/Models/ClassSubject.php`
- `resources/js/lib/navigation.ts`, `resources/js/lib/permissions.ts`

---

# Files Recently Changed

Milestone 2 (this session, after the Milestone 0–1 commit):

```text
app/Enums/{Permission,SystemRole,AuditAction,CandidateStatus}.php
app/Models/{AcademicPeriod,Subject,ClassBatch,ClassSubject,InstructorAssignment,Candidate,User,Role}.php
app/Services/{AcademicPeriodService,SubjectService,ClassBatchService,InstructorAssignmentService,CandidateService,AuditLogger,UserAccountService}.php
app/Policies/{CandidatePolicy,UserPolicy}.php, app/Rules/GrantableStaffRole.php
app/Support/{QueryFilters,AcademicOptions}.php
app/Http/Requests/Academic/*, app/Http/Requests/Candidates/*
app/Http/Controllers/Staff/{AcademicPeriod,Subject,ClassBatch,ClassSubject,InstructorAssignment,Instructor,Candidate,User,Role}Controller.php
routes/web.php, app/Providers/AppServiceProvider.php (morph map)
database/migrations/2026_09_29_000200..000600_*, database/factories/*, database/seeders/{AccessControl,DemoAccounts,DemoAcademic,Database}Seeder.php
resources/js/lib/{routes,navigation,permissions,terminology,format}.ts
resources/js/components/ui/{table,filter-bar,form-section,confirm-action,form-field}.tsx
resources/js/components/{academic,candidates}/*, resources/js/components/users/user-form.tsx
resources/js/pages/staff/{academic-periods,subjects,classes,instructors,candidates}/*, pages/staff/roles/index.tsx
tests/Feature/Academic/*, tests/Feature/Candidates/*, tests/TestCase.php, tests/Unit/PermissionCatalogueTest.php
README.md, SESSION_HANDOFF.md
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
DELETE /classes/{classBatch}/subjects/{classSubject} (scoped)    class_batches.manage
GET  /instructors, /instructors/{instructor}                     instructor_assignments.manage
POST /instructor-assignments, DELETE /instructor-assignments/{instructorAssignment}
     /candidates (index, create, store, show, edit, update)      CandidatePolicy
GET  /portal                                      exam_portal.access
     fallback                                     404 inside the web middleware group
```

Frontend URL helpers: `resources/js/lib/routes.ts` (keep in sync with `routes/web.php`).

---

# Important Models and Relationships

```text
User                 belongsTo Role; hasOne Candidate; hasMany InstructorAssignment (teachingAssignments)
                     scopes: teachingStaff(), eligibleToTeach()
Role                 belongsToMany Permission; hasMany User; rank decides who may assign it
AcademicPeriod       hasMany ClassBatch; scope active()
ClassBatch           belongsTo AcademicPeriod; hasMany Candidate; hasMany ClassSubject
ClassSubject         belongsTo ClassBatch, Subject; hasMany InstructorAssignment; belongsToMany User (instructors)
InstructorAssignment belongsTo ClassSubject, User (instructor)
Candidate            belongsTo User (account), ClassBatch; status cast to CandidateStatus
AuditLog             belongsTo User (actor); morphTo auditable
Morph map: user, role, academic_period, subject, class_batch, class_subject, instructor_assignment, candidate
```

---

# Important Architectural Decisions

Project-level decisions (in addition to AGENTS.md):

- Backend: Laravel. Frontend: React + TypeScript through Inertia.js.
- Database: MySQL 8.4+ or MariaDB. PostgreSQL was replaced at the owner's request on 2026-09-29. Local development uses XAMPP's MariaDB 10.4. Keep SQL portable between MySQL and MariaDB.
- The candidate examination experience is a PWA for organization-issued tablets. Public internet access is not assumed.
- Grades are entered manually. Imports, native Android, and advanced infrastructure are deferred.

Decisions made in this session:

- Bare Laravel skeleton instead of the React starter kit. The skeleton's Laravel Boost AGENTS.md/CLAUDE.md files were discarded and Boost is not installed.
- Username sign-in; email optional; no "remember me"; no email password reset (administrators reset passwords).
- **Single role per user.** Permissions are defined in `App\Enums\Permission` and mirrored into the database by `AccessControlSeeder`, which **resets system roles to their defaults** (revisit before any role-editing UI).
- **No privilege escalation by rank:** a user may only assign roles, and edit accounts holding roles, ranked below their own (`User::canAssignRole`, `GrantableStaffRole`). Editing one's own profile is allowed; the self-lockout guards block own role and status changes. Ranks: Super Administrator 100, Academic Administrator 80, Instructor 40, Candidate 10. (This replaced an earlier "subset of own permissions" rule, which would have stopped academic administrators from creating instructor accounts.)
- The area a user enters is decided by permissions (`staff_area.access`, `exam_portal.access`), never by role names.
- **Instructors are teaching staff identified by the `classes.teach` permission** (Instructor role only by default); there is no separate instructor table. Accounts are managed in Users; teaching assignments in Classes and Instructors.
- **Candidates:** the record and its sign-in account are created and updated together by `CandidateService`. Username = candidate number in lowercase; account name = "First Last". Changing the number changes the username and ends the candidate's sessions. Candidate accounts are not managed in the Users module.
- **Only one active academic period**, enforced by a unique index on a stored generated column (no partial indexes in MySQL/MariaDB). `AcademicPeriodService::activate()` also locks rows.
- A class's academic period is fixed at creation.
- Removing a subject from a class removes its instructor assignments. Once assessments exist (Milestone 4), removal must be blocked for offerings with assessments.
- Accounts are deactivated, never deleted. Role changes, deactivation, password resets, and candidate username changes end stored sessions immediately.
- Audit logs are append-only; `AuditLogger::recordChanges()` stores only changed values (previous and new).
- Toasts use Inertia v3 flash data; default layouts are chosen by page-name prefix in `resources/js/app.tsx`.
- No SSR, no Inertia DevTools, `QUEUE_CONNECTION=sync`.
- Timestamps stored in UTC, displayed in `INSTITUTION_TIMEZONE` (local: Asia/Manila). Calendar dates use `formatCalendarDate()` (no timezone shift).
- `Model::shouldBeStrict()` outside production. Tests call `withoutVite()` so they never depend on built assets.

---

# UI/UX Decisions

- Staff sidebar sections: Dashboard; Academics (Candidates, Classes, Subjects, Academic Periods); Administration (Instructors, Users, Roles & Permissions). Items are filtered by permission and only implemented modules appear.
- "Class / Batch" is displayed as "Class" through `resources/js/lib/terminology.ts`; change the term there once the institution confirms it. Code uses `ClassBatch`.
- `pointer-coarse:` variants give 44px touch targets and 16px input text on tablets.
- Status badges always pair an icon with text.
- Shared building blocks: `Table`/`Th`/`Td`/`RowAction`, `FilterBar` + `SearchField`, `FormSection` + `FormActions`, `ConfirmAction` (button + confirmation + request), `ConfirmDialog`, `Pagination` (singular/plural nouns).
- Destructive or significant actions require confirmation: deactivating accounts, setting the active period, removing subjects and instructor assignments.
- Per-item form labels carry screen-reader-only context (for example "Assign Instructor to Subject 1").
- Dashboards and profiles show honest empty states where later milestones will add data.

---

# Security / Authorization Notes

```text
Gate:    every Permission enum case is a Gate ability (AppServiceProvider)
Routes:  each academic module is behind its own permission (see Routes)
UserPolicy:
- viewAny: users.view; create: users.manage
- update: users.manage AND staff account AND (own account OR target role ranked below actor)
CandidatePolicy:
- viewAny/view: candidates.view_all (Milestone 3 adds instructor access to assigned classes)
- create/update: candidates.manage
Assignments: instructor must hold classes.teach and be active (Form Request + service check)
Inactive users: hold no permissions; EnsureAccountIsActive signs them out
Shared props: id, name, username, role (code, name), the user's own permission codes
```

---

# Tests Added

```text
tests/Feature/Auth/LoginTest.php, AreaAccessTest.php
tests/Feature/Users/UserManagementTest.php          includes rank rules
tests/Feature/Account/PasswordUpdateTest.php
tests/Feature/AuditLogTest.php, SeederTest.php
tests/Feature/Academic/AcademicPeriodTest.php       includes DB-level active/date constraints
tests/Feature/Academic/SubjectTest.php
tests/Feature/Academic/ClassBatchTest.php           offerings, scoped removal
tests/Feature/Academic/InstructorAssignmentTest.php eligibility, duplicates, directory
tests/Feature/Candidates/CandidateManagementTest.php account sync, sign-in, sessions, DB status check
tests/Unit/PermissionCatalogueTest.php              TS/PHP permission sync, role invariants
```

Status: **132 passed, 0 failed** (770 assertions). `npm run types`, `npm run build`, and Pint pass.

`tests/TestCase.php` uses RefreshDatabase, seeds `AccessControlSeeder` once, calls `withoutVite()`, and **refuses to refresh any database whose name does not end in `_testing`** (the XAMPP root account can see other projects' databases on this machine).

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

The Claude Code preview configuration is in `.claude/launch.json` (name: `laravel`, port 8000).

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

### Issue: Instructors cannot yet see their classes or candidates

Status: Planned (Milestone 3)

Details: Instructors currently see only the dashboard. Candidate and class pages require administrator permissions.

---

# Blockers

None.

---

# Requirements Still Needing Confirmation

Do not hardcode these until confirmed:

- production database server (MySQL or MariaDB) and version;
- actual subject names;
- official grading formula, passing grade, and warning threshold;
- candidate identifier format (candidate numbers currently allow letters, numbers, `.`, `-`, `_`, up to 30 characters);
- candidate enrollment statuses (currently Enrolled, On Leave, Withdrawn, Completed);
- exact Class / Batch terminology;
- number of academic periods;
- whether Academic Administrators may create other Academic Administrator accounts (currently not allowed: ranks must be lower);
- whether Academic Administrators also teach (currently only the Instructor role holds `classes.teach`);
- candidate password rules on shared tablets;
- session timeout behaviour during examinations;
- exam navigation restrictions and whether candidates may see scores immediately;
- official branding (organization name, logo, colors);
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

ASSUMPTION: Branding uses placeholders ("Organization Name" and a neutral mark).
Reason: official branding has not been approved.
```

---

# Git / Version Control State

```text
Branch: main (tracks origin/main)
Remote: origin https://github.com/vrash12/student-portal.git
Commits: 0ff5cc3 first commit (README only)
         ad168a7 Milestones 0–1
         plus the Milestone 2 commit (see `git log`)
```

---

# Uncommitted or Incomplete Code

None. Milestones 0–2 are complete and committed.

---

# Validation Before Continuing

At the start of the next session, the coding agent should verify:

```text
1. Repository status (git status, git log)
2. XAMPP MySQL is running (port 3306)
3. php artisan migrate:status shows no pending migrations
4. php artisan test passes
5. npm run types and npm run build pass
6. Current milestone (Milestone 3 next)
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
