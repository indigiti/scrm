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


## Persistent audit log (from DigiOps release #9)

SchoolFlow saves an append-only, school-scoped audit trail in the protected JSON store at `private_html/scrm/data/store.json`. It records **who**, **when**, **school**, **enquiry** (where applicable), **action**, and a short description. Important actions include successful and known-user failed logins, logouts, school/staff setup, website connections/key rotation/enabling, inbound and manually created enquiries, enquiry detail views, stage/assignment/follow-up changes, note additions, CSV exports, and adding/removing demo data. An enquiry's audit log is at the **bottom of its right-side View drawer**; Admins can also review the school-wide log under **Reports → School activity log**. Both have “Load older activity” pagination.

Audit records are saved under the same locked JSON transaction as the related business operation and are not deleted when demo data is cleared. Historic enquiry activity from prior releases is copied once into the audit stream on the first audited write; existing conversation notes remain intact in the separate conversation history. Secrets (passwords, tokens, private website API keys) and full note text are never placed in audit entries. The API does not expose an audit-delete action. Admin access is required for school-wide records; the per-enquiry log follows normal enquiry visibility permissions.

Audit logging covers **meaningful API and user actions**, not each keystroke or passive dashboard request. Activity generated prior to enabling this feature is limited to the historical enquiry activity already stored; there was no general log for earlier events.

**Operational note:** JSON-based audit storage grows with use; monitor `store.json` size and back up the protected directory. Use MariaDB or another database plus retention/access policies for higher-volume and regulated deployments. The audit trail is application-level and is not cryptographically tamper-proof against an administrator with filesystem access.


## SchoolFlow 2.0 — admissions productivity (PHP + JSON)

This release extends the existing application without replacing its JSON store or changing any stored school, user, enquiry or audit identifiers. **No MariaDB, Python or external messaging service is required.** Deploying the artifact does not create or delete enquiries.

### New features

1. **Call and follow-up management:** From an enquiry's right-hand drawer, staff can record an outcome (Answered / No Answer / Busy / Interested / Not Interested / Callback Requested), optional notes, and next follow-up date. The outcomes are saved to private JSON; the audit log records action metadata without exposing call-note text. Follow-ups show due/overdue items and a manual email-reminder draft action when the guardian has provided contact consent and an email address.
2. **WhatsApp and email quick actions:** Buttons open `wa.me` and `mailto:` links with draft wording. The user must click Send in WhatsApp or their email client. The CRM **does not automatically deliver or confirm delivery**. WhatsApp/email options require recorded guardian contact consent; the links opening are audit-logged. Phone links start a call using the device's dialer if available.
3. **Automatic assignment:** Under **Team**, admins choose `least_loaded` (prior behavior), `round_robin` across active counsellors, or `manual` (leave new leads unassigned). The policy applies only to future enquiries without an explicit assignee. The round-robin cursor is school-scoped and persisted in JSON.
4. **School visits:** A month-based calendar and visit table show bookings, assigned staff and completion/no-show/cancelled status. Staff can book visits from an enquiry or the calendar. Times are entered as school-local date/time (initial convention: Asia/Kolkata) and are not synced to third-party calendars.
5. **Advanced filters:** On **Enquiries**, filter by stage, source, counsellor/unassigned, contact status, follow-up timing and text search.
6. **Analytics:** Dashboard adds first-contact pending, upcoming visits, family profiles and conversion. Reports show assignment distribution and existing funnel/source breakdown. Counsellor analytics are limited to their assigned enquiries.
7. **Parent and sibling profiles:** The **Families** page groups enquiries with the same normalized telephone number within a school. Add a sibling enquiry from an existing enquiry; it reuses the parent contact and assignment but creates a separate enquiry and audit event. These are linked by phone, **not** an identity-verification/merge operation.
8. **Email templates and reminders:** Admins can create/edit school-scoped templates with `{parent}`, `{student}`, `{grade}` and `{school}` placeholders. Staff select a template before opening their email client. Reminders are visible in the follow-up queue, with manual draft actions; **scheduled outbound email or WhatsApp campaigns are not enabled** until a provider, verified opt-in and reliable cron job are configured.

