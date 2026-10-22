# Spartan RMS — Hostinger shared-hosting deployment

This guide deploys the existing Laravel 12 / Filament 4 application. No Redis, Supervisor, Docker or persistent queue worker is required. Perform deployment against staging first. Production database migrations require the application owner's explicit approval and a verified backup.

## 1. Hosting requirements

Use a plan with PHP 8.2+, MySQL/MariaDB, HTTPS, private filesystem space, cron and sufficient memory for Composer and the application. Confirm SSH/Composer access and the selected plan's limits in hPanel. Laravel is supported on Hostinger Web/Cloud hosting; availability of account tools varies by plan. [Hostinger framework support](https://www.hostinger.com/support/which-programming-languages-and-frameworks-are-supported-at-hostinger/).

Keep the locked Laravel 12 and Filament 4 versions. Run `composer install`, not `composer update`, during deployment. Node is needed only on the build machine; upload compiled assets to hosting.

## 2. PHP configuration

Set the domain's PHP version and confirm the CLI uses the same supported version with `php -v`. Enable PDO MySQL, mbstring, intl, fileinfo, zip, DOM/XML, ctype, curl, openssl, tokenizer and bcmath. Check exact installed package requirements with `composer check-platform-reqs --no-dev`.

Suggested PHP settings:

```ini
upload_max_filesize=6M
post_max_size=8M
memory_limit=256M
max_execution_time=120
display_errors=Off
log_errors=On
```

The application enforces a stricter 5 MB resume limit. Fileinfo reads actual content; DOCX files must have Word package entries. This is format validation, not antivirus scanning.

## 3. MySQL setup

Create a dedicated database and database user in hPanel, using utf8mb4. Grant that user access only to this application's database. Populate `.env` using the exact database host, name and username provided by hPanel. Do not place passwords in commands, source control or screenshots. Confirm connectivity with `php artisan migrate:status`.

## 4. Production environment example

Keep the real `.env` outside the web document root. This example contains placeholders, not usable credentials:

```dotenv
APP_NAME="Spartan RMS"
APP_ENV=production
APP_KEY=<existing-or-new-generated-application-key>
APP_DEBUG=false
APP_URL=https://rms.example.com
APP_TIMEZONE=Asia/Kolkata
LOG_CHANNEL=daily
LOG_LEVEL=warning
DB_CONNECTION=mysql
DB_HOST=<hpanel-database-host>
DB_PORT=3306
DB_DATABASE=<database-name>
DB_USERNAME=<database-user>
DB_PASSWORD=<strong-secret>
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
CACHE_STORE=database
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
BROADCAST_CONNECTION=log
MAIL_MAILER=log
```

Generate `APP_KEY` once for a new installation with `php artisan key:generate`. Preserve it for every later release and backup; rotating it invalidates encrypted data/sessions. The application uses Asia/Kolkata wall-clock timestamps; keep the timezone consistent across web, CLI and future data migrations. No email delivery workflow is required in version 1.

## 5. Install PHP dependencies

