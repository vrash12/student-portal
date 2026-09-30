# AGENTS.md

## Project Overview

This repository contains an **internal Academic Monitoring, Assessment, and Examination System** intended for a professional institutional training environment.

The system is designed primarily for:

- Super Administrators
- Academic Administrators
- Instructors
- Candidates / students using organization-issued tablets for examinations and quizzes

The system must support environments where users may have **limited or no public internet access**.

The application should therefore be capable of operating over an organization's:

- Internal LAN
- Private Wi-Fi
- Intranet
- Locally hosted server infrastructure

Do not design the system with the assumption that public internet connectivity is always available.

---

## Required Project Documentation

Before beginning development work, read and follow:

- `AGENTS.md` — architecture, engineering, security, business rules, and system behavior
- `UI_UX_DESIGN.md` — mandatory visual, responsive, accessibility, and interaction standards
- `MILESTONES.md` — development order, milestone scope, and acceptance criteria
- `SESSION_HANDOFF.md` — current implementation state and cross-session continuity

`UI_UX_DESIGN.md` is the authoritative reference for frontend design decisions.

`SESSION_HANDOFF.md` must be updated before ending a development session, when context becomes long, when a context/token-limit warning appears, or whenever partially completed work needs to be continued in another session.

Do not rely on remembering previous chat context. The repository and the handoff file must contain the information required to continue safely.

All four documents must be treated as project-level implementation requirements.

---

# 1. Your Role

Act as a **Senior Full-Stack Software Engineer, Software Architect, UI/UX Engineer, Database Designer, and Security-Conscious Systems Engineer**.

You are expected to make professional engineering decisions independently.

Do not behave like a junior developer waiting for exact implementation instructions.

When implementing a feature:

1. Understand the business requirement.
2. Inspect the existing architecture.
3. Determine the safest and simplest implementation.
4. Consider database implications.
5. Consider authorization and security.
6. Consider tablet usability.
7. Consider internal-network behavior.
8. Consider failure scenarios.
9. Implement the solution cleanly.
10. Test the complete workflow.
11. Refactor when appropriate.

Prefer maintainable, production-quality solutions over quick hacks.

---

# 2. Engineering Philosophy

Follow these principles throughout the project:

- Simplicity over unnecessary complexity.
- Reliability over novelty.
- Security by default.
- Server-authoritative data.
- Strong validation at every boundary.
- Clear separation of concerns.
- Reusable components instead of duplication.
- Explicit business logic instead of hidden magic.
- Tablet usability is a first-class requirement.
- Do not introduce unnecessary dependencies.
- Do not introduce microservices unless there is a demonstrated need.
- Do not over-engineer early versions.
- Never sacrifice data integrity for UI convenience.
- Academic records and examination data must be treated as sensitive institutional information.
- Core functionality must remain usable without public internet access.

Build the system so another senior developer can understand and maintain it.

---

# 3. Preferred Technology Stack

For the current MVP/prototype, keep the technology stack simple.

## Backend

- PHP
- Laravel 13
- Laravel authentication/session mechanisms
- Laravel Policies and Gates

## Frontend

- React 19
- TypeScript
- Inertia.js
- Vite
- Tailwind CSS

## Database

- MySQL 8.4 LTS or newer, or MariaDB
- Local development currently uses XAMPP's MySQL (MariaDB 10.4) on port 3306 with `root` and no password

## Tablet Experience

- Progressive Web App (PWA)
- IndexedDB only where needed for temporary exam-answer recovery

Keep the MVP intentionally simple.

Do not add advanced supporting infrastructure unless a confirmed requirement later makes it necessary.

The MVP should be easy to run locally and easy for another developer to understand.

Avoid unnecessary frontend state-management libraries unless complexity genuinely requires one.

Prefer:

- server-provided state
- local React state
- context where appropriate

before introducing global state libraries.

---

# 4. Database

Primary database:

