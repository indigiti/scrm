# SchoolFlow Enquiry Interface — school admin controls

## School administration
Go to **Administration → Enquiry Interface** (Admin only), then choose:
- **Communication** — enable or disable the phone dial, WhatsApp draft, email draft, provider WhatsApp send, and provider email send buttons independently.
- **Drawer tabs** — enable or disable the Follow-up, Admission, Family, History and More tabs.
- **Settings** — choose the default tab, remember the selected tab across enquiries, show an Admin-only settings shortcut and preserve the existing seven section-visible/expanded controls.

All controls are school-scoped. The settings endpoint retains backward compatibility with the earlier `sections`-only JSON. An unset setting defaults to the previous functionality (enabled); previously saved flat section settings are migrated transparently when read. No database migration required.

## Staff enquiry workspace
- Persistent header: enquiry, next action, guardian, phone and received time (displayed in IST).
- **Follow-up**: enabled communication actions, call outcome and next follow-up, recent calls and campus visits.
- **Admission**: admission stage/assignment, application milestone and document checklist.
- **Family**: parent/sibling details and contact-consent evidence.
- **History**: dated calls, visits, admission stage changes and conversation notes.
- **More**: attribution and lazy-loaded audit log (visibility follows original section settings).
- Switching tabs changes only which panel is visible, preserving unsaved draft fields. Save actions keep the active tab. The original scroll-link "tabs" are removed.
- Switching schools clears the prior selected tab. A disabled default tab falls back to the first enabled tab.
- At least one tab must remain enabled. Server validates every field and rejects unknown settings or unauthorized changes.

## Security boundaries
Disabling a communication button also disables the corresponding server-side contact action or provider send. WhatsApp and email still require guardian consent, provider connection and real lead permissions, even when shown. A disabled tab hides interface sections only; it never deletes records or relaxes API role checks. Counsellors remain limited to their own assigned leads within their assigned school. Audit recording continues even when audit section is hidden or collapsed.

## Acceptance
1. Run PHP lint and `php tests/test_enquiry_interface_controls.php` plus full DigiOps suite.
2. Confirm `node tests/check_frontend_v2.js`, all JS UI tests and bundled release packaging.
3. Verify toggles and tab defaults in two schools; Admin-only Settings shortcut.
4. Confirm all five tabs and each form work on a 720px desktop drawer and full-screen mobile drawer.
5. Disable WhatsApp and Email API sends; confirm direct API requests are rejected with 403, no provider traffic.
6. Record a call after choosing an outcome; verify call, follow-up and chronology. Save while in Admission and verify the tab does not reset.
7. Confirm stage updates, sibling creation, communication consent and lazy audit access still function.
8. Deploy only from a successful `main` DigiOps artifact; GitHub merge is not staging deployment.
