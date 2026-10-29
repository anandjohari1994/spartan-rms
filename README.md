# Spartan RMS

Recruitment management for **Spartan Infosoft Pvt. Ltd.**, built inside the existing Laravel 12 / Filament 4 application. Manual candidate registration only; no Excel import or public registration.

## Requirements

- PHP 8.2+ with intl, fileinfo, zip, mbstring, PDO MySQL and Laravel's standard extensions.
- MySQL or MariaDB for production. SQLite is supported for isolated automated tests.
- Composer 2 and Node 20.19+ / 22.12+ for Vite builds. Keep the supplied lock files.
- No Redis, Supervisor, Docker or permanent queue worker is needed.

## Local setup

The existing `.env` is preserved. For a new installation only, copy `.env.example` to `.env`, configure MySQL and generate an application key. Never replace an existing key.

```sh
composer install
npm ci
npm run build
php artisan filament:assets
php artisan migrate
php artisan db:seed
php artisan rms:create-admin
php artisan serve
```

Open `/admin`. The administrator command prompts for name, email and a confirmed password of at least 12 characters with mixed case and a number. There are no seeded credentials. Seeders create the two roles, required permissions and six job positions. Run migration commands only against the intended database; production requires approval and a backup.

Set `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=database` and `CACHE_STORE=database` (or `file`). The existing `.env` was not rewritten, so its previous queue value may still be `database`; RMS does not dispatch background jobs. Dates use Asia/Kolkata unless explicitly configured otherwise. CTC values are INR per year.

## Using the application

**Super Admin:** create active HR accounts under **HR & users**, maintain positions, view all candidates, reassign ownership with a reason, soft-delete/restore candidates, manage all recruitment workflows, read audit logs and export reports. Administrators cannot deactivate/demote themselves. Password updates invalidate other sessions.

**HR Executive:** register candidates and work only with candidates currently assigned to them. Candidate ownership and creator are set on the server. Search, lists, dashboards, service mutations, relationship actions and document downloads all enforce access. HR users cannot delete/reassign candidates, manage accounts or open global reports/audit/settings.

1. Add a candidate, optionally attaching a PDF/DOCX resume (5 MB maximum).
2. Open the candidate to see profile, interview rounds, calls, follow-ups, documents and assignment history.
3. Record calls through **Call Logs**. History is append-only; the chronologically latest call drives the candidate's calling status.
4. Update the three interview rounds. Round 1 starts Pending; rounds 2/3 begin Not Started. Move a new round to Pending/Scheduled only after previous rounds pass. Reopening terminal rounds or overriding progression requires a Super Admin and a reason.
5. Schedule follow-ups, filter pending/today/upcoming/overdue/completed work, and mark pending entries complete. Finished entries remain historical records; create a new follow-up to resume contact.
6. Use **Change status** on the candidate list. Selected/Joined requires three passed rounds unless a Super Admin supplies an override reason.

Indian mobile formats (`9876543210`, `+91 98765 43210`, `0091...`, leading zero) normalize to one unique value. Duplicates produce a generic message without disclosing another HR's record. Soft-deleted candidates reserve their mobile numbers. Restore the original candidate rather than creating duplicates.

Reassignment transfers pending follow-ups atomically. Historical calls retain their original caller; visibility follows the current candidate owner. Assignment history records the administrator and reason. Documents of soft-deleted candidates cannot be downloaded until restoration.

## Dashboards and reports

Both dashboards use real database aggregates and scoped operational tables. Admin sees HR distribution and HR performance; HR sees only their current candidate portfolio. Calling KPI cards are today's calls for currently visible candidates; HR performance is labeled lifetime and credits the actual caller. The funnel represents current cumulative stage counts; explicit admin overrides can make stages non-monotonic.

Reports cover daily registration, HR distribution, calling performance, interview status, selections, pending follow-ups, position recruitment and date-range recruitment. Filters are shared between preview and CSV export. Dates apply to candidate entry, call time, interview schedule or follow-up schedule depending on report type. HR/location/position filters refer to the candidate's current assignment/profile. The round filter includes candidates whose specified round has progressed beyond Not Started/Cancelled. Unscheduled rounds appear when no schedule date filter is supplied.

The preview shows 50 rows. Export streams all matches and neutralizes spreadsheet-formula prefixes. Only administrators with export permission can export. No public export files are created.

## Security and storage

