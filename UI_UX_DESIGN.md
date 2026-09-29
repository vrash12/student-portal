# UI_UX_DESIGN.md

## Academic Monitoring, Assessment & Examination System

This document defines the mandatory **UI/UX design system, interaction principles, responsive behavior, and visual standards** for the application.

It supplements `AGENTS.md`.

All frontend work must follow both documents.

The objective is to create an interface that feels:

- professional
- disciplined
- institutional
- modern
- trustworthy
- efficient
- calm
- highly readable
- suitable for prolonged administrative use
- optimized for tablet-based examinations

This is not a generic SaaS dashboard.

This is an operational academic and examination system where clarity, reliability, and speed matter more than decorative design.

---

## MVP Implementation Note

For the current prototype, UI behavior should not depend on advanced infrastructure.

Use simple Laravel/Inertia requests and periodic polling where live status is needed.

Do not design frontend flows that depend on advanced supporting infrastructure at this stage.

---

# 1. Design Philosophy

Use the following priorities:

1. Clarity
2. Usability
3. Information hierarchy
4. Consistency
5. Accessibility
6. Responsiveness
7. Professional appearance
8. Visual polish

Never sacrifice usability for aesthetics.

A visually impressive screen that is difficult to understand is considered a design failure.

---

# 2. Overall Visual Direction

The application should feel similar to a modern professional institutional information system.

Visual characteristics:

- clean layouts
- strong alignment
- restrained color usage
- generous but efficient spacing
- clear section boundaries
- highly readable typography
- predictable navigation
- subtle elevation
- minimal decorative effects
- consistent component behavior

Avoid making the application look like:

- a gaming interface
- a cryptocurrency dashboard
- a consumer social application
- a futuristic military game
- a marketing landing page
- a heavily animated startup website

The interface should communicate stability and seriousness.

---

# 3. Institutional Design

The system may eventually be configured for a specific organization.

Until official branding is approved:

- do not fabricate official logos
- do not reproduce military insignia
- do not imply official government endorsement
- do not hardcode organization-specific visual assets

Use configurable placeholders.

Example:

```text
[ Organization Logo ]

Academic Monitoring &
Assessment System
```

The application architecture should allow branding to be configured later.

---

# 4. Color System

Use a restrained neutral-first color system.

Recommended general direction:

```text
Background
Light neutral / off-white

Surface
White

Primary
Deep institutional green or similarly restrained tone

Secondary
Muted neutral

Text Primary
Near-black / very dark gray

Text Secondary
Medium gray

Border
Light gray
```

Status colors should follow recognizable conventions:

```text
Success / Passing
Green

Warning / At Risk
Amber / Orange

Critical / Failing
Red

Information
Blue

Inactive / Neutral
Gray
```

Never communicate a status using color alone.

Always pair color with text or an icon.

Correct:

```text
[!] At Risk
```

Incorrect:

```text
[!]
```

where color is the only indication.

---

# 5. Avoid Excessive Color

Do not use multiple strong colors unnecessarily.

A dashboard should not resemble:

```text
Red card
Blue card
Purple card
Green card
Orange card
Pink card
```

simply to create visual variety.

Instead, use neutral cards and apply color specifically to meaningful statuses.

Example:

```text
Total Candidates      400

Passing               342
At Risk                38
Failing                14
Incomplete              6
```

Status may use subtle indicators rather than full saturated card backgrounds.

---

# 6. Dark Mode

Dark mode is not a priority for the initial MVP.

Do not spend development time implementing dark mode unless explicitly requested.

The default interface should prioritize excellent light-mode readability.

---

# 7. Typography

Use a modern, highly readable sans-serif typeface that can be packaged locally.

Do not rely on an external web-font CDN for core functionality.

Preferred characteristics:

- excellent readability
- clear numeric characters
- strong weight hierarchy
- good tablet rendering
- professional appearance

Use a limited typography hierarchy.

Example:

```text
Page Title
24-32px
Semibold / Bold

Section Heading
18-22px
Semibold

Card Heading
14-16px
Medium / Semibold

Body
14-16px
Regular

Supporting Text
12-14px
Regular

Table Text
13-15px

Large Dashboard Metric
28-40px
Semibold / Bold
```

Avoid excessive font-size variations.

---

# 8. Writing Style

Interface copy should be:

- concise
- professional
- direct
- understandable
- consistent

Prefer:

```text
Create Examination
```

instead of:

