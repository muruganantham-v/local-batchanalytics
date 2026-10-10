# Bug Report & Resolution Log — `local_batchanalytics` Plugin Audit

**Audit Date:** 2026-10-10  
**Resolution Date:** 2026-10-10  
**Plugin Path:** `moodle-local-New-UI-Batchanalytics`  
**Scope:** Full static code review & issue resolution — PHP services, JavaScript dashboard, DB schema, lang strings, settings, and block renderer.  
**Branch:** `feature/block-task`  
**GitHub Repository:** [muruganantham-v/local-batchanalytics](https://github.com/muruganantham-v/local-batchanalytics)  
**Overall Status:** **All 25 bugs resolved, verified, and closed on GitHub (Issues #8–#17).**

---

## Summary & Resolution Matrix

| # | Severity | Area | Short Description | Status | GitHub Issue | Commit |
|---|----------|------|-------------------|:------:|:------------:|:------:|
| 1 | Critical | Security | AJAX endpoints before capability check | **Resolved** | [#8](https://github.com/muruganantham-v/local-batchanalytics/issues/8) | `30eabc3` |
| 2 | Critical | Security | SS approval has no role or ownership verification | **Resolved** | [#9](https://github.com/muruganantham-v/local-batchanalytics/issues/9) | `124a0ff` |
| 3 | High | Logic | Cliq notification dedup misses multi-recipient separation | **Resolved** | [#14](https://github.com/muruganantham-v/local-batchanalytics/issues/14) | `56ab4ce` |
| 4 | High | Performance | `$defs` variable shadowed + rebuilt on every SS activity iteration | **Resolved** | [#14](https://github.com/muruganantham-v/local-batchanalytics/issues/14) | `56ab4ce` |
| 5 | High | Logic | Notifications only fire on exact day match (skipped cron drops alert) | **Resolved** | [#14](https://github.com/muruganantham-v/local-batchanalytics/issues/14) | `56ab4ce` |
| 6 | High | JS | Race condition: optimistic renderTodoList before server data applied | **Resolved** | [#15](https://github.com/muruganantham-v/local-batchanalytics/issues/15) | `0e135f2` |
| 7 | High | Logic | `am_closer` dashboard action redirects instead of writing `actualend` | **Resolved** | [#17](https://github.com/muruganantham-v/local-batchanalytics/issues/17) | `e4c19f6` |
| 8 | Medium | Performance | `resolve_user_personas` scans all sections on every page load | **Resolved** | [#10](https://github.com/muruganantham-v/local-batchanalytics/issues/10) | `ff6c64b` |
| 9 | Medium | Performance | `resolve_user_personas` called twice per request | **Resolved** | [#10](https://github.com/muruganantham-v/local-batchanalytics/issues/10) | `ff6c64b` |
| 10 | Medium | Logic | Escalated SS tasks hidden from admin role | **Resolved** | [#11](https://github.com/muruganantham-v/local-batchanalytics/issues/11) | `f40794a` |
| 11 | Medium | Data | SS approval falls through to raw key write on format mismatch | **Resolved** | [#12](https://github.com/muruganantham-v/local-batchanalytics/issues/12) | `f048001` |
| 12 | Medium | JS | `rejectApproval` can submit empty review_notes after confirm dialog | **Resolved** | [#15](https://github.com/muruganantham-v/local-batchanalytics/issues/15) | `0e135f2` |
| 13 | Medium | UX | Server-rendered initial todos cause flash of duplicate content | **Resolved** | [#15](https://github.com/muruganantham-v/local-batchanalytics/issues/15) | `0e135f2` |
| 14 | Medium | Lang | Block lang file missing `sslead_roles` and `sslead_email` strings | **Resolved** | [#16](https://github.com/muruganantham-v/local-batchanalytics/issues/16) | `ae72a5d` |
| 15 | Medium | Performance | Missing composite index on Cliq log dedup query | **Resolved** | [#17](https://github.com/muruganantham-v/local-batchanalytics/issues/17) | `e4c19f6` |
| 16 | Medium | Data | SS completion throws misleading error on invalid JSON softskillsdata | **Resolved** | [#12](https://github.com/muruganantham-v/local-batchanalytics/issues/12) | `f048001` |
| 17 | Medium | Logic | `am_sched` cannot update an already-set schedule | **Resolved** | [#13](https://github.com/muruganantham-v/local-batchanalytics/issues/13) | `6b5d92b` |
| 18 | Medium | UX | Greeting uses server wall clock, ignores Moodle user timezone | **Resolved** | [#13](https://github.com/muruganantham-v/local-batchanalytics/issues/13) | `6b5d92b` |
| 19 | Low | Logic | Stale `$days` variable in am_next_mentor forthcoming block | **Resolved** | [#13](https://github.com/muruganantham-v/local-batchanalytics/issues/13) | `6b5d92b` |
| 20 | Low | Logic | `is_already_sent` without `date_sent` permanently suppresses future alerts | **Resolved** | [#14](https://github.com/muruganantham-v/local-batchanalytics/issues/14) | `56ab4ce` |
| 21 | Low | JS | localStorage cross-tab sync has no listener | **Resolved** | [#15](https://github.com/muruganantham-v/local-batchanalytics/issues/15) | `0e135f2` |
| 22 | Low | UX | Modal "Go to Activity" href="#" causes page jump when no URL | **Resolved** | [#15](https://github.com/muruganantham-v/local-batchanalytics/issues/15) | `0e135f2` |
| 23 | Low | UX | Pagination page state lost after server data refresh | **Resolved** | [#15](https://github.com/muruganantham-v/local-batchanalytics/issues/15) | `0e135f2` |
| 24 | Low | Lang | Block lang file missing all Zoho Cliq strings | **Resolved** | [#16](https://github.com/muruganantham-v/local-batchanalytics/issues/16) | `ae72a5d` |
| 25 | Low | Observability | `send_cliq_message` returns only last recipient HTTP code | **Resolved** | [#14](https://github.com/muruganantham-v/local-batchanalytics/issues/14) | `56ab4ce` |

---

## Detailed Bug Descriptions & Resolution Notes

---

### BUG-01 [CRITICAL] — AJAX Endpoints Accessible Before Capability Check

- **File:** `index.php` (and `blocks/batchanalytics/index.php`)
- **Severity:** Critical / Security
- **Status:** **Resolved** (Closed)
- **GitHub Issue:** [#8](https://github.com/muruganantham-v/local-batchanalytics/issues/8)
- **Commit:** `30eabc3`

**Issue Description:**  
The dashboard AJAX handlers were executed before the capability check:
`require_login()` only enforced authentication, not authorization. Any logged-in user could execute actions (`get_dashboard_tasks`, `request_ss_approval`, `reject_ss_approval`, `mark_activity_complete`) without permission.

**Resolution:**  
Moved the `$can_view = has_capability('local/batchanalytics:view', $context) || \local_batchanalytics\task_service::can_view_dashboard($USER->id);` check before the action dispatcher. If `$can_view` is false, JSON error response `{success: false, message: 'Access denied'}` is immediately returned.

---

### BUG-02 [CRITICAL] — No Role/Ownership Validation in SS Approval and Rejection

- **File:** `classes/task_service.php` (and `blocks/batchanalytics/classes/task_service.php`)
- **Severity:** Critical / Security
- **Status:** **Resolved** (Closed)
- **GitHub Issue:** [#9](https://github.com/muruganantham-v/local-batchanalytics/issues/9)
- **Commit:** `124a0ff`

**Issue Description:**  
`request_ss_approval`, `reject_ss_approval`, and `mark_activity_complete` (for `ss` and `ss_approve`) accepted any `$userid` and `$batchid` without verifying if `$userid` was actually the SSE for the batch or an SS Lead / Admin.

**Resolution:**  
Added persona and assignment validation in `task_service.php`:
- `request_ss_approval`: Verifies that `$userid` is assigned as SSE on the batch (or is Admin).
- `reject_ss_approval`: Verifies that `$userid` has the `sslead` persona or `admin` capability.
- `mark_activity_complete`: Verifies SSE assignment for `ss` actions and SS Lead / Admin permission for `ss_approve` actions. Throws `moodle_exception('nopermissions')` upon violation.

---

### BUG-03 [HIGH] — Cliq Notification Dedup Misses Multi-Recipient Separation

- **File:** `classes/cliq_activity_notifier.php` (and `blocks/batchanalytics/classes/cliq_activity_notifier.php`)
- **Severity:** High / Logic
- **Status:** **Resolved** (Closed)
- **GitHub Issue:** [#14](https://github.com/muruganantham-v/local-batchanalytics/issues/14)
- **Commit:** `56ab4ce`

**Issue Description:**  
`log_notification` stored all recipients as a single comma-separated string in one row. `is_already_sent` did not check per recipient, causing partial delivery failures to permanently suppress retries for other recipients.

**Resolution:**  
Refactored notification logging to record separate log entries per individual recipient. Updated `is_already_sent` to verify deduplication per recipient email.

---

### BUG-04 [HIGH] — `$defs` Shadowed and Rebuilt on Every SS Activity Iteration

- **File:** `classes/cliq_activity_notifier.php` (and `blocks/batchanalytics/classes/cliq_activity_notifier.php`)
- **Severity:** High / Performance + Logic
- **Status:** **Resolved** (Closed)
- **GitHub Issue:** [#14](https://github.com/muruganantham-v/local-batchanalytics/issues/14)
- **Commit:** `56ab4ce`

**Issue Description:**  
`$defs = self::get_template_definitions();` was called inside the inner loop of `evaluate_due_activities` for every SS activity item, causing massive redundant array construction per cron cycle.

**Resolution:**  
Removed the inner `$defs = self::get_template_definitions();` reassignment; reused the outer definitions constructed once per batch run.

---

### BUG-05 [HIGH] — Notifications Only Fire on Exact Day Match

- **File:** `classes/cliq_activity_notifier.php` (and `blocks/batchanalytics/classes/cliq_activity_notifier.php`)
- **Severity:** High / Logic
- **Status:** **Resolved** (Closed)
- **GitHub Issue:** [#14](https://github.com/muruganantham-v/local-batchanalytics/issues/14)
- **Commit:** `56ab4ce`

**Issue Description:**  
Stages used strict equality `diff_days === 3` and `diff_days === 0`. If a cron execution was delayed or skipped on that calendar day, alerts were permanently missed.

**Resolution:**  
Implemented range checks (`$diff_days >= 2 && $diff_days <= 3` for T-3; `$diff_days >= 0 && $diff_days <= 1` for due today) coupled with stage-based deduplication so missed runs catch up gracefully without sending duplicate alerts.

---

### BUG-06 [HIGH] — JS Race Condition: Optimistic Render Before Server State Applied

- **File:** `block_task_dashboard.js` (and `blocks/batchanalytics/block_task_dashboard.js`)
- **Severity:** High / UX
- **Status:** **Resolved** (Closed)
- **GitHub Issue:** [#15](https://github.com/muruganantham-v/local-batchanalytics/issues/15)
- **Commit:** `0e135f2`

**Issue Description:**  
After task completion, `renderTodoList()` was invoked optimistically before server data arrived, followed immediately by `applyData(resp.dashboard_data)`. This caused double rendering and visual flashes.

**Resolution:**  
Streamlined completion flow: if `resp.dashboard_data` is returned by the server, `applyData(resp.dashboard_data)` is called directly; otherwise `loadDashboardData()` is called asynchronously.

---

### BUG-07 [HIGH] — `am_closer` Dashboard Action Inconsistency

- **File:** `classes/task_service.php` (and `blocks/batchanalytics/classes/task_service.php`)
- **Severity:** High / Logic
- **Status:** **Resolved** (Closed)
- **GitHub Issue:** [#17](https://github.com/muruganantham-v/local-batchanalytics/issues/17)
- **Commit:** `e4c19f6`

**Issue Description:**  
`get_dashboard_data` configured AM closer tasks to redirect to the tracker form, whereas `mark_activity_complete` supported direct writes, creating inconsistent handling and inability to pass explicit completion dates.

**Resolution:**  
Added support for optional `actualend` parameter in `mark_activity_complete` for `am_closer` / `am_end`. If supplied, it validates and writes the provided Unix timestamp; otherwise it defaults to current time.

---

### BUG-08 [MEDIUM] — Full Table Scan of All Sections on Every Page Load

- **File:** `classes/task_service.php` (and `blocks/batchanalytics/classes/task_service.php`)
- **Severity:** Medium / Performance
- **Status:** **Resolved** (Closed)
- **GitHub Issue:** [#10](https://github.com/muruganantham-v/local-batchanalytics/issues/10)
- **Commit:** `ff6c64b`

**Issue Description:**  
`resolve_user_personas` scanned the entire `local_bm_classsection` table and decoded module JSON on every request without caching.

**Resolution:**  
Introduced a per-request static memory cache `self::$personas_cache[$userid]`. Subsequent calls within the same request lifecycle reuse cached persona resolutions.

---

### BUG-09 [MEDIUM] — `resolve_user_personas` Called Twice Per Request

- **File:** `classes/task_service.php` (and `blocks/batchanalytics/classes/task_service.php`)
- **Severity:** Medium / Performance
- **Status:** **Resolved** (Closed)
- **GitHub Issue:** [#10](https://github.com/muruganantham-v/local-batchanalytics/issues/10)
- **Commit:** `ff6c64b`

**Issue Description:**  
Both `can_view_dashboard` and `get_dashboard_data` independently triggered full persona evaluations on every page visit.

**Resolution:**  
Static cache introduced for BUG-08 completely eliminates duplicate database queries and JSON decodings across redundant callers within the same request.

---

### BUG-10 [MEDIUM] — Escalated SS Tasks Hidden from Admin Role

- **File:** `classes/task_service.php` (and `blocks/batchanalytics/classes/task_service.php`)
- **Severity:** Medium / Logic
- **Status:** **Resolved** (Closed)
- **GitHub Issue:** [#11](https://github.com/muruganantham-v/local-batchanalytics/issues/11)
- **Commit:** `f40794a`

**Issue Description:**  
Admin role conditions checked `($active_role === 'admin' && $is_pending_approval)`, causing escalated Soft Skills tasks that were not pending approval to be omitted from the Admin view.

**Resolution:**  
Broadened the Admin check in `get_dashboard_data` to `($active_role === 'admin' && ($is_pending_approval || $is_escalated))`, enabling administrators to oversee all escalated Soft Skills tasks.

---

### BUG-11 [MEDIUM] — SS Approval Falls Through to Raw Key Write on Format Mismatch

- **File:** `classes/task_service.php` (and `blocks/batchanalytics/classes/task_service.php`)
- **Severity:** Medium / Data Integrity
- **Status:** **Resolved** (Closed)
- **GitHub Issue:** [#12](https://github.com/muruganantham-v/local-batchanalytics/issues/12)
- **Commit:** `f048001`

**Issue Description:**  
When an activity key did not match either format in `softskillsdata`, the system wrote orphan properties into the JSON root (`$raw_data[$act_key . '_ApprovalStatus'] = ...`) rather than rejecting invalid keys.

**Resolution:**  
Removed silent fallthrough. If an `act_key` cannot be matched in key-based or object-list structures, a clear `moodle_exception('invalidactivity')` is thrown, preventing JSON data corruption.

---

### BUG-12 [MEDIUM] — `rejectApproval()` Can Submit Empty Notes After Dialog Bypass

- **File:** `block_task_dashboard.js` (and `blocks/batchanalytics/block_task_dashboard.js`)
- **Severity:** Medium / UX
- **Status:** **Resolved** (Closed)
- **GitHub Issue:** [#15](https://github.com/muruganantham-v/local-batchanalytics/issues/15)
- **Commit:** `0e135f2`

**Issue Description:**  
If a user accepted the confirmation dialog without entering rejection review notes, the system sent an empty string, leaving SSEs without feedback.

**Resolution:**  
Enforced mandatory review notes: if the notes field is blank upon clicking Reject, an alert is displayed and focus is moved to the notes textarea without making an API call.

---

### BUG-13 [MEDIUM] — Server-Rendered Todos Cause Flash of Duplicate Content

- **File:** `block_batchanalytics.php` (and `blocks/batchanalytics/block_batchanalytics.php`)
- **Severity:** Medium / UX
- **Status:** **Resolved** (Closed)
- **GitHub Issue:** [#15](https://github.com/muruganantham-v/local-batchanalytics/issues/15)
- **Commit:** `0e135f2`

**Issue Description:**  
The block template outputted the first 5 todo items statically, which were immediately cleared and re-rendered by JavaScript on load, causing a visual flash.

**Resolution:**  
Replaced static 5-todo HTML with an initial skeleton/loading state placeholder that smoothly transitions when JavaScript renders the full dataset.

---

### BUG-14 [MEDIUM] — Block Lang File Missing SS Lead Settings Strings

- **File:** `blocks/batchanalytics/lang/en/block_batchanalytics.php`
- **Severity:** Medium / UX
- **Status:** **Resolved** (Closed)
- **GitHub Issue:** [#16](https://github.com/muruganantham-v/local-batchanalytics/issues/16)
- **Commit:** `ae72a5d`

**Issue Description:**  
`sslead_roles`, `sslead_roles_desc`, `sslead_email`, and `sslead_email_desc` were absent from `block_batchanalytics.php`, causing missing strings if referenced via the block namespace.

**Resolution:**  
Added all missing SS Lead strings and descriptions to `blocks/batchanalytics/lang/en/block_batchanalytics.php`.

---

### BUG-15 [MEDIUM] — Missing Composite Index on Cliq Log Table

- **File:** `db/install.xml`, `db/upgrade.php`, `version.php`
- **Severity:** Medium / Performance
- **Status:** **Resolved** (Closed)
- **GitHub Issue:** [#17](https://github.com/muruganantham-v/local-batchanalytics/issues/17)
- **Commit:** `e4c19f6`

**Issue Description:**  
`local_batchanalytics_cliq_log` queried `(batchid, activity_key, stage, date_sent/recipient)` repeatedly during cron cycles without a composite index.

**Resolution:**  
Added composite index `dedup_recipient_ix` covering `(batchid, activity_key, stage, recipient)` in `install.xml` and an upgrade step in `db/upgrade.php`. Bumped version to `2026101001`.

---

### BUG-16 [MEDIUM] — SS Completion Throws Misleading Error on Invalid JSON

- **File:** `classes/task_service.php` (and `blocks/batchanalytics/classes/task_service.php`)
- **Severity:** Medium / Data Integrity
- **Status:** **Resolved** (Closed)
- **GitHub Issue:** [#12](https://github.com/muruganantham-v/local-batchanalytics/issues/12)
- **Commit:** `f048001`

**Issue Description:**  
Corrupt or non-array `softskillsdata` fell through to generic `invalidaction` moodle exception.

**Resolution:**  
Added explicit validation checking `json_last_error()` and `is_array()`, throwing an informative `invalidsoftskillsdata` exception.

---

### BUG-17 [MEDIUM] — `am_sched` Cannot Update an Already-Set Schedule

- **File:** `classes/task_service.php` (and `blocks/batchanalytics/classes/task_service.php`)
- **Severity:** Medium / Logic
- **Status:** **Resolved** (Closed)
- **GitHub Issue:** [#13](https://github.com/muruganantham-v/local-batchanalytics/issues/13)
- **Commit:** `6b5d92b`

**Issue Description:**  
`am_sched` checked `empty($modules[$mod_key]['plannedstart'])`, silently ignoring requests to update an already planned schedule.

**Resolution:**  
Allowed updating `plannedstart` whenever `am_sched` is called, ensuring reschedule requests are honored.

---

### BUG-18 [MEDIUM] — Greeting Uses Server Wall Clock, Ignores User Timezone

- **File:** `classes/task_service.php` (and `blocks/batchanalytics/classes/task_service.php`)
- **Severity:** Medium / UX
- **Status:** **Resolved** (Closed)
- **GitHub Issue:** [#13](https://github.com/muruganantham-v/local-batchanalytics/issues/13)
- **Commit:** `6b5d92b`

**Issue Description:**  
Used server `date('G')`, showing inaccurate greetings to users residing in different timezones.

**Resolution:**  
Changed hour calculation to `(int)userdate(time(), '%H')` so greeting matches the authenticated user's local timezone.

---

### BUG-19 [LOW] — Stale `$days` Variable in `am_next_mentor` Forthcoming Block

- **File:** `classes/task_service.php` (and `blocks/batchanalytics/classes/task_service.php`)
- **Severity:** Low / Logic
- **Status:** **Resolved** (Closed)
- **GitHub Issue:** [#13](https://github.com/muruganantham-v/local-batchanalytics/issues/13)
- **Commit:** `6b5d92b`

**Issue Description:**  
The forthcoming mentor activity item reused `$days` calculated from the preceding `am_closer` task.

**Resolution:**  
Calculated independent `$m_days = (int)ceil(($p_end - $today_midnight) / 86400)` specifically for forthcoming mentor activities.

---

### BUG-20 [LOW] — `is_already_sent` Without `date_sent` Permanently Suppresses Future Alerts

- **File:** `classes/cliq_activity_notifier.php` (and `blocks/batchanalytics/classes/cliq_activity_notifier.php`)
- **Severity:** Low / Logic
- **Status:** **Resolved** (Closed)
- **GitHub Issue:** [#14](https://github.com/muruganantham-v/local-batchanalytics/issues/14)
- **Commit:** `56ab4ce`

**Issue Description:**  
Calling `is_already_sent` without `date_sent` could permanently silence recurring reminder stages.

**Resolution:**  
Differentiated between one-off notification stages (`t_minus_3`, `due_today`) and repeating escalation stages (`t_plus_3`, `t_plus_5_escalation`), scoping repeat stages to daily calendar boundaries.

---

### BUG-21 [LOW] — Cross-Tab localStorage Sync Has No Listener

- **File:** `block_task_dashboard.js` (and `blocks/batchanalytics/block_task_dashboard.js`)
- **Severity:** Low / UX
- **Status:** **Resolved** (Closed)
- **GitHub Issue:** [#15](https://github.com/muruganantham-v/local-batchanalytics/issues/15)
- **Commit:** `0e135f2`

**Issue Description:**  
`localStorage.setItem('ba_task_updated', ...)` was written after completion but lacked a `storage` event listener to sync across tabs.

**Resolution:**  
Added `window.addEventListener('storage', function(e) { if (e.key === 'ba_task_updated') loadDashboardData(); });`.

---

### BUG-22 [LOW] — Modal "Go to Activity" Button Uses Fallback `href="#"`

- **File:** `block_batchanalytics.php` (and `blocks/batchanalytics/block_batchanalytics.php`)
- **Severity:** Low / UX
- **Status:** **Resolved** (Closed)
- **GitHub Issue:** [#15](https://github.com/muruganantham-v/local-batchanalytics/issues/15)
- **Commit:** `0e135f2`

**Issue Description:**  
The fallback `href="#"` on modal action buttons caused unintended viewport jumps when clicked without a valid URL.

**Resolution:**  
Updated default link attribute to `href="javascript:void(0);"`.

---

### BUG-23 [LOW] — Pagination Page State Lost After Server Data Refresh

- **File:** `block_task_dashboard.js` (and `blocks/batchanalytics/block_task_dashboard.js`)
- **Severity:** Low / UX
- **Status:** **Resolved** (Closed)
- **GitHub Issue:** [#15](https://github.com/muruganantham-v/local-batchanalytics/issues/15)
- **Commit:** `0e135f2`

**Issue Description:**  
`currentPage` was not clamped when total items changed, leading to blank pages if the current page exceeded total available pages.

**Resolution:**  
Added boundary check in `renderTodoList()` clamping `currentPage` to `Math.max(1, maxPage)`.

---

### BUG-24 [LOW] — Block Lang File Missing All Zoho Cliq Strings

- **File:** `blocks/batchanalytics/lang/en/block_batchanalytics.php`
- **Severity:** Low / UX
- **Status:** **Resolved** (Closed)
- **GitHub Issue:** [#16](https://github.com/muruganantham-v/local-batchanalytics/issues/16)
- **Commit:** `ae72a5d`

**Issue Description:**  
Zoho Cliq configuration, template, and notification strings were only present in `local_batchanalytics.php`.

**Resolution:**  
Synchronized all Zoho Cliq configuration, notification, template, and editor strings into `blocks/batchanalytics/lang/en/block_batchanalytics.php`.

---

### BUG-25 [LOW] — `send_cliq_message` Reports Only Last Recipient HTTP Code

- **File:** `classes/cliq_activity_notifier.php` (and `blocks/batchanalytics/classes/cliq_activity_notifier.php`)
- **Severity:** Low / Observability
- **Status:** **Resolved** (Closed)
- **GitHub Issue:** [#14](https://github.com/muruganantham-v/local-batchanalytics/issues/14)
- **Commit:** `56ab4ce`

**Issue Description:**  
`$last_http_code` was overwritten on each iteration, masking earlier HTTP failure codes when subsequent recipient calls succeeded.

**Resolution:**  
Tracked failure codes across iterations; returned the earliest failing HTTP status code if any send failed.

---

## Files Modified & Synced

| File | Changes Made |
|---|---|
| `index.php` & `blocks/batchanalytics/index.php` | Enforced dashboard capability / permission check prior to AJAX dispatch. |
| `classes/task_service.php` & `blocks/batchanalytics/classes/task_service.php` | Added role & ownership checks, static persona caching, admin escalation visibility, soft skills format validation, user timezone greetings, and schedule updates. |
| `classes/cliq_activity_notifier.php` & `blocks/batchanalytics/classes/cliq_activity_notifier.php` | Per-recipient notification logging & deduplication, date range matching, inner loop optimization, HTTP error code tracking. |
| `block_batchanalytics.php` & `blocks/batchanalytics/block_batchanalytics.php` | Replaced static 5-todo flash with loading placeholder, updated modal link to `javascript:void(0);`. |
| `block_task_dashboard.js` & `blocks/batchanalytics/block_task_dashboard.js` | Applied server response data directly, mandatory reject notes validation, cross-tab storage sync, pagination clamping. |
| `blocks/batchanalytics/lang/en/block_batchanalytics.php` | Added missing SS Lead strings and all Zoho Cliq configuration & template strings. |
| `db/install.xml` & `db/upgrade.php` & `version.php` | Added `dedup_recipient_ix` composite index on `local_batchanalytics_cliq_log`; bumped plugin version to `2026101001`. |

---

*Report updated on 2026-10-10. All 25 logged bugs are verified resolved with zero impact to business workflows. Zero commits made to Emertxe remote.*
