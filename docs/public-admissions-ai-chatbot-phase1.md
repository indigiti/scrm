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

### Single-script website integration
**No new script is required on an existing Lexicon school website.** The currently installed \`lexicon-form.js\` automatically checks the approved group's public chatbot settings and loads \`admissions-chat.js\` if at least one associated campus has enabled the chatbot.

The same existing embed continues to work for both the original admission enquiry form and the chatbot:

```html
<script defer src="https://YOUR_CRM_DOMAIN/scrm/static/lexicon-form.js?v=1" data-schoolflow-group="GROUP_GUID"></script>
```

The CRM admin **Admissions AI** screen now copies this same one-tag embed, not a second chatbot-specific script. Previously installed tags do not need replacement. If a standalone chatbot tag also exists on a website, the runtime avoids duplicate chat panels.

- The chatbot is **off by default**, per school. School administrators enable it and publish approved school information through **Admissions AI**.
- It checks the allowlisted origin via \`GET /api/public/forms/{group_guid}/chat-config\`, without requiring secret website tokens in the browser.
- If no school in that group has enabled the chatbot, or if the configuration API fails, the original enquiry form continues working without an extra chatbot UI.
- Optional one-tag opt-out: add \`data-admissions-chat="off"\` to the existing script when a specific school website must show the enquiry form alone.
- The public GUID is **not** a privileged API key.

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

## GUID-based website script and dashboard refresh (October 2026)

Website embedding now uses a **public form ID in the script URL** rather than exposing the generic `lexicon-form.js` filename:

```html
<script defer src="https://YOUR_CRM_DOMAIN/scrm/embed/GROUP_FORM_GUID.js"></script>
```

**SchoolFlow → Schools & Routing → Group websites** displays the exact script and a **Copy head script** button. **Admissions AI** displays and copies the same URL.

The script is generated by PHP with `Cache-Control: no-store`. It loads both the existing admission form and, if enabled, the admissions chatbot. JavaScript and CSS assets are fingerprinted using hashes of the deployed files, so updates do not reuse stale `?v=1` assets. The public group GUID is not a secret.

**Refresh Form ID** changes the group's public GUID. This immediately rejects the old script URL and old public form API GUID. The dashboard explicitly warns that the embed must be updated on the website. All existing enquiries, campus associations and private integration keys are preserved. Only the school group owner can refresh the ID. Refreshing the **assets** after deployment does not require refreshing the ID or changing a website tag.

Existing integrations using `/static/lexicon-form.js?v=1` with `data-schoolflow-group` continue to work for backwards compatibility. Move existing sites to the new GUID script once to eliminate manually maintained `?v=1` URLs. Group-site and website origin checks are unchanged.


## Guided pre-chat enquiry and OpenRouter (October 2026)

### Parent flow
1. Parent opens the existing GUID-based SchoolFlow assistant and selects a campus.
2. Before any Q&A, the assistant collects **parent/guardian name, phone, student name, intended class**, with optional email and city, followed by consent and the signed mathematics challenge.
3. `POST /api/public/forms/{GUID}/chat-start` validates the school website origin, required data, rate limits, CAPTCHA reuse and idempotency. Only then does it create a school-scoped CRM lead and First Contact task and issue a one-hour signed browser chat session. No parent data is sent to an AI provider.
4. The parent receives the campus's first eight approved FAQ questions as quick-question chips and can ask follow-up admission questions. Every chat query requires the signed session token; tokens cannot be forged or reused for a different school/site.
5. Parent can request an admissions callback during the chat, updating the *same CRM lead* instead of creating a second enquiry.
6. Enquiry history and protected parent data stay in private CRM storage. Raw chat transcripts are not saved by this feature. Screened unanswered generic topics still enter administrator review.

### Admin: published Q&A and OpenRouter
- Open **SchoolFlow → Admissions AI** for a school. **Add 8 starter Q&As** inserts safe, generic admission handoff prompts into the editor. Review and explicitly save them to publish; no admission fees, dates or vacancies are invented.
- School staff can add/edit school-specific verified Q&As, review unanswered topics and configure external AI opt-in.
- The new **OpenRouter AI provider** panel lets an authorized school admin enter an OpenRouter API key and a model identifier (e.g. `openai/gpt-4o-mini`). The key is AES-256-GCM encrypted using the private SchoolFlow key file; neither the original key nor ciphertext is included in admin API responses or browser HTML. The dashboard shows only **connected / not connected** and model name. Leaving the key blank retains it; **Disconnect** clears it and disables external AI for that school.
- OpenRouter is optional, and the existing school-level **Allow external AI** switch remains off by default. Verified local FAQs work regardless of provider availability. If the provider fails, the chatbot offers human admissions follow-up.

### Integration
- OpenRouter API: fixed HTTPS `https://openrouter.ai/api/v1/chat/completions`, Bearer authorization, non-streamed response. No user-defined outbound URL or model-supplied tools.
- PHP network requests execute *outside* the locked JSON transaction.
- The model receives only a screened general question and published school overview/FAQs, **never** the pre-chat enquiry data or signed session. Screening is heuristic and must not be treated as a guarantee; schools should disclose optional AI processing in their privacy notice.
- Protect and back up `private_html/scrm/data/messaging.key` alongside `store.json`; restoring the JSON without its key prevents the stored OpenRouter token from being decrypted.
- New endpoints: `/chat-start`, `/chat-followup`, `/chat` (now requires session), and authenticated `/api/schools/{id}/public-chat/provider`. Old `/callback` remains for backwards compatibility. Existing embedded form script and GUID bootstrap remain unchanged.

