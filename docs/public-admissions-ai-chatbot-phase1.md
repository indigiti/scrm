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

## Offline-first knowledge release (21 October 2026)
Implemented on the feature branch; do not treat a successful build as deployment.

### How the fallback works
1. Query the selected campus's administrator-approved FAQs first, using conservative matching.
2. A verified match is returned without an external AI request.
3. An unmatched, nonpersonal question can be added to a **private, school-scoped review queue** (topic deduplication and request count). Parent chat transcripts and AI-generated responses are not automatically reused as facts.
4. An administrator can review the topic in **Admissions AI**, write the correct answer and approve it. The approved answer becomes a published FAQ and is available without AI.
5. If no verified answer is available, external AI is attempted **only if the school's administrator has opted in** and a server-side `SCHOOLFLOW_OPENAI_API_KEY` is configured. Otherwise the assistant offers a callback or enquiry.

### School administrator
1. Open SchoolFlow, select a campus, then **Admissions AI** (Admin only).
2. Provide a verified overview and question/answer pairs. Enable the chatbot separately for each school. **External AI is disabled by default**.
3. Under **Unanswered question review**, approve a de-identified topic with a verified answer or reject it.
4. Under **Website chatbot embed**, copy the snippet for an approved group website. Each group/campus route is validated on the CRM server.

### Embed
For each authorized group website, the UI displays the script including its published group GUID:

```html
<script defer src="https://YOUR_CRM_DOMAIN/scrm/static/admissions-chat.js" data-schoolflow-group="GROUP_GUID"></script>
```

The public GUID is **not** a privileged API key. Do not embed any secret website tokens.

### Public lead capture
The chat widget supports campus selection, FAQ interaction and a lower-friction callback request with parent name, mobile, optional student/class details, explicit consent and server-signed CAPTCHA. A successful callback creates a campus-scoped CRM enquiry and first-contact task. A full enquiry uses the existing registered website lead form where present.

### Privacy and operations
- Enquiry/chat pages send `Cache-Control: no-store`. Public origins must be HTTPS and match an approved group website.
- New answers are published **only by school administrators**; the chat visitor cannot publish or edit them.
- No complete chat transcripts are retained by this feature. Short, filtered unanswered question candidates can enter private review; heuristic personal-data detection cannot guarantee all sensitive text is removed. Review data handling and notices before production.
- The external AI provider is off by default for every school. Only the school-approved overview/FAQs and screened question are sent when enabled; no CRM leads, phone numbers or account tokens are intentionally supplied.
- Existing `store.json` is migrated lazily without destructive changes. Back up protected storage as part of staging rollout.
- Outages of **OpenAI** do not prevent approved FAQ answers or CRM callback submissions. Outages of the **CRM API** prevent new callbacks and current server-side FAQ lookups; no separate offline browser store has been implemented.
- PHP 8.2+ and cURL are sufficient; no Python, Redis, vector DB or MariaDB is required. Current keyword matching intentionally avoids speculative semantic answers.
- Keep the main branch, Cloudways deployment and any parent data unchanged until staging verification.