### School-scoped API additions

- `GET/PATCH /scrm/api/schools/{id}/assignment`
- `GET/POST /scrm/api/schools/{id}/visits` and `PATCH /visits/{visitId}`
- `GET /scrm/api/schools/{id}/families` and `POST /families/{leadId}/siblings`
- `GET/POST /scrm/api/schools/{id}/templates` and `PATCH /templates/{templateId}`
- `GET /scrm/api/schools/{id}/analytics`
- `POST /scrm/api/schools/{id}/leads/{leadId}/calls`
- `POST /scrm/api/schools/{id}/leads/{leadId}/contact-action`

All paths above are beneath `/scrm/api/schools/{id}`. Authenticated requests use the existing bearer session. API role and school isolation are checked server-side; normal counsellors cannot manage assignment policy or templates.

### Storage and verification

Existing `store.json` objects are backwards compatible: new collections (`calls`, `visits`, `email_templates`, assignment policies/cursors) are initialized lazily when needed. Do not wipe `private_html/scrm/data` during deployment. Keep secure, tested backups and monitor store growth before expanding the user count substantially. This version does not implement MariaDB migration.

To verify from repository source:

```bash
php tests/test_schoolflow_v2.php
node tests/check_frontend_v2.js
php tools/build-digiops.php
php tests/test_http_json.php
php tests/test_csp.php
php tests/test_layout.php
```

Run **DigiOps → sCRM → Check update → Deploy** to apply the latest successful `digiops-release` to `/scrm/`. GitHub builds do not themselves deploy to Cloudways.


## Multiple schools and automatic school routing

School administrators can create multiple independent school workspaces. Select **Schools & Routing** in the left navigation to see the connected sources, lead counts, and assignment policy for each school you administer. Choose **Connect website** on the desired school: the website's private `sfsite_...` key is permanently bound to that school. Staff from other schools cannot read its leads, and counsellor assignment follows the *destination school's* least-loaded/round-robin/manual setting. The existing school selector still opens each separate dashboard.

**Different WordPress websites:** Create one school and its own website source/key for each school. Configure `SCHOOLFLOW_CRM_SITE_KEY` on that school's WordPress site. Each inbound submission is routed using the key, never a user-submitted `school_id` or school name.

**One shared WordPress website, multiple school forms:** Create a separate website source/key in each school. In the shared WordPress site's private `wp-config.php` use:

```php
define('SCHOOLFLOW_CRM_URL', 'https://stage.digiti.in/scrm');
define('SCHOOLFLOW_CRM_FORM_KEYS', [
    123 => 'sfsite_SCHOOL_A_KEY', // Replace 123 with School A Contact Form 7 ID
    456 => 'sfsite_SCHOOL_B_KEY', // Replace 456 with School B Contact Form 7 ID
]);
```

Activate the updated `wordpress/schoolflow-contact-form-7.php` plugin **on that WordPress website** (this connector is part of the full GitHub source, not the five-file DigiOps release artifact). Unmapped forms are ignored to avoid accidental cross-school routing, even if the old `SCHOOLFLOW_CRM_SITE_KEY` constant is still set. API keys stay on the WordPress server; do not embed them in a public form field. The CRM does not move historical leads if a site's key changes; create a new source for a different school and revoke the old key only when appropriate.

The admin-only `GET /scrm/api/schools/overview` endpoint returns counts and *non-secret* website routing information for schools administered by the authenticated user. No MariaDB schema change is required. The route is covered by `tests/test_multischool_routing.php`.

## School Group website — one enquiry form with a campus dropdown (Release #12)

This is distinct from the previous per-Contact-Form-7-ID mapping. A **single parent enquiry form** on a school group's WordPress website can now route a submission to whichever authorized school/campus the parent selects.

### CRM administrator setup

