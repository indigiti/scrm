# School-scoped group forms and landing-page popup campaigns

## Why these controls exist

A SchoolFlow account can administer several schools. **Group forms, landing-page approvals, popup campaigns and group embed codes must follow the active school**, rather than the logged-in owner's entire group portfolio. A group connected to multiple campuses legitimately appears in each connected campus's workspace, because it is shared by those campuses.

Staff must have an enabled **Admin** membership for the selected school to view its group form administration. The server now supports `GET /api/group-sites?school_id=<school-number>` and validates that authorization before returning the owner's forms connected to that school. Other schools' forms are never included in the scoped response. The dashboard clears old group data on switching campus and ignores late responses from the previous selection.

## Add a page popup

1. Select the desired school in the SchoolFlow sidebar.
2. Open **Lead Capture → Landing Pages → Add popup to LP** or **Popup Campaigns → Add landing-page popup**.
3. Enter an internal campaign name and the exact complete **HTTPS landing-page URL**. For additional domains, first approve that URL in **Landing Pages**.
4. Configure the trigger, delay, repeat cadence, position, mobile/exit settings and optional allowed campuses.
5. Save, then use **Copy code** on the newly created popup campaign. Paste the generated, GUID-based short popup script into that exact page.
6. Enable the campaign after installing and testing its script.

Each campaign has a distinct GUID and its own browser display frequency. It will not render outside its exact configured origin and path, even if the same script is copied into another page. The server rejects duplicate popup campaigns for the same group + URL, offsite/unapproved LP URLs, thank-you page destinations, and school routes outside the connected group. Removing a landing-page approval immediately makes its page popup inaccessible, without deleting saved enquiries.

**Important:** The original group-wide popup remains for existing embeds. If both group-wide and page-specific scripts are installed on the same landing page, remove or disable the group-wide embed there to avoid competing enquiry widgets. Creating a page-specific campaign does **not** insert a script into an external website automatically.

## API / storage

- `GET/POST /api/group-sites/{groupId}/page-popups`
- `PATCH/DELETE /api/group-sites/{groupId}/page-popups/{campaignGuid}`
- `GET /api/public/forms/{groupFormGuid}/config` returns only **enabled, still-approved** page popup configurations; no API keys.
- The existing `/scrm/embed/{groupFormGuid}` loader forwards `data-schoolflow-popup-campaign` to the short popup browser module.
- Existing global `popup_settings`, math CAPTCHA, optional WhatsApp OTP, inline form redirects and popup in-place confirmations are unaffected.

## Verification

Run `php tests/test_page_popup_campaigns.php` and `node tests/check_short_popup_campaign.js`, plus the DigiOps release suite. Browser checks cover matching page, wrong page and removed/disabled campaign; PHP checks cover active-school isolation, authorization, independent campaign CRUD and approval revocation.

Cloudways deployment and testing of a real published landing page must be verified separately from GitHub Actions.
