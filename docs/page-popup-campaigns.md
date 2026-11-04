# SchoolFlow — school website popups (canonical setup)

## Domain model and product behavior

- **Connected school website**: one school-scoped website record, GUID and private server API key. Creating a new public popup form uses the registered website as its source, without creating another website record.
- **School popup form**: an independent GUID-based public form associated with a connected school website. Its school routing is fixed to that website's school. It is intentionally **popup-only** by default.
- **Website popup campaign**: GUID with name, enable/disable, manual/auto trigger, placement, optional page prefixes, schedule and eligible campuses. It may show across the registered website without an LP approval.
- **Legacy shared group popup**: existing form still supported for multiple campuses and its originally registered primary website; it is not a prerequisite for creating a new school-specific popup.
- **Inline landing page**: a separate placement with a known URL, approved external-origin rules and an approved thank-you page. **Only inline form submissions may redirect.** All popup successes are confirmed in-place.
- **External domains**: must be authorized before their form scripts are permitted. URL registration is an authorization control, **not verified proof of ownership**. Only approved administrators may register websites.

Do not treat every website page as a new landing-page application. The registered school's website needs no separate LP approval to create a popup. Optional page targeting narrows where it renders; it is not a new domain registration.

## Configure a school popup

1. Select the correct school in the sidebar.
2. Go to **Websites & Forms**, ensure the intended website is connected and enabled. Or go to **Popup Campaigns → Create school website popup** and choose an existing connected website.
3. The CRM provisions or reuses the school-specific public form automatically. No LP approval is requested.
4. Enter a popup name. Select **Entire registered school website** (default), or **One exact page (advanced)**. Optional include/exclude path prefixes are available for website-wide campaigns.
5. Configure schedule, trigger, mobile and frequency. Only the selected school is checked by default. Additional campuses may be selected deliberately for eligible legacy shared-group forms.
6. Save, copy the campaign-specific detailed-popup embed script and install it on the chosen school website. Keep the original Lexicon popup HTML and CSS. Enable the campaign after verifying its behavior.

Each popup campaign is uniquely identified by its GUID and can be individually edited or removed. Old scripts for removed or disabled campaigns stop displaying after their current configuration request. Deleting a campaign does **not** delete admissions enquiries or the connected school website.

## Inline LPs and thank-you pages

Under **Landing Pages**, manage approved external URLs and conversion settings for inline admissions forms. A successful short inline submission may redirect to its own approved same-origin thank-you URL. Short or detailed popup submissions always show confirmation in the popup; the admin settings UI does not offer an ineffective popup redirect switch.

Retain the established `/lp/thank-you/` flow, math CAPTCHA, optional WhatsApp OTP, campus routing and attribution. Do not install both a legacy group-wide popup embed and a new page-specific campaign embed on the same page: this can cause a duplicate or conflicting popup presentation.

## Security and lifecycle boundaries

- The selected school determines administrative visibility. Admin rights are checked server-side; staff of a different school cannot provision forms.
- Multi-campus legacy form configuration requires the proper administrator permissions for all connected schools.
- Public popup configuration carries no secret API key.
- Public submissions are checked for active form, registered origin, campaign GUID, current campaign enabled state, optional path and selected school; the OTP request uses the same policy.
- Page URL metadata is supplied by the browser: origin is enforced through CORS and request checks, while fine-grained page claims cannot serve as an independently authenticated security boundary. Treat path targeting primarily as a publication and UX rule.
- Removing a public popup form preserves its linked website connector and its historical enquiries. Remove that form before removing or reassigning a linked website.
- Existing legacy group forms and original inline LP scripts remain supported. A versioned and successful GitHub release is **not** a confirmed Cloudways deployment.

## API and release checks

- `POST /api/schools/{schoolId}/sites/{siteId}/public-form` — idempotent school website form provisioning.
- `GET/POST /api/group-sites/{groupId}/page-popups` — legacy route also handles website popup scopes.
- `PATCH/DELETE /api/group-sites/{groupId}/page-popups/{campaignGuid}` — edit/remove a specific campaign.
- `GET /api/public/forms/{formGuid}/config` — only enabled campaigns are advertised.
- Recommended public school popup script: `/scrm/embed/{formGuid}` with `data-schoolflow-popup-campaign="{campaignGuid}"`. This renders the original approved Lexicon detailed form and its existing stylesheet. Explicit legacy compact short popups remain supported with `data-schoolflow-form="short" data-schoolflow-mode="popup"`.

Regression gates: `php tests/test_school_website_popup.php`, `php tests/test_page_popup_campaigns.php`, `php tests/test_form_lifecycle.php`, `node tests/check_group_routing.js`, and `node tests/check_short_popup_campaign.js`. The DigiOps release workflow runs these and the broader PHP/JS suite. Verify real Cloudways lead receipt and the website popup in a browser **after** deploying.
