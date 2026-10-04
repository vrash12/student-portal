# MILESTONES.md

## Academic Monitoring, Assessment & Examination System

This document defines the recommended development milestones for the current MVP.

It should be used together with:

- `AGENTS.md`
- `UI_UX_DESIGN.md`

The goal is to build a stable, demonstrable system in clear phases without over-engineering the early version.

---

# Milestone 0 — Project Setup and Foundation

## Objective

Establish the core Laravel + React application structure and make sure the project can be developed consistently.

## Deliverables

- Laravel project initialized
- React + TypeScript configured
- Inertia.js configured
- Vite configured
- Tailwind CSS configured
- MySQL connected
- `.env.example` prepared
- Base application layout created
- Authentication flow prepared
- Core project folders organized
- Shared frontend components folder created
- Basic development seed data prepared

## Acceptance Criteria

- Application runs successfully in the local development environment
- Database connection works
- Login page loads correctly
- Authenticated area can be accessed after login
- Base layout works on desktop and tablet
- No unnecessary advanced infrastructure is introduced

---

# Milestone 1 — Authentication, Roles, and Authorization

## Objective

Create a secure role-based access foundation.

## Roles

Initial roles:

- Admin
- Academic Administrator
- Instructor
- Candidate

## Deliverables

- Login
- Logout
- Session handling
- User model
- Role model / permission structure
- Role assignment
- Route protection
- Laravel Policies / Gates
- Authorization checks for protected resources
- Unauthorized access page / response

## Acceptance Criteria

- Users can log in and log out
- Users see only pages allowed by their role
- Candidates cannot access administrative pages
- Instructors cannot access administrator-only pages
- Unauthorized URL access is rejected server-side
- Role checks are not based only on frontend visibility

---

# Milestone 2 — Core Academic Data Management

## Objective

Build the basic academic structure of the system.

## Modules

- Candidates
- Instructors
- Classes / Batches
- Subjects
- Academic Periods
- Instructor Assignments

## Deliverables

### Candidate Management

- Candidate list
- Create candidate
- Edit candidate
- View candidate
- Archive/deactivate candidate when appropriate
- Candidate number
- Name
- Class / batch assignment
- Academic status

### Instructor Management

- Instructor list
- Create instructor
- Edit instructor
- Assign subjects
- Assign classes / batches

### Class / Batch Management

- Create class / batch
- Edit class / batch
- View candidates per class
- Assign academic period

### Subject Management

- Create subject
- Edit subject
- Activate/deactivate subject
- Assign instructor

### Academic Period Management

- Create academic period
- Set active period
- Assign classes and subjects

## Acceptance Criteria

- Administrator can create and manage all core academic records
- Instructor assignments are enforced
- Candidates can be grouped by class / batch
- Subjects are configurable
- No real institutional subject names are hardcoded
- Data relationships are protected by foreign keys and validation

---

# Milestone 3 — Instructor Dashboard

## Objective

Provide instructors with a clear overview of their assigned responsibilities.

## Deliverables

- Instructor dashboard
- Assigned subjects
- Assigned classes
- Candidate counts
- Upcoming assessments
- Academic alerts
- Quick navigation to grades
- Quick navigation to examinations
- Quick navigation to question bank

## Acceptance Criteria

- Instructor sees only their own assigned classes and subjects
- Dashboard loads relevant data from the database
- No fake statistics are displayed
- Dashboard remains readable on laptop and tablet
- Important academic alerts are easy to identify

---

# Milestone 4 — Grade Management

## Objective

Allow authorized instructors to manually encode and manage academic grades.

## Deliverables

- Grade entry screen
- Assessment creation
- Assessment categories
- Assessment weights
- Raw score entry
- Percentage calculation
- Weighted score calculation
- Final grade calculation
- Grade status
- Draft / finalized state
- Grade change history
- Instructor comment where appropriate

## Important Scope Rule

For the current MVP:

- No Excel upload
- No CSV import
- No spreadsheet import

