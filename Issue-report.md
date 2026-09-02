# Issue Report

This register tracks issues resolved from this point forward. GitHub remains the source of truth for earlier issues.

| Issue | Severity | Status | Resolution | Commit |
|---|---|---|---|---|
| #20 | High | Closed | Ticket writes now queue Cliq notifications as adhoc tasks. HTTP delivery and its existing timeouts run only in the background worker. | `fix#20: synchronous Cliq delivery delays ticket saves per recipient` |
| #21 | Medium | Closed | Cliq history requires a non-empty recipient confirmation before recording delivery as successful. | `fix#21: Cliq delivery is logged as success for rejected HTTP 200 responses` |
| #22 | Medium | Closed | CRM rate limiting uses a fixed 60-second window rather than extending the counter lifetime after each request. | `fix#22: CRM rate limiter uses an inactivity timeout instead of a fixed minute window` |
| #23 | Medium | Closed | Ticket message providers now have readable labels, explicit popup/email defaults, and a migration for missing existing-site defaults. | `fix#23: Ticket message providers have missing labels and delivery defaults` |
| #24 | Medium | Closed | Cliq history now uses server-side filters and 50-row pages; a daily task prunes records older than the configurable 90-day default. | `fix#24: Cliq history loads the entire unpruned table into an inline script` |
| #25 | Low | Closed | Plugin version now uses a valid calendar date and remains greater than all upgrade savepoints. | `fix#25: Plugin version encodes an invalid July date` |
| #26 | Critical | Closed | CRM student lookups now permit only students enrolled in the caller's accessible courses; the legacy endpoint is POST-only with sesskey and rate-limit protection. | `fix#26: CRM endpoints do not restrict requested students to accessible courses` |
| #27 | High | Closed | CRM failures are now explicit errors rather than cached missing records; a rejected token refreshes once and CRM criteria preserve the requested username. | `fix#27: Failed CRM chunks are cached as clean no-record results` |
| #28 | High | Closed | Course summaries now honor Moodle capability overrides and the accessible-course scope; ticket dashboard entry checks apply the configured course-keyword scope. | `fix#28: Course-summary edit rights bypass capability overrides` |
| #29 | Medium | Closed | Teacher counts use inherited Moodle role assignments and resolve teacher roles by shortname instead of fixed database IDs. | `fix#29: Teacher counts silently drop category-level teachers` |

## Resolution Process

1. Create each issue with summary, affected files, root cause, failure scenario, expected behaviour, reproduction steps, impact, and severity label.
2. Add a closure report with confirmed root cause, changes made, validation, deployment steps when needed, and deferred limitations.
3. Update this register when the issue is resolved and record its local commit.
4. Use `fix#<issue-number>: <issue name>` for the local commit. The user pushes commits.
