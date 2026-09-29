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

**Milestone 0 (Project Setup and Foundation): complete.**
**Milestone 1 (Authentication, Roles, and Authorization): complete.**
**Next: Milestone 2 (Core Academic Data Management): not started.**

## Current Status

Foundation, sign-in, roles and permissions, server-side route protection, staff account management, and the audit-log foundation are implemented. The full test suite passes (83 tests, 389 assertions).

## Last Updated

**Date:** 2026-09-29

**Updated by:** Claude Code (Opus 5.5)

---

# Completed Work

- [x] Laravel 13 bare skeleton (not the React starter kit) with React 19, strict TypeScript 7, Inertia v3, Vite 8, Tailwind CSS v4
- [x] Database: XAMPP MySQL (MariaDB 10.4.32), port 3306, `root` with no password, Laravel `mariadb` connection. Databases `academic_system` and `academic_system_testing`
- [x] `.env.example` (XAMPP defaults) and README setup instructions
- [x] Design tokens in `resources/css/app.css` (Tailwind default palette disabled; only tokens). Inter bundled locally with `@fontsource-variable/inter`
- [x] Layouts: staff (sidebar at 1024px and wider, native-`<dialog>` drawer below), candidate portal (no admin navigation), auth
- [x] UI components in `resources/js/components/ui/`: Button/ButtonLink, FormField + TextInput/PasswordInput/SelectInput/CheckboxField, RadioCards, StatusBadge, PageHeader (breadcrumbs), Panel, EmptyState, Pagination, ConfirmDialog, Toaster, Alert. Placeholder `BrandMark`
- [x] Sign-in by username, rate limited (5 attempts per username+IP per minute), session regeneration, sign-out, deactivated accounts blocked and signed out on their next request
- [x] Roles and permissions: `App\Enums\Permission` cases are registered as Gate abilities. Single role per user. System roles seeded
- [x] Server-side route protection by permission (`can:` middleware), `UserPolicy`, and a no-privilege-escalation rule
- [x] Error pages through Inertia: 403 and 404 always; 500 and 503 only when `APP_DEBUG=false`. An expired CSRF token (419) shows a flash message and redirects back
- [x] Users module (staff accounts only): list with search, role and status filters and pagination; create; edit; role change; deactivate/reactivate (with confirmation dialog); admin password reset; guards against self-lockout
- [x] Roles & Permissions page (read-only permission matrix)
- [x] Change Password page (keeps the current session, signs out other sessions)
- [x] Audit log foundation: `audit_logs` table, `AuditLogger` service, append-only `AuditLog` model. Records sign-in, failed sign-in, sign-out, and all account changes
- [x] Security headers middleware; `robots.txt` disallows all
- [x] Seeders: `AccessControlSeeder` (idempotent, production-safe) and `DemoAccountsSeeder` (fictional accounts, refuses to run in production)
- [x] Verified in the browser: sign-in errors, dashboard, drawer, users search, edit, deactivation confirmation and toast, candidate portal, 403 page, roles matrix, 44px touch targets with a coarse pointer

---

# Work In Progress

None. There is no partially completed code.

---

# Next Recommended Task

Start **Milestone 2 (Core Academic Data Management)**. Suggested order:

1. Academic periods: create, edit, set active. MySQL/MariaDB have no partial indexes, so enforce "only one active period" with a transaction and row lock.
2. Classes / batches: `ClassBatch` model, `class_batches` table, linked to an academic period. (`Class` is reserved in PHP; `Batch` clashes with Laravel job batches.)
3. Subjects: configurable, activate/deactivate, no hardcoded real names (seed "Subject 1" to "Subject 4").
4. Candidates: create the candidate record and its login account (Candidate role) in one transaction. Candidate number, name, class/batch, academic status. Candidate accounts are **not** managed in the Users module.
5. Instructors and instructor assignments (instructor, subject, class/batch, period). Instructor-scoped authorization through policies.
6. For each new permission: add the enum case, the `SystemRole` defaults, `resources/js/lib/permissions.ts` (`PermissionCatalogueTest` enforces the match), and the navigation entry in `resources/js/lib/navigation.ts`. Only add a permission together with the code that enforces it.