Grades are entered directly in the system. (These rules are about grades. CSV import of questions into the question bank was approved by the owner on 2026-10-01.)

## Acceptance Criteria

- Instructor can encode grades for assigned candidates
- Instructor cannot grade candidates outside assigned classes
- Grade formulas are configurable
- Final grades are calculated by the backend
- Grade changes are traceable
- Finalized grades are clearly marked
- Invalid scores are rejected

---

# Milestone 5 — Grade Calculation Engine

## Objective

Centralize all academic grade logic.

## Deliverables

- `GradeCalculationService`
- Assessment weighting logic
- Missing-score handling
- Final grade calculation
- Passing threshold support
- Warning threshold support
- Academic standing calculation
- Automated recalculation when relevant scores change

## Suggested Academic States

- PASSING
- NEEDS IMPROVEMENT
- FAILING
- INCOMPLETE

## Acceptance Criteria

- Grade calculation exists in one authoritative backend service
- Frontend does not contain the authoritative grading logic
- Tests confirm expected weighted-grade results
- Changing a score recalculates the correct academic standing
- Thresholds are configurable and not permanently hardcoded

---

# Milestone 6 — Academic Monitoring and Early Warning

## Objective

Allow instructors and administrators to quickly identify candidates who require academic attention.

## Deliverables

### Academic Monitoring Dashboard

- Total candidates
- Passing count
- Needs Improvement count
- Failing count
- Incomplete count

### Candidate Risk Views

- Filter by academic standing
- Filter by class / batch
- Filter by subject
- Search candidates
- Sort by performance

### Candidate Academic Profile

- Candidate information
- Subject grades
- Assessment scores
- Overall academic standing
- Recent academic activity
- Current warnings

## Acceptance Criteria

- Needs Improvement and Failing candidates are automatically identified
- Filters work correctly
- Academic status is never represented by color alone
- Candidate profile provides a clear academic overview
- Instructor access remains limited to authorized candidates

---

# Milestone 7 — Question Bank

## Objective

Create a reusable repository of examination and quiz questions.

## Initial Question Types

- Multiple Choice
- True / False
- Essay

## Deliverables

- Question list
- Create question
- Edit question
- Preview question
- Activate/deactivate question
- Assign subject
- Assign topic/category
- Set point value
- Configure answer options
- Mark correct answer for objective questions
- Search
- Filter by subject
- Filter by topic
- Filter by type

## Acceptance Criteria

- Instructor can manage questions for authorized subjects
- Questions can be reused in multiple assessments
- Used questions are not destructively removed from historical records
- Correct answers are not exposed to candidate-facing interfaces
- Question forms work well on desktop and tablet

---

# Milestone 8 — Quiz and Examination Builder

## Objective

Allow instructors to create digital quizzes and examinations.

## Deliverables

### Examination Details

- Title
- Subject
- Class / batch
- Description / instructions

### Question Selection

- Add questions from question bank
- Remove questions
- Set order
- Optional randomization

### Examination Settings

- Availability date/time
- Duration
- Attempt limit
- Passing score
- Randomize questions
- Randomize choices
- One-question-at-a-time mode
- Navigation rules
- Optional access code
- Automatic submission

### Examination Lifecycle

- Draft
- Published
- Active
- Ended
- Archived

## Acceptance Criteria

- Instructor can create and save a draft
- Instructor can preview examination configuration
- Instructor cannot publish to unauthorized classes
- Published exams are visible only to eligible candidates
- Examination rules are enforced on the backend

---

# Milestone 9 — Candidate Examination PWA

## Objective

Build the tablet-optimized candidate examination experience.

## Deliverables

- Candidate login / access
- Available examinations screen
- Exam-start confirmation screen
- Tablet-friendly question layout
- Large touch targets
- Timer
- Question progress
- Previous / Next controls
- Question navigator where permitted
- Flag for review where permitted
- Essay input
- Save-state indicator
- Final submission confirmation
- Submission success screen
- PWA manifest
- Installable application behavior
- Home-screen support
- Standalone display mode

