# SESSION_HANDOFF.md

Updated 2026-09-30 by Codex. Read this file together with `AGENTS.md`, `UI_UX_DESIGN.md`, and `MILESTONES.md`; inspect Git and the actual code before editing.

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
- Stale worktrees `.worktrees/m7` and `.worktrees/m8` (branches `m7-question-bank`, `m8-examination-builder`) are superseded by `main`; kept untouched per the earlier note. The test databases `academic_system_{m7,m8,r1,r2,r3,w1}_testing` can be dropped.

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

**Next planned milestone: M17 — Security Hardening**, followed by M18 UI/UX QA, M19 testing/stability, M20 demo preparation. Do not add deferred imports, AI, native apps, or unrelated infrastructure.

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
- `.worktrees/m7` and `.worktrees/m8` remain untouched, with their prior uncommitted work preserved. Do not delete, overwrite, or recreate them.
- M7's preserved full controller, request, policy, service/data classes, question form components, pages, presenter additions, types and route file were integrated. Its tests and optional synthetic seeder were also copied, without running the broad suite.
- M8's preserved design was inspected. Its `ExaminationKind` enum was reused. Its complete schema/services cannot be copied wholesale: they use different field names from the schema already consumed by M9–13. Main retains `description`, `attempt_limit`, `passing_score`, `randomize_questions`, `randomize_choices`, `allow_back_navigation` and the existing examination/attempt tables. The builder was completed against that contract. No duplicate question models or tables were introduced.
- Prior history/handoff statements claiming M7/M8 were already finished were inaccurate; this session resolves the identified skeletal pages and broken writes.
- Owner's earlier preference: milestone-sized commits, no push unless asked. No remote push or worktree cleanup in this session.

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

Earlier applied attempt migrations 000300–000800 remain the authoritative M9–13 schema (delivery/recovery, scoring, essay grades/revisions, last activity). No destructive database reset occurred.

## Verification and limits

- `npm run check` (strict TypeScript plus production build) passed after the integrated pages and recovery fixes.
- Pint applied to changed PHP files. PHP syntax checks, registered routes, and migration status were checked.
- A short synthetic workflow, wrapped in an outer rollback transaction, exercised question creation, draft creation, selection, publication, candidate answering/submission, objective scoring, initial essay grading (80%), correction (100%), result release and monitoring. It passed and generated expected audit entries. No smoke records were retained.
- All eight reports and administrator dashboard generated data. Authenticated HTTP/Inertia responses returned 200 for dashboard, reports/print, audit history, question list/create/preview/edit, examination list/create/settings/question selection/review, grading queue and essay review. Candidate access to all staff modules and instructor access to administrative audit history were denied as expected; deadline reconciliation also passed.
- The reusable local scratch smoke script is ignored at `storage/app/m14-16-smoke.php`; it is not a substitute for the owner's regression suite. Earlier broad suite results predate these changes and must not be presented as current.
- Restored M7 tests are under `tests/Feature/QuestionBank`; they were not broadly run this session. Optional `DemoQuestionBankSeeder` was integrated but is not automatically executed against existing data.
- Full PHPUnit, final browser visual QA, actual tablet PWA installation and physical connection-interruption checks remain with the owner, as requested. Earlier sessions exercised a synthetic tablet flow, but that is not verification of all later changes.

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
- Both preserved worktrees remain available for comparison; do not treat their alternative M8 schema as a migration to run.