1. In SchoolFlow, create all campuses under **Schools & Routing → Add school**. You must hold the Admin role for each campus to connect it.
2. In **Schools & Routing → Group websites → Connect group website**, supply the group website name and domain, and check every participating school. SchoolFlow generates **one `sfgroup_...` secret** and a server-side mapping from stable campus codes to school IDs. Copy the key immediately; only its SHA-256 hash is stored.
3. You can edit campus choices without editing PHP: choose **Edit schools** on the group website card. Remove a school to stop new leads being routed there; existing enquiries and audit logs remain with their original school. Rotate or disable a group key as required.
4. In WordPress, replace the SchoolFlow Contact Form 7 connector with `wordpress/schoolflow-contact-form-7.php` version **1.2+** from this repository. Put the private group key into the website's **server-side `wp-config.php`**, not into JavaScript or the public form.

```php
// On the School Group WordPress website only. Replace placeholders.
define('SCHOOLFLOW_CRM_URL', 'https://stage.digiti.in/scrm');
define('SCHOOLFLOW_CRM_GROUP_SITE_KEY', 'sfgroup_COPY_THE_GROUP_SECRET_HERE');
define('SCHOOLFLOW_CRM_GROUP_FORM_IDS', [789]); // Your actual CF7 form ID(s)
```

5. In the selected Contact Form 7 form, add a required school choice, student and parent details, and guardian consent:

```text
<label>Preferred school / campus
[select* school_code]
</label>
<label>Student's name [text* student_name]</label>
<label>Parent / guardian [text* parent_name]</label>
<label>Phone [tel* phone]</label>
<label>Applying for class [text grade]</label>
<label>Email [email email]</label>
<label>Message [textarea message]</label>
[acceptance consent] I agree to be contacted about this admission enquiry. [/acceptance]
[submit "Send enquiry"]
```

SchoolFlow populates the `school_code` select choices through a private **WordPress-server-to-CRM** request. A 60-second WordPress transient can cache choices; submission validation fetches the latest choices, and the CRM independently checks the mapping again. A school added or removed in SchoolFlow becomes available without manually editing PHP.

**Routing:** WordPress sends the group secret and selected `school_code` (`campus-<ID>`) to `POST /scrm/api/v1/inbound-leads`. The CRM checks that the group key is active, the school is an allowed route, the owner's Admin permission for that campus is still valid, and guardian consent is present. The CRM creates the enquiry and school-specific website source attribution under that school, assigns a counsellor using that school's allocation policy, and logs the event. A forged `school_id`, invalid or removed campus code, and cross-school replay are rejected. The private key is never sent to the parent/browser.

The private `GET /scrm/api/v1/group-schools` accepts only the `Authorization: Bearer sfgroup_...` HTTP header and returns `{ "schools": [{ "code": "campus-1", "name": "School Name" }] }` without exposing keys, parent contacts, or any campus outside the group mapping. Do not call this protected route directly from browser JavaScript.

Existing `SCHOOLFLOW_CRM_SITE_KEY` and per-form `SCHOOLFLOW_CRM_FORM_KEYS` remain supported for separate school websites/forms. A group form is explicitly opted in through `SCHOOLFLOW_CRM_GROUP_FORM_IDS` and can coexist with independently keyed forms.

### Validation and deployment

Tests: `php tests/test_group_routing.php`, `php tests/test_cf7_group.php`, `node tests/check_group_routing.js`, the existing CRM tests, and the DigiOps packaging/CSP checks. There are no new dependencies, migrations, or privileged browser credentials. Deploy the CRM through DigiOps, then **update the WordPress connector separately** on each applicable group WordPress site. Ensure the WordPress server can call the CRM over HTTPS and that `/scrm/api/*` is not cached or stripped of Authorization headers. Run a real test enquiry for every campus after deployment.


## Release #13: audit, immutable GUIDs, complete demo and official messaging APIs

### IDs and backwards compatibility

All persisted records now receive an immutable RFC 4122-style UUID v4 (`guid`), including legacy records on the first write under the same exclusive JSON lock. **Numeric `id` fields and foreign keys are deliberately retained** to preserve deployed WordPress connectors, historic audit records, groups, school relationships and existing browser actions. School-scoped API routes accept a school UUID, and enquiry GET/PATCH/audit/call routes additionally accept the enquiry UUID; new enquiries expose `guid` immediately. Do not treat identifiers as authorization: tenant membership and assigned counsellor checks are still enforced. A later version can migrate other external URL paths to UUIDs once existing integrations are known. Never regenerate GUIDs during demo re-seeding, backup restoration or deployment.

