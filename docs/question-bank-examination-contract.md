# Question Bank ↔ Examination Contract

Interface between Milestone 7 (Question Bank) and Milestone 8 (Quiz & Examination Builder), agreed before the two milestones were built in parallel (2026-09-30). Later milestones (9 Candidate PWA, 10 Autosave, 11 Scoring, 12 Essay Grading) rely on it too.

The executable part of this contract (schema, models, enum, factories, `QuestionLocking`, `QuestionPresenter`, shared TypeScript types, `QuestionPreview`) is in the repository. Change it only additively; renaming or removing anything listed here needs a coordinated change in every module that uses it.

---

## 1. Ownership

| Area | Owner | Others may |
|---|---|---|
| `questions`, `question_choices`, `question_topics` schema; `Question`, `QuestionChoice`, `QuestionTopic`; `QuestionType`; question factories | Question bank (M7) | read; M8 changes question rows only through `QuestionLocking` |
| `App\Services\QuestionBank\*`, `routes/question-bank.php`, `resources/js/lib/question-bank-routes.ts`, `resources/js/types/question-bank.ts`, `resources/js/components/question-bank/*`, `resources/js/pages/staff/question-bank/*` | M7 | use (read-only) |
| `examinations`, `examination_questions` schema and models, `App\Services\Examinations\*`, `routes/examinations.php`, `resources/js/lib/examination-routes.ts`, `resources/js/types/examinations.ts`, `resources/js/components/examinations/*`, `resources/js/pages/staff/examinations/*` | Examination builder (M8) | use (read-only) |
| Shared files: `Permission`, `SystemRole`, `AuditAction`, `AuditLogger`, `AppServiceProvider` (morph map), `routes/web.php`, `resources/js/lib/{routes,permissions,navigation,url}.ts`, `resources/js/types/index.ts`, `resources/js/components/ui/*`, `User`, `Subject`, dashboard, `DatabaseSeeder`, `SESSION_HANDOFF.md`, `README.md` | Integration (lead) | request changes; never edit in a module track |

---

## 2. Questions (Milestone 7)

### Data

```text
question_topics   id, subject_id FK restrict, name (100), timestamps
                  unique (subject_id, name) (case-insensitive collation), unique (id, subject_id)
questions         id, subject_id FK restrict, question_topic_id nullable, type (30), prompt TEXT, points decimal(5,2),
                  explanation TEXT null (staff only), is_active, locked_at null,
                  created_by FK users, updated_by FK users null, timestamps
                  composite FK (question_topic_id, subject_id) -> question_topics (id, subject_id)
                  CHECK type IN ('multiple_choice','true_false','essay'); CHECK 0 < points <= 100
question_choices  id, question_id FK cascade, position 1..6, text (1000), is_correct, timestamps
                  correct_marker (stored generated: question_id when is_correct) UNIQUE -> at most one correct choice
                  unique (question_id, position); CHECK position BETWEEN 1 AND 6
```

### Rules

- A question belongs to exactly one **Subject**, fixed at creation. The topic is optional and must belong to the same subject.
- Types (`App\Enums\QuestionType`): **Multiple Choice** (`multiple_choice`), **True / False** (`true_false`), **Essay** (`essay`).
  - Multiple Choice: **2–6 choices** (`QuestionType::MIN_CHOICES`, `MAX_CHOICES`), texts required and distinct within the question (trimmed, case-insensitive), **exactly one correct**.
  - True / False: exactly two choices, "True" (position 1) and "False" (position 2), exactly one correct.
  - Essay: no choices; graded manually.
  - The database guarantees at most one correct choice; the service guarantees exactly one and the choice counts.
- Positions are contiguous from 1 and give the display letters A–F (`QuestionChoice::letter()`).
- `points` is the default value when the question is added to an examination; examinations keep their own points per question.
- **Lifecycle: active / inactive. Questions are never deleted.** Inactive questions stay visible in the bank and in every examination that already contains them, but cannot be added to examinations.
- **Locking:** `locked_at` is set when the question is first included in a published examination (`QuestionLocking::lock()`). Locks are permanent. A locked question's **type, prompt, and choices (texts, order, correct answer)** cannot change; its **topic, points, explanation, and active flag** can. To change locked content, duplicate the question and edit the copy.
- Edits and deactivation lock the question row (`SELECT ... FOR UPDATE`) before checking `locked_at`, so they are serialized with publication.
- Questions are created and changed only through `App\Services\QuestionBank\QuestionBankService` (Milestone 7). `updated_by` and `updated_at` record content edits (topic, type, prompt, points, explanation, choices); activation, deactivation, and locking do not change them (they are audited or timestamped separately). Kept choice positions keep their `question_choices.id` when an unlocked question is edited.

### CSV import (added 2026-10-01, owner request)

- `/question-bank/import`: instructors import up to 500 questions (1 MB UTF-8 CSV) into **one subject they teach** (checked server-side). Columns: `type`, `question`, `choice_a`…`choice_f`, `correct`, `points`, `topic`, `explanation`; template at `/question-bank/import/template`.
- Every row is validated with the question form's rules; **all or nothing**: any invalid row imports nothing and lists problems by row and column. Valid files create every question in one transaction through `QuestionBankService::create` (audited per question). Media cannot be imported.

### Authorization

- Permission `question_bank.manage` (Instructor by default), **and** the user teaches the subject: `User::teachesSubject($subjectId)` = active account with `classes.teach` and an instructor assignment to a class subject of that subject, in any academic period. `User::taughtSubjectIds()` lists them.
- Administrators have no question bank access by default. Never authorize by role name.

### Confidentiality

