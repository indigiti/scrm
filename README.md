# SchoolFlow — Multi-user School Enquiry CRM

A working **multi-user web app** inspired by the supplied school admin dashboard reference. Includes a FastAPI backend, persistent SQLite storage, role-enforced school workspaces, a responsive HTML/CSS/JS CRM interface, and a live website enquiry API.

This is a **self-hosted MVP**, not a hosted service. It works across multiple staff browsers on the same reachable server. Schools and their data are kept separate. A full security/privacy review, HTTPS, backups and production infrastructure are required before entering live child/guardian data at scale.

## 1. Start the app

You need Python 3.11+.

```bash
cd SchoolFlow_Multiuser_CRM
python -m venv .venv
# Linux/macOS:
source .venv/bin/activate
# Windows: .venv\Scripts\activate
pip install -r requirements.txt
python app.py create-admin
uvicorn app:app --host 127.0.0.1 --port 8000
```

Open **http://127.0.0.1:8000**. Sign in using the email/password you provided during `create-admin`.

For access from other devices on a trusted LAN, use `--host 0.0.0.0` with firewall controls and a trusted HTTPS reverse proxy. **Do not expose bare Uvicorn or HTTP login traffic to the public Internet.**

To choose the database path, set the `SCHOOLFLOW_DB` environment variable before both `create-admin` and `uvicorn`, e.g. `SCHOOLFLOW_DB=/secure/path/schoolflow.sqlite3`.

### Docker option

If you have Docker with Compose, you can also run:

```bash
docker compose build
docker compose run --rm schoolflow python app.py create-admin
docker compose up -d
```

The app will be available at **http://127.0.0.1:8000**, bound only to localhost. Configure an HTTPS reverse proxy before public Internet access. The named `schoolflow_data` volume contains the SQLite database; include it in encrypted backups.

## 2. Everyday CRM workflow

1. Log in as Admin. Optionally click **+ Add school** for another isolated school workspace.
2. Go to **Team**, choose **Add staff member** and create an account for each counsellor/admissions user. New account passwords must have 12+ characters. Existing account emails can be granted membership in another school without resetting the password.
3. Go to **Websites & Forms** → **Connect website** for each WordPress site, campaign page or custom website. **Copy the generated API key immediately.** It will never be displayed again.
4. Connect the form to the server-side intake API (see below).
5. New leads appear in **Enquiries** and **Dashboard**, with source attribution and automatic assignment to the least-loaded counsellor. Move cards in **Pipeline**, add notes, schedule follow-ups and export CSV reports.

Roles are enforced on the backend:

| Role | View enquiries | Edit enquiries | Add sources/staff |
|---|---|---|---|
| Admin | All leads for member school | Yes | Yes |
| Admissions | All leads for member school | Yes | No |
| Counsellor | Only assigned leads | Only assigned leads | No |

## 3. WordPress form connection

The sample plugin `wordpress/schoolflow-contact-form-7.php` supports **Contact Form 7**. For each WordPress website:

1. Add its own website source in SchoolFlow. Copy its site-specific key.
2. Place the included PHP file in a WordPress plugin folder (`wp-content/plugins/schoolflow-cf7/schoolflow-contact-form-7.php`) and activate it. Install/activate Contact Form 7 first.
3. Add these two constants to that website's `wp-config.php` (server-side only):

```php
define('SCHOOLFLOW_CRM_URL', 'https://crm.yourschooldomain.com');
define('SCHOOLFLOW_CRM_SITE_KEY', 'sfsite_YOUR_SITE_KEY');
```

4. Configure Contact Form 7 input field names: `student_name`, `parent_name`, `phone`, `grade`, `email`, `message`, `consent`. Make `consent` a **required guardian consent checkbox**.
5. Submit a test enquiry. It should show in SchoolFlow. If it fails, confirm outbound WordPress HTTPS works, the CRM URL and key are correct, and your form's field names match the mapping. The integration never embeds the key in page HTML.

For **WPForms, Elementor, Gravity Forms and custom websites**, configure their trusted server-side automation/webhook logic to call the same endpoint. A browser-side script must **not** contain the key.

### Inbound API contract

`POST https://crm.example.com/api/v1/inbound-leads`

Headers:

```http
Authorization: Bearer sfsite_PER_WEBSITE_PRIVATE_KEY
Content-Type: application/json
Idempotency-Key: form-submission-000123
```

Body:

```json
{
  "student_name": "Example Student",
  "parent_name": "Example Guardian",
  "phone": "+91 9876543210",
  "class": "Grade 4",
  "email": "parent@example.org",
  "message": "Please share admission fees",
  "utm_source": "facebook",
  "utm_campaign": "admissions-2026",
  "consent": true
}
```

On success the API returns `201 {"id":123,"duplicate_submission":false}`. Resending the **same** `Idempotency-Key` for the **same website** returns HTTP 200 and the original ID; it does not create another lead. New form submissions using the same phone number are retained but flagged with `duplicate_of` for staff review. The backend resolves `school_id` exclusively from the site key; it does not accept a caller-provided school ID.

## 4. Security and data handling

Built in: PBKDF2 password hashes with per-user random salt, session tokens stored as SHA-256 hashes in DB with 12-hour expiry, school membership checks on every CRM API, per-role access, per-site secret keys stored as hashes, HTTPS-oriented WordPress connector, idempotent webhooks, phone-duplicate warning, basic process-local rate limits, activity timeline and admin/lead audit events, parameterised SQL and escaped HTML rendering.

**Before real-world deployment:** put this behind HTTPS (Caddy, nginx or another trusted reverse proxy), add distributed rate limiting, configure firewall/security headers and CSRF posture, enforce password reset/MFA, automate encrypted offsite backups and restore drills, configure alerts and operational logs without personal information, create consent notices/data retention and deletion policies appropriate to your location, implement least-privilege tenant-specific exports, perform penetration testing, and migrate from SQLite to PostgreSQL if multiple API processes or high write concurrency are required. This MVP does not send automated SMS/WhatsApp/email alerts and does not support staff self-service password reset yet. Auto assignment is not an asynchronous queue.

**Development/test data:** do not store real student or guardian information until the above controls are in place. This package contains no seeded personal records and no default passwords.

## 5. Project structure

```
app.py                 FastAPI backend, SQLite schema and authentication
static/index.html      CRM entry page
static/style.css       UI design system based on reference screenshots
static/app.js          Interactive multi-page front end
wordpress/*.php        Contact Form 7 server-side connector
requirements.txt       Python dependencies
Dockerfile / compose.yaml   Optional container-based deployment
tests/test_api.py       Core API tests
```

## 6. Tests

```bash
pip install pytest httpx
python -m pytest -q
```

The source code is deliberately framework-light so your developers can extend this into a React/Next.js or PostgreSQL version later without changing the intake API contract.