- MySQL 8.4 LTS or newer, or MariaDB (XAMPP's MariaDB 10.4 is used for local development)

Until the production database server is chosen, keep all SQL compatible with both MySQL 8.4+ and MariaDB 10.4+. Avoid features exclusive to one of them, such as `REGEXP_LIKE`, `JSON_TABLE`, functional indexes, and `SKIP LOCKED`. Use Laravel's `mariadb` connection with MariaDB and `mysql` with MySQL.

Use:

- foreign keys
- database constraints
- indexes
- transactions
- unique constraints
- timestamps
- soft deletion only where logically appropriate

MySQL-specific rules:

- Use the InnoDB storage engine only (transactions, row locking, and foreign keys depend on it).
- Use the `utf8mb4` character set.
- Keep strict SQL mode enabled (Laravel's default).
- Use `CHECK` constraints for important value ranges (enforced since MySQL 8.0.16 and MariaDB 10.2).
- MySQL has no partial indexes. Enforce rules such as "only one active record" with transactions and row locks, or generated columns, instead.
- Do not use native `ENUM` columns. Use string columns with PHP backed enums (plus `CHECK` constraints where valuable) so allowed values can evolve through migrations.

Do not rely exclusively on application-level validation for relational integrity.

Database design must be normalized where practical.

Avoid storing data redundantly unless there is a documented performance or historical reason.

---

# 5. MVP Infrastructure Approach

Keep infrastructure minimal during the current prototype and early-development stage.

The application should initially require only:

```text
Browser / Tablet
       |
Laravel + React Application
       |
MySQL
```

The system may be run:

- on a developer machine during development
- on a simple local server for demonstrations
- on a standard PHP-capable server later

Do not introduce production-scale infrastructure prematurely.

If future requirements become more demanding, the supporting architecture can be expanded later without changing the core domain logic.

---

# 6. Application Architecture

Prefer a Laravel-centered modular architecture.

Conceptually:

```text
React + TypeScript
        |
     Inertia
        |
     Laravel
        |
  +-----+------------------+
  |                        |
Auth                    Services
                           |
                    Grade Engine
                    Exam Engine
                    Risk Engine
                    Reporting
                           |
                         MySQL
```

Keep important business rules in dedicated services or domain classes instead of controllers.

Controllers should remain thin.

---

# 7. Primary System Modules

The platform should support the following major modules:

- Authentication
- User and role management
- Candidate management
- Instructor management
- Classes / batches
- Subjects
- Academic periods
- Grade management
- Academic monitoring
- Question bank
- Quizzes
- Examinations
- Candidate tablet examination interface
- Examination results
- Reporting
- Audit logs

---

# 8. Authentication and Roles

Support authenticated access for different user roles.

Potential roles:

- Super Administrator
- Academic Administrator
- Instructor
- Candidate

Never assume role names alone are sufficient for authorization.

Implement granular permissions.

---

# 9. Administrator Module

Administrators should be able to manage:

- users
- instructors
- candidates
- candidate batches/classes
- academic periods
- subjects
- instructor assignments
- grading rules
- assessment configurations
- system settings
- roles
- permissions
- audit logs

Administration interfaces should prioritize clarity and efficiency.

---

# 10. Instructor Module

Instructor functionality may include:

- assigned classes
- assigned subjects
- candidate lists
- direct grade entry
- assessment management
- examination management
- quiz management
- question-bank management
- academic monitoring
- candidate performance
- reports
- live examination monitoring

An instructor must only have access to records and classes permitted by their assignment and role.

Never expose unrelated candidate or instructor records simply because the URL is known.

---

# 11. Candidate Module

Candidate access is intentionally limited.

The first implementation should primarily support candidate access for:

- quizzes
- examinations
- authorized assessments

Do not automatically expose:

- complete grades
- academic ranking
- administrative information
- other candidates
- instructor information
- internal reports

unless explicitly required.

Candidate access should be optimized for organization-issued tablets.

---

# 12. Academic Structure

The system should be flexible enough to model:

```text
Academic Period
    |
Class / Batch
    |
Subject
    |
Instructor Assignment
    |
Candidates
    |
Assessments
```

Do not hardcode real subject names.

During prototype development, generic names such as:

- Subject 1
- Subject 2
- Subject 3
- Subject 4

may be used.

Subjects must ultimately be configurable through the application.

---

# 13. Candidate Management

Candidate records may contain:

- Candidate number
- Name
- Class / batch
- Academic status
- Assigned subjects
- Assessment records
- Examination results
- Current academic standing

Keep candidate records concise and focused on requirements.

Do not introduce unnecessary personal-data fields.

---

# 14. Grade Management

The system must support flexible grading structures.

Example:

```text
Quiz                20%
Examination         30%
Practical           30%
Other Requirements  20%
                    ----
                    100%
```

Never permanently hardcode percentages.

Grading rules should be configurable by authorized users.

Support:

- assessment weights
- raw scores
- percentage scores
- weighted scores
- final grades
- passing thresholds
- warning thresholds
- incomplete status
- academic standing

For the current version, authorized instructors may encode grades directly through the system.

Do not implement spreadsheet or file-based grade import unless explicitly requested later.

---

# 15. Grade Calculation Engine

Grade calculation logic must be centralized.

Do not calculate grades separately in:

- controllers
- frontend components
- reports
- individual pages

Create a single authoritative grade-calculation service.

For example:

```text
GradeCalculationService
```

It should handle:

- weighted assessments
- missing scores
- grading rules
- final grades
- academic status
- warning thresholds

The backend must always be the authoritative source.

Frontend calculations may only be used for previews.

---

# 16. Academic Risk Monitoring

A major purpose of the system is identifying candidates who may require academic attention.

The system should support states such as:

```text
PASSING
AT RISK
FAILING
INCOMPLETE
```

Never hardcode institutional thresholds without explicit requirements.

Authorized administrators should be able to configure relevant thresholds.

Potential dashboard metrics include:

- total candidates
- passing candidates
- candidates at risk
- failing candidates
- incomplete records
- subject performance
- class performance

Example:

```text
Academic Overview

400 Candidates

342 Passing
38 At Risk
14 Failing
6 Incomplete
```

---

# 17. Candidate Academic Profile

Authorized instructors and administrators should be able to open an individual candidate profile.

A candidate academic profile may contain:

```text
Candidate 0214

Class / Batch:
OCC Class XX

ACADEMIC STANDING

Subject 1        84.50    PASSING
Subject 2        72.40    AT RISK
Subject 3        91.00    PASSING
Subject 4        68.20    FAILING

Recent Assessments

Quiz 1           82%
Quiz 2           74%
Examination      69%

Overall Status:
AT RISK
```

The interface should make academic problems immediately understandable.

---

# 18. Examination and Quiz Module

Support digital examinations and quizzes.

Initial question types should include:

- Multiple Choice
- True / False
- Essay

Architecture should allow additional question types later, including:

- Multiple Response
- Identification
- Matching
- Short Answer

Do not overcomplicate the first implementation.

---

# 19. Question Bank

Questions should be reusable.

Potential structure:

```text
Subject
   |
Topic
   |
Question Bank
   |
Questions
```

A question may contain:

- question text
- question type
- choices
- correct answer
- point value
- subject
- topic/category
- difficulty
- explanation
- status

Support question activation/deactivation instead of destructive deletion when a question has already been used historically.

---

# 20. Examination Creation

An instructor should be able to create an examination or quiz.

Typical workflow:

```text
Create Examination
       |
Select Subject
       |
Select Class / Batch
       |
Add / Select Questions
       |
Configure Examination
       |
Review
       |
Publish
```

Do not allow instructors to publish examinations to classes they are not authorized to manage.

---

# 21. Examination Configuration

An instructor should potentially be able to configure:

- examination title
- subject
- class/batch
- availability period
- duration
- number of attempts
- question count
- passing score
- randomized questions
- randomized answers
- one-question-at-a-time mode
- navigation rules
- access code
- automatic submission

All important examination rules must be enforced server-side.

Never trust browser-side timers or restrictions alone.

---

# 22. Examination Lifecycle

Every examination should have a lifecycle.

Example:

```text
DRAFT
  |
PUBLISHED
  |
ACTIVE
  |
ENDED
  |
ARCHIVED
```

Only authorized users should be able to transition examination states.

---

# 23. Examination Attempts

Every examination attempt should have a clear lifecycle.

Example:

```text
NOT_STARTED
     |
IN_PROGRESS
     |
SUBMITTED
```

Additional states may include:

```text
EXPIRED
AUTO_SUBMITTED
INTERRUPTED
INVALIDATED
```

Store:

- candidate
- examination
- start time
- last activity
- submission time
- attempt number
- score
- status

---

# 24. Tablet Examination Experience

The candidate examination interface must be designed specifically for tablets.

A candidate should be able to:

```text
Open Tablet
     |
Open Examination Application
     |
Authenticate
     |
See Available Examination
     |
Start Examination
     |
Answer Questions
     |
Submit
```

Avoid unnecessary menus and distractions.

---

# 25. Examination Screen

A tablet examination interface might resemble:

```text
SUBJECT 1 - QUIZ 01

Candidate 0214

Question 7 of 30

Time Remaining
00:24:31

Which of the following is correct?

( ) Option A
( ) Option B
(*) Option C
( ) Option D

[ Previous ]            [ Next ]
```

Use:

- large tap targets
- clear typography
- visible question progress
- visible timer
- obvious selected answers
- clear submission workflow

---

# 26. Autosave

Candidate answers must be autosaved.

Never depend exclusively on the final Submit button.

Recommended behavior:

```text
Candidate selects answer
        |
UI updates
        |
Server save
        |
Confirmation
```

Use debouncing or batching where appropriate to avoid excessive requests.

Persist answers frequently enough that a browser refresh or temporary connection interruption does not destroy meaningful work.

---

# 27. Temporary Connection Loss

The application does not need to be completely offline-first unless explicitly required.

However, exam functionality must gracefully handle short network interruptions.

For candidate tablets, consider:

- IndexedDB
- local persistence
- synchronization queues
- connection indicators
- unsynchronized-answer indicators

Never show an answer as safely saved to the server when it exists only locally.

Clearly distinguish:

```text
Saved
Saving...
Offline - stored on device
Sync failed
```

---

# 28. Progressive Web App

The candidate examination interface should be designed as a **Progressive Web App (PWA)**.

Goals:

- installable on tablets
- home-screen icon
- fullscreen/app-like experience
- responsive layout
- touch optimized
- fast startup
- local asset caching
- graceful handling of connection loss

Do not build a separate native mobile application unless future requirements justify it.

---

# 29. Internal Network Compatibility

The system must not assume public internet access.

It should be capable of operating like:

```text
Candidate Tablets
       |
Internal Wi-Fi
       |
Local Network
       |
Application Server
       |
Laravel
       |
MySQL
```

Core system functionality must not require:

- public internet access
- Google APIs
- external authentication services
- public CDNs
- cloud-only databases
- externally hosted frontend assets

unless explicitly approved.

Bundle required frontend assets locally.

---

# 30. Examination Monitoring

For the MVP, do not require specialized real-time infrastructure.

The instructor monitoring screen may use simple periodic refresh/polling where necessary.

It may show:

- candidates currently taking the examination
- candidates who have not started
- submitted candidates
- recently disconnected or inactive candidates
- examination progress
- elapsed time

Example:

```text
LIVE EXAMINATION

400 Candidates

372 Active
18 Submitted
7 Not Started
3 Inactive / Disconnected
```

Keep monitoring reliable and simple.

Do not broadcast candidate answer content.

If a future version requires more sophisticated real-time behavior at larger scale, the architecture can be expanded later.

---

# 31. Exam Scoring

Objective question types may be automatically scored.

Examples:

- Multiple Choice
- True / False

Essay and other subjective questions should be placed into a manual grading queue.

Example:

```text
Examination Complete

Objective Items
42 / 50

Essay Items
Awaiting Instructor Review

Final Score
Pending
```

Exam scores should integrate with the academic grade system only when explicitly configured to do so.

---

# 32. Security

Security is a core system requirement.

Apply:

- authentication
- authorization
- CSRF protection
- input validation
- output escaping
- secure password hashing
- rate limiting
- secure sessions
- permission checks
- query parameter validation
- mass-assignment protection

Never trust:

- hidden form fields
- client-side role checks
- browser timers
- candidate IDs from the frontend
- subject IDs submitted by users

Always verify authorization on the backend.

---

# 33. Sensitive Data

Treat the following as sensitive:

- candidate records
- grades
- examination scores
- examination questions
- candidate answers
- instructor records
- account information
- audit logs

Do not log sensitive data unnecessarily.

Never expose confidential information through frontend debugging output.

Do not place sensitive information in URLs where avoidable.

---

# 34. Authorization

Use Laravel Policies/Gates for resource-level authorization.

An instructor may:

- view assigned classes
- manage grades for assigned subjects
- create examinations for authorized classes
- view authorized candidate records

An instructor must not automatically:

- edit another instructor's grades
- access unrelated classes
- change administrator configuration
- view unauthorized candidate records

Candidate permissions must be significantly more restrictive.

---

# 35. Audit Logging

Important actions must be auditable.

Examples:

- login
- grade modification
- examination creation
- examination modification
- exam publication
- exam invalidation
- role changes
- candidate creation
- candidate record changes

A useful audit entry may contain:

```text
Actor
Action
Entity Type
Entity ID
Timestamp
IP / Client
Previous Value
New Value
Reason
```

Do not record secrets or plaintext passwords.

---

# 36. Grade Change History

Never silently overwrite important academic data.

If an instructor changes a previously recorded or finalized grade, preserve:

- previous value
- new value
- actor
- date/time
- reason

Important academic history must remain traceable.

---

# 37. Database Transactions

Use database transactions for workflows involving multiple dependent writes.

Examples:

- examination submission
- grade finalization
- candidate enrollment
- batch assignment
- assessment creation

A partially completed critical transaction is unacceptable.

---

# 38. Concurrency

Consider concurrent users.

Examples:

- multiple instructors updating records
- hundreds of candidates answering examinations
- multiple candidates submitting simultaneously
- auto-submission occurring at the same time as manual submission

Use:

- transactions
- atomic updates
- locking where appropriate
- idempotent operations

Prevent:

- duplicate submissions
- duplicate attempts
- inconsistent grade calculations

---

# 39. Performance

The system may initially serve hundreds of candidates, but architecture should comfortably support larger deployments.

Prevent common problems such as:

- N+1 queries
- loading entire datasets unnecessarily
- inefficient dashboard calculations
- unpaginated tables
- enormous frontend payloads
- unnecessary polling

Use:

- eager loading
- pagination
- indexed queries
- cached aggregates where justified
- queued jobs where necessary

---

# 40. Heavy Operations

For the MVP, keep heavy operations limited and straightforward.

Avoid building dedicated background-job infrastructure unless a feature genuinely requires it.

If an operation becomes slow, first optimize the implementation before introducing queues or additional infrastructure.

Examples that may be reconsidered later:

- large report generation
- bulk recalculations
- large exports
- high-volume notifications

---

# 41. Reporting

Potential reports include:

- candidate academic standing
- subject performance
- class/batch performance
- assessment results
- examination results
- failing candidate list
- at-risk candidate list
- grade distribution
- instructor/class reports

Reports must derive from authoritative backend data.

---

# 42. Dashboard

The administrator dashboard should provide a useful academic overview rather than decorative statistics.

Potential cards:

```text
400
Total Candidates

342
Passing

38
At Risk

14
Failing
```

Potential additional sections:

- Candidates requiring attention
- Recent examinations
- Subject performance
- Recent activity
- Academic status distribution

Do not fill dashboards with meaningless metrics.

---

# 43. Instructor Dashboard

The instructor dashboard should immediately help an instructor understand their responsibilities.

Potential content:

```text
My Subjects

Subject 1
120 Candidates

Subject 2
98 Candidates

UPCOMING

Quiz 03
Tomorrow

ACADEMIC ALERTS

11 Candidates At Risk
4 Candidates Failing
```

---

# 44. UI/UX Design Direction

The application is for a professional institutional environment.

The UI should feel:

- disciplined
- professional
- modern
- structured
- efficient
- trustworthy

Avoid:

- excessive gradients
- gimmicky animations
- gaming aesthetics
- overly playful colors
- oversized decorative elements
- excessive glassmorphism
- visual clutter

Use restrained institutional styling.

Detailed frontend design rules are defined in `UI_UX_DESIGN.md`.

---

# 45. Branding

Do not permanently hardcode unofficial institutional branding.

Use placeholders/configuration until approved branding assets are provided.

Brand configuration should eventually allow:

- organization name
- system name
- logo
- primary color
- secondary color
- favicon

Never fabricate official government or military insignia.

---

# 46. Responsive Design

The application must work well on:

- desktop
- laptop
- tablet

The candidate examination interface must be optimized specifically for tablets.

Minimum candidate exam requirements:

- large tap targets
- readable typography
- clear selected states
- clear progress
- visible timer
- simple navigation
- minimal distractions

---

# 47. Accessibility

Follow practical accessibility standards.

Use:

- semantic HTML
- labels
- keyboard navigation
- visible focus states
- adequate contrast
- accessible dialogs
- useful error descriptions
- ARIA only when necessary

Do not communicate important states exclusively through color.

Also display text such as:

```text
FAILING
PASSING
AT RISK
```

---

# 48. Forms

Every form must provide:

- clear labels
- validation
- useful validation errors
- loading state
- disabled state where appropriate
- success/error feedback

Prevent accidental double submissions.

---

# 49. Destructive Actions

Require explicit confirmation for destructive operations.

Examples:

- deleting candidate records
- removing subjects
- invalidating examinations
- deleting questions
- removing instructor assignments

Prefer archival or deactivation when historical information depends on the entity.

---

# 50. Error Handling

Handle failures gracefully.

Never expose raw stack traces to production users.

Provide clear messages such as:

```text
The examination could not be submitted because the connection was interrupted. Your saved answers have been preserved.
```

instead of technical database or server errors.

Log technical details server-side.

---

# 51. Testing

Critical functionality should include appropriate automated tests.

Prioritize:

- feature tests
- authorization tests
- business-logic tests
- examination tests
- grade-calculation tests

Critical scenarios include:

```text
Instructor cannot access an unrelated class.

Candidate cannot open an unpublished examination.

Candidate cannot submit an examination twice.

Expired examination follows configured submission rules.

Grade calculation produces the expected result.

Unauthorized user cannot modify academic records.

Candidate cannot view another candidate's records.
```

---

# 52. Grade Engine Testing

Grade-calculation logic must have deterministic tests.

For example:

```text
Quiz:       90 x 20%
Midterm:    80 x 30%
Practical:  95 x 50%
```

The expected result must always be reproducible.

Never rely only on manual testing for important academic calculations.

---

# 53. Exam Engine Testing

Test:

- examination availability
- timer rules
- attempt limits
- autosave
- submission
- expiration
- objective scoring
- manual grading
- randomization
- candidate authorization
- duplicate submissions
- temporary interrupted connections

---

# 54. Code Quality

Use:

- descriptive names
- small focused methods
- typed method signatures
- typed React props
- enums where appropriate
- constants instead of magic strings
- reusable services
- reusable UI components

Avoid meaningless names such as:

```text
$data
$temp
$x
$thing
handleStuff()
processData()
```

when more meaningful names are available.

---

# 55. TypeScript

Use strict TypeScript conventions.

Do not use `any` as a shortcut.

Define types for:

- users
- candidates
- classes
- subjects
- examinations
- questions
- attempts
- grades
- academic standings

Handle nullable values explicitly.

---

# 56. Laravel Conventions

Follow Laravel conventions unless there is a strong reason not to.

Use:

- Form Requests
- Eloquent relationships
- Policies
- Services
- Jobs
- Events
- Listeners
- Notifications where relevant
- Resources where APIs require them

Do not place large amounts of business logic inside controllers.

---

# 57. Migrations

Never manually modify the production database schema.

Use migrations.

Migrations should:

- be reversible when practical
- include indexes
- include constraints
- use appropriate data types

Review the effect of schema changes on existing records.

---

# 58. Seeders and Demo Data

Development/demo environments must use synthetic information.

Never populate development environments with real institutional candidate data unless explicitly authorized and appropriately protected.

Create useful seeders for:

- administrators
- instructors
- sample candidates
- sample classes
- sample subjects
- sample examinations
- sample grades

---

# 59. Sample Identities

During development, use clearly fictional records such as:

```text
Candidate 001
Candidate 002
Instructor Alpha
Instructor Bravo
```

Do not fabricate actual personnel information.

---

# 60. Deployment

For the current MVP, deployment should remain simple.

The application should be able to run on:

- a development machine
- a standard local PHP server environment
- a simple institutional server
- a standard hosted PHP/Laravel environment if needed

Do not require advanced supporting infrastructure for the current version.

The goal is straightforward deployment with as few moving parts as possible.

---

# 61. Local Deployment

The system should support deployment inside the organization's network.

Example:

```text
academic-system.local
```

or an internal IP during initial setup.

A local DNS hostname is preferable to requiring users to remember IP addresses.

HTTPS should still be considered for internal deployments.

---

# 62. Backup Strategy

Academic information must be backed up.

Support an operational strategy covering:

- database backups
- backup retention
- restoration procedures
- restore testing

A backup that has never been tested for restoration should not be considered sufficient.

---

# 63. Logging

Use structured application logging.

Log:

- application failures
- background job failures
- examination processing errors
- synchronization failures
- important administrative actions

Do not expose:

- passwords
- tokens
- candidate answers unnecessarily
- confidential academic data unnecessarily

---

# 64. Configuration

Environment-specific values belong in environment configuration.

Never commit:

- database passwords
- API keys
- production credentials
- private tokens
- certificates
- real `.env` files

Maintain `.env.example`.

---

# 65. API Design

If APIs are introduced, follow RESTful conventions where practical.

For example:

```text
GET    /api/exams
POST   /api/exams
GET    /api/exams/{exam}
PUT    /api/exams/{exam}
DELETE /api/exams/{exam}
```

Use predictable response structures.

Version public or long-lived APIs where necessary.

---

# 66. Native Mobile Application

Do not build a separate native mobile application unless requirements justify it.

The initial candidate experience should use the tablet-optimized PWA.

A native Android application may be considered later if requirements include:

- kiosk enforcement
- Android device-management APIs
- extended offline operation
- hardware integration
- biometric authentication
- stronger device restrictions

Do not prematurely duplicate the frontend codebase.

---

# 67. Future Native Path

If native functionality becomes necessary, preserve architectural separation so the Laravel backend can expose APIs to:

```text
Web Application
PWA
Android Application
Future Mobile Client
```

Core domain logic must not depend entirely on Inertia-specific assumptions.

---

# 68. Multi-Organization Consideration

Although the initial deployment may be for one organization, design core domain logic without unnecessarily hardcoding that organization.

Avoid patterns such as:

```php
if ($organization === 'Specific Organization') {
    // custom logic
}
```

Prefer configurable rules.

This leaves room for the software to evolve into a reusable ServLife product.

Do not implement full multi-tenancy prematurely unless explicitly required.

---

# 69. Feature Development Workflow

Before implementing a significant feature:

1. Inspect existing models.
2. Inspect migrations.
3. Inspect routes.
4. Inspect authorization.
5. Inspect related frontend components.
6. Determine the required data flow.
7. Consider failure states.
8. Implement backend rules.
9. Implement frontend interface.
10. Add tests.
11. Run existing tests.
12. Review security and accessibility.

Never blindly create duplicate models, routes, or services without first inspecting the repository.

---

# 70. Refactoring

Refactor when code becomes:

- duplicated
- confusing
- tightly coupled
- difficult to test
- inconsistent

Do not refactor unrelated areas merely because they could theoretically be improved.

Keep changes focused.

---

# 71. Dependency Policy

Before installing a dependency, determine:

1. Can Laravel or React already handle this?
2. Is the dependency maintained?
3. Is it necessary?
4. Does it introduce security risk?
5. Will it work in internal-network deployments?
6. Does it significantly increase bundle size?

Avoid dependency bloat.

---

# 72. Definition of Done

A feature is not complete merely because the UI appears to work.

A feature is complete when:

- requirements are implemented
- validation exists
- authorization exists
- database integrity is considered
- error handling exists
- loading states exist
- responsive behavior works
- critical tests pass
- security implications are reviewed
- accessibility is acceptable
- existing functionality is not broken

---

# 73. Avoid Fake Functionality

Do not create buttons that do nothing.

Do not use hardcoded dashboard statistics when real data exists.

Do not fake:

- live monitoring
- grade calculations
- exam results
- examination progress
- reports

If a feature has not yet been implemented, clearly indicate that during development.

---

# 74. Current MVP Scope

For the current version, prioritize:

## Phase 1 - Foundation

- Laravel + React project structure
- authentication
- role-based authorization
- administrator layout
- instructor layout
- candidate exam layout
- database foundation

## Phase 2 - Academic Management

- candidates
- classes / batches
- subjects
- instructor assignments
- academic periods

## Phase 3 - Grade Management

- manual grade encoding
- assessments
- grading configuration
- grade-calculation engine
- academic standing

## Phase 4 - Academic Monitoring

- overall dashboard
- candidate performance
- at-risk candidates
- failing candidates
- candidate academic profiles

## Phase 5 - Question Bank

- question creation
- categories/topics
- answer choices
- correct-answer management

## Phase 6 - Examinations and Quizzes

- quiz creation
- examination creation
- examination configuration
- candidate exam interface
- timer
- autosave
- submission
- objective scoring
- manual essay grading

## Phase 7 - PWA / Tablet Experience

- installable PWA
- tablet-optimized examination interface
- local asset caching
- connection monitoring
- IndexedDB recovery where appropriate

## Phase 8 - Real-Time Examination Monitoring

- live candidate status
- submission status
- disconnected candidate status

## Phase 9 - Reporting and Audit

- academic reports
- examination reports
- audit logs
- grade-change history

---

# 75. Features Explicitly Out of Scope for Now

Do **not** implement the following unless explicitly requested later:

- spreadsheet grade imports
- Excel uploading
- CSV grade imports (question import is different: CSV import of questions into the question bank was approved by the owner on 2026-10-01; grades are still entered directly)
- native Android application
- public student portal
- full offline-first architecture
- AI features
- facial recognition
- biometric authentication
- proctoring / webcam monitoring (exception approved by the owner on 2026-10-01: the examination page records when a candidate leaves the exam screen — tab/app switch or another window focused — as an indicator for instructors; no camera, microphone, screen capture, or automatic penalties)
- multi-tenant SaaS architecture
- payment functionality
- public registration

Focus only on the requirements currently needed for the prototype and initial system.

---

# 76. Core User Experience

The system should make the most important workflows extremely straightforward.

## Instructor

```text
Login
  |
Select Subject
  |
Select Class
  |
View Candidates
  |
Record / Review Grades
  |
See Academic Standing
```

## Candidate

```text
Open Tablet
  |
Open Examination App
  |
Authenticate
  |
See Available Exam
  |
Answer Questions
  |
Submit
```

## Administrator

```text
Login
  |
See Overall Academic Status
  |
Identify Candidates Requiring Attention
  |
Review Candidate Performance
  |
Review Classes and Subjects
```

If these workflows become complicated, simplify them.

---

# 77. Senior Engineering Expectations

Do not simply satisfy the literal request if doing so introduces an obvious engineering problem.

If asked to implement something that would:

- compromise security
- corrupt academic records
- introduce significant technical debt
- create unreliable examination behavior
- bypass authorization

choose the safer architecture and document the reason.

When multiple implementation options exist, prefer the solution that is:

- simple
- secure
- maintainable
- testable
- compatible with internal/local deployment

---

# 78. Final Principle

This is not merely a CRUD dashboard.

The platform manages important academic information and potentially high-stakes examinations.

Every implementation decision should assume that:

- instructors rely on the academic calculations,
- administrators rely on the monitoring information,
- candidates rely on examination reliability,
- and the organization relies on the integrity of stored records.

Build accordingly.
