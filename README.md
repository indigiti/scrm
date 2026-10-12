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
5. Open `https://YOUR_CLOUDWAYS_DOMAIN/scrm/`. If the app has no users, it displays **Create first administrator** instead of a sign-in form. To prevent strangers claiming your admin account, generate a one-time bootstrap code via Cloudways SSH, then enter it in the form along with your school name, name, email and password (12+ characters):

   ```bash
   cd ~/applications/YOUR_CLOUDWAYS_APP
   php public_html/scrm/index.php setup-code
   ```

   **Keep the printed setup code private.** Only a SHA-256 hash is stored server-side in `private_html/scrm/data/store.json`; the raw code is never returned over HTTP or included in the release. Running `setup-code` again before initialization rotates the code. The creation endpoint works only while the store has no users; it consumes the code atomically and signs the administrator in. After initialization, visitors see the standard **Sign in** page, and attempts to register another first administrator are rejected. The setup endpoint cannot reset an existing account.

   **Optional headless setup:** You can still create the administrator entirely over SSH by using `SCHOOLFLOW_ADMIN_PASSWORD` and `php public_html/scrm/index.php create-admin admin@example.com 'School Admin' 'My School'`. Never put a password in shell arguments or GitHub workflows.
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

## Data security, backups and MariaDB later

All student, guardian, school and user records persist in **private JSON storage** with exclusive `flock()` locks and atomic writes. The runtime data is **not** included in the GitHub artifact, and a new deployment must **never** remove or overwrite `private_html/scrm/data`.

This is intended for modest usage on a single Cloudways PHP server. File-backed JSON is not an enterprise data store: every write replaces the JSON document, and it does not support multiple servers without redesign. MariaDB can later replace `SchoolFlowStore` with an import of the JSON data; **MariaDB migration is not implemented** yet.

Before processing actual pupil or guardian details, ensure TLS, backups with restore tests, proper permissions, school privacy and consent policies, account recovery/MFA, additional abuse protection, and a security/privacy review. At-rest data is **not encrypted by the app**. Avoid putting secrets or school enquiry data in a public repository or GitHub Actions artifacts.

**Deployment status:** The GitHub build creates a deployable artifact; clicking Deploy in DigiOps is a separate step. Do not assume the CRM is live until DigiOps reports successful deployment and the public URL is checked.