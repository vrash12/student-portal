# SESSION_HANDOFF.md

Updated 2026-10-01 by Claude Code (earlier sections by Codex, 2026-09-30). Read this file together with `AGENTS.md`, `UI_UX_DESIGN.md`, `MILESTONES.md`, and `docs/question-bank-examination-contract.md`; inspect Git and the actual code before editing. Newest entries are first.

## Start here (state at the end of the 2026-10-01 session)

- **Branch `main`.** Newest commits (none pushed; push only when the owner asks; one commit per milestone or owner request): "Review UI/UX and tablet experience (Milestone 18)", "Add Statement of Account ledger", `70b9dc8` standard military fitness, `abd3360` charts, `2a30161` periods/classes/subjects/instructors, `14a0705` client demo data. Working tree clean after these commits.
- **Implemented:** Milestones 0–18, plus owner additions: candidate information and photos, exam-to-gradebook posting, student home, registration/academic PDFs, green/yellow UI, leave-screen detection, question images/audio/video, images in answer choices, random question subsets, question CSV import, item analysis, "Powered by" credit, client demo data, period/class/subject/instructor views, charts, standard military fitness, Statement of Account ledger.
- **Next: Milestone 19 — testing and stability**, then M20 demo preparation. Do not start other features unless the owner asks.
- **Verified at the end of the session:** full suite **1,325 passing** (about 7–8 minutes), `npm run types`, `npm run build`, `vendor/bin/pint --test`; no pending migrations. **Never run two `php artisan test` processes on the same database**: they wipe each other's tables. For parallel runs use separate databases, e.g. `$env:DB_DATABASE='academic_system_w2_testing'` (PowerShell).
- **Verify before changing anything:** `php artisan test`, `npm run check`, `vendor/bin/pint --test`, `php artisan migrate:status`. XAMPP MariaDB on port 3306 must be running.
- **Local use:** `php artisan serve`, then http://localhost:8000. Client demo set: `admin`, `instructor1`, `instructor2`, `finance1`, `student01`…`student20`, password `password` (see `README.md`). Run `php artisan schedule:work` for unattended exam expiry.
- **Local data note:** `instructor1`'s assignment to Subject 1 was removed by `admin` at 09:59 (Manila) on 2026-10-01 (audit entry 108, `instructor_assignment.removed`) — not by this session's code. Until it is re-added (Instructors → assign), `instructor1` teaches nothing and sees "You do not teach any subjects yet". A voided demo ledger entry exists on `student01` from the ledger browser check.
- **Open owner decisions** are under "Requirements still needing confirmation" at the end of this file.
- **The local database was reset on 2026-10-01 at the owner's request** to the client demo set (`ClientDemoSeeder`); the previous data is backed up in `storage/app/backups/before-client-demo-20261001-*/` (git-ignored). Do not run `migrate:fresh` on the working database without the owner's request.
- Extra test databases `academic_system_{r1,r2,r3,w1,w2,w3,v1…v12}_testing` exist from parallel test runs and can be dropped.

## Milestone 18 — UI/UX Review and Tablet QA, plus owner UI requests (2026-10-01, Claude Code; done)

Owner requests this session: "enhance the UI/UX overall"; "remove the login image (I will place one later) and enhance that section"; "exams/quizzes like the image" (a Canvas-style quiz side panel: question list with status icons, "Time Running: Hide", attempt due, "9 Minutes, 53 Seconds"); "the instructor's question/answer creation is confusing"; "better candidate UX".

- **Sign-in page** (`layouts/auth-layout.tsx`, `pages/auth/login.tsx`): the photo is no longer used by default (`institution.login_image_url` defaults to null; the file `public/branding/login-training.jpg` is kept). Wide screens show a deep-green brand panel (logo, organization, system name, authorized-use note); when `LOGIN_IMAGE_URL` is set, the image fills that panel (README "Internal deployment notes"). Narrow screens show a compact brand header. The form gained a Caps Lock warning, larger fields and a clearer layout.
- **Candidate examination screen** (`pages/portal/examinations/attempt.tsx`): two columns from 1024 px — question card plus a sticky side panel with the question list (Answered / Not answered / Flagged icons, current question outlined, jump only when free navigation is allowed), "Time Running: Hide/Show", "Attempt ends", time left in words, answered/remaining/flagged counts and Submit. Portrait tablets get a sticky bar with the timer (mm:ss) and a Questions toggle. Also: header with candidate name and progress bar, points and a Flag button per question, lettered answer cards with "Selected", essay character count, fixed save states (Saved / Saving… / Not saved yet / Offline – saved on this device / Syncing… / Unable to sync), answers never disabled during autosave (typing continues; a follow-up save runs), submit errors shown in an alert, low-time warning not by color only, focus moves to each new question. Browser-checked at 1366×900 and 768×1024 with a real attempt (student20; the test attempt was deleted afterwards).
- **Candidate pages:** start page in two columns with fact tiles, rules (time-out behaviour, closing time), reason when Start is unavailable; result page per status/submission kind with time; home exam cards with a status badge (Open now / In progress / All attempts used / Scheduled) and a fact grid; Sign Out hidden during an attempt.
- **Question authoring** (`components/question-bank/question-form.tsx`, `choice-editor.tsx`): numbered steps (1 Subject and type — cards with icons; 2 Question; 3 Answer; 4 Points and topic; 5 Explanation), a live **Candidate view** preview beside the form, compact choice rows where the letter marks the correct answer ("Correct answer: B" summary; Enter moves to the next choice instead of submitting), and **Save and Add Another** on create (`add_another` → redirect to `question-bank/create?subject=…`, test `QuestionAuthoringFlowTest`). Shared: `FormSection` optional `step`, `RadioCards` optional `icon`/`columns`.
- **Examination builder:** `components/examinations/builder-steps.tsx` (1 Settings → 2 Questions → 3 Review and Publish) on the settings form, the question picker and a draft's review page; a new draft now redirects to **Choose Questions** (test updated in `ExaminationDraftTest`). The picker has type filter, compact cards (type, topic, points), Add All Shown, New Question shortcut, a sticky "In This Examination" list with total points and icon buttons, and a sticky save bar with unsaved state ("Save and Review").
- **Milestone 18 review fixes** (from the earlier part of the session; all now committed): staff exam pages rebuilt with shared components (breadcrumbs, Table, StatusBadge, EmptyState, FormField, client paginator, state-appropriate descriptions, accessible grading filter and buttons), audit history (readable before/after values, FilterBar), reports (numeric columns, terms), users list, distinct navigation icons, `FileInput`, table scroll containment, ConfirmDialog focus, toasts (errors stay until dismissed), darker `ink-subtle`/`line-strong` tokens, focus moves to `#main-content` after page changes (`use-focus-main-on-navigate.ts`), standalone error page `<main>`, PWA 192 px icon and a styled offline page (worker cache `candidate-assets-v2`).
- **Tests:** `tests/Feature/QuestionBank/QuestionAuthoringFlowTest.php` (3), `tests/Feature/Examinations/Delivery/CandidateExamPagesTest.php` (4); full suite 1,325 passing.
- **Not done (later):** Title Case pass on remaining older pages; `MetricCard` text values, row-header cells in `Table`, `SearchField` error prop (suggested by the review agents).

## Owner request — Statement of Account (2026-10-01, Claude Code; done)