```text
Proceed to Create New Examination
```

Prefer:

```text
Save Changes
```

instead of:

```text
Click Here to Save Your Changes
```

Use plain language.

---

# 9. Terminology

Use terminology consistently.

Do not alternate unnecessarily between:

```text
Student
Candidate
Officer Candidate
Trainee
```

For the initial system, prefer:

```text
Candidate
```

unless the organization provides different terminology.

Similarly, choose one consistent term for:

```text
Class / Batch
```

after requirements are finalized.

---

# 10. Application Layout

Desktop and laptop administrative screens should generally follow:

```text
+--------------------------------------------------------------+
| Top Header                                                   |
+--------------+-----------------------------------------------+
|              |                                               |
| Sidebar      | Main Content                                  |
|              |                                               |
|              |                                               |
|              |                                               |
+--------------+-----------------------------------------------+
```

Use:

- persistent desktop sidebar
- collapsible sidebar where appropriate
- clear top-level navigation
- page title and breadcrumbs
- consistent content width
- predictable action placement

---

# 11. Sidebar Design

The sidebar should be functional rather than decorative.

Potential structure:

```text
Dashboard

ACADEMICS
Candidates
Classes
Subjects
Grades
Academic Monitoring

ASSESSMENTS
Examinations
Quizzes
Question Bank
Results

REPORTS
Academic Reports
Examination Reports

ADMINISTRATION
Instructors
Users & Roles
Audit Logs
Settings
```

Use section labels sparingly.

Navigation icons should support recognition but should never replace labels.

---

# 12. Active Navigation

The currently selected section must be obvious.

Use:

- subtle background highlight
- stronger text
- optional small accent indicator

Avoid aggressive glow effects or large animated indicators.

---

# 13. Header

The application header may contain:

- page context
- organization name
- current user
- notifications
- profile menu

Avoid filling the header with unnecessary controls.

The sidebar should remain the main navigation mechanism on desktop.

---

# 14. Breadcrumbs

Use breadcrumbs for deeper screens.

Example:

```text
Examinations / Subject 1 / Quiz 03
```

Do not use breadcrumbs on simple top-level dashboard pages where they provide no value.

---

# 15. Page Header Pattern

Use a predictable page header.

Example:

```text
Candidates

Manage candidate academic records and class assignments.

                                [ Add Candidate ]
```

Primary page actions generally belong in the upper-right area.

Do not place the same primary action in multiple locations without reason.

---

# 16. Dashboard Design

Dashboards should answer operational questions immediately.

The administrator dashboard should help answer:

- How many candidates are being monitored?
- Who is at risk?
- Who is failing?
- Are any examinations active?
- What needs attention today?

Avoid decorative analytics.

---

# 17. Dashboard Metric Cards

Example:

```text
+------------------+
| Total Candidates |
|                  |
|       400        |
+------------------+

+------------------+
| At Risk          |
|                  |
|        38        |
+------------------+
```

Metric cards should have:

- one primary number
- one clear label
- optional supporting comparison
- optional status icon

Do not overload metric cards with charts.

---

# 18. Administrator Dashboard

Suggested sections:

```text
Academic Overview

[ 400 Candidates ]
[ 342 Passing ]
[ 38 At Risk ]
[ 14 Failing ]

Candidates Requiring Attention

Recent Examinations

Subject Performance

Recent Administrative Activity
```

Priority information should appear above the fold.

---

# 19. Instructor Dashboard

The instructor dashboard should emphasize assigned responsibilities.

Example:

```text
Good morning, Instructor Alpha

MY SUBJECTS

Subject 1
120 Candidates

Subject 2
98 Candidates

ACADEMIC ALERTS

11 At Risk
4 Failing

UPCOMING ASSESSMENTS

Quiz 03
Tomorrow

Examination 01
Friday
```

The user should immediately know what requires attention.

---

# 20. Candidate Tablet Interface

The candidate interface should not look like the administrator dashboard.

It should be dramatically simpler.

Do not expose the administrative sidebar during examinations.

Candidate tablet interface priorities:

1. examination title
2. candidate identity
3. question
4. answer options
5. timer
6. progress
7. navigation
8. save state

Avoid irrelevant information.

---

# 21. Tablet Exam Layout

Recommended concept:

```text
+---------------------------------------------+
| Subject 1 - Quiz 01             24:31      |
| Candidate 0214                             |
+---------------------------------------------+
|                                             |
| Question 7 of 30                            |
|                                             |
| Which of the following is correct?          |
|                                             |
| +-----------------------------------------+ |
| | ( ) Option A                            | |
| +-----------------------------------------+ |
|                                             |
| +-----------------------------------------+ |
| | (*) Option B                            | |
| +-----------------------------------------+ |
|                                             |
| +-----------------------------------------+ |
| | ( ) Option C                            | |
| +-----------------------------------------+ |
|                                             |
| Saved                                      |
|                                             |
| [ Previous ]                    [ Next ]    |
+---------------------------------------------+
```

Large touchscreen targets are mandatory.

---

# 22. Tablet Touch Targets

Interactive controls should generally be at least approximately:

```text
44 x 44px
```

or larger.

Important exam options should generally use larger full-width controls.

Do not require precision tapping.

---

# 23. Exam Answer Options

Multiple-choice options should be treated as selectable cards.

Good:

```text
+------------------------------+
| ( ) A. First answer          |
+------------------------------+
```

Selected:

```text
+------------------------------+
| (*) B. Second answer         |
+------------------------------+
```

The entire option area should be tappable.

Do not make users tap only the small radio control.

---

# 24. Exam Progress

Always show question progress.

Example:

```text
Question 12 of 50
```

A subtle progress bar may also be included.

Do not rely solely on a progress bar because exact position should remain understandable.

---

# 25. Examination Timer

The examination timer must be clearly visible without dominating the interface.

Normal:

```text
Time Remaining
34:12
```

When little time remains, increase visual urgency carefully.

Do not:

- flash the entire screen
- use distracting animations
- make the timer unnecessarily stressful

A restrained warning state is sufficient.

---

# 26. Saving State

The candidate must understand whether an answer is safe.

Display statuses such as:

```text
Saved
Saving...
Offline - saved on this device
Syncing...
Unable to sync
```

Do not use technical terminology such as:

```text
IndexedDB queued transaction
```

in candidate-facing UI.

---

# 27. Offline Indicator

When the device loses connection, display a clear but calm notification.

Example:

```text
Connection interrupted

Your answers are being saved on this tablet.
They will sync when the connection returns.
```

Do not block the candidate unnecessarily if the configured recovery behavior allows continued answering.

---

# 28. Submission Experience

Do not submit an examination accidentally.

The final action should be explicit.

Example:

```text
Submit Examination
```

Then confirmation:

```text
Submit examination?

You have answered 48 of 50 questions.

2 questions are unanswered.

[ Continue Exam ]
[ Submit Anyway ]
```

Do not hide unanswered-question information.

---

# 29. Successful Submission

After submission:

```text
Examination Submitted

Your responses were successfully received.

Submitted:
10:42 AM
```

Do not show the score unless examination settings explicitly permit candidates to see it.

---

# 30. Tables

Administrative tables will be heavily used.

Tables must support:

- clear column headings
- consistent alignment
- sorting where useful
- filtering
- search
- pagination
- status indicators
- row-level actions

Avoid extremely dense table layouts.

---

# 31. Numeric Alignment

Numeric information should generally align consistently.

For example:

```text
Candidate          Grade
Candidate 001       91.25
Candidate 002       74.80
Candidate 003       68.40
```

Grades and scores should be easy to scan vertically.

---

# 32. Candidate List

Recommended columns:

```text
Candidate No.
Candidate Name
Class / Batch
Current Standing
Subjects
Last Updated
Actions
```

Do not display every available database field.

Prioritize useful information.

---

# 33. Candidate Status

Use a consistent status component.

Examples:

```text
PASSING
AT RISK
FAILING
INCOMPLETE
```

Possible treatment:

```text
[ Passing ]
[ At Risk ]
[ Failing ]
```

Use restrained backgrounds and readable contrast.

---

# 34. Candidate Profile

Recommended structure:

```text
Candidate 0214

Candidate Number
0214

Class / Batch
OCC Class XX

Overall Standing
AT RISK

----------------------------------------

Academic Performance

Subject 1       84.50       PASSING
Subject 2       72.40       AT RISK
Subject 3       91.00       PASSING
Subject 4       68.20       FAILING

----------------------------------------

Recent Assessments

Quiz 01
82%

Quiz 02
74%

Examination
69%
```

The most important status should be easy to identify immediately.

---

# 35. Academic Risk Dashboard

This is one of the most important system screens.

Prioritize:

