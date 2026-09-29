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

The demo seeders create clearly fictional data: staff accounts, one active academic period, Subjects 1–4, two sample batches with instructor assignments, and ten candidates. All accounts use the password `password` unless `DEMO_ACCOUNT_PASSWORD` is set in `.env` before seeding. The demo seeders refuse to run in production.

| Username | Role |
| --- | --- |
| `admin` | Super Administrator |
| `academic.admin` | Academic Administrator |
| `instructor.alpha`, `instructor.bravo` | Instructor |
| `2026-0001` … `2026-0010` | Candidate (candidates sign in with their candidate number) |

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
- Never commit `.env` or credentials. Keep `.env.example` current.