Upload source to a private application directory, for example `/home/u123/domains/example.com/rms`. From that directory:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
php artisan filament:assets
```

If Composer cannot run on the plan, build the deployment bundle on a compatible PHP environment and upload `vendor` with the application. Exclude development tests, `.git`, `.env` from the source bundle, `node_modules`, browser fixture databases and all local test files from production.

## 6. Frontend production assets

On the trusted build machine, from the project root:

```sh
npm ci
npm run build
```

Upload `public/build`, including `manifest.json`, and the Filament assets published under `public/css`, `public/js` and `public/fonts`. Never deploy `public/hot`. The theme uses locally available system fonts and supports light/dark mode. No Vite server is needed in production.

## 7. Migrations and initial account

Review `php artisan migrate:status`, back up the database and obtain production migration approval. Then:

```sh
php artisan down
php artisan migrate --force
php artisan db:seed --force
php artisan rms:create-admin
```

The role and position seeders are repeatable. They install the two defined roles, their permissions and six positions; they never create default accounts or overwrite existing position profiles. Role permission sets are deliberately synchronized to this version's policy. The interactive admin command creates a new user and asks for the password without displaying it. Never run database reset/fresh commands on application data. Existing schema changes are additive.

## 8. Storage and permissions

PHP must be able to write `storage` and `bootstrap/cache`. Use ownership appropriate to the hosting account; use restrictive permissions, never blanket `777`. Keep `storage/app/rms-private` private. Resumes and Livewire temporary uploads use this disk; no public URL or storage symlink is created for them. Download access is checked through `/documents/{id}/download`. Back up metadata and files together.

Do not symlink `storage/app/rms-private` into `public_html`. A public storage link is unnecessary for RMS resumes. Store backups outside public directories as well.

## 9. Public directory configuration

Only `public/` may be the document root. Laravel explicitly warns against exposing the application root. [Laravel 12 deployment](https://laravel.com/docs/12.x/deployment).

Preferred: point the domain's document root to `/home/u123/domains/example.com/rms/public`, if your plan permits it.

If the plan fixes the document root at `public_html`, keep the whole application in the private sibling `rms` directory and copy only the contents of `rms/public` into `public_html`, including `.htaccess` and compiled/published assets. Adjust the three bootstrap paths in the deployed `public_html/index.php`:

```php
if (file_exists($maintenance = __DIR__.'/../rms/storage/framework/maintenance.php')) {
    require $maintenance;
}
require __DIR__.'/../rms/vendor/autoload.php';
$app = require_once __DIR__.'/../rms/bootstrap/app.php';
$app->usePublicPath(__DIR__);
$app->handleRequest(\Illuminate\Http\Request::capture());
```

Retain the original `LARAVEL_START` declaration and request imports from the generated entry point. For CLI asset publication and manifest lookups with this split layout, set `APP_PUBLIC_PATH=/home/u123/domains/example.com/public_html`; bootstrap supports this deployment-only setting. Do not place `.env`, `vendor`, `app`, `storage`, SQL dumps or the application root inside `public_html`, even behind a rewrite rule.

## 10. Cron

Version 1 does not require scheduled notifications; overdue follow-ups are calculated at query time. For Laravel's scheduler, create a once-per-minute hPanel cron using the actual PHP binary and private app path:

```cron
* * * * * /usr/bin/php /home/u123/domains/example.com/rms/artisan schedule:run >> /home/u123/domains/example.com/rms/storage/logs/scheduler.log 2>&1
```

Confirm the PHP binary and timezone on your account. Do not run a permanently resident queue worker. Use `QUEUE_CONNECTION=sync`.

## 11. Cache optimization and release

```sh
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:optimize
php artisan up
```

Rebuild caches after configuration, route or resource changes. To diagnose stale deployment caches, use `php artisan optimize:clear` and `php artisan filament:optimize-clear`, then rebuild them. Do not regenerate the application key on releases.

## 12. HTTPS

Install/activate the domain certificate and enable HTTPS redirection in hPanel. Set the canonical HTTPS `APP_URL` and secure cookies. Verify there are no mixed-content asset requests. If a proxy terminates TLS, configure trusted proxy addresses explicitly for the hosting topology; do not trust arbitrary forwarded headers. Exclude authenticated RMS pages and document routes from shared/CDN page caching.

## 13. Backups and recovery

Maintain daily encrypted backups of MySQL, `storage/app/rms-private`, the application key and the deployed release. Keep at least one off-host copy accessible only to authorized administrators. Define retention with the business owner. Restore a backup into a separate private staging environment regularly and verify resume downloads, ownership, role assignments and candidate-code sequence continuity. Soft deletion is not a backup. Roll back application releases without dropping recruitment tables; use a coordinated database restore only when approved.

## 14. Deployment verification

- [ ] Confirm Laravel 12, Filament 4 and PHP 8.2+ through installed package metadata/platform checks.
- [ ] `/up` responds; `/admin/login` loads CSS and Livewire JavaScript.
- [ ] Create the real administrator with `rms:create-admin`; verify no demo credentials or test database were deployed.
- [ ] Create two HR test accounts; each can see only assigned candidates, including global search and related records.
- [ ] Verify invalid interview transitions, duplicates, inactive sessions and unauthorized downloads are denied.
- [ ] Upload actual PDF/DOCX files; reject oversized files and renamed executable/text files.
- [ ] Test reassignment, pending follow-up transfer, soft deletion and restoration.
- [ ] Export all required reports and inspect filtered totals and spreadsheet-safe text values.
- [ ] Verify light/dark mode and mobile layouts with the deployed hostname.
- [ ] Requests for `/.env`, `/composer.json`, `/storage/app/rms-private/...` and SQL backups cannot expose files.
- [ ] Run backup/restore rehearsal, inspect error logs and verify HTTPS/cookies before business use.

## 15. Troubleshooting

| Symptom | Check |
| --- | --- |
| Login page reloads without logging in | `/livewire/livewire.js` must return JavaScript; ensure `.htaccess` routes dynamic requests. Locally use `php artisan serve`, not an unconfigured PHP static server. |
| 419 / session expired | Correct HTTPS domain, cookie settings, writable sessions, sessions migration, shared application key and stale config cache. |
| 403 after login | Account must be active and have `Super Admin` or `HR Executive`; seed roles first. |
| Class or extension missing | Upload complete `vendor`, verify CLI/domain PHP versions, intl, zip, fileinfo and PDO MySQL. |
| Vite manifest missing / plain layout | Build and upload `public/build`; publish Filament assets; verify `APP_PUBLIC_PATH` for split layouts. |
| Upload fails before validation | PHP request limits must exceed the 5 MB application limit; confirm temporary/private disk permissions. |
| Database access denied | Use hPanel's exact database/user prefixes, host and password; never expose those values in error pages. |
| Slow exports | Use date/HR/position filters; check hosting limits. CSV is streamed in chunks, while dashboards use database aggregates. |
| 500 after deploy | Check private Laravel logs, permissions and cached config. Keep `APP_DEBUG=false` on public deployments. |

Hostinger account credentials, DNS, certificates, hosting-plan limits, backups and real production deployment are outside the local verification performed in this repository.
