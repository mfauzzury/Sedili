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

### Fixed

- Employees and admins created in the admin panel no longer get their email address as password; they choose one with "Forgot password" (as the form says)
- Departments: the list showed "No Manager" for every department; it now shows the manager, and can be searched and sorted by manager name
- Departments: the manager is told only when they are assigned (a new manager was never told, the previous one was told again, and every edit sent a notification)
- Dashboard: the employee counts included the admins, so they did not match the Employees list they link to
- Calendar: an event that starts before or ends after the displayed month (or week) was not shown
- Attendance: only one employee of the whole company could have a record for a given date; it is now one per employee, day and shift. Clocking out before clocking in is refused, and night shifts (22:00 to 06:00) are accepted and counted correctly
- Task board: moving a card no longer notifies the assignees of the cards around it; only a change of title, description, status or due date does, and a reassigned task tells the new assignee
- Inbox: a reply notified the person who replied instead of the one who wrote first
- Employees: `is_active` and the dates are cast; the audit log stores dates as dates

## [v1.1.0] - 2026-01-05

### Added

- Admin authentication tests
- CHANGELOG.md
- GitHub workflows for testing

### Changed

### Fixed