## Acceptance Criteria

- Candidate can complete an exam using a tablet
- Interface works in portrait and landscape
- No administrative navigation is shown during an exam
- Question controls are touch friendly
- Exam progress and timer are visible
- Candidate cannot accidentally submit without confirmation
- Candidate cannot access another candidate's examination attempt

---

# Milestone 10 — Autosave and Connection Recovery

## Objective

Protect examination answers during refreshes and short connection interruptions.

## Deliverables

- Automatic answer saving
- Save-state indicator
- Temporary local answer storage where needed
- IndexedDB-based temporary recovery
- Connection status
- Retry / synchronization logic
- Recovery after page refresh
- Recovery after temporary network interruption

## Candidate-Facing States

- Saved
- Saving...
- Offline — saved on this device
- Syncing...
- Unable to sync

## Acceptance Criteria

- Candidate answers are not dependent only on final submission
- Refreshing the page does not lose previously saved answers
- Temporary connection loss does not immediately destroy exam progress
- UI clearly distinguishes local save from server save
- Duplicate answer submissions do not corrupt the attempt

---

# Milestone 11 — Examination Submission and Scoring

## Objective

Complete the examination lifecycle and calculate results safely.

## Deliverables

- Manual submission
- Automatic submission when configured
- Duplicate-submission protection
- Objective question scoring
- Multiple-choice scoring
- True/False scoring
- Essay pending-review state
- Final result calculation
- Attempt history
- Submission timestamp
- Examination status updates

## Acceptance Criteria

- Objective questions are scored correctly
- Essay questions remain pending until manually graded
- Candidate cannot submit the same attempt twice
- Submitted attempts become immutable except through authorized workflows
- Exam submission uses a database transaction
- Final score is shown to the candidate only if configured

---

# Milestone 12 — Instructor Manual Essay Grading

## Objective

Allow instructors to review and grade subjective examination responses efficiently.

## Deliverables

- Pending manual grading queue
- Candidate response view
- Question view
- Maximum points
- Score input
- Instructor comment
- Save grading
- Navigate to next response
- Final result recalculation

## Acceptance Criteria

- Only authorized instructors can grade responses
- Scores cannot exceed maximum points
- Manual scoring updates the examination result
- Grading history remains traceable
- Instructor can efficiently move through pending submissions

---

# Milestone 13 — Examination Monitoring

## Objective

Give instructors a simple view of examination progress while a quiz or exam is active.

## MVP Approach

Use simple periodic refresh / polling where needed.

Do not require advanced real-time infrastructure for the current version.

## Deliverables

- Active candidate count
- Submitted count
- Not-started count
- Inactive/disconnected count where detectable
- Candidate status list
- Question progress count where appropriate
- Last activity timestamp

## Example

```text
Subject 1 — Examination 01

400 Candidates

372 Active
18 Submitted
7 Not Started
3 Inactive / Disconnected
```

## Acceptance Criteria

- Instructor can view current exam participation
- Status information updates periodically
- Candidate answers are not displayed on monitoring screen
- Monitoring does not interfere with exam performance
- Screen remains usable with hundreds of candidates

---

# Milestone 14 — Administrator Dashboard

## Objective

Provide administrators with a clear institutional academic overview.

## Deliverables

- Total candidates
- Passing count
- Needs Improvement count
- Failing count
- Incomplete count
- Candidates requiring attention
- Recent examinations
- Recent academic activity
- Subject performance summary
- Instructor/class summary where useful

## Acceptance Criteria

- Dashboard uses real database data
- Important warnings are prioritized
- Dashboard is not overloaded with decorative charts
- Administrator can navigate directly to problem areas
- Metrics respect the active academic period

---

# Milestone 15 — Reports

## Objective

Provide useful academic and examination reports.

## Initial Reports