- candidates failing
- candidates at risk
- problematic subjects
- overall distribution

Example:

```text
ACADEMIC RISK

Critical
14 Candidates

At Risk
38 Candidates

-------------------------------------

Candidate       Subject       Grade
Candidate 103   Subject 2     64.20
Candidate 119   Subject 1     67.50
Candidate 207   Subject 3     69.10
```

Avoid making risk visualization overly dramatic.

---

# 36. Forms

Use clear form layouts.

Preferred desktop form:

```text
First Name
[____________________________]

Last Name
[____________________________]

Candidate Number
[____________________________]

Class / Batch
[ Select                    v ]

                    [ Cancel ] [ Save Candidate ]
```

Avoid excessively narrow columns.

---

# 37. Form Grouping

Long forms should be grouped by meaning.

Example:

```text
BASIC INFORMATION

ACADEMIC ASSIGNMENT

ACCOUNT ACCESS
```

Do not present 25 unrelated fields as one uninterrupted form.

---

# 38. Labels

Labels should always remain visible.

Avoid relying exclusively on placeholder text.

Bad:

```text
[ Enter candidate number ]
```

Good:

```text
Candidate Number
[ Enter candidate number ]
```

---

# 39. Required Fields

Required fields should be clearly identified.

Do not make every field required unnecessarily.

Example:

```text
Candidate Number *
```

Provide validation when appropriate.

---

# 40. Validation

Validation messages should explain how to resolve the problem.

Good:

```text
Candidate number is already assigned to another candidate.
```

Bad:

```text
Invalid input.
```

Place errors close to the relevant field.

---

# 41. Modal Dialogs

Use modals for:

- confirmations
- small focused forms
- simple previews

Do not put large complex workflows inside modals.

If an interaction requires significant navigation or data entry, use a full page.

---

# 42. Confirmation Dialogs

Destructive confirmation should clearly describe the action.

Example:

```text
Remove Candidate?

Candidate 0214 will be removed from the current class.

Historical academic records will remain available.

[ Cancel ]
[ Remove Candidate ]
```

Avoid generic:

```text
Are you sure?
```

without context.

---

# 43. Buttons

Button hierarchy:

## Primary

Used for the most important action.

Examples:

```text
Save Changes
Create Examination
Publish Examination
```

## Secondary

Supporting actions.

```text
Cancel
Preview
Back
```

## Destructive

```text
Delete
Invalidate Exam
Remove Assignment
```

Do not show several primary buttons competing on the same screen.

---

# 44. Button Labels

Use verbs.

Good:

```text
Create Candidate
Save Grade
Publish Exam
Generate Report
```

Avoid vague labels:

```text
OK
Proceed
Submit
```

when a more descriptive action is possible.

`Submit Examination` is better than simply `Submit`.

---

# 45. Icons

Icons should reinforce meaning.

Use them for:

- navigation
- status
- common actions
- supplemental context

Do not use unexplained icon-only buttons for important actions.

If an icon-only control is used, provide:

- tooltip
- accessible label

---

# 46. Cards

Cards should group related information.

Do not wrap every individual section in a card just because a component library offers cards.

Excessive cards create visual noise.

Use cards for meaningful grouping.

---

# 47. Empty States

Empty screens must be designed intentionally.

Example:

```text
No examinations yet

Create an examination for this subject when you're ready.

[ Create Examination ]
```

Avoid showing an empty white table with no explanation.

---

# 48. Loading States

Do not allow unexplained blank screens.

Use:

- skeleton states
- loading indicators
- disabled actions where appropriate

Avoid excessive spinner usage.

Skeletons are preferable for structured data screens.

---

# 49. Error States

Errors should communicate:

1. what happened
2. whether data is safe
3. what the user should do

Example:

```text
Unable to save this grade

Your previous value has not been changed.

Check your connection and try again.
```

---

# 50. Success Feedback

After important actions, provide brief confirmation.

Examples:

```text
Candidate created successfully.

Grades updated.

Examination published.

Question saved.
```

Do not show excessive success dialogs for trivial actions.

Use unobtrusive toast notifications where appropriate.

---

# 51. Search

Search boxes should describe their scope.

Example:

```text
Search candidates...
```

not simply:

```text
Search...
```

Search should tolerate reasonable partial matches.

---

# 52. Filters

Filters should be easily discoverable.

For candidate records:

```text
Class
[ All Classes v ]

Standing
[ All Statuses v ]

Subject
[ All Subjects v ]
```

