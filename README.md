# SchoolFlow CRM — DigiOps / Cloudways PHP deployment

This repository contains the **PHP-only, database-free** School Enquiry CRM. It supports multiple schools, authenticated staff, counselling and admission stages, enquiries from many WordPress websites, staff assignments, notes and follow-ups. Records live in locked JSON files; MariaDB can be added later without changing the intake API.

## DigiOps settings (already configured)

| Setting | Value |
| --- | --- |
| GitHub repository | `indigiti/scrm` |
| Release branch | `main` |
| Workflow artifact | **`digiops-release`** |
| Browser route | **`/scrm/`** |
| Public deploy folder | **`public_html/scrm/`** |
| Private runtime folder | **`private_html/scrm/`** |

**Do not deploy the whole repository directly to `public_html/scrm/`.** The repository has private PHP source, tests and WordPress connector files. GitHub Actions builds a **public-only release artifact** containing exactly:

```
index.php                  # Self-contained PHP API + dashboard gateway
.htaccess                 # Apache routing at /scrm/
static/index.html         # Browser shell
static/app.js             # CRM frontend
static/style.css          # CRM styles
```

`index.php` in the artifact is built from the editable `app_private/store.php`, `app_private/api.php`, and `public_html/index.php` sources. The bundled PHP contains no API keys, user passwords or stored data. **Never include JSON runtime records in a release.**

The app writes its persistent records to `private_html/scrm/data/store.json`, with an adjacent `store.lock`. It determines that location relative to the deployed `public_html/scrm/index.php` and preserves data across new releases. Requires a **single writable shared filesystem** and PHP 8.2+. No Python, Docker, MySQL or MariaDB installation is required.

## Build and release through GitHub Actions

On push to `main` (or manual *Run workflow*), `.github/workflows/digiops-release.yml`:

1. Lints PHP files and runs the PHP API smoke tests.
2. Generates and lints a standalone `dist/digiops-release/index.php` and copies only public assets.
3. Publishes a GitHub Actions artifact named **`digiops-release`** (including hidden `.htaccess`).

DigiOps should show a successful build after **Check update** and then be able to deploy this artifact into `public_html/scrm/`. **This GitHub workflow only builds and uploads the artifact.** It does **not** contact Cloudways or invoke the DigiOps Deploy button directly; deploy using DigiOps. If DigiOps requires a different internal archive layout, confirm its artifact contract before deployment.

To reproduce locally:

```bash
php tests/test_smoke.php
php tools/build-digiops.php
php -l dist/digiops-release/index.php
```

Do not upload `dist/` into Git; the workflow generates the public-only payload.

## First Cloudways installation

