# Milestone 19 — Testing and Stability

Record of the Milestone 19 verification (2026-10-01). Automated tests are PHPUnit (`php artisan test`, about 7–8 minutes, MariaDB test database `academic_system_testing`). Never run two test processes against the same database.

## Automated coverage by area

| Area | Item | Main tests |
| --- | --- | --- |
| Authentication | Valid login | `Auth/LoginTest` (staff → dashboard, candidate → portal, username trimmed/case-insensitive) |
| | Invalid login | `Auth/LoginTest` (same message for wrong password and unknown user, missing fields, deactivated, lockout after 5 failures); `Security/AccountHardeningTest` |
| | Role restrictions | `Auth/AreaAccessTest`; `Security/RouteAccessMatrixTest` (every route × every role); **new:** `LoginTest::test_a_page_remembered_before_sign_in_is_opened_only_within_the_users_own_area` |
| Academic management | Candidate creation | `Candidates/CandidateManagementTest` |
| | Instructor assignment | `Academic/InstructorAssignmentTest` |
| | Class assignment | `CandidateManagementTest`; **new:** `test_a_candidate_moves_to_another_class_and_unknown_classes_are_refused` |
| | Subject assignment | `Academic/ClassBatchTest`, `Academic/AcademicStructureTest` |
| Grade engine | Weighted scores | `Unit/GradeCalculationServiceTest`, `Grading/GradebookAndIntegrationTest` |
| | Academic standing | `Unit/AcademicStandingTest`, `Grading/StandingEngineDatabaseTest` |
| | Missing grades | `GradeCalculationServiceTest`, `AcademicStandingTest`, `Grading/AcademicStandingIntegrationTest` |
| | Threshold changes | `AcademicStandingIntegrationTest`, `StandingEngineDatabaseTest`, `Grading/GradingThresholdConfigurationTest` |
| Examinations | Unpublished / published access | `Examinations/Delivery/AttemptLifecycleTest`, `Portal/CandidateExaminationTest` |
| | Timer (server deadline) | `AttemptLifecycleTest` (duration vs closing time, saves at the deadline) |
| | Autosave | `AttemptLifecycleTest` (revisions, idempotent retry, wrong shapes) |
| | Refresh recovery | `AttemptLifecycleTest`, `CandidateExaminationTest`; **new:** `Delivery/AttemptRecoveryTest::test_reopening_after_time_passes_keeps_the_deadline_and_the_saved_answers` |
| | Temporary disconnection | `AttemptLifecycleTest` (heartbeat gap, stale revision, lost responses), `Delivery/ExamHardeningTest`; **new:** `AttemptRecoveryTest` (queued saves replayed in order after a gap; queued save after the deadline not applied) |
| | Manual / automatic submission | `AttemptLifecycleTest`, `Delivery/ExpiryCommandTest` |
| | Duplicate submission | `AttemptLifecycleTest`, `ExpiryCommandTest`; **new:** `AttemptRecoveryTest::test_submission_locks_the_attempt_row_so_simultaneous_submits_cannot_both_apply` |
| | Objective scoring | `Delivery/ObjectiveScoringTest` |
| | Essay grading | `Grading/EssayGradingTest`, `ResultReleaseTest`, `EssayGradebookPostingTest` |
| Authorization | Class, grade, exam and candidate access | `Teaching/TeachingClassTest`, `Teaching/InstructorCandidateAccessTest`, `Grading/ScoreRecordingTest`, `Grading/AssessmentLifecycleTest`, `Builder/ExaminationBuilderAccessTest`, `RouteAccessMatrixTest`, `Candidates/CandidateProfileTest` |

The candidate page's offline storage and replay (`resources/js/lib/exam-recovery.ts`, `resources/js/pages/portal/examinations/attempt.tsx`) has no JavaScript test runner; it was verified manually (below).

## Manual verification (local server, client demo data)

| Workflow | Result |
| --- | --- |
| Wrong password | "The username or password is incorrect." — no account details revealed |
| Candidate sign-in after a staff page was remembered | **Bug found and fixed** (see below) |
| Start "Online Quiz 1 — Subject 2" as `student05` | Rules page, start, timer 20:00, question 1 of 5 |
| Autosave | "Saved" after each answer |
| Reload in the middle | Same question, same answers, timer continued (deadline kept by the server) |
| Server unreachable (server stopped, Wi-Fi still up) | **Bug found and fixed**; after the fix: "Offline – saved on this device", the candidate keeps moving between questions and writes the essay offline |
| Reload while answers are queued | Queued answers restored from the tablet and sent; "Saved" |
| Server back | Queue sent in order within 10 seconds; all 5 answers on the server, including the essay written offline |
| Submit | Confirmation with answered count; success page; objective 8.00 / 8.00, essay pending |
| Second submit / late autosave | Ignored; answers unchanged; status "submitted" |
| Essay grading as `instructor2` | Score 1.5 / 2 saved, final 95.00 %, grading history "— → 1.50"; leave-screen record shown |

Automatic submission at the time limit was verified by the automated tests only (`ExpiryCommandTest`, `AttemptLifecycleTest`), not by waiting out a 20-minute attempt.

## Bugs found and fixed

1. **Server unreachable while the tablet's Wi-Fi is up (high).** After one answer had been queued, "Save and Next" tried to send the queue, got a network error and reported "Unable to sync — Failed to fetch"; the candidate could not move to the next question until the server returned. Network failures, 5xx gateway errors, timeouts (408) and rate limiting (429) while sending the queue now mean "offline": the new answer joins the queue in order and the candidate continues. Nothing was ever lost (answers stayed on the tablet), but the examination stalled.
2. **Taps lost during an autosave (medium).** The background autosave and sync disabled the navigation buttons, so tapping "Save and Next" right after choosing an answer did nothing; confirming Submit during an autosave reported a false connection failure. Background saves no longer disable the buttons; a tap waits for the save in progress (up to 15 seconds) and then continues.
3. **Sign-in to a page outside the user's area (low).** Signing in redirected to the page remembered before sign-in even when it belonged to the other area (for example a staff page remembered on a shared tablet, then a candidate signs in), showing "You do not have access to this page". The remembered page is now used only when it is in the signed-in user's own area; otherwise the user goes to their home page.

## Known limitations (not defects)

- The leave-screen indicator records the page being hidden, including when the device's window is in the background; it is an indicator for instructors, not proof.
- There is no JavaScript unit-test runner; frontend behaviour is covered by the manual checks above and by the server-side contract tests.
