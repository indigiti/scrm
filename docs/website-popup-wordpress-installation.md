# WordPress SchoolFlow popup — approved detailed design

The current Lexicon popup layout is the **approved design**. School-specific campaigns use the existing `lexicon-form.js` markup and `lexicon-form.css`. Do not replace the popup with a compact widget or redesign its logo, layout, fields, typography, colours, spacing, or mobile presentation when changing the form backend.

## Correct school website campaign embed

From **Popup Campaigns → Edit website popup → Copy popup script**, paste the exact generated tag into WordPress Header Footer Code Manager:

```html
<script defer src="https://stage.digiti.in/scrm/embed/FORM_GUID"
  data-schoolflow-popup-campaign="CAMPAIGN_GUID"></script>
```

Both GUIDs are public references, not API keys. Copy the real values from the CRM rather than these placeholders. Choose **HTML, Site Wide, Header, All Devices, Active** in HFCM. Disable older overlapping SchoolFlow snippets.

For the original plain WordPress embed, `<script defer src="https://stage.digiti.in/scrm/embed/FORM_GUID"></script>`, the server can automatically identify **exactly one enabled campaign matching the website and current page**. It now renders the same detailed popup and familiar yellow **ENQUIRE NOW** side rail. When zero or multiple campaigns qualify it does not show an inactive rail; use the campaign-specific embed instead.

Legacy multi-campus group forms continue to support their original detailed script. Existing explicitly configured **compact short popup** embeds remain supported for integrations that intentionally chose that variant.

## Display behaviour

- **School popup:** Original detailed form; manual yellow side rail; optional campaign-based timing, scroll and exit-intent triggers; confirmation in the popup.
- **Inline landing-page form:** Separate short inline form; may redirect to its own approved thank-you destination. This must not affect a school popup.
- **WhatsApp OTP:** The original detailed layout stays identical when OTP is disabled. If required by the admin, a small matching verification section appears; submissions enforce the verified proof on the server.
- **School routing and security:** Registered domain, campaign GUID, selected campus, enabled state, optional page restrictions and CAPTCHA are checked on the server before accepting leads.

## If ENQUIRE NOW does not open

First deploy the release to the live Cloudways sCRM app. Confirm the intended campaign is enabled and matches the website. Open the browser Network tab and verify `/scrm/embed/{FORM_GUID}`, `/scrm/static/lexicon-form.js`, and `/scrm/api/public/forms/{FORM_GUID}/config` load successfully. Remove duplicate HFCM snippets and clear website caches. Finally test an actual lead and verify the correct school received it.

A GitHub release does **not** automatically update WordPress or Cloudways. Site-wide HFCM script changes require an authorized WordPress administrator, unless the existing plain script is reused.