### Full demo regeneration

On each school's Dashboard, **Clear demo data** removes only explicitly `is_demo` enquiries, sample web sources, their calls, scheduled visits, simulated communication previews and two demo email templates. The append-only audit history remains. **Add demo data** regenerates 18 fictional pupils with all seven admissions stages, follow-up dates, three disabled sites, linked sibling/family profiles, sample call outcomes, campus visits, conversation history, example email templates, and `demo_preview` messaging entries. No demo contact is ever sent to an external provider, and real parents/users/schools are not cleared. Demo phone numbers and `.invalid` emails are deliberately non-routable. Clear must be explicitly confirmed with `CLEAR DEMO`. You can repeat the clear-and-add cycle.

### Official email API (Resend)

Each school admin can open **Communications** and choose *Resend*, provide a domain-verified `from` email address and its Resend sending API key, then save. Staff with visibility of a consented real enquiry may use **Send email via API** from the enquiry drawer with an existing school email template (or default text). The email is sent by the PHP backend using `POST https://api.resend.com/emails`, never from the browser, with a unique idempotency key. Resend requires a verified sender domain and API key. See <https://resend.com/docs/api-reference/emails/send-email> and <https://resend.com/docs/dashboard/domains/introduction>. A successful API response means **accepted**, not delivered, and is logged with provider ID. No automated background email sending is enabled.

### Official WhatsApp Cloud API (Meta)

Each school admin can choose *Meta Cloud API*, enter the WhatsApp Business **phone number ID**, permanent/long-lived system-user access token, and an **approved no-variable WhatsApp template** name and language (for example `hello_world`, `en_US`). Staff can explicitly select **Send WhatsApp template** for a consented real parent with an international-format telephone number. The backend sends a template message using `POST https://graph.facebook.com/v24.0/{phone-number-id}/messages`. Note that templates with required header/body/button variables are not supported by this version; use approved templates without variables. A Meta accepted response is not a delivered/read receipt. See the official Meta collection <https://www.postman.com/meta/whatsapp-business-platform/documentation/wlk6lh4/whatsapp-cloud-api> and verify your Meta Business permissions, account setup and messaging policy. Messages may incur Meta/provider charges.

### Credential handling / failure boundaries

Provider access tokens are encrypted with OpenSSL AES-256-GCM before storage in private JSON. An independently generated 32-byte key is stored at **`private_html/scrm/data/messaging.key`** (0600). Treat both this key and `store.json` as sensitive and **back up both together**. Restoring only `store.json` cannot decrypt provider credentials. Secrets are never returned to API consumers, placed in the public release, or copied into the CRM audit log. This is encryption at rest, not a replacement for Cloudways access control and secure backups.

Enquiry send actions enforce staff role/school/lead visibility, parent consent, non-demo destinations, input and daily per-user send limits, and an idempotency UUID to prevent double clicks. The server records *pending → accepted/failed* and provider reference; provider timeouts or failures should be reconciled with the provider dashboard before retrying using a new request ID. The installed CRM does **not** yet process provider webhook delivery/read updates, scheduled cron reminders, inbound WhatsApp replies or arbitrary custom API endpoints; adding a custom adapter requires a vetted server-side endpoint allowlist and signed authentication (never a caller-supplied URL). Manual `wa.me` and `mailto:` drafts continue to work when API providers are unconfigured.

### Checks

Run `php tests/test_release_audit.php` to verify legacy GUID backfill, immutable identifiers, cross-school permissions, encrypted provider keys, simulated provider dispatch, retry protection, consent enforcement, demo-only prevention and complete demo cleanup. The existing PHP, WordPress mapping, JS render, HTTP JSON, CSP and responsive layout checks remain in the CI workflow. DigiOps still publishes **only** the five public runtime files, never credentials or school records. Connectivity requires Cloudways outbound HTTPS access and PHP OpenSSL/cURL extensions.


