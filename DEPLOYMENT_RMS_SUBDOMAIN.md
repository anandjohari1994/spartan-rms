# RMS Hostinger subdomain runbook

Working deployment reported by the owner on 22 October 2026 after applying the two rewrite files below. Login and Livewire must use URLs without `/public`. Full production workflows were not independently verified as part of this deployment troubleshooting.

## Current server layout

| Setting | Value |
| --- | --- |
| Website | `https://rms.apjsoftwares.in` |
| Login | `https://rms.apjsoftwares.in/admin/login` |
| Application / SSH working folder | `/home/u948541527/domains/apjsoftwares.in/public_html/rms` |
| Current subdomain folder | `public_html/rms` |
| Laravel entry point | `public/index.php` |
| Compiled manifest | `public/build/manifest.json` |
| PHP requirement | `^8.2`; web PHP observed as 8.2.34 |

This runbook preserves the existing deployment arrangement. For a new deployment, prefer serving only Laravel's `public` directory or using the private application / public files split described in [the general deployment guide](DEPLOYMENT_HOSTINGER.md). Root rewrites depend on the web server honoring `.htaccess`; they are not equivalent to keeping application files outside the document root. Do not remove the root rules while this layout is in use.

## 1. Back up before updating

Back up both server `.htaccess` files, the existing `.env`, database, and private uploaded documents. Preserve `APP_KEY`. Do not upload a development `.env`, test databases, `node_modules`, `.git`, or `public/hot`. Do not run `migrate:fresh` or reset production data.

## 2. Root rewrite file

Save as `/home/u948541527/domains/apjsoftwares.in/public_html/rms/.htaccess`:

```apache
# Hostinger PHP 8.2
<FilesMatch "\.(php|phtml)$">
    SetHandler application/x-lsphp82
</FilesMatch>

Options -Indexes

<IfModule mod_rewrite.c>
    RewriteEngine On

    # Requests inside public are handled by public/.htaccess
    RewriteRule ^public(?:/|$) - [L]

    # Route everything else into Laravel's public directory
    RewriteRule ^(.*)$ public/$1 [L]
</IfModule>
```

Do not add an existing-file bypass here: application files outside `public` must not be served directly. Do not replace the last rule with `index.php`; this layout's entry point is inside `public`.

## 3. Public rewrite file

Save as `/home/u948541527/domains/apjsoftwares.in/public_html/rms/public/.htaccess`:

```apache
# Hostinger PHP 8.2
<FilesMatch "\.(php|phtml)$">
    SetHandler application/x-lsphp82
</FilesMatch>

<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On

    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    RewriteCond %{HTTP:X-XSRF-Token} .
    RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]

    # Serve public files directly; send other requests to Laravel
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
```

These rules intentionally omit the trailing-slash redirect used in some standard templates, avoiding redirects involving the internal `/public` rewrite. The PHP handler is specific to this Hostinger environment.

## 4. Production environment

Update the existing server `.env` without changing its key or database credentials:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://rms.apjsoftwares.in
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=sync
LOG_CHANNEL=daily
LOG_LEVEL=warning
```

For this layout, normally leave `APP_PUBLIC_PATH` unset: Laravel's default public path is already `rms/public`. Do not append `/public` to `APP_URL`. Web PHP and SSH PHP can differ; verify extensions with Composer and the web runtime when diagnosing differences.

## 5. Build and upload a release

On the local build machine, from the project folder:

```sh
npm ci
npm run build
```

Upload the entire `public/build` directory, including both `manifest.json` and its matching `assets` directory. Its server destination is:

```text
/home/u948541527/domains/apjsoftwares.in/public_html/rms/public/build/manifest.json
```

Avoid an extra `build/build` folder. Local changes do not update Hostinger until uploaded. Preserve both deployment rewrite files when uploading releases.

Upload application changes and both Composer files. In Hostinger SSH:

```sh
cd /home/u948541527/domains/apjsoftwares.in/public_html/rms
php -v
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
php artisan optimize:clear
php artisan filament:assets
php artisan config:cache
php artisan view:cache
```

Use `composer install`, not `composer update`, on the server. The lock file resolves PHP 8.2-compatible dependencies while retaining Laravel 12 and Filament 4. Without server Composer, prepare production dependencies in a compatible build environment and upload the full `vendor` directory. If publishing assets locally, upload `public/css`, `public/js`, and `public/fonts` as well.

Database migrations and seeding are separate release steps: review changes and take a verified backup before running approved production migrations. For a new installation, follow the general deployment guide. To create a new super administrator when needed, run `php artisan rms:create-admin`; do not recreate accounts on each release.

## 6. Verify after deployment

1. Open `https://rms.apjsoftwares.in/admin/login` without `/public`.
2. Wait for background requests; a visible form alone is not proof of success. Confirm no error overlay appears.
3. Confirm the Livewire script URL referenced by the page returns JavaScript. Its `id` query value changes between releases; copy the current URL rather than hard-coding an old hash.
4. Sign in and confirm the dashboard loads. In browser Network tools, check that Livewire update POST requests succeed. A direct GET to the update endpoint is not a valid functional test.
5. Verify styling, logout, and an authorized private-document download.
6. Verify `/.env`, `/composer.json`, and private storage paths do not expose application files. Test parent-domain paths too if it serves the same directory tree.
7. Remove temporary `php-version.php` and `rms-check.php` diagnostic files.

## 7. Troubleshooting commands

Run from the application folder. To locate all current logs and extract error headings rather than only the bottom of a long stack trace:

```sh
ls -lt storage/logs/
grep -H -E '^\[[0-9]{4}-.*\.(ERROR|CRITICAL|EMERGENCY):' storage/logs/*.log | tail -n 10
```

Compare timestamps with a fresh failing request. This command groups output by filename, so consult the newest file from `ls` when several daily logs exist. The old missing-manifest exception remains in the log after the file is fixed.

```sh
ls -l public/build/manifest.json
php -r '$p=getcwd()."/public/build/manifest.json"; echo $p.PHP_EOL; var_dump(is_file($p), is_readable($p));'
php artisan route:list --path=livewire
```

| Symptom | Action |
| --- | --- |
| Vite manifest missing | Upload the complete matching build to the exact path in the error; confirm no nested `build/build` folder. |
| Login form behind 404/500 overlay | Inspect the failing Livewire request; verify root and public rewrite files and use `/admin/login`. |
| `/public/admin/login` renders but `/admin/login` fails | Check the subdomain folder mapping and root rewrite file. |
| Livewire script returns HTML or 500 | Check server PHP logs and rewrite configuration; do not manually copy one script to conceal a routing failure. |
| No new Laravel errors | Check daily log files, web-process write permissions, and Hostinger PHP/server logs. CLI logging success does not prove web logging works. |
| PHP version mismatch | Confirm both web PHP and SSH PHP; retain the updated Composer lock file and installed dependencies. |

Keep `APP_DEBUG=false` publicly. Do not post session cookies, credentials, or complete `.env` files when sharing diagnostics.