1. Enable **HTTPS** for the application and use a Cloudways PHP runtime version **8.2 or newer**.
2. Ensure the DigiOps deployment extracts artifact contents into `public_html/scrm/` such that `public_html/scrm/index.php` exists. In particular, **do not deploy the artifact as an extra nested folder** `public_html/scrm/digiops-release/`.
3. Ensure PHP can create and write `private_html/scrm/data` (or create it with owner permissions, **not** mode `777`). The directory must not be publicly accessible or included in GitHub release artifacts.
4. Exclude `/scrm/*` from all **Varnish / reverse-proxy caching**, particularly `/scrm/api/*` and `/scrm/health`. Ensure the `Authorization` header is forwarded to PHP. The app sends `Cache-Control: private, no-store`.
5. Open `https://YOUR_CLOUDWAYS_DOMAIN/scrm/`. If the app has no users, it displays **Create first administrator** instead of a sign-in form. To prevent strangers claiming your admin account **without using SSH**, verify ownership of the `indigiti/scrm` GitHub repository directly in your browser. While signed in as GitHub user `indigiti`, create a [fine-grained personal access token](https://github.com/settings/personal-access-tokens/new) with no additional repository permissions (GitHub `GET /user` needs none). Fill in the SchoolFlow first-admin form with the token, school name, admin name, email, and a password of 12+ characters. SchoolFlow checks the token directly with GitHub over HTTPS, confirms the immutable GitHub owner user ID `34906944`, and never stores the token. **Revoke the token immediately after successful setup.**

   **Optional fallback** if GitHub verification cannot reach GitHub: an operator with Cloudways SSH access can still create an administrator directly using the `create-admin` CLI. Set `SCHOOLFLOW_ADMIN_PASSWORD` as an environment variable and run `php public_html/scrm/index.php create-admin admin@example.com 'Admin Name' 'School Name'`. Do not expose the password in terminal history, shell arguments or GitHub Actions logs. Older SSH-generated setup codes remain accepted by the API for compatibility, but the normal browser form uses GitHub verification.

   After initialization, visitors see the standard **Sign in** page, and attempts to register another first administrator are rejected. Browser setup attempts are rate-limited, and the setup endpoint cannot reset an existing account.
6. Open `https://YOUR_CLOUDWAYS_DOMAIN/scrm/` (or your domain's `/scrm/` path), sign in and create staff, schools and website integrations.
7. Verify `https://YOUR_CLOUDWAYS_DOMAIN/scrm/health` responds, then verify login and WordPress lead capture using a test enquiry before using real family records.

## Connect WordPress websites (Contact Form 7)

Copy `wordpress/schoolflow-contact-form-7.php` from the repository to the WordPress site's `wp-content/plugins/schoolflow-cf7/` directory, then activate it. On **each** connected website, store the unique site API key server-side in `wp-config.php`, never in browser JavaScript or a public form:

```php
define('SCHOOLFLOW_CRM_URL', 'https://YOUR_CLOUDWAYS_DOMAIN/scrm');
define('SCHOOLFLOW_CRM_SITE_KEY', 'sfsite_UNIQUE_KEY_FROM_THE_CRM');
```

The plugin sends to `https://YOUR_CLOUDWAYS_DOMAIN/scrm/api/v1/inbound-leads` using the website-specific bearer token. Create a Contact Form 7 form with fields `student_name`, `parent_name`, `phone`, `grade`, `email`, `message` and required guardian `consent`. Each website has its own revocable key and the correct school is determined from that key (not from a public submitted school ID).

Manual API example:

```http
POST /scrm/api/v1/inbound-leads HTTP/1.1
Authorization: Bearer sfsite_THE_WEBSITE_SECRET
Idempotency-Key: wordpress-submission-123456
Content-Type: application/json

{"student_name":"Example Student","parent_name":"Example Guardian","phone":"+91 9876543210","class":"Grade 5","consent":true}
```

Unique idempotency keys prevent duplicate submissions. A phone-number duplicate is tracked and flagged.

## Content Security Policy (CSP) diagnostics

The CRM permits only **same-origin external JavaScript** through `script-src 'self' 'report-sample'`. The dashboard loads **`/scrm/static/app.js`** as an external file; it does not use inline `<script>` blocks or inline `onclick` handlers. **Do not disable CSP or add `unsafe-inline`** to silence an error.

If Chrome reports `Executing inline script violates Content Security Policy`, and login / first-admin setup still function, the blocked script may have been injected by a browser extension (such as a password manager), Cloudways/DigiOps page customization, or a proxy. `report-sample` requests diagnostic samples of blocked content where supported; it does not permit execution.

To isolate the origin:

1. Try `/scrm/` in an Incognito browser window with extensions disabled.
2. Open DevTools → **Console**. Click the CSP error to inspect the **source filename and line**; then use **Network** to confirm `/scrm/static/app.js` has status **200**.
3. In **Network → Document → Response**, compare the served HTML with `public_html/static/index.html`. An additional inline `<script>` tag in the response indicates server/proxy injection. If this occurs, remove the injector or move its trusted JavaScript into a same-origin external file.
4. Share the blocked script's source filename / line if it persists in a clean browser. Only allow a narrowly scoped script hash **after** identifying and reviewing its content. Do not whitelist an unknown hash merely because the browser suggests it.

The GitHub release workflow runs `php tests/test_csp.php` to reject first-party inline script regressions in the **packaged** DigiOps artifact.


### If a previous login screen stays after deploying

The DigiOps build now fingerprints `static/app.js` and `static/style.css` using a 12-character SHA-256 query (`?v=...`) in the packaged HTML. This makes each updated frontend asset fetch a new URL, even behind a browser/CDN cache. The PHP page itself sends `Cache-Control: private, no-store`. In Chrome DevTools → Network, confirm `/scrm/static/app.js?v=...` returns HTTP 200 and that `/scrm/api/setup/status` returns `{"needs_setup":true}` for a fresh installation. Avoid enabling `unsafe-inline`; CSP warnings about injected scripts may originate from extensions or an upstream HTML injector. If the error remains, disable **all** extensions (incognito does not always disable extensions that have been permitted there) and reload.

## Data security, backups and MariaDB later

All student, guardian, school and user records persist in **private JSON storage** with exclusive `flock()` locks and atomic writes. The runtime data is **not** included in the GitHub artifact, and a new deployment must **never** remove or overwrite `private_html/scrm/data`.

This is intended for modest usage on a single Cloudways PHP server. File-backed JSON is not an enterprise data store: every write replaces the JSON document, and it does not support multiple servers without redesign. MariaDB can later replace `SchoolFlowStore` with an import of the JSON data; **MariaDB migration is not implemented** yet.

Before processing actual pupil or guardian details, ensure TLS, backups with restore tests, proper permissions, school privacy and consent policies, account recovery/MFA, additional abuse protection, and a security/privacy review. At-rest data is **not encrypted by the app**. Avoid putting secrets or school enquiry data in a public repository or GitHub Actions artifacts.

**Deployment status:** The GitHub build creates a deployable artifact; clicking Deploy in DigiOps is a separate step. Do not assume the CRM is live until DigiOps reports successful deployment and the public URL is checked.

## Administrator demo-data controls

On the **Dashboard**, an authenticated administrator can select **Add demo data** to create 18 clearly marked fictional enquiries, example follow-ups, and three **disabled** demo website sources. The sample enquiries cover all admissions pipeline stages and use non-routable `example.invalid` domains and synthetic zero-prefixed telephone numbers. The operation is idempotent: an existing set must be cleared before adding a new one. No dummy staff credentials are generated.

**Clear demo data** requires typing `CLEAR DEMO`, deletes only records explicitly marked `is_demo` and their lead activities, and leaves real enquiries, registered staff, actual websites, login sessions and other schools untouched. A demo website with a real lead linked to it is deliberately retained to avoid orphaning real leads. Both endpoints enforce the Admin role server-side and are scoped to the selected school.

- `GET /scrm/api/schools/{id}/demo-data` — get demo record counts (Admin only)
- `POST /scrm/api/schools/{id}/demo-data/seed` — add demo records (Admin only)
- `POST /scrm/api/schools/{id}/demo-data/clear` with `{"confirm":"CLEAR DEMO"}` — remove only demo records (Admin only)

No sample enquiries are imported automatically on deployment; the admin clicks the button for the school where examples are wanted.

## Troubleshooting: Add demo data says “JSON object required”

This error was fixed in the HTTP request parser. A legitimate empty JSON object `{}` is now accepted; JSON arrays such as `[]` are still rejected. The demo seed button deliberately submits `{}` because it does not require any parameters. The GitHub workflow runs `php tests/test_http_json.php` against the **built** release to prevent regressions.

After DigiOps deploys the new `digiops-release` artifact, refresh `/scrm/` and select **Add demo data** again. Existing administrator accounts and actual enquiries are preserved.


## Layout and enquiry drawer

The authenticated CRM fills the browser width on desktop (including zoomed-out displays). Enquiry **View** opens a fixed right-side detail drawer above the page with a dimmed backdrop and its own scroll area. On narrow screens it becomes a full-width drawer. Click the outside backdrop or the close icon to exit.

DigiOps build validates presence of the required `.drawer-overlay` and `.drawer-head` styles; no API/database changes are needed for this fix. Stylesheets use content-hash cache versioning, so deploying a new `digiops-release` loads updated CSS automatically.