## Native public popup: single head script (no Google Apps Script / WordPress plugin)

This is a **new public SchoolFlow intake method**. The existing authenticated
server-to-server WordPress connectors stay available for legacy installations.
The native embed requires only a script tag in the public school's site header.

1. In SchoolFlow → **Schools & Routing → Group websites**, create or select the
   correct group website, enter its **HTTPS website domain** (for example
   `lexiconedu.in`), and select the allowed campuses. The administrator must
   still be an active admin of each destination school.
2. Use **Copy head script** on that group card and paste it inside the website
   HTML `<head>`. The displayed `data-schoolflow-group` is a **public UUID**,
   not a private API key. Do **not** place `sfgroup_...` or `sfsite_...`
   secrets in HTML or JavaScript.
3. Remove any prior HFCM/Google Apps Script enquiry snippet or WordPress plugin
   instance responsible for the **same** popup to prevent double submissions.
4. On the website, verify the side tab, logo, 10-second auto-popup, the
   **Solve: A + B** CAPTCHA, six-digit PIN lookup, campus choices, consent and
   thank-you redirect. Confirm one test enquiry reaches **only the selected
   campus**, with expected source, assignment and location metadata.
5. Do not enable real parent traffic until browser CORS, SSL, anti-spam, backups
   and consent language have been validated in staging.

Sample (the UUID is an example **not a working configured group**):

```html
<script defer
  src="https://stage.digiti.in/scrm/static/lexicon-form.js?v=1"
  data-schoolflow-group="00000000-0000-4000-8000-000000000000">
</script>
```

Optional `data-thank-you` and `data-logo` attributes override the default
Lexicon acknowledgement page and logo. The loader fetches its companion CSS
from SchoolFlow automatically; the website needs only that one script tag.

### Security and API

- Browser: `GET /scrm/api/public/forms/{group_uuid}/config` returns **only**
  allowed campus names/codes; `GET /challenge` issues a short-lived
  HMAC-signed math question; `GET /pin/{six_digits}` looks up the India Post
  postal directory via fixed HTTPS on the SchoolFlow server and caches results.
- Browser: `POST /scrm/api/public/forms/{group_uuid}/enquiries` records the
  validated enquiry and returns a lead UUID. The endpoint checks HTTPS
  website Origin against the configured group domain (apex/www), active campus
  mapping, CAPTCHA, duplicate submission ID, 10-digit mobile number, parent
  consent, honeypot and per-origin/IP/group rate limits. These checks are
  server-side and must **not** be bypassed by embedding private keys.
- A public form identifier is deliberately *not* authentication. Origin can be
  forged by non-browser clients; HMAC CAPTCHA is elementary anti-spam, not a
  substitute for WAF / managed bot protection, rate monitoring, and operational
  review. Consider a stronger challenge for high-traffic or abused sites.
- PIN lookup has a manual State/City fallback if the directory is temporarily
  unavailable. External lookup availability requires outbound HTTPS and cURL.
  The directory receives the PIN only; **parent/student details are submitted
  directly to SchoolFlow**, not to an intermediary service.
- New enquiry fields (`pincode`, `state`, `city`, `utm_medium`, `utm_term`,
  `utm_content`, `landing_page`, `referrer`) persist with the school-scoped
  JSON lead. Existing school IDs, UUIDs and records are preserved.
- A 32-byte HMAC key is created at `private_html/scrm/data/public-form.key`.
  Store it outside webroot, with owner-only permissions. Do not deploy the key
  into the public artifact. Rotating or losing it only invalidates outstanding
  10-minute form challenges.
- Same-origin or other domains cannot use the endpoint unless specifically
  listed in the group connection. If the WordPress site's hostname changes,
  update the group website domain in SchoolFlow before changing the embed.

Tests: `php tests/test_public_forms.php`, `node --check
public_html/static/lexicon-form.js`, plus all previous regression and DigiOps
packaging tests. The GitHub PR workflow validates this feature without
publishing deployable artifacts. A successful GitHub build alone does *not*
deploy anything: DigiOps deployment is still a separate operator action.