- Candidate academic standing
- Class / batch performance
- Subject performance
- Candidates needing improvement
- Failing candidates
- Examination results
- Quiz results
- Grade distribution

## Deliverables

- Report screen
- Filters
- Date / academic period context
- Printable layout
- Basic export where simple and appropriate

## Acceptance Criteria

- Reports derive from authoritative database data
- Reports respect permissions
- Reports can be filtered
- Report layouts are readable
- No spreadsheet import functionality is introduced as part of this milestone

---

# Milestone 16 — Audit Logs and Academic History

## Objective

Make important system changes traceable.

## Deliverables

- Audit log
- Grade-change history
- Exam publication history
- Exam modification history
- User/role changes
- Candidate record changes
- Actor
- Action
- Entity
- Timestamp
- Previous value where appropriate
- New value where appropriate
- Reason field for important changes

## Acceptance Criteria

- Important academic changes are auditable
- Finalized grade changes are traceable
- Audit records cannot be edited by ordinary users
- Sensitive secrets are not stored in audit logs
- Administrator can filter audit history

---

# Milestone 17 — Security Hardening

## Objective

Review and strengthen the system before demonstration or production use.

## Review Areas

- authentication
- authorization
- session handling
- CSRF
- validation
- mass assignment
- resource ownership
- candidate privacy
- examination access
- question confidentiality
- score modification
- rate limiting where appropriate
- error-message exposure

## Acceptance Criteria

- Candidate cannot access admin/instructor routes
- Instructor cannot access unauthorized classes
- Candidate cannot access another candidate's attempt
- Correct exam answers are never exposed before submission
- Raw stack traces are disabled outside development
- Sensitive information is not placed in URLs unnecessarily

---

# Milestone 18 — UI/UX Review and Tablet QA

## Objective

Ensure the implementation follows `UI_UX_DESIGN.md`.

## Review Areas

### Desktop

- sidebar
- page headers
- tables
- forms
- filters
- dashboards
- dialogs
- reports

### Tablet

- candidate exam UI
- portrait orientation
- landscape orientation
- touch targets
- timer readability
- question navigation
- save indicators
- submission flow

### Accessibility

- contrast
- labels
- focus states
- keyboard navigation
- status text
- semantic HTML

## Acceptance Criteria

- No major screen violates the design guide
- Important actions are obvious
- Tablet exam flow can be understood without instructions
- Status is never represented only by color
- Loading, empty, error, and success states are implemented

---

# Milestone 19 — Testing and Stability

## Objective

Verify critical system workflows before final demonstration.

## Required Testing Areas

### Authentication

- valid login
- invalid login
- role restrictions

### Academic Management

- candidate creation
- instructor assignment
- class assignment
- subject assignment

### Grade Engine

- weighted score calculation
- academic standing
- missing grades
- threshold changes

### Examinations

- unpublished exam access
- published exam access
- timer
- autosave
- refresh recovery
- temporary disconnection
- manual submission
- automatic submission
- duplicate submission protection
- objective scoring
- essay grading

### Authorization

- unauthorized class access
- unauthorized grade modification
- unauthorized exam access
- unauthorized candidate access

## Acceptance Criteria

- Critical automated tests pass
- Major workflows are manually verified
- No known high-severity bugs remain
- Academic calculations produce expected results
- Candidate exam recovery has been tested

---

# Milestone 20 — Demo Preparation

## Objective

Prepare a professional prototype for presentation.

## Demo Data

Use synthetic records only.

Example:

- Candidate 001
- Candidate 002
- Candidate 003
- Instructor Alpha
- Instructor Bravo
- Subject 1
- Subject 2
- Sample Batch A

## Recommended Demo Flow

### 1. Administrator

Show:

- login
- academic dashboard
- candidate count
- candidates needing improvement
- failing candidates

### 2. Instructor

Show:

- assigned subject
- candidate list
- grade entry
- academic standing

### 3. Examination Builder

Show:

- create quiz
- select questions
- configure exam
- publish

### 4. Candidate Tablet

Show:

