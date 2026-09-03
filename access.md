# Batch Analytics Access Guide

This document describes the access behavior currently implemented in the
`local_batchanalytics` plugin. Moodle capability overrides and local role
assignments can change the effective access for an individual user.


## Access Model

- Every Batch Analytics page, including MAAC, Module Tracker, and Ticket
  Dashboard, requires login and the system-level capability
  `local/batchanalytics:view`.
- Course actions are checked only after this plugin-level gate. A user must
  also have the relevant course capability or configured ticket role.
- Site administrators always have full plugin access.
- Plugin settings, ticket templates, and Cliq templates require Moodle site
  configuration access: `moodle/site:config`.
- Guest users are not shown the Batch Analytics navigation item. They cannot
  access pages that require login.

## Capability Reference

| Capability | Context | Access granted |
| --- | --- | --- |
| `local/batchanalytics:view` | System | Open Batch Analytics pages, including MAAC, Module Tracker, and Ticket Dashboard. |
| `local/batchanalytics:manage` | System | Full plugin access, including all courses and MAAC data. |
| `local/batchanalytics:viewallcourses` | System | Search and view all permitted courses. |
| `local/batchanalytics:viewenrolledcourses` | Course | View an enrolled course through Batch Analytics. |
| `local/batchanalytics:viewmaac` | Course | View MAAC and Module Tracker data. |
| `local/batchanalytics:editmaac` | Course | Edit MAAC data, raise tickets, and update Module Tracker status. |
| `local/batchanalytics:viewtickets` | Course | View tickets for the course. |
| `local/batchanalytics:managetickets` | Course | View, update, and resolve course tickets. |
| `local/batchanalytics:manageescalatedtickets` | Course | View and resolve escalated tickets for the course. |

## Role Matrix

| Role | Batch Analytics and courses | MAAC and Module Tracker | Tickets | Settings |
| --- | --- | --- | --- | --- |
| Admin | Full access to all courses. | View and edit. | View, raise, update, resolve, and escalate. | Full access. |
| Manager | Requires system `local/batchanalytics:view`; full access also requires system `local/batchanalytics:manage`. Otherwise access follows assigned capabilities. | View and edit when the relevant capabilities are assigned. | Full ticket management when the relevant capabilities are assigned. | Only with `moodle/site:config`. |
| Teacher | Must have system `local/batchanalytics:view` to access plugin pages. Course access follows course capabilities. | Default plugin archetype grants view and edit MAAC capabilities when active in the course. Module Tracker can be viewed and edited. | Default plugin archetype grants ticket view and management when active in the course. | No default access. |
| MAAC Executive | Must have system `local/batchanalytics:view`. The role selected in **MAAC Executive role** setting grants ticket dashboard access in its assigned course. | No MAAC or Module Tracker access unless separate course capabilities are granted. | Can view assigned-course tickets, handle tickets, and escalate tickets to PM. | No default access. |
| Batch Manager | Must have system `local/batchanalytics:view`. The role selected in **Batch Manager role** setting grants the Ticket Dashboard button and dashboard access in its assigned course. | No MAAC or Module Tracker access unless separate course capabilities are granted. | Can view assigned-course tickets and resolve tickets only after escalation to PM. Cannot escalate tickets. | No default access. |
| Student | No default Batch Analytics access. | No default MAAC or Module Tracker access. | Cannot access the Ticket Dashboard. A student can receive the configured email when their ticket is escalated. | No access. |
| Guest | Navigation item is hidden and login-required pages are blocked. | No access. | No access. | No access. |

## Ticket Flow Permissions

| Action | Allowed users |
| --- | --- |
| Raise ticket from MAAC | Users with `editmaac` or `managetickets` in that course. |
| View ticket dashboard | System managers, course users with ticket capabilities, configured MAAC Executive, and configured Batch Manager users. |
| Update or resolve normal ticket | Ticket managers and configured MAAC Executive users. |
| Escalate ticket to PM | Ticket managers and configured MAAC Executive users. |
| Resolve escalated ticket | Ticket managers, users with `manageescalatedtickets`, or configured Batch Manager users. |
| Student escalation email | Sent only to the student on the ticket when a user manually escalates the ticket to PM. |

## Configuration Dependencies

- **MAAC Executive role** and **Batch Manager role** are selected in plugin settings.
  Those roles must be assigned at the course level for role-based ticket
  access to work.
- A user with a configured ticket role still needs the system-level
  `local/batchanalytics:view` capability to enter any plugin page, including
  MAAC, Module Tracker, plugin home, and the Ticket Dashboard.
- Course visibility and configured allowed-course keywords can further limit
  which courses appear in the ticket dashboard.
- This plugin does not give students or guests access by default. Any such
  access requires explicit Moodle capability changes.
