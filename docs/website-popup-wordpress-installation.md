# Install a school website popup with WordPress HFCM

## Correct embed code

From SchoolFlow → Popup Campaigns, choose the correct school, open its popup, and click **Copy popup script**.
The recommended snippet has `data-schoolflow-form="short"`, `data-schoolflow-mode="popup"` and `data-schoolflow-popup-campaign="CAMPAIGN_GUID"` on its external `/scrm/embed/FORM_GUID` script tag. Use the exact generated GUIDs; the form and campaign identifiers are different.

In HFCM choose HTML → Site Wide → Header → All Devices → Active and save. Use one popup script per website placement. No shortcode is required for a site-wide HFCM header. Never put a private website or group API key into public HTML.

## Previously installed plain script

Existing connected school-site forms that use only the plain `/scrm/embed/FORM_GUID` tag automatically resolve **one** enabled popup campaign matching the website origin and current page, and keep the yellow right-side Enquire Now rail. If no campaign qualifies or multiple campaigns overlap, no button is displayed; copy a campaign-specific script instead.

Legacy shared group forms retain their historic plain-script detailed-form behavior.

## If Enquire Now does not open

1. Deploy the DigiOps artifact for this fix to Cloudways. A GitHub merge does not update the live CRM.
2. Confirm the school form and popup campaign are enabled. An explicit campaign URL, path prefixes, and campus restriction must permit the current page.
3. Check only one SchoolFlow popup snippet is installed in HFCM. Remove obsolete duplicate snippets.
4. In Developer Tools → Network, confirm the external `/scrm/embed/...` script, the versioned short-form JS, and `/scrm/api/public/forms/{FORM_GUID}/config` return success.
5. Confirm the live site's HTTPS origin matches the school's registered website domain.
6. Submit one accepted test enquiry. The popup must confirm success without navigating away; inline LP forms alone can redirect to an approved thank-you page.

The installed HFCM snippet is not edited by this repository fix. The server bootstrap and client assets change only after Cloudways deployment.