- available exam
- start exam
- answer questions
- autosave
- submit

### 5. Instructor Monitoring

Show:

- active candidates
- submitted candidates
- exam progress

### 6. Results

Show:

- automatic scoring
- manual essay grading
- candidate academic profile

## Acceptance Criteria

- Demo contains no unfinished broken navigation
- All visible buttons used in the demo work
- Demo data is clearly fictional
- No real sensitive institutional information is used
- Demo can run without depending on public internet access

---

# Current MVP Completion Definition

The MVP can be considered ready for presentation when all of the following are functional:

- authentication
- roles and permissions
- candidate management
- instructor management
- classes / batches
- subjects
- manual grade entry
- grade calculation
- academic standing
- Needs Improvement / Failing monitoring
- question bank
- quiz/exam creation
- candidate tablet exam interface
- PWA behavior
- autosave
- basic connection recovery
- exam submission
- automatic objective scoring
- manual essay grading
- simple examination monitoring
- administrator dashboard
- instructor dashboard
- basic reports
- audit history
- responsive UI
- tablet testing

---

# Owner-Requested Additions After Milestone 16

Implemented at the owner's request (details and decisions in `SESSION_HANDOFF.md`):

- Candidate information and profile photos; candidate My Information page
- Examination results posted to the gradebook by the instructor (reviewed, confirmed snapshot)
- Candidate My Home page
- Registration and academic record PDFs: landscape Certificate of Registration (one page) and Academic Record (one page per academic period)
- Green and yellow institutional UI refresh; login photograph
- Leave-screen detection during examinations (indicator for instructors only)
- Images, audio, and video attached to questions
- Component test pass: every module has automated tests (1,099 passing on 2026-10-01)
- Standard military fitness testing; charts on dashboards and reports; Expenses assigned by the Admin
- OCS performance: merits and demerits, attendance, company/platoon, performance areas with qualification and class rank (staff only)
- Candidate portal split into Home, Examinations, My Grades, My Performance, Physical Fitness and My Information pages, with icons and charts (own records only, no rank)
- Authorized grade corrections: instructors request changes to finalized scores with an incident report; administrators approve or reject them (2026-10-02)
- Candidate medical records with administrator-defined fields in sections (shown to the candidate or staff only); instructors of the candidate's class see the record and its documents view only, a download only with an administrator's approval (2026-10-02)

The next planned milestone remains **Milestone 17 — Security Hardening**.

---

# Explicitly Deferred Features

The following are intentionally deferred until requirements are confirmed:

- Excel grade uploads
- CSV grade imports
- spreadsheet-based imports
- native Android application
- public student portal
- full offline-first operation
- AI-generated exam questions
- AI grading
- facial recognition
- biometric authentication
- webcam proctoring
- multi-tenant SaaS architecture
- advanced infrastructure
- payment functionality
- public registration

Do not implement deferred features unless they are explicitly approved later.

---

# Recommended Development Order

Use this implementation sequence:

```text
1. Project Foundation
2. Authentication & Roles
3. Academic Data Management
4. Instructor Dashboard
5. Grade Management
6. Grade Calculation Engine
7. Academic Monitoring
8. Question Bank
9. Examination Builder
10. Candidate Examination PWA
11. Autosave & Recovery
12. Submission & Scoring
13. Manual Essay Grading
14. Examination Monitoring
15. Administrator Dashboard
16. Reports
17. Audit Logs
18. Security Review
19. UI/UX QA
20. Testing
21. Demo Preparation
```

---

# Final Milestone Principle

Do not move quickly by sacrificing correctness.

Each milestone should leave the system in a stable state.

The project should evolve from:

```text
Foundation
    |
Academic Management
    |
Grade Monitoring
    |
Digital Assessment
    |
Tablet Examination
    |
Reporting & Oversight
```

The priority is to produce a system that is:

- understandable
- stable
- secure
- easy to demonstrate
- easy to maintain
- appropriate for an internal institutional environment
