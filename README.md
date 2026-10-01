# student-portal

Internal **Academic Monitoring, Assessment & Examination System** for administrators, instructors, and candidates taking quizzes and examinations on organization-issued tablets.

The system is designed to run on an internal network (LAN, private Wi-Fi, local server) **without public internet access**. All frontend assets, including fonts, are bundled locally.

## Project documents

| Document | Purpose |
| --- | --- |
| [AGENTS.md](AGENTS.md) | Architecture, engineering, security, and business rules |
| [UI_UX_DESIGN.md](UI_UX_DESIGN.md) | Visual, responsive, accessibility, and interaction standards |
| [MILESTONES.md](MILESTONES.md) | Development order and acceptance criteria |
| [SESSION_HANDOFF.md](SESSION_HANDOFF.md) | Current implementation state for the next development session |

## Stack

- Laravel 13 (PHP 8.3+)
- React 19 + TypeScript, Inertia.js v3, Vite 8, Tailwind CSS v4
- MySQL 8.4+ or MariaDB (local development uses XAMPP's MySQL, which is MariaDB 10.4)

## Local setup (Windows + XAMPP)

Requirements: PHP 8.3+ with `pdo_mysql`, Composer 2, Node.js 22 or newer, and XAMPP.

1. Start **MySQL** in the XAMPP Control Panel.
2. Create the application and test databases (phpMyAdmin, or `C:\xampp-new\mysql\bin\mysql.exe -u root`):

   ```sql
   CREATE DATABASE academic_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE DATABASE academic_system_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

3. Install and configure:

   ```bash
   composer install
   copy .env.example .env
   php artisan key:generate
   php artisan migrate --seed
   npm install
   npm run build
   ```

   `.env.example` matches XAMPP defaults (`root`, no password, port 3306).

4. Start the application:

   ```bash
   php artisan serve
   ```

   Open http://localhost:8000. During frontend development run `npm run dev` alongside it.

## Demo accounts (local development only)

The demo seeders create clearly fictional data: staff accounts, one active academic period, Subjects 1–4, two sample batches with instructor assignments, ten candidates, and demo grading data (demo passing and warning grades of 75 and 80 for the demo period, a sample grading setup for each taught subject, two finalized assessments, one partly scored draft, and one upcoming examination). The demo weights and thresholds are sample data only; real grading rules are set by administrators in the application (Academic Periods → Thresholds, and Classes → subject → Grading Setup). All accounts use the password `password` unless `DEMO_ACCOUNT_PASSWORD` is set in `.env` before seeding. The demo seeders refuse to run in production.

| Username | Role |
| --- | --- |
| `admin` | Super Administrator |
| `academic.admin` | Academic Administrator |
| `instructor.alpha`, `instructor.bravo` | Instructor |
| `2026-0001` … `2026-0010` | Candidate (candidates sign in with their candidate number) |

### Client demo data set (current local database)

On 2026-10-01 the local database was reset to a small set for client demonstrations (`ClientDemoSeeder`). Every account's password is `password`:

| Username | Role |
| --- | --- |
| `admin` | Super Administrator |
| `instructor1` | Instructor, Subject 1 |
| `instructor2` | Instructor, Subject 2 |
| `student01` … `student20` | Candidates in Class A |

It includes grading weights, passing/warning grades (75/80), finalized scores (a mix of Passing, At Risk, Failing and Incomplete), five questions per subject and one published online quiz per subject, open for 7 days, plus three sample fitness events (placeholder standards) and a "Diagnostic Fitness Test" with synthetic results for Class A (`DemoFitnessSeeder`, also runnable on its own). To rebuild it on an empty database (this deletes all data):

```bash
php artisan migrate:fresh
php artisan db:seed --class=AccessControlSeeder
php artisan db:seed --class=ClientDemoSeeder
```

## Trying an online quiz (local development only)

```bash
php artisan db:seed --class=DemoExaminationSeeder
```

This publishes **Demo Quiz — Subject 1** and **Demo Image Quiz — Subject 1** (every question shows an image) for Sample Batch A (open for 7 days, 20 minutes, 3 attempts, results released). Sign in as `2026-0001` to `2026-0005`, open it from **My Home**, and start it. Sign in as `instructor.alpha` and open **Examinations → Demo Quiz** to watch live participation, including how often each candidate left the examination screen, and **Essay grading** to grade the essay. To build your own, sign in as `instructor.alpha`: **Question Bank → Add Question**, then add images, audio, or video under **Edit → Images and Media**, then **Examinations → Create**, select questions, set the time limit and availability, review, and publish.

Other question and examination tools (instructors):

- **Question Bank → Import Questions**: add many questions to one subject from a UTF-8 CSV file (download the template on that page). If any row has a problem, nothing is imported and the problems are listed by row.
- **Images in answer choices**: on a multiple-choice question's **Edit → Images and Media**, choose a choice under **Show with**.
- **Questions per attempt** (examination settings): give each attempt a random selection, for example 20 of 50 questions. All questions must then have equal points.
- **Item analysis** (on a published examination): which questions most candidates missed, how often each choice was chosen, and discrimination (from 10 scored attempts).

## Common commands

| Command | Purpose |
| --- | --- |
| `php artisan test` | Run the test suite against `academic_system_testing` |
| `npm run types` | TypeScript type check |
| `npm run build` | Production frontend build |
| `vendor/bin/pint` | Format PHP code (Laravel style) |
| `php artisan db:seed --class=AccessControlSeeder` | Re-sync permissions and system roles (safe in production) |

The test suite refuses to run against any database whose name does not end in `_testing`, so it can never wipe development data.

## Internal deployment notes

- Set `APP_ENV=production` and `APP_DEBUG=false`. Error pages then never show stack traces.
- Use a dedicated database account limited to this application's database. Never use `root` in production.
- Serve the application over HTTPS on the internal network. Secure cookies (`SESSION_SECURE_COOKIE=true`), browser-history encryption (`INERTIA_ENCRYPT_HISTORY=true`), and the tablet PWA all require a secure context.
- Prefer an internal hostname (for example `academic-system.local`) over a raw IP address.
- The application sends a Content-Security-Policy and, on HTTPS requests, `Strict-Transport-Security`. Do not add a second, conflicting policy in the web server.
- Staff accounts created or reset by an administrator must choose their own password at the next sign-in.
- Never commit `.env` or credentials. Keep `.env.example` current.
- Back up the database together with `storage/app/private` (question images/audio/video and candidate photos). See `docs/examination-operations.md`.

## Candidate examination PWA (Milestone 9)

Candidate routes live under `/portal` and require the candidate portal permission.
A published examination must have a positive duration and questions before a candidate
can start. Candidates see only their assigned class's currently available examinations.
The attempt screen supports MCQ, true/false, essay, persisted question/choice order,
server deadlines, answer saving, refresh recovery, permitted navigation/review flags,
and an explicit submission confirmation. Submission is transactional and idempotent;
objective items are scored on the server, while essays remain pending manual review.
Instructors can choose whether released results are visible to candidates.

The staff examination area includes an essay-grading queue. Authorized instructors
can review submitted responses, record bounded scores and comments, and inspect
append-only grading history. Final results remain pending until every essay is graded.

The examination review page also includes a lightweight live monitor. It polls every
15 seconds and shows participation and progress counts without exposing candidate
answers.

The PWA uses `/portal.webmanifest` and `/portal-sw.js`. For installation on real LAN
tablets, serve the application over trusted HTTPS; localhost development also works.
Assets are served locally. The worker caches public build assets and an offline fallback,
never authenticated pages, examination content, or answers. Full offline answer recovery
and IndexedDB synchronization are Milestone 10 work. Keep a disconnected attempt open
until the connection returns; unsaved answers in memory are not reported as server-saved.

Validation: `php artisan test --filter=CandidateExaminationTest`, `npm run types`,
`npm run build`, and `php vendor/bin/pint --dirty`.

## Autosave and connection recovery (Milestone 10)

Candidate answers are saved automatically to the server. During a short network interruption, the active attempt stores a temporary snapshot and ordered pending writes in IndexedDB on the tablet. The screen distinguishes server-saved answers from device-only recovery, resumes the queue on reconnection, and removes temporary recovery data after submission. Authenticated exam pages and answers are never cached by the service worker.
