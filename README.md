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

Last, `DemoMedicalSeeder` adds 39 placeholder medical record fields in seven sections (General Information, Medical History, Immunizations, Examinations and Tests, Fitness for Training, Emergency, Medical Staff Notes; see Records → Medical Records → Configure Fields) and **fictional** medical values for `student01`–`student20`, then (`DemoMedicalDocumentSeeder`) seven **fictional** uploaded medical documents, each marked as a demo document on its face: `student01` (one accepted, one waiting for review), `student02` (waiting), `student03` (returned with a reason), `student05` (a photo, waiting) and `student06` (two accepted). It never runs in production, is safe to run again, and only fills empty values:

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
- Backups are built in: see **Backups** below. They cover the database together with `storage/app/private` (question images/audio/video, candidate photos and the medical documents candidates upload) and `storage/app/public`.

## Hostinger trial site: automatic deploys

Every push to `main` deploys to the Hostinger trial site (`.github/workflows/deploy-hostinger.yml`). GitHub builds the frontend and sends the release over SSH. On the server `deploy/hostinger/remote-deploy.sh` then:

1. backs up the database (`~/backups/testwebsitetrial.site/db-before-deploy-*.sql.gz`, newest 15 kept);
2. installs the PHP packages;
3. switches to maintenance mode, copies the new code (the live `.env` and `storage/` are kept) and runs migrations;
4. publishes `public/`;
5. rebuilds the caches and comes back up.

The run's page on GitHub (Actions tab) shows each step; a failed deploy makes the run fail, and GitHub emails whoever pushed.

- **Set up once:** the deploy key's private half and the server details are repository secrets (`HOSTINGER_SSH_KEY`, `HOSTINGER_KNOWN_HOSTS`, `HOSTINGER_HOST`, `HOSTINGER_PORT`, `HOSTINGER_USER`), stored with `bash deploy/hostinger/set-github-secrets.sh HOST PORT USER` (GitHub CLI signed in). Until they exist, a run only warns.
- **The deploy key can do nothing but deploy:** on the server its `authorized_keys` line forces `~/deploy/octms-receive.sh` (a copy of `deploy/hostinger/receive.sh`), which takes one release on standard input.
- **Roll back:** Actions → Deploy to Hostinger → Run workflow, with the older commit id. Migrations are not undone; restore the matching database backup if needed.
- **PHP 8.4:** the site's `.htaccess` is `~/domains/testwebsitetrial.site/htaccess-hostinger.conf` (selects PHP 8.4) followed by `public/.htaccess`. Keep that file on the server.
- **Do not use hPanel's Deploy/Redeploy or Git deploy for this site:** it is still registered as a Node.js app linked to an old repository and could overwrite it.
- **Caching:** files under `public/build` change name whenever they change. Logos, backgrounds and icons keep their names, so the app adds a version tag to their addresses (`App\Support\PublicAsset`) and `public/.htaccess` makes browsers check them for a newer copy on every visit. A replaced logo shows at once.

## Backups

Every night (01:00, institution timezone) the system writes **one encrypted file with the whole database and every uploaded file** (`storage/app/private` and `storage/app/public`). Every Sunday at 03:00 the newest backup is **test-restored** into a scratch database and its files are checked. Old backups rotate: 14 daily, 8 weekly (Sundays), 12 monthly (the 1st), 10 manual and 5 safety copies taken before a restore. All values are in `config/backups.php` and `.env`.

**Set up once per server**

1. Choose a long passphrase and set `BACKUP_PASSPHRASE` in `.env`. **Every backup is encrypted with it (AES-256-GCM); without it no backup can be restored.** Keep a written copy in a sealed envelope or safe, away from the server. Backups are refused while it is empty, and changing it later does not re-encrypt older backups (keep the old one while they are kept).
2. Optional: `BACKUP_PATH` (default `storage/backups`; prefer another disk) and `BACKUP_COPY_PATH` (a second folder, e.g. a network share, that receives a copy of every backup).
3. If `mysqldump` and `mysql` are not on the PATH, set `BACKUP_MYSQLDUMP_PATH` and `BACKUP_MYSQL_PATH` (XAMPP: `C:\xampp\mysql\bin\mysqldump.exe` and `...\mysql.exe`). For the weekly restore test the database user needs CREATE and DROP on a scratch database (`BACKUP_VERIFY_DATABASE`, default `<database>_restore_check`).
4. **Run the scheduler every minute.** Nightly backups, the weekly test and the requests from the Backups page are all started by it: a cron entry `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1` on Linux, a Task Scheduler task running `php artisan schedule:run` every minute on Windows, or `php artisan schedule:work` while developing.

**Backups page** (Administration → Backups, Admin role only; permission `backups.manage`): the last backup, the next one, the last restore test, free disk space and warnings (no recent backup, a failed backup or restore test, scheduler not running, passphrase missing, no copy in the second folder, disk almost full); **Back Up Now** and **Test Restore Now**; the list of backups; and the history of every backup, test and restore. The dashboard shows the same warnings to the Admin. Backups cannot be downloaded from the browser: IT copies the files from the server.

**Restore** (replaces the whole system with a backup): on the Backups page choose **Restore**, re-enter your password and type `RESTORE`. Within a minute the scheduler takes a **safety backup**, shows a maintenance page, replaces the database and the uploaded files, runs migrations (so an older backup fits the current version) and brings the system back; everyone is signed out. If anything fails it puts the safety backup back. From the server: `php artisan backups:list`, then `php artisan backups:restore <backup id>`.

**Commands:** `backups:run` (back up now), `backups:verify [id]` (test-restore), `backups:list`, `backups:restore <id>`, `backups:process` (requests from the page; run by the scheduler). Backups, their descriptions, the history (`history.jsonl`) and pending requests live as files in the backup folder, never in the database, so they survive a restore. Every operation is also written to the audit history.

**Restore drill:** a backup that has never been restored is not proven. Besides the weekly automatic test, restore a recent backup on a spare machine a few times a year, with the passphrase from the safe.

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