Allow users to reset filters easily.

---

# 53. Pagination

Use server-side pagination for potentially large datasets.

Provide:

- current page
- total results where practical
- next/previous navigation

Example:

```text
Showing 1-25 of 400 candidates
```

---

# 54. Question Bank UX

Question-bank management should support efficient instructor workflows.

Recommended layout:

```text
Question Bank

[ Search questions... ]

Subject: [ Subject 1 v ]
Topic:   [ All Topics v ]
Type:    [ All Types v ]

[ + Add Question ]

-------------------------------------------------

Multiple Choice

Which of the following ...?

Subject 1
Topic 2

[ Edit ] [ Preview ]
```

---

# 55. Question Creation

For multiple choice:

```text
Question

[______________________________________]

Choices

A [____________________________________]

B [____________________________________]

C [____________________________________]

D [____________________________________]

Correct Answer
[ B v ]

Points
[ 1 ]

[ Cancel ]               [ Save Question ]
```

Make correct-answer selection obvious.

---

# 56. Examination Builder

Exam creation should use a structured workflow.

Recommended:

```text
1. Details
2. Questions
3. Settings
4. Review
5. Publish
```

Do not put every configuration setting onto one enormous page.

---

# 57. Exam Review

Before publication, summarize:

```text
Subject 1 - Examination 01

Class
OCC Class XX

Questions
50

Duration
60 minutes

Attempts
1

Randomized Questions
Enabled

Availability
October 10
09:00-10:30

Candidates
400

[ Back to Edit ]
[ Publish Examination ]
```

---

# 58. Live Examination Monitoring

The live monitoring screen should prioritize status.

Example:

```text
Subject 1 - Examination 01

Live Examination

372 Active
18 Submitted
7 Not Started
3 Disconnected

------------------------------------------------

Candidate       Status         Progress

Candidate 001   Active         24 / 50
Candidate 002   Submitted      50 / 50
Candidate 003   Disconnected   18 / 50
Candidate 004   Not Started    -
```

Avoid showing actual candidate answers by default.

---

# 59. Real-Time Updates

When real-time data changes:

- update smoothly
- avoid jarring full-page refreshes
- avoid unnecessary animations

Do not constantly reorder rows while the instructor is trying to read them unless sorting explicitly requires it.

---

# 60. Reporting Screens

Reports should emphasize readability and exportability.

Include:

- clear title
- filters
- generated date
- relevant organization/class context
- clean tables/charts

Do not overuse charts when a table communicates the information better.

---

# 61. Charts

Only use charts when they provide faster understanding.

Useful examples:

- grade distribution
- candidate status distribution
- performance over time
- subject comparison

Avoid decorative donut charts for every metric.

---

# 62. Animation

Animations should be subtle.

Appropriate:

- sidebar transition
- dropdown opening
- modal transition
- small state change
- toast appearance

Avoid:

- page entrance animations
- bouncing elements
- animated gradients
- glowing components
- parallax
- excessive motion

This is an operational application.

---

# 63. Responsive Breakpoints

Design intentionally for:

- desktop
- laptop
- tablet

Phone layouts may remain functional, but phone optimization is not the primary target unless requirements change.

Candidate examination screens must receive special tablet attention.

---

# 64. Tablet Orientation

Exam interfaces should work in:

- portrait
- landscape

Do not assume a single orientation unless devices are physically locked to one orientation.

---

# 65. Desktop Tables on Tablet

Do not simply squeeze large desktop tables onto tablets.

Consider:

- fewer columns
- horizontally scrollable table region
- responsive cards
- expandable rows

Choose the interaction that preserves readability.

---

# 66. Mobile Navigation

If administrative screens are used on tablets or narrow widths:

- collapse the sidebar
- provide a clear menu button
- maintain page title visibility

Do not hide essential navigation completely.

---

# 67. Accessibility

Target strong practical accessibility.

Requirements include:

- semantic HTML
- visible keyboard focus
- meaningful labels
- adequate contrast
- correct button semantics
- proper form associations
- understandable errors
- screen-reader-friendly status indicators

Do not rely on hover for required functionality.

Tablet users may not have hover capability.

---

# 68. Keyboard Navigation

Administrative screens should remain usable via keyboard.

Ensure logical tab ordering.

Do not introduce custom interactive elements when native HTML controls are more appropriate.

---

# 69. Contrast

