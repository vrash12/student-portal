# Demonstration Script (Milestone 20)

A step-by-step guide for presenting the prototype. All data is synthetic (`ClientDemoSeeder`): Class A, Subjects 1–2, instructors Ramon S. Estrada and Liza M. Tan, and twenty candidates with fictional Filipino names and illustrated profile pictures. Nothing needs public internet access: fonts, images, icons and scripts are served by the application itself.

## Accounts

Every demo account uses the password `password`.

| Username | Role | Used for |
| --- | --- | --- |
| `admin` | Admin | Dashboard, monitoring, candidates, classes, qualification, records, audit history |
| `instructor1` | Instructor, Subject 1 | Gradebook, question bank, examination builder, live monitoring, essay grading |
| `instructor2` | Instructor, Subject 2 | Second instructor (shows that each instructor sees only their own subject) |
| `student01` … `student20` | Candidates, Class A | Tablet examination, own grades, performance |

## Before the demonstration

1. Start XAMPP **MySQL**, then `php artisan serve --host=0.0.0.0` (tablets on the same network open `http://<server-address>:8000`), and `php artisan schedule:work` in a second window so attempts end on time without anyone opening the page.
2. Run `npm run build` once if the frontend changed since the last build.
3. Make sure `instructor1` still teaches Subject 1: **Classes → Class A → Subject 1 → Assign Instructor** (re-assigned on the local database on 2026-10-02).
4. Sign in once with each account you will show, so the first page load is fast.
5. Optional, recommended: use a **separate demo database** so the live demonstration never touches working data, and can be reset between rehearsals:

   ```bash
   # once: create it (MySQL / phpMyAdmin)
   CREATE DATABASE academic_system_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
   Then, with `DB_DATABASE=academic_system_demo` set for these commands (in PowerShell: `$env:DB_DATABASE='academic_system_demo'`):
   ```bash
   php artisan migrate:fresh --force
   php artisan db:seed --class=AccessControlSeeder --force
   php artisan db:seed --class=ClientDemoSeeder --force
   ```
   and serve the application with the same variable set. `migrate:fresh` deletes everything in that database only; never run it against the working database.

## Flow

Each step lists what to open and what to point out. Roughly 20–25 minutes in total.

### 1. Administrator: academic overview (`admin`)

- **Sign-in page:** institutional design; a wrong password gives one neutral message; sign-in is rate limited and recorded.
- **Dashboard:** monitored candidates and the Passing / At Risk / Failing / Incomplete counts with the distribution bar; *Candidates Requiring Attention*; recent examinations.
- **Academic Monitoring:** filter by standing (e.g. Failing); open a candidate.
- **Candidate profile:** subject grades with standing in words, current warnings ("2 missing scores" — missing scores are never counted as zero), recent assessments, qualification panel, Registration and Academic Record PDFs.
- **Records → Qualification:** performance areas, must-pass rules, Qualified / Pending / Not Qualified and class rank (staff only).
- **Records → Military Fitness → Events and Points** (administrators only): **Edit** an event → *Points table*: each result and its points (e.g. 25 push-ups = 60 points), **Fill the table in steps**, and the passing points; existing tests keep their points.
- Optional: **Classes → Class A** (subjects, grading weights, instructor assignments), **Audit History** (every change with who, when, before and after).

### 2. Instructor: grades (`instructor1`)

- **Dashboard:** my subjects, candidates, academic alerts.
- **My Classes → Class A → Subject 1:** grading components and weights, assessments, the gradebook with each candidate's current grade and standing.
- Open the draft **Midterm Examination → Record Scores**, enter a few scores, **Save Scores**; point out that drafts do not count until **Finalize Assessment**, and that later changes to finalized scores require a reason and keep their history.
- **Records → Military Fitness:** the fitness tests of Class A only (an instructor sees the classes they teach); open a test and record results.

### 3. Examination builder (`instructor1`)

- **Question Bank → Add Question:** numbered steps, live *Candidate view* preview, select the letter of the correct answer, **Save and Add Another**. Mention images/audio/video per question and CSV import.
- **Examinations → Create Examination:** step bar *Settings → Questions → Review and Publish*; title, time limit, attempts, availability, randomization, questions per attempt, access code.
- **Save and Choose Questions:** filter, **Add All Shown**, reorder, points total; **Save and Review**.
- **Publish Examination:** the confirmation explains that questions and settings are then locked.

### 4. Candidate tablet (`student02` on a tablet, or the browser at tablet size)

- **Home:** open examinations as cards with their status; **Examinations**, **My Grades**, **My Performance** pages (own records only, never a rank). Fitness tests are staff only.
- Open the quiz: rules, time limit, attempts; **Start Examination**.
- During the attempt: question list with Answered / Not answered / Flagged icons, *Time Running* (Hide/Show), when the attempt ends, autosave status (*Saving… → Saved*), Flag for review, **Save and Next**.
- Resilience (optional): reload the page (answers and the timer come back); switch off Wi-Fi briefly (*Offline – saved on this device*), answer, switch it back on (*Syncing… → Saved*).
- **Submit Examination:** the confirmation states answered/unanswered/flagged counts; the result page shows the objective score and that essays await review.

### 5. Live monitoring (`instructor1`, while candidates are answering)

- **Examinations → the quiz:** *Participation* refreshes every 15 seconds: Active, Inactive, Submitted, Not started, progress per candidate and the leave-screen indicator. Answers are never shown here.

### 6. Results (`instructor1`, then `admin`)

- Objective items are scored automatically; **Essay Grading** queue → grade an essay with a comment; the final result is calculated.
- **Item Analysis** on the completed *Diagnostic Quiz* (18 submissions): which questions most candidates missed, choice distribution, discrimination.
- **Post to Gradebook** (after the examination ends and results are released) turns the scores into an assessment.
- As `admin`, reopen the candidate profile: the examination result and the updated standing.

## If something goes wrong

| Symptom | Fix |
| --- | --- |
| Blank page or "Server Error" | MySQL stopped: start it in XAMPP and reload. |
| `instructor1` sees "You do not teach any subjects yet" | Re-assign Subject 1 (see *Before the demonstration*, step 3). |
| A candidate cannot start: "You have used all permitted attempts" | Use another `studentNN` account, or reset the demo database. |
| Attempts do not end at the time limit on their own | Start `php artisan schedule:work`; opening the attempt or the monitoring page also ends overdue attempts. |
| Tablet cannot reach the server | Same Wi-Fi network; start the server with `--host=0.0.0.0`; allow PHP through the Windows firewall. |
| Old styles after an update | `npm run build`, then reload the page (Ctrl+F5). |
