# Kajal Bot – Notification & Escalation Message Templates

**Platform:** Zoho Cliq (Kajal Bot)
**Scope:** Full batch lifecycle – batch start → module / activity events → stage transitions → batch closure
**Status:** DRAFT – for review with Balwant Sir
**Owner of this document:** Deepak

---

## Contents

1. [Overview](#1-overview)
2. [Lifecycle Pattern](#2-lifecycle-pattern)
3. [Conventions](#3-conventions)
4. [Templates by Recipient](#4-templates-by-recipient)
   - [4.1 Program Manager (PM)](#41-program-manager-pm)
   - [4.2 Student Success Executive (SSE)](#42-student-success-executive-sse)
   - [4.3 Class Mentor](#43-class-mentor)
   - [4.4 Lab Mentor](#44-lab-mentor)
5. [Escalation Matrix](#5-escalation-matrix)
6. [Placeholder Dictionary](#6-placeholder-dictionary)
7. [Open Items for Review](#7-open-items-for-review)

---

## 1. Overview

Every trackable activity in the LMS has a lifecycle. Whenever a mentor, Program Manager (PM), Student Success Executive (SSE) or lab mentor performs an activity in the LMS (or fails to do it on time), the Kajal bot sends the appropriate message on Zoho Cliq.

Each activity type declares:

- an **owner** (the role responsible),
- a **due rule** (often a module-relative anchor, e.g. "2 days before module end"),
- an **escalation threshold** (how many days of delay before the PM is notified).

This document groups every message by **who receives it**, so each role can see exactly what they will get from the bot.

---

## 2. Lifecycle Pattern

```
Due → Notify owner → (Reminder if not done) → Escalate to PM → Owner marks done → Confirmation
```

| Stage | What happens | Template type |
|---|---|---|
| 1. Due | Activity approaches its due date | Activity due |
| 2. Notify owner | Owner is told in advance | Activity due |
| 3. Reminder | Owner has not completed it | Activity due / Activity overdue |
| 4. Escalate | Delay crosses the threshold, PM is told | Escalation |
| 5. Done | Owner marks the activity complete in the LMS | Activity completed |
| 6. Confirmation | Owner and PM get confirmation | Activity completed |

---

## 3. Conventions

| Item | Convention |
|---|---|
| Placeholders | Written as `{placeholder}`; see [Section 6](#6-placeholder-dictionary) |
| Template IDs | `<ROLE>-<NN>` (PM-01, SSE-01, CM-01, LM-01). Same trigger keeps the same suffix logic across roles where possible |
| Severity icons | ℹ️ Info · 🔔 Reminder · ⏰ Overdue · 🚨 Escalation · ✅ Confirmation · 🎉 Milestone |
| Tone | Short, direct, one clear action. Every actionable message ends with what the person must do |
| Links | Every message that needs action ends with `{lms_link}` (deep link to the exact activity or batch page in the LMS) |
| Status tags | **Existing** = from the original catalogue (expanded). **NEW** = proposed addition for course activities / milestones |
| Thresholds | `{n}`, reminder lead time etc. are **configurable per activity type**. Suggested defaults are listed in [Section 5](#5-escalation-matrix) |

---

## 4. Templates by Recipient

### 4.1 Program Manager (PM)

The Program Manager receives batch-level and escalation messages. The PM is the escalation point for every owner role.

| ID | Trigger event | Status | Escalation | Message template |
|---|---|---|---|---|
| PM-01 | Batch created | Existing | — | ℹ️ **New batch created**<br>Batch: {batch_id} ({mode})<br>Course: {course_name}<br>Start date: {start_date} · Planned end: {planned_end}<br>You are assigned as **Program Manager**.<br>View batch: {lms_link} |
| PM-02 | Escalation | Existing | PM is the escalation target | 🚨 **ESCALATION**<br>Activity: {activity}<br>Batch / Module: {batch_id} / {module}<br>Owner: {owner} ({owner_role})<br>Overdue by: {delay_days} day(s)<br>Due date was: {due_date}<br>The owner has been reminded but has not completed this. Please follow up.<br>{lms_link} |
| PM-03 | Schedule slip | Existing | — | ⚠️ **Schedule slip**<br>{batch_id} / {module} is delayed by {delay_days} day(s) vs plan.<br>Planned end: {planned_end} · Revised end: {revised_end}<br>Downstream modules affected: {affected_modules}<br>Review recommended.<br>{lms_link} |
| PM-04 | Activity completed | Existing | — | ✅ **Activity completed**<br>{activity} for {batch_id} / {module} marked done by {owner} on {date}.<br>{lms_link} |
| PM-05 | Stage transition | Existing | — | ℹ️ **Stage transition**<br>{batch_id} moved from {module} to {next_module}.<br>New mentor: {next_mentor}<br>SS activities have been re-anchored to the new module dates.<br>{lms_link} |
| PM-06 | Nomination raised | Existing | — | 🎉 **Nomination raised**<br>{owner} nominated {count} student(s) for {award_type} in {batch_id}.<br>Nominees: {nominee_names}<br>Approval pending. Please review and approve / reject.<br>{lms_link} |
| PM-07 | Nomination pending too long | NEW | → Reminder to PM | 🔔 **Nomination awaiting your approval**<br>{award_type} nomination in {batch_id} (raised by {owner} on {date}) is pending for {pending_days} day(s).<br>Please approve or reject.<br>{lms_link} |
| PM-08 | Batch closure | Existing | — | 🎉 **Batch completed**<br>{batch_id} completed on {end_date}.<br>Pending closure items:<br>• Closure meeting<br>• Final review<br>Please schedule and complete both by {closure_due_date}.<br>{lms_link} |
| PM-09 | Module completed | NEW | — | ✅ **Module completed**<br>{module} for {batch_id} ended on {actual_end} (planned {planned_end}).<br>Variance: {delay_days} day(s).<br>Pending activities in this module: {pending_count}.<br>{lms_link} |
| PM-10 | Mid-batch review due | NEW | → Escalate if not held | 🔔 **Mid-batch review due**<br>{batch_id} has reached its mid-point ({midpoint_date}).<br>Please schedule the mid-batch review with {reviewers}.<br>{lms_link} |
| PM-11 | Weekly batch digest | NEW | — | ℹ️ **Weekly digest – {batch_id}**<br>Week of {week_start}<br>Current module: {module}<br>Activities done: {done_count} · Due this week: {due_count} · Overdue: {overdue_count}<br>Schedule status: {schedule_status}<br>{lms_link} |
| PM-12 | Repeated overdue by same owner | NEW | — | 🚨 **Repeated delays**<br>{owner} has {overdue_count} overdue activities in {batch_id} (oldest: {oldest_activity}, {delay_days}d).<br>Please discuss with the owner.<br>{lms_link} |

---

### 4.2 Student Success Executive (SSE)

The Student Success Executive receives batch-setup, transition, and closure messages for batches they support.

| ID | Trigger event | Status | Escalation | Message template |
|---|---|---|---|---|
| SSE-01 | Batch created | Existing | — | ℹ️ **New batch assigned**<br>Batch: {batch_id} ({mode})<br>Course: {course_name}<br>Start date: {start_date}<br>You are assigned as **Student Success Executive**.<br>View batch: {lms_link} |
| SSE-02 | Stage transition | Existing | — | ℹ️ **Stage transition**<br>{batch_id} moved from {module} to {next_module}.<br>SS activities have been re-anchored. Please review your upcoming SS activities and due dates.<br>{lms_link} |
| SSE-03 | SS activity due | NEW | → PM at threshold | 🔔 **Reminder:** {activity} for {batch_id} / {module} is due {due_date}.<br>Please complete it in the LMS.<br>{lms_link} |
| SSE-04 | SS activity overdue | NEW | → PM after {n} days | ⏰ **Overdue:** {activity} for {batch_id} / {module} is overdue by {delay_days} day(s).<br>Complete it now, otherwise it will be escalated to the PM.<br>{lms_link} |
| SSE-05 | Activity completed | NEW | — | ✅ {activity} for {batch_id} / {module} marked done by {owner} on {date}. Thank you.<br>{lms_link} |
| SSE-06 | Schedule slip | NEW | — | ⚠️ {batch_id} / {module} is delayed by {delay_days} day(s) vs plan. Your SS activities may be re-anchored.<br>{lms_link} |
| SSE-07 | Batch closure | Existing | — | 🎉 **Batch completed**<br>{batch_id} completed on {end_date}.<br>Closure meeting and final review are due by {closure_due_date}.<br>Please complete your closure inputs.<br>{lms_link} |

---

### 4.3 Class Mentor

The Class Mentor receives module-level and activity-level messages for theory / classroom delivery.

| ID | Trigger event | Status | Escalation | Message template |
|---|---|---|---|---|
| CM-01 | Module started | Existing | — | ℹ️ **Module started**<br>{module} started for {batch_id} on {actual_start}.<br>Planned end: {planned_end}.<br>Activities due in this module: {activity_count}.<br>{lms_link} |
| CM-02 | Activity due | Existing | → PM at threshold | 🔔 **Reminder:** {activity} for {batch_id} / {module} is due {due_date}.<br>Please complete it in the LMS.<br>{lms_link} |
| CM-03 | Activity overdue | Existing | → PM after {n} days | ⏰ **Overdue:** {activity} for {batch_id} / {module} is overdue ({delay_days}d).<br>Complete it now, otherwise it will be escalated to the PM.<br>{lms_link} |
| CM-04 | Activity completed | Existing | — | ✅ {activity} for {batch_id} / {module} marked done by you on {date}. Thank you.<br>{lms_link} |
| CM-05 | Stage transition (next mentor) | Existing | — | ℹ️ **You are up next**<br>{batch_id} moved from {module} to {next_module}.<br>You are the mentor for {next_module}, starting {next_start_date}.<br>Please review the module plan and activities.<br>{lms_link} |
| CM-06 | Module ending soon | NEW | — | 🔔 {module} for {batch_id} ends on {planned_end} ({days_left} day(s) left).<br>Pending activities: {pending_activities}.<br>Please complete them before the module closes.<br>{lms_link} |
| CM-07 | Attendance not marked | NEW | → PM after {n} days | 🔔 Attendance for {batch_id} / {module} on {session_date} has not been marked.<br>Please update it in the LMS today.<br>{lms_link} |
| CM-08 | Assessment scheduled | NEW | — | 🔔 **Assessment scheduled**<br>{assessment_name} for {batch_id} / {module} is scheduled on {assessment_date}.<br>Please make sure questions / papers are ready.<br>{lms_link} |
| CM-09 | Assessment evaluation pending | NEW | → PM at threshold | ⏰ Evaluation of {assessment_name} for {batch_id} / {module} is pending ({delay_days}d). Please upload marks / results.<br>{lms_link} |
| CM-10 | Nomination submitted (confirmation) | Existing (confirmation) | — | ✅ Your nomination of {count} student(s) for {award_type} in {batch_id} has been sent to the PM for approval.<br>{lms_link} |
| CM-11 | Nomination approved | NEW | — | 🎉 Your {award_type} nomination in {batch_id} has been **approved** by {approver}.<br>Approved: {approved_names}.<br>{lms_link} |
| CM-12 | Nomination rejected | NEW | — | ℹ️ Your {award_type} nomination in {batch_id} was **not approved** by {approver}.<br>Remarks: {remarks}.<br>You may re-submit with changes.<br>{lms_link} |
| CM-13 | Batch closure | NEW | — | 🎉 {batch_id} was completed on {end_date}. Thank you for your contribution.<br>Please complete any pending feedback / closure inputs by {closure_due_date}.<br>{lms_link} |

---

### 4.4 Lab Mentor

The Lab Mentor receives messages for lab sessions, practicals, and lab-related activities.

| ID | Trigger event | Status | Escalation | Message template |
|---|---|---|---|---|
| LM-01 | Module started | Existing | — | ℹ️ **Module started**<br>{module} started for {batch_id} on {actual_start}.<br>Planned end: {planned_end}.<br>Lab activities in this module: {lab_activity_count}.<br>{lms_link} |
| LM-02 | Activity due | Existing | → PM at threshold | 🔔 **Reminder:** {activity} for {batch_id} / {module} is due {due_date}.<br>Please complete it in the LMS.<br>{lms_link} |
| LM-03 | Activity overdue | Existing | → PM after {n} days | ⏰ **Overdue:** {activity} for {batch_id} / {module} is overdue ({delay_days}d).<br>Complete it now, otherwise it will be escalated to the PM.<br>{lms_link} |
| LM-04 | Activity completed | Existing | — | ✅ {activity} for {batch_id} / {module} marked done by you on {date}. Thank you.<br>{lms_link} |
| LM-05 | Stage transition (next mentor) | Existing | — | ℹ️ **You are up next**<br>{batch_id} moved from {module} to {next_module}.<br>You are the lab mentor for {next_module}, starting {next_start_date}.<br>Please verify lab readiness.<br>{lms_link} |
| LM-06 | Lab readiness check due | NEW | → PM at threshold | 🔔 Lab readiness for {batch_id} / {module} must be confirmed by {due_date} (systems, software, access, datasets).<br>Please update the checklist in the LMS.<br>{lms_link} |
| LM-07 | Lab session attendance not marked | NEW | → PM after {n} days | 🔔 Lab attendance for {batch_id} / {module} on {session_date} has not been marked.<br>Please update it today.<br>{lms_link} |
| LM-08 | Practical / lab assessment evaluation pending | NEW | → PM at threshold | ⏰ Evaluation of {assessment_name} (lab) for {batch_id} / {module} is pending ({delay_days}d).<br>Please upload marks / results.<br>{lms_link} |
| LM-09 | Nomination submitted (confirmation) | Existing (confirmation) | — | ✅ Your nomination of {count} student(s) for {award_type} in {batch_id} has been sent to the PM for approval.<br>{lms_link} |
| LM-10 | Nomination approved / rejected | NEW | — | Use CM-11 / CM-12 templates. |

---

## 5. Escalation Matrix

Suggested defaults, to be confirmed with Balwant Sir. All values are configurable per activity type.

| Activity category | Owner | Reminder before due | Overdue message | Escalate to PM after | Notes |
|---|---|---|---|---|---|
| Module activity (classroom) | Class Mentor | 1 day before and on due date | Daily | {n} = 2 days overdue | Sent via CM-02 / CM-03 / PM-02 |
| Lab activity | Lab Mentor | 1 day before and on due date | Daily | {n} = 2 days overdue | Sent via LM-02 / LM-03 / PM-02 |
| SS activity | SSE | 1 day before and on due date | Daily | {n} = 2 days overdue | Sent via SSE-03 / SSE-04 / PM-02 |
| Attendance | Class / Lab Mentor | Same-day end-of-day nudge | Next morning | {n} = 1 day overdue | CM-07 / LM-07 |
| Assessment evaluation | Class / Lab Mentor | 1 day before due | Daily | {n} = 2 days overdue | CM-09 / LM-08 |
| Nomination approval | PM | — | Reminder to PM after 2 days pending | — | PM-07 |
| Closure items | PM, SSE | At batch end | Daily | — | PM-08 / SSE-07 |

**Quiet hours:** no bot messages outside {working_hours_start}–{working_hours_end} (reminders are queued for the next working morning). *To be confirmed.*

---

## 6. Placeholder Dictionary

| Placeholder | Meaning | Example |
|---|---|---|
| `{batch_id}` | Batch identifier | B2026-014 |
| `{mode}` | Delivery mode | Online / Offline / Hybrid |
| `{course_name}` | Course name | Full Stack Development |
| `{start_date}` | Batch start date | 05 Oct 2026 |
| `{end_date}` | Actual batch end date | 20 Dec 2026 |
| `{planned_end}` | Planned end date of batch / module | 15 Nov 2026 |
| `{revised_end}` | Revised end date after a slip | 18 Nov 2026 |
| `{role}` | Role assigned to the recipient | PM / SSE |
| `{module}` / `{next_module}` | Module name | Core Java |
| `{actual_start}` / `{actual_end}` | Actual module start / end date | 12 Oct 2026 |
| `{activity}` | Activity name | Weekly assessment report |
| `{activity_count}` / `{lab_activity_count}` | Number of activities in module | 6 |
| `{pending_activities}` / `{pending_count}` | Pending activities (names / count) | 2 |
| `{owner}` / `{owner_role}` | Person / role responsible | Ravi Kumar / Class Mentor |
| `{next_mentor}` | Mentor of the next module | Anita S. |
| `{due_date}` | Due date of activity | 10 Oct 2026 |
| `{delay_days}` | Days overdue / delayed | 3 |
| `{pending_days}` | Days an approval has been pending | 2 |
| `{days_left}` | Days remaining | 2 |
| `{n}` | Escalation threshold in days | 2 |
| `{date}` | Date of the event | 10 Oct 2026 |
| `{session_date}` | Date of class / lab session | 09 Oct 2026 |
| `{count}` | Number of students nominated | 3 |
| `{award_type}` | Award category | Spot Award |
| `{nominee_names}` / `{approved_names}` | Student names | A, B, C |
| `{approver}` | Person who approved / rejected | PM name |
| `{remarks}` | Approver's comments | Needs more evidence |
| `{assessment_name}` | Assessment name | Module 2 Test |
| `{assessment_date}` | Assessment date | 12 Oct 2026 |
| `{affected_modules}` | Modules shifted due to slip | Module 3, Module 4 |
| `{midpoint_date}` | Batch mid-point date | 15 Nov 2026 |
| `{reviewers}` | Review participants | Balwant Sir, SSE |
| `{closure_due_date}` | Deadline for closure items | 27 Dec 2026 |
| `{week_start}` | Start of week for digest | 05 Oct 2026 |
| `{done_count}` / `{due_count}` / `{overdue_count}` | Activity counts | 8 / 3 / 1 |
| `{schedule_status}` | On track / Delayed | On track |
| `{oldest_activity}` | Oldest overdue activity | Lab report |
| `{lms_link}` | Deep link to the relevant LMS page | https://… |

---

## 7. Open Items for Review

To be discussed with **Balwant Sir**:

1. **Spot Award template reference:** the Spot Award format was not available while drafting this document. The templates here follow the original catalogue's format (Trigger event / Recipient / Escalation / Message template) and are expanded with title, details, and next action. Please confirm this matches the Spot Award style, and adjust if needed.
2. **Escalation thresholds:** confirm the default values in [Section 5](#5-escalation-matrix) (reminder lead time, `{n}` days).
3. **Second-level escalation:** if the PM does not act on an escalation, should it go to a higher level (e.g. Balwant Sir / Head)? Not defined in the current catalogue.
4. **NEW templates:** confirm which proposed templates (module completed, attendance, assessment, mid-batch review, weekly digest, repeated delays, nomination approved / rejected) should go live.
5. **Student-facing messages:** the current catalogue covers staff roles only. Decide whether students (e.g. award approval, assessment reminders) should also get Kajal bot messages.
6. **Frequency control:** limit of reminders per day per person to avoid notification fatigue.
7. **Course-specific milestones:** list the key milestones per course (project kickoff, project review, mock interviews, placement readiness) so dedicated templates can be added.

---

*Document version: 0.1 (Draft)*
