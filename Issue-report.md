# Issue Report

This register tracks issues resolved from this point forward. GitHub remains the source of truth for earlier issues.

| Issue | Severity | Status | Resolution | Commit |
|---|---|---|---|---|
| #20 | High | Closed | Ticket writes now queue Cliq notifications as adhoc tasks. HTTP delivery and its existing timeouts run only in the background worker. | `fix#20: synchronous Cliq delivery delays ticket saves per recipient` |
| #21 | Medium | Closed | Cliq history requires a non-empty recipient confirmation before recording delivery as successful. | `fix#21: Cliq delivery is logged as success for rejected HTTP 200 responses` |
| #22 | Medium | Closed | CRM rate limiting uses a fixed 60-second window rather than extending the counter lifetime after each request. | `fix#22: CRM rate limiter uses an inactivity timeout instead of a fixed minute window` |

## Resolution Process

1. Create each issue with summary, affected files, root cause, failure scenario, expected behaviour, reproduction steps, impact, and severity label.
2. Add a closure report with confirmed root cause, changes made, validation, deployment steps when needed, and deferred limitations.
3. Update this register when the issue is resolved and record its local commit.
4. Use `fix#<issue-number>: <issue name>` for the local commit. The user pushes commits.