## October 2026 cache regression and fix

A read-only check of `https://lexiconedu.in/demo/` confirmed that the site already embeds its group using `/scrm/embed/<GUID>.js`, but the public server returns `Cache-Control: max-age=1440` for that dynamic `.js` response and up to one-year caching for static JS. The stale bootstrap referenced a previous, 9,729-byte chatbot even though the new 9,007-byte guided-chat JS was deployed. Browser reload alone does not reliably replace the cached bootstrap.

**New recommended URL:** `/scrm/embed/<GUID>` (no file extension). This deliberately routes through the dynamic PHP gateway, avoiding hosting/CDN rules that classify `*.js` as a static resource. The response is JavaScript with `no-store`/CDN no-store headers and content-hash asset versions; the one-tag embed still runs normally. The old `.js` route and old `lexicon-form.js` integrations remain compatible, but may remain stale until CDN cache expires or is purged.

SchoolFlow admin's **Copy head script** now provides the extensionless form URL. Existing sites already using the previous GUID `.js` URL should replace that one tag with the new extensionless URL, keeping the exact same GUID. **Do not rotate the Form ID** to fix cache; ID rotation intentionally invalidates the old embed and requires website edits.

**Infrastructure caveat:** The application can request no-store, but Cloudways or Cloudflare rules may override origin headers. After deploying, verify the extensionless URL has no long-lived public cache header and that it retrieves current JS hashes. Where CDN rules override the origin, bypass caching for `/scrm/embed/*` and `/scrm/api/public/forms/*`. Purge the cached legacy `/scrm/embed/<GUID>.js` URL as needed.

## Conversational admissions intake UI

The widget now collects enquiry information **entirely within chat bubbles**, not as a visible multi-input registration form. On opening, the assistant selects an approved campus through quick-reply chips, then asks the parent/guardian name, mobile, student name, intended grade, optional email and city (both with Skip buttons), consent, and finally the signed math verification. A progress label shows the active step.

A parent must explicitly accept consent and complete CAPTCHA before the `/chat-start` call creates the CRM lead. Until the backend returns the signed session token, regular FAQ/AI requests cannot be made. Parent contact details are kept only in the browser's temporary in-memory draft until submission and are cleared on successful lead registration. Phone and email are masked in the visible chat transcript. The chatbot does not store full chat transcripts or send parent personal details to OpenRouter.

After registration, the same chat pane offers eight school-approved quick questions, free-form questions, and an optional callback action linked to the existing lead. School admins continue to manage approved Q&As, OpenRouter and consent settings in **Admissions AI**. No new API route, WordPress code or form GUID is required.

Regression: `node tests/check_conversational_chat.js` simulates the chat flow, checks that consent refusal never submits a lead, verifies the signed session is created after CAPTCHA, and validates Q&A/callback wiring. The public PHP origin, consent, school isolation and lead creation tests remain in CI.

## WordPress style isolation, short questions and provider diagnostics

The public admissions widget is mounted inside an **open Shadow DOM**, with its CSS loaded inside that boundary. WordPress themes and page builders frequently apply global button, heading, text-input, and form rules (including `!important` colors); these cannot style the controls inside this widget's shadow root. The launcher, close button, chat suggestions, speech bubbles and input row now share consistent mobile/desktop styling. The existing `/scrm/embed/<GUID>` loader and school API routes are unchanged.