- **Client request:** "Statement Of Account (banking, billing, chargeable, uniforms, meals allowances, military fitness)". **Owner decisions:** an internal ledger per candidate (charges and credits, running balance, printable SOA PDF); **no online payments, no bank connections, no bank account numbers**; **staff only**. AGENTS.md §75 now notes the approval ("records amounts only, no payments").
- **Schema** (`2026_10_01_000700_create_account_tables`, **ran on the local DB**): `account_categories` (unique name, usual side `charge|credit`, sort order, active flag; seeded Billing, Chargeable Items, Uniforms, Meals, Military Fitness, Meal Allowance, Allowance, Bank Deposit, Payment Received) and `account_entries` (candidate, category, side, `DECIMAL(12,2)` amount > 0, date, description, reference, recorded by, `voided_at/voided_by/void_reason` all-or-none; CHECK constraints). Entries are **never edited or deleted**: a mistake is voided with a reason (5–255 chars) and re-entered. The migration also adds the permissions and the **Finance Officer** role to existing databases.
- **Rule (single place, `App\Services\Accounts\AccountLedger`):** balance = charges − credits over entries that are not voided; positive "Balance due", negative "Credit balance" (shown in parentheses), zero "Settled". All money is integer cents (`App\Support\Money`); the browser only formats (`resources/js/lib/money.ts`, currency from `INSTITUTION_CURRENCY`, default PHP, shared as `app.currency`).
- **Permissions:** `accounts.view`, `accounts.manage`. Super Administrator both; Academic Administrator view only; new **Finance Officer** (`finance_officer`, rank 50) with `staff_area.access` + both account permissions and nothing academic. Instructors and candidates have no access.
- **Routes/pages:** `/accounts` (balances list with search/class/balance filters and totals), `/accounts/{candidate}` (summary, period filter with balance brought forward, record-entry form, ledger with running balance, voided rows struck through with reason, Void dialog), `/accounts/{candidate}/statement` (letter-portrait PDF of the same period, voided entries left out and counted; `throttle:record-downloads`), `/account-categories` (list/create/edit/deactivate). Sidebar "Records → Statements of Account"; "Statement of Account" button on candidate profiles (users with `accounts.view`); dashboard panel `statementsOverview` (totals + latest entries). Every write and every PDF download is audited (`account_entry.recorded|voided`, `account_category.created|updated`, `account_statement.downloaded`; morph types `account_entry`, `account_category`).
- `App\Support\PdfDocument` is now the shared dompdf renderer and logo loader; `CandidatePdfService` uses it.
- **Demo data:** `DemoAccountStatementsSeeder` (called by `ClientDemoSeeder`; the name `DemoAccountsSeeder` was already taken by the staff sign-in seeder) creates the Finance Officer **`finance1`** (password `password`) and synthetic entries for every class of the active period. **Run on the local DB on 2026-10-01:** 10 candidates with a balance due (PHP 180,000.00 in total), 5 in credit (PHP 10,000.00), 5 settled; student01 has two voided entries (one from the seeder, one from the browser check). README.md does not list `finance1` yet.
- **Tests:** `tests/Feature/Accounts/StatementOfAccountTest.php` (18), `tests/Unit/MoneyTest.php`; `RouteAccessMatrixTest` has `accountEntry`/`accountCategory` values. Passing: accounts + Money (36), Security, Auth, PermissionCatalogue, Seeder, Candidates (109; one timing flake in `AccountHardeningTest` passed on rerun), Teaching, AdministratorDashboard, AuditLog, Users, Fitness, Account (80). `npm run types`, `npm run build` and Pint pass. **The full suite has not been run since; run it (alone) before committing** "Add Statement of Account ledger".
- Browser-checked as `finance1` (dashboard, list, statement, record with an invalid then valid amount, void with and without a reason, period filter, PDF, categories, 375 px) and `admin` (candidate profile button, statement, audit history).
- Not done: candidate-facing statement (deliberately), editing entries (by design), reports/exports of balances.

## Owner request — standard military fitness (2026-10-01, Claude Code)

- **Client request:** "standard military fitness (data of the candidates)". Owner decisions (2026-10-01): configurable test events with standards; a **separate record** that does not affect academic grades or standing; **staff only** (candidates do not see it). No body data (height/weight/BMI) and standards do not vary by age or sex — candidate records hold neither; ask before adding.
- **Scoring (single rule, `App\Services\Fitness\FitnessStandard`):** each event scores 60 points at its passing standard and 100 at its maximum, linear in between and capped at 100; short of passing scores proportionally below 60 and fails the event. `FitnessOutcome`: any failed event fails the test (even before all events are recorded); otherwise Passed once every event has a result, else Incomplete; overall points = mean of event points, only when complete. **This rule is a sensible default, not an official standard — confirm with the client.** Times are stored in seconds and entered as minutes:seconds (`FitnessValue`).
- **Schema** (`2026_10_01_000600_create_fitness_tables`): `fitness_events` (configurable, deactivate instead of delete), `fitness_tests` (class + date), `fitness_test_events` (a **copy of the standards** at test creation, so later changes never alter recorded results), `fitness_results` (raw value per candidate per test event). CHECK constraints on unit, positive values, and direction (maximum better than passing). The migration also grants the new permissions to existing roles.
- **Permissions:** `fitness.view`, `fitness.manage` (Super and Academic Administrators by default; not instructors). Routes `/fitness`, `/fitness/standards…`, `/fitness/tests…` (`FitnessTestController`, `FitnessEventController`); sidebar section "Records → Military Fitness". Only tests without results can be deleted. Every change is audited (`fitness_event.*`, `fitness_test.*`, `fitness_test.results_recorded` with previous/new values per "candidate · event"). Results can be recorded only for the class's candidates who are not withdrawn.
- **Pages:** test list (filters, passed/failed/incomplete counts), standards CRUD, new test (class, date, events), test page (outcome counts, mean score, pass rate by event and overall score charts, editable results grid with inline errors and an unsaved-changes guard), candidate profile "Military Fitness" panel (latest test by event, earlier tests).
- **Demo data:** `DemoFitnessSeeder` (called by `ClientDemoSeeder`; run on the local DB on 2026-10-01): sample events Push-ups 40/70, Sit-ups 45/75, 3.2 km Run 16:00/12:00 (**placeholders**) and "Diagnostic Fitness Test" for Class A (13 passed, 6 failed, 1 incomplete).
- Tests: `tests/Unit/FitnessScoringTest.php`, `tests/Feature/Fitness/FitnessTest.php`; route matrix covers the new routes. Browser-checked as `admin` (list, standards, create, results entry with an invalid value, candidate profile).
- Not done: fitness reports/PDF, dashboard card, candidate-facing view (deliberately), concurrent-edit detection on the results grid (last save wins; every change is audited).

## Owner request — charts on dashboards and reports (2026-10-01, Claude Code)

- **No chart library:** small HTML/CSS chart components in `resources/js/components/charts/` (`ChartFigure`, `StandingBar`/`StandingLegend`/`StandingDistribution`/`StandingBreakdown`, `BarList` with passing/warning reference lines, `ColumnChart`). They work offline, print with their colors, adapt to their own width (container queries), and every bar is also shown as text (counts, values, legend); `ColumnChart` adds a screen-reader list. Chart colors are tokens `--color-chart-*` in `resources/css/app.css`. Data types: `resources/js/types/charts.ts`.
- **All figures come from the server** (grade engine or report rows); the browser only draws them. Shared ranges: `App\Support\ScoreBands` (90–100, 80–89.99, 70–79.99, 60–69.99, Below 60, No grade; descriptive only), now also used by the grade distribution report.
- **Administrator dashboard:** Standing Distribution bar under the standing cards; Subject Performance is a bar chart of mean grades with the period's passing (solid) and warning (dashed) lines; new Grade Distribution panel. `AdministratorDashboardService::overview()` now evaluates the period once for both (`gradeDistribution`, `thresholds`).
- **Instructor dashboard:** new "Standing by Subject" panel (each taught class subject split by standing, mean grade, link to the gradebook) from `AcademicMonitoring::activePeriodSummary(..., withSubjects: true)` — same evaluation as the alerts. The "My Subjects" panel no longer stretches to the height of the side column.
- **Reports:** `App\Services\ReportCharts` builds charts from every row of the filtered report before pagination (`charts` prop): candidate standing → overall standing bar; class → standing by class; subject → mean grade bars + standing by subject; distribution → columns; examination/quiz → score distribution of final scores (hidden until one attempt has a final score); at-risk/failing lists have no chart. At most 15 groups per chart (the table lists all).
- **Item analysis:** `summary.scoreDistribution` (final percentages by range) shown under the summary.
- Tests: `tests/Unit/ScoreBandsTest.php`, `tests/Feature/Reporting/ChartsTest.php`; updated `ItemAnalysisTest`, `AdministratorDashboardTest` (empty overview keys), `MonitoringDashboardAndProfileTest` (teaching alerts may carry aggregate `subjects`). Browser-checked as `admin` and `instructor1` at desktop and 375 px.
- **M18 note:** this commit also contains Milestone 18's finished reports-page work it builds on — `resources/js/pages/staff/reports/index.tsx`, the new `resources/js/components/reports/report-table.tsx`, and `numericColumns` in `ReportingService`. Only the chart tokens of `app.css` were committed; its two M18 color changes remain uncommitted with the rest of M18.