- Correct answers (`question_choices.is_correct`) and explanations are **staff-only**: sent only to users authorized for the subject.
- `QuestionChoice` hides `is_correct` and `correct_marker`; `Question` hides `explanation`. Never serialize these models into a page; use `QuestionPresenter`:
  - `staff(Question)`: shape `StaffQuestion` (TypeScript), includes `isCorrect` and `explanation`. Eager load `QuestionPresenter::STAFF_RELATIONS` for lists.
  - `summary(Question)` (added in Milestone 7): shape `QuestionSummary`, a staff list row: `id`, `subject`, `topic`, `type`, `excerpt` (the prompt on one line, about 200 characters), `points`, `isActive`, `isLocked`. Never choices, correct answers, or the explanation. Eager load `QuestionPresenter::SUMMARY_RELATIONS`.
  - `forCandidate(Question)`: shape `CandidateQuestion`: `id`, `type`, `prompt`, `choices[{id, text, image}]` (`image` is null or a media view), `media[{id, kind, description, mimeType, width, height}]` only (no storage path, file name, or URL).
- Question text, choice text, correct answers, and explanations never go into audit logs, application logs, URLs, notifications, or dashboards. Audit entries record which fields changed and non-sensitive values (type, points, topic, status).

---

## 3. Examinations and their questions (Milestone 8)

- An examination (or quiz) belongs to one **class subject** (`class_subjects.id`: class + subject + academic period), fixed at creation. Authorization: permission `examinations.manage` **and** `User::teachesOffering($classSubjectId)`. Instructors cannot create, change, or publish examinations for other class subjects.
- `examination_questions` references `questions.id` (FK restrict) and stores, per examination: **position** (order, contiguous from 1) and **points** (default: the question's points; 0 < points <= 100). A question appears at most once per examination (unique).
- Only **active** questions of the examination's **subject** can be added. The builder never creates, edits, or copies questions; there is no second question table.
- **Publishing** (one transaction, examination row locked): check readiness, lock the question rows with `QuestionLocking::lockRowsForPublication()`, verify every question is still active and of the subject, then call `QuestionLocking::lock()` and set the status. After publication, the examination's questions, order, points, and settings do not change.
- **Lifecycle** (`Draft → Published → Active → Ended → Archived`): the stored status is `draft`, `published`, or `archived`; **Published / Active / Ended are derived from the availability window** on the server (`opens_at` ≤ now < `closes_at` is Active). No scheduler is needed. Transitions: Publish (draft → published), Return to Draft (only before the window opens), Archive (only after it ends), Delete (drafts only). Only authorized users transition states.
  - **As implemented (checked 2026-10-01):** Publish and Archive exist; Archive requires a reason and no in-progress attempts, but also accepts drafts and open examinations. Return to Draft and Delete Draft are not implemented. Which rule is correct is an open owner decision (see `SESSION_HANDOFF.md`).
- Published examinations are visible only to **eligible candidates**: candidates of the examination's class who are not withdrawn, with an active account. The builder provides this rule (query scope/service) for the candidate portal (Milestone 9).
- Settings are enforced server-side; the browser only displays them.
- **Random subsets** (`examinations.question_draw_count`, added 2026-10-01): null delivers every question; a number N gives each attempt N questions drawn at random (a fresh draw per attempt, kept in examination order unless "Randomize question order" is on). The attempt's `delivery` and `scoring_key` contain only the drawn questions, so scoring, monitoring, grading and item analysis read each attempt's own questions. Publication rejects N greater than the number of questions, and, when N is smaller, questions with different points (every candidate must have the same maximum). `Examination::questionsPerAttempt()` and `attemptMaximumPoints()` give the per-attempt count and maximum (gradebook posting uses the maximum).
- **Item analysis** (`/examinations/{id}/analysis`, `ItemAnalysisService`, added 2026-10-01): aggregate statistics from submitted attempts only, using each attempt's snapshot: difficulty (percent correct, counted per delivery), choice distribution, essay averages, discrimination (upper/lower 27 %, only from 10 attempts with a final score), plain-language flags. Same authorization as essay grading. Never shows which candidate chose what.
---

## 4. Candidate-facing rules (Milestone 9 and later)

- **Media** (`question_media`, added 2026-10-01): up to 4 images (JPEG/PNG/WebP/GIF, 5 MB), audio (MP3/M4A/OGG/WAV, 15 MB) or video (MP4/WebM, 30 MB) per question, each with a required description (image alt text / caption), on the private local disk. Media is question content: locked questions cannot gain, change, or lose media; duplicating copies the files. Answer choices of multiple-choice questions can each show one image (`question_media.question_choice_id`, no position, image kinds only, enforced by constraints); choice images do not count toward the 4 files, follow their choice when choices are shuffled, and are removed with their choice or when the question stops being multiple choice. Staff who teach the subject load it from `/question-media/{id}`; candidates only through `/portal/attempts/{attempt}/media/{id}`, while that attempt is in progress and only for media in its delivered questions. The type is detected from the file contents; SVG is not allowed.
- Build question payloads with `QuestionPresenter::forCandidate()` (plus examination-specific values such as points and order). Never include `is_correct`, `explanation`, `correct_marker`, `locked_at`, or topic/bank metadata.
- "Randomize choices" applies to multiple-choice questions only; True / False keeps True first.
- Answers to objective questions reference `question_choices.id` of a locked question, so they stay valid.

---

## 5. Integration

1. M7 is integrated into `main` first (one commit), then M8 is rebased onto it and integrated (one commit).
2. Navigation (sidebar "Assessments": Examinations, Question Bank), dashboard quick links, demo seeder registration in `DatabaseSeeder`, and documentation are integration changes.