Start by inspecting:

- `app/Enums/Permission.php`, `app/Enums/SystemRole.php`
- `app/Models/User.php`, `app/Services/UserAccountService.php`, `app/Policies/UserPolicy.php`
- `routes/web.php`, `resources/js/lib/navigation.ts`, `resources/js/lib/routes.ts`
- `resources/js/pages/staff/users/*` (reference pattern for list, filter, and form pages)

Confirm the open items under "Requirements Still Needing Confirmation" with the project owner where they affect Milestone 2 (especially the candidate number format and the Class / Batch term).

---

# Files Recently Changed

Everything below was created in this session (first implementation):

```text
app/Enums/{Permission,SystemRole,AuditAction}.php
app/Models/{User,Role,Permission,AuditLog}.php
app/Services/{AuditLogger,UserAccountService}.php
app/Policies/UserPolicy.php
app/Rules/GrantableStaffRole.php
app/Http/Middleware/{HandleInertiaRequests,EnsureAccountIsActive,AddSecurityHeaders}.php
app/Http/Requests/Auth/LoginRequest.php
app/Http/Requests/Users/{UserAccountRequest,StoreUserRequest,UpdateUserRequest}.php
app/Http/Requests/Account/UpdatePasswordRequest.php
app/Http/Controllers/{HomeController}.php, Auth/AuthenticatedSessionController.php
app/Http/Controllers/Staff/{DashboardController,UserController,RoleController,AccountPasswordController}.php
app/Http/Controllers/Portal/PortalHomeController.php
app/Providers/AppServiceProvider.php, bootstrap/app.php, routes/web.php
config/{institution,demo}.php, config/database.php (engine InnoDB)
database/migrations/*, database/seeders/*, database/factories/UserFactory.php
resources/views/app.blade.php, resources/css/app.css
resources/js/{app.tsx, types/*, lib/*, layouts/*, components/**, pages/**}
tests/TestCase.php, tests/Feature/**, tests/Unit/PermissionCatalogueTest.php
vite.config.ts, tsconfig.json, package.json, composer.json, phpunit.xml, .env.example, README.md
AGENTS.md, MILESTONES.md (PostgreSQL replaced by MySQL/MariaDB at the owner's request)
```

---

# Database Changes

```text
roles            id, code (unique), name, description, is_system, timestamps
permissions      id, code (unique), name, description, group, timestamps
permission_role  role_id FK cascade, permission_id FK cascade, PK (role_id, permission_id)
users            id, name, username (unique), email (nullable, unique), password,
                 role_id FK restrict, is_active, last_login_at, remember_token, timestamps
                 index (role_id, is_active)
sessions         Laravel default (database session driver)
audit_logs       id, actor_id FK users restrict (nullable), action, auditable_type, auditable_id,
                 old_values json, new_values json, reason, ip_address, user_agent, created_at
                 indexes: (auditable_type, auditable_id), (actor_id, created_at), (action, created_at), created_at
cache, cache_locks, jobs, job_batches, failed_jobs   Laravel defaults
```

Removed from the skeleton: `password_reset_tokens` and `users.email_verified_at` (no email flows).

---

# Routes Added or Changed

```text
GET  /login                     login           (guest)
POST /login                     login.attempt   (guest)
POST /logout                    logout
GET  /                          home            redirects by permission
GET  /dashboard                 dashboard       staff_area.access
GET  /users                     users.index     UserPolicy@viewAny
GET  /users/create              users.create    UserPolicy@create
POST /users                     users.store     UserPolicy@create
GET  /users/{user}/edit         users.edit      UserPolicy@update
PUT  /users/{user}              users.update    UserPolicy@update
GET  /roles                     roles.index     roles.view
GET  /account/password          account.password.edit     staff_area.access
PUT  /account/password          account.password.update   staff_area.access
GET  /portal                    portal.home     exam_portal.access
     fallback                   404 inside the web middleware group
```

