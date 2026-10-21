# Public Admissions AI Chatbot — Phase 1

## Scope
The public chatbot is the first AI integration priority for SchoolFlow. The internal CRM Copilot is deferred.

The chatbot will be embedded on administrator-approved school or group websites. Parents can select a campus, ask questions based on administrator-published school information, request a callback, or submit an admission enquiry.

## Existing infrastructure to reuse
- Group website GUID and school/campus routing from `app_private/public_forms.php`.
- Origin allowlisting for HTTPS website domains.
- HMAC-signed CAPTCHA, honeypot, consent, rate limiting and idempotency.
- `sf_create_lead` and first-contact task scheduling.
- School-level permissions and audit events.
- DigiOps bundled PHP release and fingerprinted static assets.

## Implementation slices
1. **Backend knowledge and settings:** school-scoped overview, FAQs, enable switch, admin-only edit API; disabled by default.
2. **Public chat endpoint:** allowlisted origin and campus, anonymous rate limits, only published knowledge in context, server-side AI key, bounded output, provider-failure fallback. No CRM/guardian data in model requests; no browser access to credentials.
3. **Admission widget:** separate `admissions-chat.js` and CSS with campus selector, accessible mobile chat interface, form and callback actions.
4. **Lead capture:** full enquiry uses existing `/api/public/forms/{group_guid}/enquiries`; callback uses the same consent and challenge safeguards, scoped to the validated campus; first-contact task created.
5. **CRM management UI:** school admin edits verified answers and enables publication, copies embeddable script snippet.
6. **Tests:** cross-campus isolation, origin rejection, permissions, prompt injection, provider failure, consent rejection, CAPTCHA replay, idempotency, duplicate submissions, PHP/JS syntax and DigiOps bundling.

## Safety and operational requirements
- Never invent admission fees, seat availability, dates, or eligibility; escalate unknown answers.
- Never send student/parent PII to an AI provider; require secure form submission for contact details.
- Explicit guardian contact consent is mandatory; consent for an enquiry does not imply WhatsApp/email campaign opt-in.
- Do not perform network AI requests while holding the JSON store file lock.
- Fixed HTTPS provider endpoint, TLS verification, bounded timeout and response size, API key stored outside public HTML and Git.
- Browser chatbot must never expose website API keys or invoke staff-only routes.
- Redact messages from audit logs. No transcript persistence by default; define retention before adding it.
- Rate limits and a per-school kill switch are mandatory before publication.
- Existing CRM behaviour, domain routing and DigiOps deployment must remain backward compatible.

## Acceptance
- School A parent cannot retrieve School B knowledge or create School B enquiries from School A website.
- Disabled/unconfigured schools cannot publish chatbot content.
- A callback or enquiry produces a consented, school-scoped CRM lead, not an automated external message.
- Provider failure causes a safe knowledge-based fallback or human-handoff answer.
- Test rollout uses synthetic records and a staging website, not production data.
