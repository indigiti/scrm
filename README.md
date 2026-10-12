# SchoolFlow — Cloudways PHP Enquiry CRM

**PHP-only, no Python and no database service required.** This is a self-hosted, multi-user School Enquiry Management CRM using the same UI as the earlier prototype. It captures enquiries from many WordPress sites, isolates schools, assigns counsellors, schedules follow-ups and tracks admission stages.

**Technology:** PHP **8.2+**, HTML, CSS, vanilla JavaScript, flat JSON-file persistence. MariaDB **is optional for future scaling**, not used by this release. PHP's native password hashing and secure random-number functions are required (standard on Cloudways PHP).

## Cloudways deployment (custom PHP application)

1. In Cloudways create/select a **PHP Custom App** (prefer an application separate from your existing WordPress site), configure a domain such as `crm.example.com`, and enable HTTPS in the Cloudways SSL settings.
2. Using the Cloudways application SFTP/SSH account, upload the contents of this package **to the application root**, with the following directory arrangement:

   ```text
   applications/YOUR_APP/
   ├── public_html/                 ← the ONLY web-accessible directory
   │   ├── .htaccess                ← Apache rewrite rules for /api/…
   │   ├── index.php                ← API + dashboard gateway
   │   ├── router.php               ← local PHP built-in server only
   │   └── static/{index.html,app.js,style.css}
   ├── app_private/                ← OUTSIDE public_html
   │   ├── api.php                  ← PHP API and role checks
   │   ├── store.php                ← locked file persistence layer
   │   └── data/                    ← created automatically at first use
   ├── bin/create-admin.php
   ├── wordpress/schoolflow-contact-form-7.php
   └── tests/test_smoke.php
   ```

   **Never place `app_private`, backups, API secrets, or `bin` inside `public_html`**. This is more secure than relying on `.htaccess` protection, especially with Nginx in front of Apache. Cloudways documents keeping sensitive files above `public_html`. If needed, set `SCHOOLFLOW_DATA_DIR` to a writable **non-public absolute path** accessible to the web PHP process and PHP CLI account.
3. Ensure PHP has write permission **only to `app_private/data/`** (normally PHP will create it, if `app_private` is writable). Protect the parent and data directory against reads by other tenants/users. The application saves its database-like records in `app_private/data/store.json` with a process-wide `flock()` on `store.lock`. If the directory is unwritable, the API returns HTTP 500; **do not** fix by making it world-writable (`777`). Use the same filesystem for all PHP workers.
4. Disable Varnish caching for the CRM (or explicitly bypass `/api/*`, `/health` and authenticated responses); do not cache any enrolment/personal-data endpoints. Confirm PHP sends `Authorization` headers to FastCGI/PHP, and that `.htaccess` rewrites `/api/*` to `index.php`.
5. Create the initial admin using **SSH/Cloudways terminal** in the package root. Enter the password **without putting it in your shell history**:

   ```bash
   cd ~/applications/YOUR_APP
   read -rs -p 'New CRM admin password: ' SCHOOLFLOW_ADMIN_PASSWORD; echo
   export SCHOOLFLOW_ADMIN_PASSWORD
   php bin/create-admin.php admin@example.com 'School Admin' 'My School'
   unset SCHOOLFLOW_ADMIN_PASSWORD
   ```

   Use a unique admin password of at least 12 characters. Only the **first** admin can be bootstrapped this way, and there are **no default accounts**. For the fastest setup the app can also run locally:

   ```bash
   php -S 127.0.0.1:8100 -t public_html public_html/router.php
   ```

6. Open `https://crm.example.com/`, sign in, create staff in **Team** and create a Website Source in **Websites & Forms** for each WordPress or landing-page domain. Copy each source key once; it is not displayed again.

## Roles and data isolation

- **Admin:** see and edit school leads; manage staff, website sources, and API keys; create another school workspace.
- **Admissions:** see and edit all leads belonging to their school.
- **Counsellor:** see and edit only leads assigned to their account. Newly submitted enquiries are auto-assigned to the least-loaded counsellor.

Every request validates that the logged-in user belongs to the selected school. A website API key identifies **one** school; the client may not supply its own school ID. Existing staff accounts cannot be silently attached to another school; invitation/acceptance is reserved for a later release.

## Connect WordPress (Contact Form 7)

Copy `wordpress/schoolflow-contact-form-7.php` to a WordPress website's `wp-content/plugins/schoolflow-cf7/` directory and activate the plugin. On the **WordPress** site, keep credentials server-side in `wp-config.php`:

```php
define('SCHOOLFLOW_CRM_URL', 'https://crm.example.com');
define('SCHOOLFLOW_CRM_SITE_KEY', 'sfsite_COPY_THIS_WEBSITE_KEY');
```

Create a Contact Form 7 form with names: `student_name`, `parent_name`, `phone`, `grade`, `email`, `message`, `consent`. Require a **guardian consent checkbox**. The server-side plugin posts to the PHP CRM over HTTPS. Other WordPress plugins or custom websites can send the same payload from **server-side** code; never expose the key in JavaScript or HTML.

### Intake API (compatible with the previous Python version)

```http
POST https://crm.example.com/api/v1/inbound-leads
Authorization: Bearer sfsite_YOUR_PRIVATE_WEBSITE_KEY
Idempotency-Key: unique-form-submission-123456
Content-Type: application/json

{
  "student_name": "Example Student",
  "parent_name": "Example Guardian",
  "phone": "+91 9876543210",
  "class": "Grade 5",
  "email": "guardian@example.com",
  "message": "Please share admission details",
  "utm_source": "facebook",
  "utm_campaign": "admissions-2026",
  "consent": true
}
```

A new submission responds with `201 {"id":1,"duplicate_submission":false}`. The same `Idempotency-Key` for the same site returns `200 {"id":1,"duplicate_submission":true}` without creating another enquiry. Another submission with the same phone number is retained and flagged `duplicate_of`. Keys are unique per source and can be rotated/disabled.

## Local tests (PHP only)

```bash
php tests/test_smoke.php
find app_private bin public_html -name '*.php' -exec php -l {} \;
```

## Storage, backup and migration

All users, schools, enquiries, notes, API key hashes and session hashes are in **one private JSON file**. Writes are serialized using `flock` and atomically replaced under a lock. This is practical for a small/medium initial deployment on **one Cloudways filesystem**. It is **not** a full relational database: it rewrites the entire data file per update, and will slow down as enquiries/activities grow. Do not use multiple servers or shared remote storage without reworking locking.

- Back up the entire private `data/` directory into access-controlled **encrypted** offsite storage; include `store.json` and test restores. Do not commit it to GitHub.
- When load increases, replace `SchoolFlowStore` with a MariaDB repository/transaction layer and a one-time JSON import; the `/api/*` contract and WordPress connectors can remain unchanged. **Automatic MariaDB migration is not included.**
- The JSON files are **not encrypted at rest by the application**. Guardian/student names and contact details are sensitive. Keep strict filesystem permissions and backups under access control.

## Production readiness limitations

This MVP is **not** yet audited or deployed. Before admitting real student/guardian data, add monitoring, managed rate limiting beyond the simple in-file limits, account recovery and MFA, password policies, automated encrypted backups, disaster recovery tests, defined retention/deletion policies, legal/guardian consent copy, and security assessment. Use HTTPS everywhere. Admin self-service password reset and SMS/WhatsApp messages are not included.

The previous Python/FastAPI version is superseded on the feature branch; the PHP release does **not** require Python, SQLite, Docker, or MariaDB to run.