- Policies, query scopes and transactional services enforce authorization; hiding controls is not the security boundary.
- Inactive users are rejected at login, on panel requests and on Livewire/web requests.
- Filament provides login rate limiting, hashed passwords, CSRF protection and session authentication.
- Resume bytes are inspected with Fileinfo; DOCX archives are checked for Word entries and rejected if a VBA payload entry is present. This is not a malware scanner.
- Files use randomized names under `storage/app/rms-private`, never `public/storage`. Livewire temporary previews are disabled for resumes. Authorized downloads use an attachment response and `no-store` headers.
- Recruitment changes, assignments, calls, documents, user changes, roles, settings and exports are audited. Passwords/tokens/private file paths are excluded from audit values.
- Candidate codes use a locked yearly sequence in the same transaction as registration and initial rounds. Database unique constraints are the final concurrency guard.
- Application settings control the displayed company name and support email. Runtime secrets and security options belong in deployment configuration.

## Tests and checks

```sh
php artisan test
php vendor/bin/pint --test app database/seeders database/migrations/2026_10_21_180000_create_recruitment_tables.php routes/web.php bootstrap/app.php config/livewire.php tests
npm run build
```

For this Windows PHP installation, PDO SQLite is present but disabled in `php.ini`; enable it for the test process only:

```powershell
php -d extension=pdo_sqlite vendor\phpunit\phpunit\phpunit
```

The suite uses `:memory:` and never resets the configured MySQL application database. On restricted Windows sandboxes, PHP can incorrectly report files as unreadable/unwritable; run the test/formatter command in a normal authorized terminal.

Optional real MySQL concurrency verification:

```sh
php tests/Support/mysql-smoke.php
```

This command refuses production/remote hosts, creates a new randomly named `rms_smoke_*` database, migrates it, runs four concurrent registrations, verifies reports/aggregations and removes only that disposable database. The local database user needs create/drop schema permission. It never runs reset commands on the configured application database.

`tests/Support/browser-fixture.php` creates synthetic data only in a new `storage/rms-browser.sqlite` file with `APP_ENV=testing` and `DB_CONNECTION=sqlite`. It refuses existing files and prints a random temporary password. This is a separate preview environment, not the real application database. Never deploy it or use it for business data.

## Implementation map

| Location | Purpose |
| --- | --- |
| `database/migrations/2026_10_21_180000_create_recruitment_tables.php` | Additive recruitment schema, indexes and foreign keys |
| `app/Models` | Users, candidates, positions, rounds, calls, follow-ups, documents, assignment history, audit, sequences and settings |
| `app/Policies` | Candidate ownership, child-record and administration authorization |
| `app/Services` | Transactional candidate/workflow/document/account operations, statistics and reports |
| `app/Filament/Resources` | Candidate CRUD and related history; follow-up, calls, HR, positions and audit screens |
| `app/Filament/Pages` | Role-aware dashboard, reports and settings |
| `app/Filament/Widgets` | KPI cards, five charts and operational/performance tables |
| `app/Filament/Support` | Shared form controls, service-backed persistence and validation feedback |
| `app/Http/Middleware/EnsureActiveUser.php` | Reject inactive/unassigned authenticated accounts |
| `app/Console/Commands/CreateRmsAdmin.php` | Interactive administrator provisioning |
| `resources/css/filament/admin/theme.css` | Responsive Filament theme |
| `tests/Feature` and `tests/Support` | Security/workflow/UI tests and isolated smoke checks |

New tables: `job_positions`, `candidates`, `interview_rounds`, `call_logs`, `follow_ups`, `candidate_documents`, `candidate_assignments`, `activity_logs`, `candidate_code_sequences`, `application_settings`, `candidate_email_batches`. The existing `users` table gains `mobile`, `is_active`, `last_login_at`. Existing Spatie permission/session/cache tables are retained.

See [Candidate outreach](CANDIDATE_OUTREACH.md) for admin-only BCC email and bulk status actions, WhatsApp click-to-chat, SMTP setup, and the proposed assessment-to-offer automation workflow. Outreach verification: 40 tests / 227 assertions passed on PHP 8.2.12, including BCC privacy, permissions, duplicate attempts, atomic status changes and Livewire actions. Tests use an in-memory mail transport and do not send real messages.

See [DEPLOYMENT_HOSTINGER.md](DEPLOYMENT_HOSTINGER.md) for production configuration, safe public-root layouts, cron, backups and release checks. See [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) for architectural decisions and [VERIFICATION.md](VERIFICATION.md) for verification results and remaining deployment work.