## Owner request — periods, classes, subjects and instructors shown together (2026-10-01, Claude Code)

- The owner asked that instructors, classes, subjects and academic periods be linked "since they go together". The data model already links them (Academic Period → Class/Batch → Class Subject offering → Instructor Assignment; Subject is a reusable catalog entry), so this change makes the linkage visible and navigable. No schema change.
- New **period page** `GET /academic-periods/{id}` (`AcademicPeriodController::show`, `staff/academic-periods/show.tsx`, permission `academic_periods.manage`): totals (classes, subjects, instructors, candidates), thresholds, a warning when subjects have no instructor, and per class its subjects with assigned instructors (links to the class and instructor pages when the user may manage them).
- **Academic Periods list:** the period name and a "View" action open the period page. **Class page:** "Academic period: …" links to the period page (`can.viewPeriod`). **Subjects list:** new "Taught In {active period}" column with the classes taking the subject this period and their instructors (or a "No instructor" badge); the old count column is now "All Classes".
- Test: `tests/Feature/Academic/AcademicStructureTest.php` (period page payload and totals, instructors forbidden, subjects `taughtIn`, class page period link). Browser-checked as `admin`.
- The commit includes the small M18 terminology edits (`terms.classBatch` labels) already present in `academic-periods/index.tsx` and `subjects/index.tsx`; the rest of the M18 work stays uncommitted.

## Owner request — client demo data set (2026-10-01, Claude Code)

- New `database/seeders/ClientDemoSeeder.php` (run explicitly; not in `DatabaseSeeder`, so tests and `migrate:fresh --seed` are unchanged): 1 Super Administrator `admin`, instructors `instructor1` / `instructor2`, 20 candidates `student01`…`student20` (candidate number = username), period "First Semester 2026-2027" (active), Subject 1 and Subject 2 in "Class A", one instructor per subject. Passwords are the literal `password` and `password_change_required` is false. Calls `DemoGradingSeeder` (weights, 75/80, finalized scores), then per subject 5 questions and a published "Online Quiz 1 — Subject N" (20 minutes, 3 attempts, results released, open 7 days from seeding); Subject 1's questions show a generated image and one choice image.
- Result on the local database: 20 monitored candidates — 7 Passing, 4 At Risk, 8 Failing, 1 Incomplete. Browser-checked sign-in for admin, both instructors and students; student home lists both quizzes.
- The presentation deck (claude.ai artifact "Academic Monitoring System — User Guide by Role") was updated to these accounts. The quizzes close 7 days after seeding; re-seed or create new quizzes after that.

## Owner request — "Powered by" credit on the sign-in page (2026-10-01, Claude Code, `3d323a7`)