**Short parent questions:** `fees`, `fee`, `documents`, `bus`, etc. are expanded into general admission-topic questions **before** approved FAQ matching and the privacy-gated AI request. Previously, the minimum-length privacy screen prevented `fees` from reaching OpenRouter at all. A question about fees can only receive a figure if the school has published verified campus/class pricing (in approved FAQs or the school overview). Otherwise the assistant explicitly offers the admissions callback, not an invented amount.

**AI configuration and test:** **SchoolFlow → Admissions AI → OpenRouter AI provider** shows separate statuses for saved API key and whether AI is enabled for parents. **Test OpenRouter connection** makes an explicit, tiny, admin-only request to the configured model; it does not include parent/child names, telephone numbers, leads, chat history or school enquiry data. It reports sanitized outcomes including connected, invalid key (401), insufficient credits (402), unavailable model (404), rate-limited (429), TLS/network failure or timeout. It is rate-limited to six checks per school administrator per hour, performs no external network request inside a JSON store lock, and may use a small number of billable tokens. Neither credentials nor raw provider response bodies are returned to the dashboard. A stored key alone is not proof of live connectivity.

**Operational steps:** Publish an approved fee FAQ with accurate campus, grade and academic year context; save the school overview and opt into external AI if needed; test the connection from Admissions AI. Do not publish unverified fees. On deploying new DigiOps release, the extensionless GUID loader will update static asset hashes automatically.

## General AI answers, dedicated Chatbot Leads and click-only enquiry form

- With external AI enabled and a working OpenRouter model for the selected school, the assistant answers general school/admissions questions using general knowledge and the published school context. General admissions procedures are described as *typical*, not confirmed school policies. Fees, timetables, transport availability, board affiliations, admission dates and other school-specific claims must be backed by approved school information; no fabricated campus facts.
- Under **ADMISSIONS → Chatbot Leads**, school staff can view only enquiries originated by the guided chat or earlier chatbot callbacks, with totals, new lead counts, callback counts, searchable list, stage filter and links to the usual CRM enquiry detail/pipeline. New chatbot sessions persist a `lead_channel=chatbot` marker, and legacy records are recognized by `utm_source=admissions_guided_chat` or `public_admissions_chat`. Original school/site source and assignments remain intact.
- The existing `lexicon-form.js` admission form **no longer opens automatically after 10 seconds**. It continues to render the manual **Enquire Now** trigger, registered `data-lexicon-form` click handlers and the explicit `window.openLeadPopup()` function. Opening the chatbot itself never calls `openLeadPopup()`. This preserves click-only behavior and avoids an unrequested overlapping enquiry dialog.
- **OpenRouter must be connected and enabled for the chosen school** to provide AI-backed general answers. The dashboard **Test OpenRouter connection** verifies provider reachability; a saved key alone does not prove actual model availability. Verified FAQ answers continue to work without the provider.

## Returning visitors and multiple campus knowledge entries

Each campus's **Admissions AI** settings can publish multiple approved information entries under fees, admission documents, age eligibility, facilities, transport, curriculum, admissions, timings, school features, contacts or other information (up to 36 per campus). Each entry contains a category, label (for example, Nursery annual tuition 2026–27) and details. Published FAQs are answered first, then category-matched campus facts, then an enabled AI provider. Exact class-wise fees and age rules must come from verified school entries; the chatbot will not invent them. AI prompts include the selected campus's approved entries only.

Schools may choose **15 or 30 days** of browser remembrance, defaulting to 30. **Remember this browser** is an explicit opt-in after a consented admission enquiry is created. Only an opaque random credential and expiry are saved in local browser storage, never parent contact details, student details or chat transcripts. The CRM stores a SHA-256 hash of that bearer credential with the original school, group, lead and expiry. The credential grants access to the existing lead's chat for that specific site and campus, and is rotated on each successful return.

A returning visitor can select **Continue previous enquiry** (new short-lived signed chat session on the same CRM lead) or **Start new chat** (revokes this browser credential and restarts guided intake). Server-side same-student, same-grade, same-phone and same-campus matching within 30 days avoids duplicate chatbot CRM leads even if the visitor starts a new conversation. A new student or class can create a distinct lead. If the school becomes disabled or the remembered credential expires, fresh intake is required.

The remembered credential is sensitive: anyone using the same browser storage could resume it. The visitor is warned before opting in. Clearing browser data removes remembrance. Past chat transcripts are not retained or replayed; continuing means resuming the enquiry, not restoring raw past messages. Expired keys are rejected, and hashed expired records are pruned as new sessions are issued.

New public endpoints: POST /api/public/forms/{GUID}/chat-remember, /chat-resume and /chat-forget. School Admin PATCH public-chat settings now supports school_facts[] and remember_days. The existing single GUID website script continues working without replacement.
