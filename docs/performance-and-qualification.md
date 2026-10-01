# Performance Areas, Qualification and Class Rank

This document explains, in plain language, how the system decides whether a candidate is **qualified**, how the **overall score** and **class rank** are worked out, and how **merits/demerits** and **attendance** feed into them. It also explains how an administrator sets everything up, what candidates can and cannot see, and which rules still need to be confirmed by OCS.

> **Every number below is a placeholder.** Areas, weights, passing grades, the conduct rule, the merit/demerit catalogue and session hours are all configurable in the application. The values seeded for the demo come from a suggested military-school structure and must be replaced by the official grading SOP once OCS provides it.

The existing **academic standing** (Passing / At Risk / Failing / Incomplete per subject, with the period's passing and warning grades) is not changed by anything here. Performance areas are a layer on top that reads the same subject grades.

All calculations are done on the server, in one place (`App\Services\Performance\QualificationEngine`, with `ConductLedger` and `AttendanceLedger` for points and attendance). Nothing is stored: results are worked out whenever a page is opened, so a new grade, a voided demerit or a changed passing grade shows immediately everywhere.

---

## 1. Performance areas

A performance area is one thing candidates are assessed in, for example "Academic", "Military Skills", "Physical Fitness", "Conduct" or "Attendance". Each area has:

| Setting | Meaning |
| --- | --- |
| **Source** | Where the grade comes from: *Subjects*, *Military fitness*, *Conduct* or *Attendance*. |
| **Weight** | The area's share in the overall score (0–100). Weights are relative; they do not have to add up to 100. A weight of 0 leaves the area out of the overall score (it can still be required to qualify). |
| **Passing grade** | The grade needed to pass the area (above 0, up to 100). A grade equal to the passing grade passes. Grades are compared to two decimals, so a grade shown as 75.00 always passes a passing grade of 75. |
| **Must pass** | Whether the area must be passed to qualify. |
| **Active** | Only active areas count. Areas are deactivated, never deleted. |

Only one **active** area may use each of the fitness, conduct and attendance sources. Any number of subject areas is allowed, and each subject counts toward at most one area.

### How each area's grade is found

**Subjects.** The candidate's class subjects that belong to the area. The area grade is the average of the current subject grades (the same grades as academic standing, from finalized assessments), rounded half up to two decimals. A subject with no grade yet is left out of the average (the result says, for example, "Based on 1 of 2 subjects").

**Military fitness.** The latest fitness test of the candidate's class that has any results. The grade is the candidate's points in that test.
- Not tested in that test → *No results yet*.
- Some events without a result → *Incomplete*.
- An event standard not met → *Failed*.
- Every event passed → *Passed* when the points reach the area's passing grade, otherwise *Failed*.

**Conduct.** A rating out of 100:

> rating = base rating + (merit points × value of a merit point) − (demerit points × value of a demerit point), limited to 0–100.

Only merits and demerits that count (not voided) are used. Example with the placeholder rule (base 85, each point worth 1): 3 merit points and 5 demerit points give 85 + 3 − 5 = 83.

**Attendance.** The attendance rate of the candidate's current class:

> rate = (present + late) ÷ (present + late + absent) × 100, rounded half up to two decimals.

Excused sessions and sessions not yet recorded are left out. Example: 5 present, 1 late, 1 excused, 1 absent → 6 ÷ 7 = 85.71.

### Area status

| Status | When |
| --- | --- |
| **Passed** | The grade reaches the passing grade (and, for subjects, no score is missing). |
| **Failed** | The grade is below the passing grade, or a fitness event standard was not met. |
| **Incomplete** | Subjects: the grade passes but a subject still has missing scores (the same meaning as in academic standing). Fitness: some events have no result. |
| **No results yet** | Nothing to grade yet: no subject grades, not tested, or no attendance that counts. |

## 2. Overall score

> overall score = Σ (weight × area grade) ÷ Σ (weight)

over the active areas that have a weight above 0 **and** a grade, rounded half up to two decimals. If some weighted areas have no grade yet, the score is shown as **Partial**. If no weighted area has a grade, there is no overall score.

## 3. Qualification

1. **Not Qualified** when any must-pass area is *Failed*. The reasons are listed as "{Area} requirement not met", in area order.
2. Otherwise **Pending** while any must-pass area is *Incomplete* or has *No results yet*.
3. Otherwise **Qualified**.

Areas that are not must-pass never change the decision; they only count toward the overall score. While no area is active at all, everyone is **Pending**, so nobody is reported Qualified before the areas are set up. (With active areas but none marked must-pass, everyone is Qualified; the Performance Areas page warns about this.)

## 4. Class rank

Candidates are ranked **within their class** by overall score, highest first. Equal scores (to two decimals) share a rank and the next rank is skipped: 1, 2, 2, 4. Candidates without an overall score and withdrawn candidates are not ranked.

The rank is **staff only** (permission *View qualification and class ranking*). It is never sent to the candidate portal.

## 5. Merits and demerits

- Recorded by staff with *Record merits and demerits* (**Records → Merits & Demerits**): choose a type (grouped Merits / Demerits; the kind and default points come from the type), adjust the points (1–100) if needed, the date (not in the future) and a reason.
- Administrators see every candidate; instructors only candidates of the classes they teach.
- Entries are never edited or deleted. A mistake is **voided** with a reason (5–255 characters) and entered again. Voided entries stay visible to staff, struck through and marked "Not counted"; they are left out of every total.
- Withdrawn candidates receive no new entries, but existing entries can still be voided.
- Types (name, kind, default points, description, order, active) are configured under **Merits & Demerits → Merit & Demerit Types** by administrators. Changing a type never changes entries already recorded.

## 6. Attendance

- Staff with *Record attendance* create training **sessions** for a class (date — not in the future —, title, hours, notes) under **Records → Attendance**, then take the **roll call**: Present, Late, Excused or Absent for each candidate, with optional remarks. "Mark All Unrecorded as Present" fills the rest; only changed rows are saved.
- Only candidates of the session's class who are not withdrawn can be recorded. Every change is audited.
- A session can be deleted only while it has no recorded attendance.
- **Hours attended** = the hours of the sessions where the candidate was present or late.

## 7. Company and platoon

Each candidate may have a company and a platoon (free text, up to 50 characters, on the candidate form). They are used to filter candidate lists and the qualification page. They do not affect any calculation; ranks are always within the whole class.

## 8. Who sees what

| Where | Who | What |
| --- | --- | --- |
| **Records → Qualification** | Administrators (*View qualification and class ranking*) | Every candidate of a class: area grades and statuses, overall score, qualification with reasons, class rank; filters by company, platoon and status; counts and a chart. Printable from the browser. |
| **Dashboard → Qualification** | Same | Counts per status across the classes of the active period and the most common unmet requirement. |
| **Candidate profile → Performance & Qualification** | Users who view all candidates | Area results, overall score, qualification with reasons; the class rank only with *View qualification and class ranking*. Instructors do not see this panel: area grades combine subjects they do not teach and fitness results they cannot view. |
| **Candidate profile → Conduct** | Staff who may record merits/demerits for the candidate, and users who view all candidates | Totals and the latest entries (voided ones marked), link to the full record. |
| **Candidate profile → Attendance** | Staff who keep the attendance of the candidate's class, and users who view all candidates | Rate, hours, counts and the latest sessions with links to the roll calls. |
| **Portal → My Performance** | The candidate, for themselves only | Their area grades and statuses, overall score, a checklist of the required areas (Passed / Pending / Not met — always as text with an icon), their merits and demerits that count, their attendance summary and recent sessions. A **My Records** tile on Home links there. |
| **Portal → Physical Fitness** | The candidate, for themselves only | Their fitness tests: each event's result, points and standard, the overall result, and charts of event points and of progress across tests. |

**Candidates never see:** the class rank, anything about other candidates, voided entries, staff names, or the remarks staff write on the roll call.

## 9. Setting it up (administrators)

1. **Subjects.** Make sure every subject that should count exists (Academics → Subjects) and is offered to the class.
2. **Merit and demerit types.** Records → Merits & Demerits → *Merit & Demerit Types*. Edit the placeholder types or add the official catalogue (name, merit or demerit, default points). Deactivate types that are not used.
3. **Fitness standards.** Records → Military Fitness → *Fitness Standards*: the official events with their passing and maximum values.
4. **Performance areas.** Records → Qualification → *Performance Areas* → *Add Area* (or edit a placeholder):
   1. Name, optional description, and the **source**.
   2. **Weight** and **passing grade**; tick **Must pass to qualify** when required.
   3. For a *Subjects* area, tick its subjects (a subject moves out of any other area). For a *Conduct* area, enter the base rating and the value of a merit and a demerit point.
   4. Set the order and keep it **active**. Save.
   5. Check the warnings at the top of the list: subjects that count toward no area, and "No active area must be passed".
5. **Day to day.** Instructors and administrators record grades (as before), fitness results, merits/demerits and attendance. Qualification updates by itself.
6. **Review.** Records → Qualification: choose the class (the active period first), filter by company, platoon or status, and print if needed.

Every change to areas, types, entries, sessions and records is written to the audit history with the previous and new values.

## 10. Demo data

`DemoPerformanceSeeder` (run at the end of `ClientDemoSeeder`; safe to run again) gives the 20 demo students a company and platoon (Alpha/Bravo Company, 1st/2nd Platoon), creates the five placeholder areas (Academic: Subject 1, weight 40, pass 75; Military Skills: Subject 2, 20, 75; Physical Fitness, 20, 60; Conduct, base 85, 1 per merit/demerit point, 10, 75; Attendance, 10, 90 — all must pass), a spread of merits and demerits, six attendance sessions with varied statuses and a "Midterm Fitness Test". With the demo grades the result is 6 Qualified, 3 Pending and 11 Not Qualified (academic, fitness, conduct or attendance). All of it is synthetic.

## 11. Questions to confirm with OCS

1. **Official grading SOP and weights.** Which areas candidates are assessed in, and each area's weight in the overall score. Should the weights add up to 100?
2. **Passing grades.** The passing grade of each area (placeholders: academic and military skills 75, fitness 60, conduct 75, attendance 90). Are they the same for every class?
3. **Must-pass areas.** Which areas must be passed to qualify (all five are must-pass in the placeholders)?
4. **Subjects per area.** Which subjects count toward which area (for example academic vs. military skills subjects)? Should a subject with no grade yet hold the area at Pending instead of being left out of the average?
5. **Conduct rule.** The base rating and the value of a merit and a demerit point, whether merits can raise the rating above the base, and the minimum conduct rating.
6. **Merit/demerit catalogue.** The official list of merits and demerits with their points, and who may record them.
7. **Attendance minimum.** The minimum attendance rate, whether *Late* counts as attended, whether *Excused* should count as attended or be left out (current rule: left out), and how training hours are counted.
8. **Physical fitness.** Whether the latest fitness test is the one that counts (current rule), or the best or an average of several tests.
9. **Company and platoon structure.** The official companies and platoons, and whether they should become fixed lists instead of free text.
10. **Rank.** Whether the class rank is published to candidates (currently staff only), how ties are broken, and whether rank should be within the class, the company or the whole period.
11. **Who sees qualification.** Whether instructors should see the qualification of the candidates in their classes (currently administrators only, because it combines subjects they do not teach and fitness results).
12. **Charges, deductions and accountabilities.** Which deductions and issued items/accountabilities are charged to candidates through **Expenses**, and whether pay and allowances need to be recorded at all. The categories exist ("Pay & Allowances", "Deductions", "Issued Items / Accountability"); no amounts or rates are hardcoded.