- The owner's placeholder **ServLife Solutions** logo is shown small (about 45 × 32 px) under the sign-in form, after the password note, with the text "Powered by", separated by a thin rule.
- Asset `public/branding/powered-by-logo.png` (136 × 96 px, resized from the owner's image). Config `institution.powered_by_name` / `powered_by_logo_url` from `POWERED_BY_NAME` (default "ServLife Solutions") and `POWERED_BY_LOGO_URL` (default the asset); an **empty name hides the credit**; without a logo URL the name is shown as text. Shared as `app.poweredBy` (`HandleInertiaRequests`, `resources/js/types/index.ts`); rendered in `resources/js/pages/auth/login.tsx`. `.env.example` documents both. Test: `LoginTest::test_login_page_shows_the_configurable_powered_by_credit`. Browser-checked on the local server.
- It is a placeholder: replace or hide it through `.env` once the final credit is decided. The Hostinger trial site does not have it yet.

## Milestone 17 — Security Hardening (2026-10-01, Claude Code)

Four read-only reviews (authentication/sessions/headers; staff authorization; candidate portal and exam confidentiality; validation/uploads/injection) found no leak of answer keys, other candidates' data, or unreleased scores. Fixed:

- **High:** array input (`name[]=x`) in six Form Requests caused a 500 before validation, including `POST /login` (`App\Http\Requests\Concerns\NormalizesTextInput` trims strings only).
- **High:** without back navigation, a save resent after a lost response was refused ("cannot return"), and the tablet's queue then blocked every later save and the submission. `CandidateAttemptService::save` now recognises the resend before the navigation rule; the tablet drops a queued write the server refuses (422) and reloads the server's answers.
- Every `{examination}` and `{attempt}` route checks its policy in route middleware, before validation (another instructor's exam used to get a validation redirect instead of 403). The exam question picker also requires `question_bank.manage`.
- **Rate limits:** named limiters (`AppServiceProvider::configureRateLimiting`). Plain `throttle:N,M` keyed only on the user, so all such routes shared one counter (screen-leave reports consumed the PDF download limit). Limits: exam writes 240/min, focus 120/min, starts 10/min per exam (access-code guessing), record PDFs 10/min, media upload/CSV import 30/min, password change 6/min; sign-in also 60 failures/min per address across usernames.
- **Access codes:** at least 4 characters; the attempt limit is checked before the code; wrong codes are audited (`examination.access_code_rejected`, never the code).
- **Failed sign-ins** store the typed username only when it is an account's (it may be a password typed in the wrong field).
- **Staff passwords set by an administrator** (new account or reset) must be replaced at the next sign-in (`users.password_change_required`, migration `2026_10_01_000500`, applied locally; middleware `password.current` on the staff area). Candidates are excluded pending the owner decision on shared-tablet passwords.
- **Headers:** Content-Security-Policy (scripts only from the server, Vite nonce; relaxed for `npm run dev`), HSTS on HTTPS requests, `no-store` on every signed-in response.
- **Tablet recovery data** (IndexedDB) is stamped with the candidate and read back only for them, expires after 24 hours, and fully synced records are removed at sign-in and sign-out. Unsynced answers are kept for their owner.
- Uploads: question images at most 8000 px per side / 40 MP. CSV import skips blank lines and stops reading at 501 rows; unknown-column lists are capped. Report page numbers cannot overflow. Laravel's private-disk `storage/{path}` route is disabled (`serve => false`). Focus reports and media check eligibility and close overdue attempts.
- Tests: `tests/Feature/Security/RouteAccessMatrixTest.php` (every registered route × guest, candidate, instructor, both administrators, against another instructor's records — new routes are covered automatically), `InputHardeningTest.php`, `AccountHardeningTest.php`, `tests/Feature/Examinations/Delivery/ExamHardeningTest.php`. Full suite 1,241 passing. Browser: CSP served with nonces and no console errors on staff pages, portal, and an attempt; recovery records stamped and cleared after submission (this used one attempt of candidate `2026-0001` on "Demo Image Quiz", now submitted).
- **Not changed (owner decisions / later):** an Academic Administrator can still reset an instructor's or candidate's password and sign in as them (audited; forced change makes it visible to the owner at next sign-in); essay regrades after gradebook posting do not change the posted grade (by design, documented); former instructors keep teaching and question bank access after a period ends; choice ids are stable, so shuffled choice order can be reconstructed with developer tools (per-attempt choice ids would need a scoring change).

## Owner request — exam content limits lifted (2026-10-01, Claude Code)

The owner asked for the four "exam content limits": images in answer choices, a random subset per attempt, question import, and item analysis. All four are implemented; see `docs/question-bank-examination-contract.md` for the rules.

- **Choice images:** `question_media.question_choice_id` (migration `2026_10_01_000300`, applied locally): nullable FK to the choice, composite FK so the choice belongs to the same question, CHECKs (choice images have no position and are images only), one image per choice (unique). `Question::media()` is question-level only; `allMedia()` covers both; `QuestionChoice::image()`. Upload form "Show with" (question or Choice A–F). Removing choices, or changing the type away from multiple choice, deletes their images and files; duplication maps images by choice position; locked questions cannot change. Candidates receive `choices[].image` (no URL/name) and load it through the attempt media route, which now also allows delivered choice images. Fixed during tests: scoped `{medium}` route binding only searched question-level media, so choice images could not be removed (`Question::resolveChildRouteBinding`).
- **Random subsets:** `examinations.question_draw_count` (migration `2026_10_01_000400`, applied locally, CHECK 1–500). Setting "Questions per attempt" on the examination form. Each attempt draws its own selection (`CandidateAttemptService::start`), stored in its delivery/scoring key. Publication rejects a count above the question count, and unequal points when drawing fewer (every candidate has the same maximum). Candidate start page, monitoring, gradebook maximum and the review page use the per-attempt count (`Examination::questionsPerAttempt()`, `attemptMaximumPoints()`). Publication audit adds `questions_per_attempt`.
- **Question CSV import:** `/question-bank/import` (+ `/template`), `QuestionImportController`, `QuestionImportRequest`, `QuestionImportService`, page `staff/question-bank/import.tsx`, "Import Questions" button. One taught subject, ≤ 500 rows, ≤ 1 MB, UTF-8 comma CSV, the question form's rules, all or nothing, created through `QuestionBankService::create`. AGENTS.md §75 and MILESTONES.md now record that CSV **question** import is approved (grades are still entered directly). Browsers cannot re-send a changed file, so the file field clears after errors.
- **Item analysis:** `/examinations/{id}/analysis`, `ItemAnalysisService`, `ExaminationItemAnalysisController`, page `staff/examinations/analysis.tsx`, types `resources/js/types/item-analysis.ts`, "Item analysis" button on the examination page. Authorized like essay grading. Submitted attempts only, latest per candidate by default. Opening it reconciles overdue attempts (as monitoring does).
- Tests: `tests/Feature/QuestionBank/ChoiceImageTest.php` (9), choice-image delivery in `QuestionMediaDeliveryTest`, `tests/Feature/Examinations/Delivery/QuestionSubsetDeliveryTest.php` (7), `tests/Feature/Examinations/Builder/QuestionSubsetSettingsTest.php` (6), `tests/Feature/QuestionBank/QuestionImportTest.php` (67), `tests/Feature/Examinations/ItemAnalysisTest.php` (17, including a real random subset). Shape-pinning tests updated for `image` and `questions_per_attempt`. Full suite 1,206 passing, Pint and strict TypeScript clean. Browser-checked: import page, examination form field, review page line and link, item analysis on the demo quiz, the "Show with" selector. File uploads were not tried in the browser (the tool cannot pick files); they are covered by tests.

## Owner request — landscape, one-page-per-semester PDFs (2026-10-01, Claude Code)

- Both candidate PDFs now follow the owner's reference Certificate of Registration layout, in **letter landscape**, compact (7 pt): masthead with logo, organization, system, title, and Registration/Record No. at the right; "Student General Information" band with three label/value columns; green section rules; compact bordered tables.
- **Certificate of Registration** (formerly "Registration Record"): one page for the current academic period; numbered subjects with code, title, class/section, instructor(s), and a blank instructor's signature column; total; Registration Summary and Candidate's Acknowledgement with candidate and Academic Office signature lines. No fees or payment (not part of this system).
- **Academic Record**: one page per academic period (semester), oldest first, each with the header. The current period shows subject grades, standing and overall standing; previous periods note that grades are calculated for the current class only and list the results recorded then. Quiz/examination results and assessment results sit side by side; a semester with more than 26 rows in a column is printed stacked (continues on the next page) so no row is cut.
- `CandidateProfileRecord` rows gained `period` and `subjectCode` (additive). `CandidatePdfService`: `period`, `periods` grouping, landscape paper, page numbers at the new position. Template split into `resources/views/pdf/partials/record-{subjects,examinations,assessments}.blade.php`.
- Tests: `tests/Feature/Candidates/CandidatePdfLayoutTest.php` (4: one landscape page registration, two semesters = two pages oldest first, no class, long semester stacked); `ResultReleaseTest` PDF check now ignores the stylesheet. Full suite 1,099 passing. Sample PDFs generated from local data were checked (792 × 612 pt, one page each).
- Follow-up fix `102b8b5`: the masthead no longer has a fixed height, and the green information band keeps about 11 pt of space below the title (the owner reported they touched).
- No PDF renderer (poppler) is installed on the development machine; the layout was checked from generated page sizes and text positions, and visually by the owner.

## Owner request — images and media in questions (2026-10-01, Claude Code)

- Questions can have up to 4 media files, shown below the prompt: images (JPEG/PNG/WebP/GIF, 5 MB), audio (MP3/M4A/OGG/WAV, 15 MB), video (MP4/WebM, 30 MB). Type detected from file contents; SVG rejected (scripts). Each needs a description (image alt text / caption, max 500).
- Table `question_media` (migration `2026_10_01_000200`, applied locally); model `QuestionMedia` (path hidden); `QuestionMediaService` (add, describe, remove with contiguous positions, `copyAll` on duplicate). Every change locks the question row; locked questions (published) cannot gain/change/lose media. Files on the private local disk `storage/app/private/question-media/{question}/{uuid}.ext`; **include this folder in backups**. Audit records kind/size/count and `changed: [media]`, never file names or descriptions.
- Managed on **Question Bank → Edit → Images and Media** (upload one file at a time with progress, edit description, remove with confirmation). Create page notes media is added after saving.
- Serving: staff `GET /question-media/{id}` (`QuestionPolicy::viewMedia` = teaches the subject, so graders and exam reviewers too); candidates `GET /portal/attempts/{attempt}/media/{id}` only for their own in-progress, unexpired attempt and only media of delivered questions (no row lock, so video range requests do not contend with saves). Responses: inline, `no-store`, `nosniff`, sandboxed CSP; BinaryFileResponse supports range requests.
- `QuestionPresenter::staff()` adds `media` with URL and file name; `forCandidate()` adds `media` without path, name, or URL (the attempt snapshot carries it). Rendered by `QuestionMediaList` on the candidate attempt, staff preview, exam review, question picker (count), and essay grading pages. Contract doc updated.
- Demo: `DemoExaminationSeeder` now also publishes "Demo Image Quiz — Subject 1" (generated shapes image on each question); run locally.
- Tests: `tests/Feature/QuestionBank/QuestionMediaTest.php` (12), `tests/Feature/Examinations/Delivery/QuestionMediaDeliveryTest.php` (7); three shape-pinning tests updated. Full suite 1,095 passing. Browser-verified: candidate image via attempt URL with alt text, exam review images, locked read-only panel, duplicate copies the file, real upload on the local server. A test copy (question 33) was deactivated.
- Not included: image editing/cropping, captions files for video. (Images inside answer choices were added later the same day; see "exam content limits lifted".)

## Owner request — detect leaving the examination screen (2026-10-01, Claude Code)

- While an attempt is in progress, the candidate page reports leaving the screen: page hidden (tab/app switch, minimized browser) at once, or window focus lost for over 1 second (Alt+Tab to another window; brief pop-ups ignored). Reports queue while offline and are sent in order (`keepalive` fetch). The candidate sees a notice on the start page and in the exam header, and a calm alert after returning.
- `POST /portal/attempts/{attempt}/focus` (`event` left|returned, `reason` hidden|blur, `delay_ms`), owner-only (`ExaminationAttemptPolicy::view`), throttled 120/min, ignored once the attempt is not in progress. `ExaminationFocusService` stores server times (client only reports the delay, bounded to 10 minutes and never before the attempt start or previous event), one open departure at a time, max 500 per attempt. Never affects answers, revision or scores.
- Table `examination_focus_events` (migration `2026_10_01_000100`, applied locally): attempt FK cascade, reason CHECK, `left_at` (explicit default — without it MariaDB makes the first TIMESTAMP column `ON UPDATE CURRENT_TIMESTAMP` and overwrote it when the return was saved), `returned_at` CHECK >= left_at.
- Staff: live monitoring has a **Left screen** total and per-candidate "Left N times · duration" with "Away now"; the essay grading page lists every departure. Wording states it is an indicator, not proof. Browsers cannot see other apps or tell why focus was lost.
- Demo: `DemoExaminationSeeder` (not in `DatabaseSeeder`; run explicitly, idempotent) publishes "Demo Quiz — Subject 1" for Sample Batch A. It was run on the local database.
- Also fixed: the administrator dashboard crashed (blank page) formatting finalized timestamps as calendar dates (`9f37685`).
- Tests: `tests/Feature/Examinations/Delivery/FocusEventsTest.php` (13); full suite 1,076 passing. Browser-verified: start-page notice, tab-switch and Alt+Tab departures recorded, 300 ms blip ignored, candidate alert, instructor monitoring counts.

## Component test pass (2026-10-01, Claude Code)

Owner request: test each component after M1–16 and the added features. **Full suite: 1,062 tests, all passing** (`php artisan test`), plus `npm run check` (strict TypeScript + production build) and Pint.

- Existing suite before this pass: 773 tests, 7 failures + 10 errors. The errors were a temporary MariaDB outage (reran green). Failures fixed:
  - Bug: `AuditLogger::sanitize()` turned nested empty arrays into `null` (e.g. previous grading `categories: []`). Now only the top level collapses to null.
  - Stale tests updated to current intended behaviour: staff score comments are no longer copied to the general audit log (they remain in `assessment_score_revisions`); instructor default permissions now include `question_bank.manage`, `examinations.manage`, `reports.view`; portal home props are `summary/available/upcoming/outstanding/recentResults`; `/portal` needs a real candidate record.
- Bug: report search for `"0"` was ignored (`empty()` in `ReportingService`); now compares with `''`.
- New tests (289, none found further bugs): `tests/Feature/Examinations/Builder/` (builder access, drafts, question sync, publication/locking, lifecycle, archive, result release), `Examinations/Delivery/` (eligibility, attempts, autosave/revisions, navigation, deadlines, submission idempotency, objective scoring, `examinations:expire`, live monitoring), `Examinations/Grading/` (essay queue, grading, corrections/history, final results, result release and candidate visibility), `tests/Feature/Reporting/` (administrator dashboard, all 8 reports and print mode, audit history, portal home/profile/photo/PDF edge cases).
- Pint reformatted `app/Models/ExaminationQuestion.php` and migration `2026_09_30_000200_create_examination_tables.php` (formatting only).
- Open questions found while testing (not changed):
  - `docs/question-bank-examination-contract.md` §3 lists Return to Draft, Delete draft and "Archive only after it ends"; the implementation has none of these, and archive is allowed for drafts/open exams without in-progress attempts. Decide which is correct.
  - The examination list has no filters.
  - Dashboard "Candidates in active period" counts classes without subjects, while standing counts don't, so totals can differ.
  - The report period filter returns 403 for out-of-scope or unknown periods instead of ignoring them.
  - Concurrency (row locks) cannot be exercised in single-process PHPUnit; it is covered by stale-version checks only.
- The old worktrees `.worktrees/m7` / `.worktrees/m8`, their branches, and the `academic_system_m7*` / `academic_system_m8*` databases were removed later the same day at the owner's request. The other extra test databases (`r1`, `r2`, `r3`, `w1`… `_testing`) can be dropped.

## Additional owner request — login photograph (2026-09-30)

- Latest photo replacement: owner's `821523531_1440394027981701_8622232413143856063_n.jpg` replaces the original ceremony image, now served as `public/branding/login-training.jpg`. Config/default/example use the new filename to avoid stale browser caching. Updated alt text and portrait framing (3:4 on narrow screens, full-height left panel on desktop). Original file remains recoverable in Git history.
- Replacement verified in the browser; strict TypeScript/production build and PHP syntax check passed. Current replacement preview: `storage/app/login-replacement-preview.png` (ignored).
- Latest owner revision: removed the green login-page background and promotional text. Desktop now has a full-height photograph on the left and the logo/organization/system title above the sign-in form on the right, on white surfaces. Narrow screens keep branding/form first and the photograph below. Missing images fall back to a centered form. This supersedes the original photo-panel layout below.
- Revised layout verification: TypeScript and production build passed; visually checked at 1366px and 390px with no narrow-screen overflow. Current preview: `storage/app/login-layout-preview.png` (ignored).
- Added the owner's supplied `OIP.jpg` as the local asset `public/branding/login-ceremony.jpg`; original image preserved without editing or inventing imagery.
- Login layout now pairs a branded photo panel with the white sign-in form on desktop. On narrower screens, branding precedes the form and the photo follows it so sign-in controls stay easy to reach. Green/yellow theme and existing authentication behavior retained; image failure hides the photo without blocking sign-in.
- Configurable through `LOGIN_IMAGE_URL` / `institution.login_image_url`, with the approved local asset as default and `.env.example` documented. No external image service or new dependency.
- Verification: strict TypeScript/production build, Pint and diff checks passed. Browser-confirmed the local photograph loads at 474 × 316, reviewed desktop (1366px) and narrow (390px) layouts with no horizontal overflow. Ignored preview saved at `storage/app/login-photo-preview.png`. No credentials were changed or login submitted during visual review.

## Additional owner request — green and yellow UI refresh (2026-09-30)

The owner requested a less minimalist interface based on the existing logo's green/yellow colors. Updated the authoritative design direction in `UI_UX_DESIGN.md` accordingly.

- Shared theme: pale green canvas, tinted green panel/table headers, stronger page headers, subtle panel elevation, rounded buttons and a yellow accent-button variant with dark green text. Semantic standing/error colors remain distinct and labelled.
- Staff: deep-green desktop sidebar and tablet drawer, yellow active-item border/icon, green-tinted navigation text and yellow header rule. Existing permission filtering, drawer focus handling and account controls remain intact.
- Candidate: green branded header, larger configured logo, yellow active navigation and welcome panel with candidate/class/period details. Counts and jump links lead to available examinations, upcoming examinations and recent released results. Academic summary cards have clearer hierarchy. The portal uses more available desktop width while retaining the narrower active-examination layout; no home navigation or footer appears during an attempt.
- Sign-in uses the same green backdrop and yellow accent. No external assets, new dependencies, backend/schema edits, new metrics or examination-flow changes. Added candidate skip link and contextual keyboard focus colors.
- Verification: strict TypeScript and production build passed. Browser-reviewed candidate home at desktop and narrow widths, and profile at tablet width; home had no horizontal overflow at 390px or 1366px. Profile navigation and both PDF actions remain present. Saved an ignored visual preview at `storage/app/portal-theme-preview.png`. Staff and sign-in changes were build-checked; their complete role/device acceptance pass and broad regression remain with the owner. M17 Security remains the next planned milestone.

## Additional owner request — student PDF downloads (2026-09-30)

The owner supplied a Certificate of Registration as a visual reference and explicitly chose **both registration and academic records as separate downloads**. Implemented on the administrator candidate profile and the candidate's own My Information page.

- **Registration PDF:** student identity/status, class, academic period, training group, current enrolled subjects and assigned instructor names. **Academic Record PDF:** the same identity header plus current subject grades/standing, examination attempts with only released fully graded scores, and finalized assessment history including recorded previous-class scores. These are system-generated records, not signed certifications or final transcripts; there is no fee, payment or signature workflow.
- Uses the application's configured organization/system name and local raster logo. The supplied reference's personal data, university branding and fees were not copied. Letter portrait layout has repeating table headers, generation time/reference, page numbers and confidentiality footer. Calendar dates retain their day regardless of timezone.
- New `CandidatePdfService`, `CandidatePdfController`, `pdf/candidate-record.blade.php` and shared `RecordDownloads` buttons. Routes: `/candidates/{candidate}/documents/{registration|academic}` and `/portal/profile/documents/{registration|academic}`. Native download links preserve the profile page.
- Full-record export requires staff-area access plus `candidates.view_all`, or portal access plus ownership. Assignment-only instructors cannot export the full record. Portal identity comes from authentication; request IDs cannot choose another candidate. Existing active-account middleware applies. Responses are attachments with private/no-store headers, limited to 10 requests/minute. Download audits contain document type only, never PDF contents, grades or answer data.
- Reuses `CandidateProfileRecord` and the central grade engine. Explicit export pagination includes more than the screen's 15 rows and ignores current page query parameters. Over 1,000 assessments or examination attempts rejects the download with a visible error instead of silently truncating the record. Both administrator and candidate PDFs respect examination result release. Answers, scoring keys, access codes and staff comments are excluded.
- Added pure-PHP `dompdf/dompdf:^3.1`, locked to **3.1.6**. Composer reported no vulnerability advisories after installation. PDF generation needs no public internet, external service or browser binary. Remote resource loading, embedded PHP and PDF JavaScript are disabled; fonts are bundled and PDF/font temporary files use private `storage/framework/cache/pdf`. No schema changes or working-data reset.
- Verification: `CandidatePdfTest` passed (3 tests, 53 assertions), covering both downloads, private headers, minimal auditing, owner/admin authorization, instructor/cross-candidate denial, pagination beyond 15 records and unreleased-score confidentiality. Strict TypeScript and production build passed; Pint and `git diff --check` passed. Visually inspected rendered registration (1 page) and academic (4 pages) synthetic PDFs, including repeated headers and footers. Preview script/data were rolled back and QA artifacts stay ignored in `storage/app/pdf-preview*`. Broad regression/tablet acceptance remains with the owner.
- Next planned milestone remains **M17 — Security**, followed by M18 UI/UX review, M19 testing and M20 demo/deployment documentation. This addition does not mark those milestones complete.

## Current state and next milestone

Implementation has reached **Milestone 16 — Audit Logs and Academic History**. Milestones 0–6 were already complete; M7/M8 have now been reconciled into the main application's existing schema, and M9–16 are implemented. The owner explicitly requested implementation through M16 and will handle the broad test/acceptance pass. This is an implementation status, not a claim that deployment, full regression testing, or tablet QA is complete.

**Next planned milestone: M17 — Security Hardening**, followed by M18 UI/UX QA, M19 testing/stability, M20 demo preparation. Do not add deferred imports, AI, native apps, or unrelated infrastructure. (Since this was written, the full suite was run and extended and further owner additions were made; see "Start here".)

## Additional owner request — exam posting and student home (2026-09-30)

The owner explicitly requested examination-to-gradebook posting and a more useful student home page. Both are implemented; M17 remains the next planned milestone.

- Instructor workflow: Examination → **Post to gradebook** → choose the subject's grading category and assessment title → choose highest or latest submitted attempt → review roster/scores → confirm **Post & Finalize Grades**. Highest uses raw points with the latest attempt as tie-breaker. Raw scores retain the examination's maximum points and use the existing category weights.
- Posting requires an ended or archived examination, released results, no in-progress attempts, and completed grading of all submissions from eligible current-class candidates. At least one graded result is required. Existing gradebook maximum of 9,999.99 points is enforced. Candidates without a submitted result remain missing, never zero. Transferred/withdrawn candidates are excluded and counted in the preview.
- `ExaminationGradebookService` requires both examination access and offering-level `recordGrades` permission. A transaction locks the roster, offering, examination and attempts; a signed preview fingerprint rejects changed scores/roster/category settings. One unique source examination per assessment makes retries idempotent. Foreign keys preserve the source examination and selected attempt for each posted score.
- Posting reuses `AssessmentService` and `ScoreRecordingService`, including score revisions, assessment finalization and audit history. `GradeCalculationService` remains authoritative. A new audit action records posting metadata/counts without answer content.
- Posting is a deliberate snapshot, not continuous synchronization. Later examination regrading requires a separate audited gradebook correction through the existing **Correct Score** workflow. This is explained on the posting page, examination page and posted assessment. Hiding exam results later does not erase or hide already finalized academic grades.
- `/portal` is now **My Home**: academic standing and subject count, open examinations with continue/start/review actions, scheduled published exams, finalized assessments missing a score, and six recent released graded submissions. Exam and missing-score lists are paginated. Dates use the institutional timezone. A missing score is explicitly described as possible outstanding work or pending recording, not proof of overdue work. No invented deadlines or draft assessments are exposed.
- Home queries always derive candidate identity from authentication, scope exams to eligible current-class assignments, and select safe result metadata only. No answer, scoring-key or access-code payload is sent. Unreleased results remain absent; historical released attempts remain accessible to their owner. The existing profile has the complete result/assessment history.
- Applied migration `2026_09_30_001300_link_examinations_to_gradebook` on local MariaDB. No working-data reset occurred.
- Verification: strict TypeScript and production build passed. Four focused PHPUnit tests passed (55 assertions) for posting/rule selection, grade-engine output, duplicate/stale protection, pending/unreleased/invalid-category rejection, authorization and home privacy. An additional rollback HTTP/service smoke passed, including source-attempt traceability, missing-score home entries and upcoming scheduling. Broad regression and physical tablet QA remain with the owner.
- Focused tests: `tests/Feature/Examinations/ExaminationGradebookTest.php`. Ignored rollback smoke: `storage/app/gradebook-home-smoke.php`. Run the focused file with `php vendor/phpunit/phpunit/phpunit tests/Feature/Examinations/ExaminationGradebookTest.php`.

## Additional owner request — candidate information (2026-09-30)

Implemented after M16 at the owner's explicit request. Administrators manage candidate details; candidates have a read-only **My Information** page at `/portal/profile`. This request explicitly authorizes showing candidates their own subjects, assigned instructor names, current grades, academic standing, assessment history and released examination results. It does not authorize other candidates' records or confidential question/answer content.

- Existing administrator Candidates create/edit/show pages now include optional middle name, suffix, training group/section/platoon and profile photo, alongside candidate number, first/last name, class, status and account access. Full account names stay synchronized. Empty optional values can be cleared. Existing deactivation/reactivation and enrollment statuses preserve academic history; no destructive candidate-delete route was added.
- Candidate details show username, account status, last sign-in, created/updated dates. Administrators and authorized instructors also see paginated quiz/examination attempts on the candidate profile; instructor rows remain limited to subjects they teach. Account details stay restricted to candidate managers and the account owner.
- Subjects and instructors derive from class-subject relationships. Grades and standing use `GradeCalculationService`; no editable copies of calculated grades or instructor assignments were added to candidate records.
- Portal assessment history is paginated, includes finalized current-class assessments plus the candidate's own recorded historical results, and excludes staff comments/audit reasons. Exam history is separately paginated and respects `release_results`; no answer, delivery or scoring-key payload is selected or serialized.
- Portal profile and photo endpoints resolve the candidate from the authenticated account; they accept no candidate route ID. Staff photo access uses the existing candidate policy. Candidate navigation is hidden on the active examination screen.
- Photos use private local storage (`storage/app/private/candidate-photos`) and authenticated, no-store responses; JPEG/PNG/WebP only, maximum 2 MB and 4096 × 4096. Replacements/removal clean the former file after transaction commit. Include private photos in future backups. No public storage link or external image service is required.
- Applied additive migration `2026_09_30_001200_add_candidate_profile_fields` locally. No reset or seeder run; existing records keep null optional values until administrators fill them.
- Strict TypeScript/production build and an isolated rollback smoke workflow passed. The workflow checked administrator create/update, optional-field clearing, private photo access/removal, own-profile isolation, candidate write denial, released/unreleased scores, and authenticated page responses. Synthetic records/photos were removed. Focused regression tests were added in `tests/Feature/Candidates/CandidateProfileTest.php` for the owner's test pass; the broad PHPUnit/tablet acceptance suite was not run.
- Reusable ignored smoke script: `storage/app/candidate-profile-smoke.php`. It uses an outer rollback transaction and does not refresh the working database. The temporary testing environment binding only applies inside that script process to exercise HTTP requests without CSRF tokens.

M17 remains the next planned milestone. This addition does not claim that security hardening or deployment acceptance is complete.

## Git and preserved work

- Current checkout: `main`. Previous head: `7a3ce80` (candidate activity), following M9/M10, M11, M12 and M13 commits.
- This session's integration, M14 dashboard, M15 reports and M16 audit/history work is saved in local commits on `main`. Inspect `git status` and `git log` for the latest hashes.
- `.worktrees/m7`, `.worktrees/m8` and branches `m7-question-bank` / `m8-examination-builder` were **removed on 2026-10-01 at the owner's request** (their work was already integrated into `main`). Only `main` exists.
- M7's preserved full controller, request, policy, service/data classes, question form components, pages, presenter additions, types and route file were integrated. Its tests and optional synthetic seeder were also copied, without running the broad suite.
- M8's preserved design was inspected. Its `ExaminationKind` enum was reused. Its complete schema/services cannot be copied wholesale: they use different field names from the schema already consumed by M9–13. Main retains `description`, `attempt_limit`, `passing_score`, `randomize_questions`, `randomize_choices`, `allow_back_navigation` and the existing examination/attempt tables. The builder was completed against that contract. No duplicate question models or tables were introduced.
- Prior history/handoff statements claiming M7/M8 were already finished were inaccurate; this session resolves the identified skeletal pages and broken writes.
- Owner's preference: milestone-sized commits, no push unless asked.

## Runtime

- Laravel 13, PHP at `C:/xampp-new/php/php.exe`, React 19, strict TypeScript, Inertia 3, Vite/Tailwind.
- XAMPP MariaDB 10.4 on localhost:3306, database `academic_system`; `_testing` database for tests. Keep SQL compatible with MySQL 8.4 and MariaDB 10.4. The other MySQL service on port 3308 is not used.
- Local application URL: `http://127.0.0.1:8000`. Start with `php artisan serve` if needed. Assets are bundled locally; no public CDN required.
- Use `php artisan migrate` for pending migrations. Never run `migrate:fresh` on the working database.
- New permissions are added by additive migrations; do not reseed/reset existing roles merely to get new permissions. `AccessControlSeeder` resets system roles to defaults and is mainly for fresh setup/tests.
- Timestamps are stored in UTC. Availability inputs and report/audit date boundaries use `INSTITUTION_TIMEZONE` (local environment was configured Asia/Manila).
- Branding is configurable, with owner-provided institutional assets under `public/branding`; do not invent or replace official marks.
- Examination deadline command: `php artisan examinations:expire`. Registered to run every minute using Laravel's scheduler. For local sessions requiring background expiry, run `php artisan schedule:work`; a deployed server should run `schedule:run` every minute. Candidate requests and staff monitoring also reconcile expired attempts.
- Tablet installation/service workers require a secure context (trusted HTTPS on the LAN, or localhost during development). Full offline startup is not part of the MVP.

## Authoritative architecture

- Permission-based authorization and Laravel policies. Instructors must hold teaching permissions and an assignment to the relevant offering. Administrators have reporting/monitoring access, not blanket question-editing access.
- `GradeCalculationService` is the only academic grade engine. Finalized assessments contribute; missing scores are not treated as zero. Category weights and passing/warning thresholds remain configurable. Dashboard/report aggregators consume grade engine outputs.
- `MonitoringScope` limits all reports to authorized offerings; filters only narrow the scope. Instructor standing summaries cover taught subjects only.
- `QuestionBankService` owns question mutations; the former incomplete `QuestionService` was removed after checking that no callers remained. `QuestionPresenter::summary` excludes choices/answers; `staff` is authorized staff detail; `forCandidate` is an explicit safe whitelist.
- Publication permanently locks question type/prompt/choices through `QuestionLocking`; inactive questions remain usable by existing published exams but cannot be added/published in new drafts. Topic, default points, explanation and activation remain editable.
- `ExaminationService` owns locked transactional draft edits, question synchronization, publication, result-release changes and archival. IDs/status are explicitly assigned rather than silently discarded by mass-assignment guards. Publication requires a duration and valid active questions. An offering containing examinations cannot be removed.
- Stored exam states: draft/published/archived. Published → Active → Ended is derived from availability dates; this avoids stale scheduled status columns. Once published, delivery settings and questions are fixed. Archive preserves history and requires no in-progress attempts.
- `CandidateAttemptService` serializes starts, saves and terminal transitions. Stable delivery/scoring snapshots preserve question order and scoring rules. Server deadline, revision, attempt limit, access code and navigation rules are authoritative.
- `ExaminationScoringService` uses integer hundredths for point sums. MCQ/true-false are scored automatically; essays hold the result pending. Exam scores contribute to academic grades only through the owner-requested instructor-confirmed posting workflow described above; there is no automatic synchronization.
- `ManualEssayGradingService` checks assignment/grade permission, locks attempts, rejects stale versions, bounds scores and requires reasons for corrections. Every change has an append-only revision; completed scores can now be corrected in the UI. Candidate result release remains configurable.
- Audit logs are append-only at model level; no edit/delete routes. `AuditLogger` recursively removes known secret and answer-content fields. Detailed essay feedback stays in restricted grading history, not general audit values. Exam instruction/code edits record change flags rather than their content.

## Implemented features

### M7/M8 reconciliation

- Complete question list/search/subject-topic-type-status filters, create/edit/preview, 2–6 MCQ choices with one correct answer, true/false, essays, topic assignment, duplication and activation/deactivation.
- Full draft examination/quiz create/edit form, subject/class selection, duration, limits, passing score, schedule, optional code, randomization, display/navigation settings and auto-submit.
- Question picker uses the same Question Bank; add/remove, point overrides, ordering and review with correct-answer markers for assigned staff.
- Published lifecycle display, publication confirmation, archive confirmation/reason, result-release controls/reason, accessible links to grading and monitoring.
- Question Bank/Examinations navigation and missing instructor permission grants fixed.

### M9–13

- Candidate exam listing/start/access code, tablet attempt, autosave, timer, answer flags/navigation, submission confirmation, terminal receipt and released result history.
- Manifest/service worker cache public assets only; authenticated question/answer HTML is no-store.
- IndexedDB stores temporary snapshots and ordered pending writes. This session made read-modify-write operations transactional, exposed persistence failures, preserved original revisions on retry, restored unsaved snapshots and flushes the queue before new server saves. Conflicting stale queues are rejected rather than rebased over another tab.
- Activity heartbeat every 45 seconds; it does not change answer revision. Staff monitor refreshes every 15 seconds, displays 25 candidate rows at a time, only queries latest attempts, does not load scoring keys/question payloads, and reconciles overdue attempts. Inactive means no recent server contact, not proof of a lost connection.
- Server scoring/essay grading/result release and correction history are implemented. General audit avoids candidate answer content.

### M14

- Active-period total candidates, standing counts and prioritized problem candidates, recent exams, finalized academic activity, current subject-grade averages and instructor/class coverage.
- Uses real database data and the authoritative grade engine; links into filtered monitoring for problem areas. No decorative statistics or external chart dependencies.

### M15

- `/reports`: candidate standing, class performance, subject performance, at-risk candidates, failing candidates, examination results, quiz results, grade distribution.
- Period/class/subject/search filters; submission date filters for exam/quiz results. Pending scores remain visibly pending. Each attempt is a separate result row.
- Paginated screen and printable view for browser Print/Save PDF. Printing caps at 5,000 rows with an explicit warning to narrow filters; no silent truncation.
- Academic reports are current snapshots over finalized assessments, including marked provisional grades; they are not historical as-of-date reconstructions. Grade-distribution ranges are descriptive, never institutional pass/fail thresholds.
- No spreadsheet import or extra reporting dependency introduced.

### M16

- `/audit-history`: administrator-only, paginated read-only history, actor/action/entity/entity-ID/date filters; timestamp, reason, safe previous/new values.
- Existing account/role, candidate, academic-structure, finalized-grade and threshold audit flows retained. Full M7 question lifecycle auditing integrated. Examination creation/settings/question-list/publication/archive/result-release and essay correction changes are traceable.
- Restricted grading page exposes score and feedback revision history. General audit does not expose answers, scoring keys or feedback.

## New schema in this session

Applied to local MariaDB:

- `2026_09_30_000900_add_examination_kind`: quiz/examination discriminator, default examination, index and CHECK.
- `2026_09_30_001000_add_reporting_permissions`: reports.view and audit_history.view, additive grants.
- `2026_09_30_001100_add_assessment_permissions`: repairs missing question_bank.manage/examinations.manage grants for default instructor role.

Added 2026-10-01 (applied locally):

- `2026_10_01_000100_create_examination_focus_events_table`: departures from the exam screen (see "detect leaving the examination screen").
- `2026_10_01_000200_create_question_media_table`: question images/audio/video (see "images and media in questions").

Earlier applied attempt migrations 000300–000800 remain the authoritative M9–13 schema (delivery/recovery, scoring, essay grades/revisions, last activity). No destructive database reset occurred.

## Verification and limits

- `npm run check` (strict TypeScript plus production build) passed after the integrated pages and recovery fixes.
- Pint applied to changed PHP files. PHP syntax checks, registered routes, and migration status were checked.
- A short synthetic workflow, wrapped in an outer rollback transaction, exercised question creation, draft creation, selection, publication, candidate answering/submission, objective scoring, initial essay grading (80%), correction (100%), result release and monitoring. It passed and generated expected audit entries. No smoke records were retained.
- All eight reports and administrator dashboard generated data. Authenticated HTTP/Inertia responses returned 200 for dashboard, reports/print, audit history, question list/create/preview/edit, examination list/create/settings/question selection/review, grading queue and essay review. Candidate access to all staff modules and instructor access to administrative audit history were denied as expected; deadline reconciliation also passed.
- The reusable local scratch smoke script is ignored at `storage/app/m14-16-smoke.php`; it is not a substitute for the owner's regression suite. Earlier broad suite results predate these changes and must not be presented as current.
- Restored M7 tests are under `tests/Feature/QuestionBank`; they were not broadly run this session. Optional `DemoQuestionBankSeeder` was integrated but is not automatically executed against existing data.
- Superseded on 2026-10-01: the full PHPUnit suite now passes (1,099 tests) and each account type was checked in the browser (see "Component test pass"). Actual tablet PWA installation and physical connection-interruption checks still remain with the owner.

## Follow-up considerations

### Hostinger trial deployment (2026-09-30, live)

- Target: `testwebsitetrial.site`, SSH account `u330835917` on port `65002`. Do not record SSH or database passwords in this file or Git.
- The previous Node.js site is backed up at `/home/u330835917/backups/testwebsitetrial.site/before-academic-20260930-084502.tar.gz` (verified with `gzip -t`). Its old `public_html` and `hbuilds` were removed after successful cutover. Unrelated account databases were preserved.
- Current code and built Vite assets are at `/home/u330835917/domains/testwebsitetrial.site/academic-app`. `composer install --no-dev` succeeded with CLI PHP 8.4.19 at `/opt/alt/php84/usr/bin/php`. The lockfile requires PHP 8.4 even though default CLI/web PHP is 8.3.
- Production `.env` is remote only, mode 600, with a new key, debug off, HTTPS cookies, Inertia history encryption, and dedicated DB `u330835917_academic`. Never copy the local `.env` to the server or log secrets. The full schema migrated and `AccessControlSeeder` ran; no demo accounts were seeded. Laravel config and routes are cached.
- Document root `/home/u330835917/domains/testwebsitetrial.site/public_html` contains only public assets and a front controller pointing to sibling `academic-app`. Its `.htaccess` selects Hostinger's `application/x-lsphp84` handler. Public storage links to `academic-app/storage/app/public`. HTTPS redirects correctly, `/login` and `/up` return 200, `/.env` returns 403, and an authenticated superadmin dashboard rendered in the browser. Browser login has the initial `admin` account and a unique generated password disclosed only to the owner; rotate it after sign-in. The old ServLife site briefly persisted in Hostinger CDN cache; development mode was enabled and cache flush requested. Confirm CDN remains bypassed or fully purged after development mode expires.
- **Operational follow-up:** Hostinger still labels this site a Node.js web app connected to the old ServLife GitHub repository with auto-deployment on. Avoid Redeploy or pushing that repository; it may recreate/overwrite files. Convert the site's hPanel platform to Custom PHP/HTML (with a fresh backup and domain/database checks) or disable/disconnect the old auto-deployment when the control becomes available. Hostinger's SSH account aliases `crontab` to read-only output and the Node-site sidebar exposes no Cron Jobs item, so the every-minute Laravel `schedule:run` job was **not installed**. Candidate/staff requests reconcile expired attempts, but unattended timely expiry needs the cron job. Use PHP 8.4 binary `/opt/alt/php84/usr/bin/php` and app path above when setting it up. This is a trial deployment, not approval for real sensitive records.
- At the owner's request, the live database also has synthetic active `instructor` and `student` accounts with their requested shared demonstration password (not recorded here). `instructor` has the instructor role but no teaching assignment. `student` has a linked candidate record (`candidate_number=student`) but no class/batch; therefore no exams or grades appear until assignments are configured. These were created through the application services, preserving audit entries. Replace the weak shared password before using the site with real records.

- Finish the owner's acceptance pass, especially multi-tab/offline queue conflicts, expiry while disconnected, tablet storage failure, print layout and concurrent grading.
- Ensure the deployment scheduler actually runs; registering a scheduled command alone does not run it.
- Audit model guards protect normal application writes, not unrestricted database administrators; use appropriate production DB privileges/backup controls in M17.
- Review access-code storage, production sessions/rate limits and sensitive browser history in the planned M17 security pass. Do not claim production readiness from implementation completion.
- `php artisan serve` is a development server; hundreds of tablets require an appropriate PHP web server.
- Class/subject removal is restricted when academic/exam history depends on it. Existing inactive accounts and historical assignments are intentionally retained.
- The Hostinger trial deployment predates the 2026-10-01 changes (component tests, dashboard fix, leave-screen detection, question media, landscape PDFs). Deploying them needs `composer install --no-dev`, `php artisan migrate` (two new migrations), `npm run build`, and backups that include `storage/app/private/question-media` and `storage/app/private/candidate-photos`.

## Milestone 6 review findings deferred by the owner (2026-09-30)

Confirmed by the Milestone 6 review but not fixed (the owner asked to move on). Not re-checked after the M7–M16 changes; verify before fixing. All low except the first (medium).

1. `CandidateAcademicRecord::recentActivity()`: "Result finalized" entries show the current (corrected) score instead of the score at finalization.
2. Comment-only corrections of finalized scores appear as score corrections ("6.00 → 6.00").
3. Dashboard Academic Alerts links open /monitoring in the all-candidates scope for custom roles holding `candidates.view_all` + `classes.teach`.
4. /monitoring echoes `standing` as active when the period has no thresholds.
5. /monitoring says "No standings yet for the 0 monitored candidates" when nobody is monitored.
6. Standing count links: small tap targets, no visible link affordance on touch, current card marked by border colour only.
7. Class and Lowest Grade columns hidden below 1280px although the list may be sorted by lowest grade.
8. "View candidates" links under Subjects Requiring Attention keep the current search.
9. Withdrawn candidates: the Recent Academic Activity limit is applied before filtering, so it can say "No academic activity yet".
10. Some Milestone 6 copy hardcodes "class" instead of `terms.classBatch`.
11. The empty monitoring scope shows the administrator empty-state text.
12. Academic Alerts renders "0 failing, 0 at risk" as links to empty lists.

Small UI notes from the 2026-10-01 browser check: an archived exam's page still says "Review delivery settings and questions before publishing"; exam dates show as `30/09/2026, 06:57:15` while the rest of the app uses `Sep 29, 2026`.

## Requirements still needing confirmation

Do not hardcode these until the owner confirms:

- production database server (MySQL or MariaDB) and version; internal deployment hostname and HTTPS certificate;
- actual subject names, Class / Batch terminology, candidate identifier format, enrollment statuses, number of academic periods;
- official grading formula, passing and warning grades (demo 75 / 80), missing-score policy (never zero), whether corrections need administrator approval, whether grading locks when a period ends;
- overall standing rule (implemented: most serious subject standing), Incomplete vs At Risk/Failing precedence, standing for provisional grades and withdrawn candidates, whether thresholds may be cleared;
- administrator access to the question bank and examinations (none by default); whether former instructors of a subject keep question bank access (currently yes, any-period assignment);
- whether Academic Administrators may create Academic Administrator accounts or teach;
- candidate password rules on shared tablets; session timeout during examinations; exam navigation restrictions and when candidates see scores;
- examination lifecycle transitions (see "Start here"), examination list filters;
- leave-screen detection policy: it is an indicator only (browsers cannot see other apps); stronger prevention needs tablet kiosk mode (device management), not the web app;
- military fitness: the official events and standards (the seeded ones are placeholders), whether standards differ by age or sex (would need birth date and sex on candidate records), the scoring rule (implemented: 60 points at passing, 100 at maximum, any failed event fails), and who records results (implemented: administrators);
- Statement of Account: the category list (seeded defaults, editable), currency (default PHP), who records entries (implemented: Finance Officer and Super Administrator) and who may only view (implemented: Academic Administrator), whether candidates should ever see their own statement (decided for now: no);
- registration PDF fields the system does not store (units, section codes, schedule/room, fees);
- brand colours.