The frontend URL helpers are in `resources/js/lib/routes.ts`. Keep them in sync with `routes/web.php`.

---

# Important Models and Relationships

```text
User      belongsTo Role (single role per user)
Role      belongsToMany Permission (permission_role); hasMany User
AuditLog  belongsTo User (actor); morphTo auditable
Morph map (enforced): user => User, role => Role
```

---

# Important Architectural Decisions

Project-level decisions (in addition to AGENTS.md):

- Backend: Laravel. Frontend: React + TypeScript through Inertia.js.
- Database: MySQL 8.4+ or MariaDB. PostgreSQL was replaced at the owner's request on 2026-09-29. Local development uses XAMPP's MariaDB 10.4. Keep SQL compatible with both MySQL and MariaDB.
- The candidate examination experience is a PWA for organization-issued tablets. Public internet access is not assumed.
- Grades are entered manually. Excel/CSV/spreadsheet imports, native Android, and advanced infrastructure are deferred.

Decisions made this session:

- Bare Laravel skeleton instead of the React starter kit, which brings public registration, email verification/reset, dark mode, a font CDN, and many UI dependencies.
- The Laravel 13 skeleton ships AGENTS.md/CLAUDE.md files that tell agents to install Laravel Boost. They were discarded (the project AGENTS.md is authoritative) and Boost is not installed.
- Sign-in by **username**; email is optional. No "remember me" (shared tablets). No password reset by email (administrators reset passwords).
- **Single role per user.** Permissions are defined in code (`App\Enums\Permission`), mirrored into the `permissions` table by `AccessControlSeeder`. The seeder **resets system roles to their defaults**, so revisit this before building any role-editing UI.
- **No privilege escalation:** a user may only assign, or edit accounts holding, roles whose permissions they already hold (`User::canGrantRole`).
- The area a user enters is decided by permissions (`staff_area.access` or `exam_portal.access`), never by role names.
- The Users module manages **staff accounts only**. Candidate accounts will be managed through candidate records (Milestone 2).
- Accounts are deactivated, never deleted. Role changes, deactivation, and admin password resets end the user's stored sessions immediately.
- Audit logs are append-only (model events throw on update and delete). The action is stored as a string, and sensitive keys are stripped by `AuditLogger`.
- Toasts use Inertia v3 flash data (`Inertia::flash('toast', ['type' => ..., 'message' => ...])`), which is not kept in browser history.
- Default layouts are chosen by page-name prefix in `resources/js/app.tsx`: `staff/`, `portal/`, `auth/`, and `errors/` (the error page uses the user's own shell).
- No SSR, no Inertia DevTools, `QUEUE_CONNECTION=sync` (no worker needed).
- Timestamps are stored in UTC and displayed in `INSTITUTION_TIMEZONE` (local `.env`: Asia/Manila).
- `Model::shouldBeStrict()` outside production. `User` defines default attributes (`is_active`, `remember_token`) so in-memory models are complete.
- Password policy: 10 to 128 characters. No breached-password API check (no internet).

---

# UI/UX Decisions

- Staff sidebar is persistent from 1024px; below that a drawer opens from the header menu button.
- `pointer-coarse:` variants give 44px touch targets and 16px input text on tablets (no zoom on focus).
- Status badges always pair an icon with text. Button labels are verbs in Title Case ("Create Account", "Save Changes").
- The dashboard shows an honest "Academic monitoring is not set up yet" state plus real active-account counts (only for users who can view accounts).
- The sidebar lists only implemented modules. Add a navigation item together with its module.
- Deactivating an account requires a confirmation dialog that describes the consequences.
- The Class / Batch UI term is still pending; code uses `ClassBatch`.

---

# Security / Authorization Notes

```text
Gate:   every Permission enum case is a Gate ability (AppServiceProvider)
UserPolicy:
- viewAny: users.view
- create:  users.manage
- update:  users.manage AND target is a staff account AND actor can grant the target's role
UpdateUserRequest: cannot change own role, deactivate self, or reset own password there
GrantableStaffRole rule: role must grant staff_area.access and be grantable by the actor
Inactive users: hold no permissions; EnsureAccountIsActive signs them out
Shared props: only id, name, username, role (code, name), and the user's own permission codes
```

---

# Tests Added

```text
tests/Feature/Auth/LoginTest.php           sign-in, messages, lockout, audit, sign-out
tests/Feature/Auth/AreaAccessTest.php      guests, candidates, instructors, admins, 403/404, shared props
tests/Feature/Users/UserManagementTest.php filters, create, validation, escalation, audit, sessions
tests/Feature/Account/PasswordUpdateTest.php
tests/Feature/AuditLogTest.php             immutability, redaction, request context
tests/Feature/SeederTest.php               system roles, idempotency, demo accounts, production guard
tests/Unit/PermissionCatalogueTest.php     TypeScript/PHP permission sync, role invariants
```

Status: **83 passed, 0 failed** (389 assertions). `npm run types` and `npm run build` pass. Pint passes.

`tests/TestCase.php` uses RefreshDatabase, seeds `AccessControlSeeder` once, and **refuses to refresh any database whose name does not end in `_testing`**. The XAMPP root account can see other projects' databases on this machine, and Laravel's wipe is scoped to the connection's database, so this guard is extra protection.

---

# Commands Used

```bash
php artisan migrate --seed
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

Details: A MySQL 9.4 Windows service (`MySQL94`, port 3308) is installed but not used by this project; its root password is not known to the project.

### Issue: `php artisan serve` is single-threaded

Status: Informational

Details: Pages load slowly when several requests overlap during browser testing. This does not affect production deployments behind a real web server.

---

# Blockers

None.

---

# Requirements Still Needing Confirmation

Do not hardcode these until confirmed:

- production database server (MySQL or MariaDB) and version;
- actual subject names;
- official grading formula, passing grade, and warning threshold;
- candidate identifier format (candidate usernames are expected to follow it);
- exact Class / Batch terminology;
- number of academic periods;
- whether Academic Administrators may create other Academic Administrator accounts (currently allowed);
- candidate access and password rules on shared tablets;
- session timeout behaviour during examinations;
- exam navigation restrictions and whether candidates may see scores immediately;
- official branding (organization name, logo, colors);
- internal deployment environment (hostname, HTTPS certificate).

---

# Temporary Development Assumptions

```text
ASSUMPTION: Sign-in uses a username; email is optional.
Reason: candidates may not have email, and outbound mail is not assumed on internal networks.

ASSUMPTION: Display timezone is Asia/Manila in the local .env (INSTITUTION_TIMEZONE, configurable).
Reason: the institution's timezone has not been confirmed.

ASSUMPTION: Minimum password length is 10 characters.
Reason: no official password policy has been supplied.

ASSUMPTION: Academic Administrators can manage staff accounts except Super Administrator accounts.
Reason: the split between the two administrator roles is not specified.

ASSUMPTION: Branding uses placeholders ("Organization Name" and a neutral mark).
Reason: official branding has not been approved.
```

---

# Git / Version Control State

```text
Branch: main (tracks origin/main)
Remote: origin https://github.com/vrash12/student-portal.git
Commits: 0ff5cc3 first commit (README only)
         plus this session's Milestone 0–1 commit (see `git log`)
```

---

# Uncommitted or Incomplete Code

None. Milestones 0 and 1 are complete and committed.

---

# Validation Before Continuing

At the start of the next session, the coding agent should verify:

```text
1. Repository status (git status, git log)
2. XAMPP MySQL is running (port 3306)
3. php artisan migrate:status shows no pending migrations
4. php artisan test passes
5. npm run types and npm run build pass
6. Current milestone (Milestone 2 next)
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
