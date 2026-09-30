# Local examination operations

Run normal migrations and compile local assets before use:

```powershell
php artisan migrate
npm run build
php artisan serve
```

Keep Laravel's scheduler running during examinations. It processes deadlines even when candidates close their browsers or lose the institutional network:

```powershell
php artisan schedule:work
```

For a deployed server, configure the operating system's scheduler to invoke `php artisan schedule:run` every minute from the application directory. `php artisan examinations:expire` performs one reconciliation pass. Deadline processing uses the same transactional submission/scoring service as candidate requests. A second pass is safe.

The instructor monitor also reconciles overdue attempts. Inactivity means no recent contact, not confirmed disconnection; reading candidates send a heartbeat every 45 seconds. Browser timers display the fixed server deadline and cannot extend it.

Use trusted HTTPS on actual LAN tablets for service workers, installation and secure sessions. Core assets are bundled locally. Public internet access is not required. IndexedDB protects short interruptions after opening an attempt; it does not provide full offline application startup.

Leaving the examination screen (switching tabs or apps, or another window taking focus for more than a second) is recorded with server times. Instructors see it in live monitoring (Left screen, Away now) and on the essay grading page. It is an indicator only: browsers cannot see other apps or tell why focus was lost, and it never changes answers or scores. For hard prevention, lock the tablets into the browser with the device's kiosk or managed mode.

Question images, audio, and video are stored privately in `storage/app/private/question-media` (candidate photos in `storage/app/private/candidate-photos`). Include both folders in backups together with the database; restoring the database without them leaves questions without their media.

For a demonstration, `php artisan db:seed --class=DemoExaminationSeeder` publishes two quizzes for Sample Batch A (one with an image in every question). It refuses to run in production.

Reports are under Reports in the staff navigation. Choose Printable report, then Print / save PDF. Academic summaries are current snapshots, not historical reconstructions. Exam and quiz report dates use the institutional timezone and report every attempt separately. Audit History is visible only to users with its administrative permission.