Text and controls must remain readable under typical indoor and bright tablet conditions.

Avoid:

- light gray text on white
- subtle status colors with insufficient contrast
- transparent overlays reducing readability

---

# 70. Focus States

Do not remove browser focus states without supplying an accessible replacement.

All interactive controls must provide visible focus indication.

---

# 71. Candidate Privacy

Do not expose unnecessary candidate information on shared screens.

For example, live monitoring should primarily show:

- candidate identifier
- required operational status

Only show full details if authorized and necessary.

---

# 72. Sensitive Examination Content

Exam questions must not appear:

- in dashboard previews unnecessarily
- in browser notifications
- in logs
- in URLs
- in unrelated instructor screens

Treat exam content as sensitive.

---

# 73. Session Expiration

Administrative session expiration should provide an understandable message.

Example:

```text
Your session has expired.

Sign in again to continue.
```

During an active candidate exam, session behavior must be designed carefully so an ordinary timeout does not unexpectedly destroy the exam attempt.

---

# 74. Notifications

Use notifications sparingly.

Potential administrative notifications:

```text
Examination submitted by all candidates.

Candidate requires manual essay grading.

Candidate academic status changed to At Risk.
```

Avoid turning the system into a noisy notification center.

---

# 75. Audit Log UX

Audit logs should be readable.

Example:

```text
September 29, 2026 - 10:42 AM

Instructor Alpha
Updated candidate grade

Candidate:
Candidate 0214

Previous:
72.40

New:
76.20
```

Provide filters by:

- actor
- action
- date
- entity

---

# 76. System Settings

Settings should be grouped logically.

Example:

```text
General
Academic
Grading
Examinations
Security
Appearance
```

Do not expose developer-only configuration through normal administrator settings.

---

# 77. Design Components

Build reusable components for common patterns.

Examples:

```text
PageHeader
MetricCard
StatusBadge
DataTable
EmptyState
ConfirmDialog
FormField
SelectField
SearchInput
Pagination
CandidateCard
ExamTimer
ConnectionStatus
QuestionNavigator
```

Reuse does not mean every component must be generic enough for every theoretical future use case.

Keep APIs understandable.

---

# 78. Design Consistency

Equivalent actions must look and behave consistently.

For example:

If `Create Candidate` opens a dedicated page, other major creation workflows should follow similar navigation unless there is a UX reason not to.

Avoid arbitrary behavior differences.

---

# 79. Avoid Component-Library Appearance

Using a component library is acceptable.

However, the final product should not look like untouched starter components.

Customize:

- spacing
- typography
- layout
- hierarchy
- states
- composition

The goal is a cohesive application, not a component-demo website.

---

# 80. Design Tokens

Centralize visual values where practical.

Examples:

```text
spacing
border radius
font sizes
shadows
surface colors
text colors
status colors
```

Do not scatter arbitrary values across components.

---

# 81. Border Radius

Use moderate border radii.

Avoid overly rounded consumer-app styling.

Example direction:

```text
Inputs     6-8px
Buttons    6-8px
Cards      8-12px
Dialogs    10-12px
```

Keep the interface structured.

---

# 82. Shadows

Use subtle shadows only where necessary.

Prefer borders and surface separation over strong floating-card shadows.

Avoid dramatic shadows.

---

# 83. Spacing

Use a consistent spacing scale.

Example:

```text
4
8
12
16
20
24
32
40
48
```

Avoid random spacing values unless a specific layout requires one.

---

# 84. Information Density

Administrative systems require moderately dense information.

Do not make everything excessively large just to create a modern appearance.

The interface should allow instructors and administrators to review meaningful amounts of information efficiently.

---

# 85. Desktop Content Width

Large screens should use available space intelligently.

Data-heavy pages may use wider layouts.

Forms and text-heavy settings screens should use narrower readable content widths.

Do not force every page into the same max-width.

---

# 86. PWA Installation

The tablet PWA should provide an intentional application identity.

Configure:

- application name
- short name
- icons
- theme color
- background color
- standalone display mode

Do not display installation prompts aggressively.

Installation may be handled centrally on managed tablets.

---

# 87. PWA Offline Assets

Essential application-shell assets should be available locally after installation.

Do not cache sensitive exam data carelessly.

Offline caching strategy must distinguish between:

- static application assets
- candidate responses
- authenticated API data
- sensitive exam content

Security takes priority over convenient caching.

---

# 88. Exam Start Experience

