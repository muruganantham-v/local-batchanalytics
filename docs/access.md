# Batch Analytics Access Guide

This document describes the access behavior currently implemented in the
`local_batchanalytics` plugin. Moodle capability overrides and local role
assignments can change the effective access for an individual user.

## Access Model

- Every Batch Analytics page (Index, Batch Overview, and Module Detail) requires login and the system-level capability `local/batchanalytics:view`.
- Site administrators always have full plugin access.
- Plugin settings require Moodle site configuration access: `moodle/site:config`.
- Guest users are not shown the Batch Analytics navigation item and cannot access pages.

## Capability Reference

| Capability | Context | Access granted |
| --- | --- | --- |
| `local/batchanalytics:view` | System | Access Batch Analytics pages (Index, Batch, Module). |
| `local/batchanalytics:manage` | System | Full plugin access, including all batches, courses, and full unrestricted CRM data. |
| `local/batchanalytics:viewallcourses` | System | Search and view all batches/courses regardless of enrollment. |
| `local/batchanalytics:viewenrolledcourses` | Course | View an enrolled course through Batch Analytics. |
| `local/batchanalytics:viewreviewnotes` | System | View batch review notes. |
| `local/batchanalytics:editreviewnotes` | System | Add and edit batch review notes. |
