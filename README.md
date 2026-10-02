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
| [docs/demo-script.md](docs/demo-script.md) | Demonstration script: accounts, pre-demo checklist, the six-step demo flow, troubleshooting |
| [docs/performance-and-qualification.md](docs/performance-and-qualification.md) | Performance areas, overall score, qualification, class rank, merits/demerits and attendance: rules, setup and questions for OCS |

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
| `admin` | Admin |
| `academic.admin` | Academic Administrator |
| `instructor.alpha`, `instructor.bravo` | Instructor |
| `2026-0001` … `2026-0010` | Candidate (candidates sign in with their candidate number) |

### Client demo data set (current local database)

On 2026-10-01 the local database was reset to a small set for client demonstrations (`ClientDemoSeeder`). Every account's password is `password`:

| Username | Role |
| --- | --- |
| `admin` | Admin (Teresita P. Vergara) |
| `instructor1` | Instructor, Subject 1 (Ramon S. Estrada) |
| `instructor2` | Instructor, Subject 2 (Liza M. Tan) |
| `student01` … `student20` | Candidates in Class A (fictional Filipino names, e.g. `student01` Mark Anthony Dizon Villanueva) |

It includes grading weights, passing/warning grades (75/80), finalized scores (a mix of Passing, At Risk, Failing and Incomplete), five questions per subject and one published online quiz per subject, open for 7 days, plus three sample fitness events (placeholder standards) and a "Diagnostic Fitness Test" with synthetic results for Class A (`DemoFitnessSeeder`, also runnable on its own), plus sample expenses (`DemoAccountStatementsSeeder`: four expenses assigned to every class of the active period and a few one-off charges; no payments, since candidates are scholars). To rebuild it on an empty database (this deletes all data):

```bash
php artisan migrate:fresh
php artisan db:seed --class=AccessControlSeeder
php artisan db:seed --class=ClientDemoSeeder
```

The last step of `ClientDemoSeeder` is `DemoPerformanceSeeder` (merits/demerits, attendance and qualification; see [docs/performance-and-qualification.md](docs/performance-and-qualification.md)). It gives `student01`–`student10` Alpha Company and `student11`–`student20` Bravo Company (1st Platoon for the first five of each, 2nd Platoon for the next five), creates the five **placeholder** performance areas (Academic = Subject 1, Military Skills = Subject 2, Physical Fitness, Conduct, Attendance; all must pass), records merits and demerits for Class A, six attendance sessions with Present/Late/Excused/Absent, and a "Midterm Fitness Test" (the latest test with results is the one that counts). With the demo grades, **Records → Qualification** shows 6 Qualified, 3 Pending and 11 Not Qualified candidates, for academic, fitness, conduct or attendance reasons; `student01` sees the result under **My Performance** in the portal. It is safe to run again and only fills what is missing (existing companies/platoons, areas with the same names, recorded conduct and existing sessions are kept), so it can also be added to an existing client demo database:

```bash
php artisan db:seed --class=DemoPerformanceSeeder
```

`ClientDemoSeeder` then runs `DemoActivitySeeder` (ten more questions per subject and a completed "Diagnostic Quiz" held on 14 September 2026: 18 of 20 candidates took it, varied scores, essays graded, results released) and `DemoPeopleSeeder` (fictional Filipino names for the demo accounts and an illustrated profile picture for each candidate; only placeholder names are replaced and uploaded photos are kept; usernames do not change). Both are safe to run again on an existing demo database:

```bash
php artisan db:seed --class=DemoActivitySeeder
php artisan db:seed --class=DemoPeopleSeeder
```

Last, `DemoMedicalSeeder` adds 39 placeholder medical record fields in seven sections (General Information, Medical History, Immunizations, Examinations and Tests, Fitness for Training, Emergency, Medical Staff Notes; see Records → Medical Records → Configure Fields) and **fictional** medical values for `student01`–`student20`; it never runs in production, is safe to run again, and only fills empty values:

```bash
php artisan db:seed --class=DemoMedicalSeeder
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

- Sign-in background: the owner-supplied campus image is bundled as `public/branding/login-campus.jpg` (its header, footer and drawn-in sign-in card were removed; the motto is part of the image, so `LOGIN_MOTTO` is empty). The earlier generated scene `login-background.png` is kept. Set `LOGIN_IMAGE_URL` in `.env` to use another local image, or leave it explicitly empty for a plain green background. The card is on the right on wide screens and centered on tablets/phones; the school logo and names still come from branding configuration. Run `php artisan config:clear` if configuration is cached.
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
The portal pages are Home (`/portal`), Examinations (`/portal/examinations`), My Grades
(`/portal/grades`), My Performance (`/portal/performance`: qualification, areas,
merits/demerits, attendance) and My Information (`/portal/profile`). Each shows only the
signed-in candidate's own records; class rank is never shown to candidates. Military fitness
is staff only (owner decision 2026-10-02): the Physical Fitness page (`/portal/fitness`) and
its Home tile appear only with `PORTAL_SHOW_FITNESS=true`.

Military fitness (staff): administrators see every class and set the events under **Military
Fitness → Events and Points**; instructors see, create and record the fitness tests of the
classes they teach. On **My Performance**, candidates do not see the fitness area either: a
failed or pending fitness requirement is named only as a "staff-assessed requirement". An
event is scored with a **points table** (each result and the points it
earns, e.g. 25 push-ups = 60 points, 30 = 70; a result earns the points of the best row it
reaches, and *Fill the table in steps* builds a table quickly) or scaled between a passing and a
maximum standard. Each event has its own passing points (60 by default). Tests keep the points
they were created with, so later changes never alter recorded results.
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