Before an exam begins, show a clear summary.

Example:

```text
Subject 1
Examination 01

Candidate:
Candidate 0214

Questions:
50

Duration:
60 minutes

Attempts:
1

Once started, the examination timer will begin.

[ Start Examination ]
```

Avoid immediately starting an exam simply because the candidate opened the page.

---

# 89. Question Navigation

When allowed by exam settings, provide question navigation.

Example:

```text
1  2  3  4  5
6  7  8  9  10
```

Possible states:

```text
Current
Answered
Unanswered
Flagged
```

Do not use color alone to distinguish these states.

---

# 90. Flag for Review

Where permitted, candidates may flag questions for later review.

Example:

```text
[ Flag for Review ]
```

This should not modify scoring.

---

# 91. Essay Questions

Essay inputs should:

- provide sufficient vertical space
- autosave
- preserve line breaks
- indicate save status

Avoid tiny textareas.

---

# 92. Instructor Manual Grading

Manual essay grading should provide:

```text
Candidate
Question
Response
Maximum Points
Score
Instructor Comment
```

Allow efficient movement between candidates.

Avoid making instructors return to a list after every graded response.

---

# 93. Finalized Grades

When a grade is finalized, communicate the state clearly.

Example:

```text
Finalized
```

Editing finalized grades should require a deliberate action and possibly a reason.

Do not make finalized and draft grades visually identical.

---

# 94. Draft States

Clearly distinguish:

```text
Draft
Published
Active
Ended
Archived
```

for assessments.

Do not rely only on page context.

---

# 95. Skeleton Screens

For lists and dashboards, prefer content-shaped skeletons.

Do not show a full-page spinner for normal navigation unless truly necessary.

---

# 96. Destructive Colors

Reserve strong red primarily for:

- destructive actions
- critical errors
- failing states

Do not use red casually for neutral emphasis.

---

# 97. Warning Fatigue

Do not show warning banners everywhere.

Only elevate information that genuinely requires attention.

If everything is presented as urgent, nothing feels urgent.

---

# 98. Copy Examples

Good:

```text
3 candidates are currently failing Subject 2.
```

Less useful:

```text
Warning! Critical academic situation detected!
```

Use professional, factual language.

---

# 99. No Fake Military Styling

Do not introduce decorative elements such as:

- camouflage backgrounds
- tactical grids
- crosshairs
- aggressive stencil fonts
- weapon imagery
- military-game UI

The institution may be military, but this is an academic administrative application.

Professional institutional styling is more appropriate.

---

# 100. Design Review Checklist

Before considering a screen complete, verify:

## Hierarchy

- Is the most important information obvious?
- Is there one clear primary action?
- Are sections easy to scan?

## Usability

- Can the user understand the page without explanation?
- Are controls predictable?
- Are errors recoverable?

## Responsiveness

- Does it work on desktop?
- Does it work on tablet?
- Does important content remain readable?

## Accessibility

- Are labels present?
- Is contrast sufficient?
- Are focus states visible?
- Can the screen work without a mouse?

## Consistency

- Does it match the rest of the system?
- Are spacing and typography consistent?
- Are status components reused?

## Reliability

- Are loading states present?
- Are errors handled?
- Is save status understandable?

---

# 101. Candidate Exam Checklist

Before releasing an examination interface, verify:

- candidate identity is clearly shown
- exam title is visible
- timer is visible
- progress is visible
- answer controls are large enough
- selected answer is obvious
- save state is shown
- temporary connection loss is handled
- navigation follows configured exam rules
- accidental submission is prevented
- unanswered questions are identified before submission
- tablet portrait layout works
- tablet landscape layout works
- refresh/recovery behavior is tested

---

# 102. Administrator Screen Checklist

For administrative pages:

- page purpose is immediately understandable
- primary action is obvious
- filtering is easy
- search has appropriate scope
- table columns are useful
- actions are permission-aware
- destructive actions require confirmation
- pagination exists when needed
- loading, empty, and error states exist

---

# 103. Final Design Principle

The best interface for this project should become almost invisible to the user.

An instructor should focus on:

- candidates
- grades
- examinations
- academic performance

not on learning the software.

A candidate should focus on:

- reading the question
- selecting an answer
- completing the examination

not on figuring out navigation.

An administrator should focus on:

- overall academic status
- candidates requiring attention
- organizational oversight

not on searching through complicated menus.

Every design decision should support those outcomes.
