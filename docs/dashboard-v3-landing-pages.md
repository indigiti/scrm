# Dashboard v3 — navigation and landing-page approval release

## Navigation
- Keep existing pages; reorganize into Admissions, Lead Capture, Engagement, Insights, and Administration.
- Pin Dashboard and My Work; provide expandable, keyboard-operable category toggles with aria-expanded.
- Add directly discoverable **Landing Pages** and **Popup Campaigns** pages under Lead Capture.
- Keep role-based visibility; server authorization remains authoritative.
- Collapse embed scripts and destructive operations under explicit disclosure controls.
- Responsive sidebar, accessible focus indicators, improved visual hierarchy and consistent admin cards.

## Landing-page approvals
- Group administrators approve up to 25 additional complete HTTPS URLs per public form, on additional domains if necessary.
- Approvals are exact host + pathname. Tracking query parameters do not require separate approval. No blanket subdomain or wildcard approval.
- Primary registered group domain retains its existing all-pages access unless **Restrict primary website too** is enabled, which requires an explicit primary page approval.
- Approvals are server-side; public group configuration publishes a public allowlist for browser display checks.
- Short and detailed public lead submissions validate the landing page against the originating website and the current approved list. Revocation is immediate; existing leads are retained.
- All permissions are checked against current connected school administrator memberships.
- Public GUIDs remain public identifiers, not private API keys; private source keys remain server-only.

## Release checklist
1. CI PHP lint, PHP regressions (including tests/test_landing_page_approvals.php), browser-level JS checks and DigiOps bundled gateway pass.
2. Log in as a group owner and a staff member: ensure admin-only pages and APIs are inaccessible to staff.
3. Confirm all 21 navigation destinations still work on desktop and mobile (especially keyboard toggles and mobile drawer).
4. Test original domain form with unchanged scripts, including the detailed legacy form, inline form and compact popup.
5. Approve a different domain's /admissions page, verify its public forms and student/campus routing; reject /other on that domain.
6. Test UTM parameters, exact page match, domain revocation, strict-primary mode and accessible error feedback.
7. Verify admin form and popup management settings persist after reload, with no private credentials in the embed.
8. Use the successful main-branch DigiOps artifact for staging deployment and test live in Chrome, Safari and mobile browsers.

**Limitations:** A browser's Origin header authenticates the website origin, not its specific pathname. The page URL supplied by client JavaScript is a normal browser integrity check and a recorded attribution hint, not proof of a site's ownership or a cryptographic per-page authorization token. For adversarial cross-path isolation on the same host, use separate subdomains/group GUIDs or a site-controlled signed server integration.
