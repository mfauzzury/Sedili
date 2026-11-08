# Changelog

## [Unreleased]

### Added

- Leave emails are queued (a worker sends them), so a slow or broken mail server no longer delays the request; the Docker setup has a `queue` service for it
- Larastan (static analysis, `composer analyse`, existing findings in `phpstan-baseline.neon`) and a Pint check of the changed files in the GitHub workflow
- Indexes on leave (status, dates) and attendances (date)
- System > Activity log: read-only audit trail of who created, changed or deleted employees, departments, leave requests, leave types, holidays, attendances and payrolls, with the before/after values (passwords are never stored). Entries are kept 5 years (`ACTIVITY_LOGGER_KEEP_DAYS`)
- System > Company: change the logo, name and details of the company (shown in both panels)
- System > Email: SMTP server and sender for the notification emails, with a test email button
- Leave emails: the admins are told about a new request, the employee about the decision
- Supervisor of each employee (falls back to the department manager): column and "No supervisor" filter in the Employees list, count on the dashboard, and the leave request email for the supervisor
- Leave types: "Evidence required" option; the employee must attach a document (e.g. a medical certificate, PDF/JPG/PNG) to request it. Documents are private and only open to the employee, their supervisor and the admins; the employee can also attach it later from My Leave
- Portal > Team Leave: a supervisor approves or rejects the leave requests of their team (with a reason for rejections); the employee is told by email and who decided is recorded

## [v1.1.0] - 2026-01-05

### Added

- Admin authentication tests
- CHANGELOG.md
- GitHub workflows for testing

### Changed

### Fixed
