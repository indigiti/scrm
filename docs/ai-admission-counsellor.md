# SchoolFlow AI Admission Counsellor

## Scope and release boundary
The website widget on the existing `/scrm/embed/<GROUP_GUID>` is now an **answer-first admission counsellor**. It is not a telecaller, and it never insists on obtaining guardian details before replying. The current implementation covers website chat. WhatsApp and AI voice would require separate channel connectors, identity verification, consent and delivery policies; they are not claimed as implemented.

## Parent experience
1. Open the widget and ask a real admission question immediately. Suggested questions are optional.
2. The server resolves the selected active school campus (if the group has more than one, parents can choose) and consults a published, campus-specific FAQ/fact library.
3. Approved FAQs and verified school facts answer first; optionally the configured OpenRouter/OpenAI model answers general school questions using **anonymised topic information** and the campus's approved knowledge. Fees, eligibility, seats, dates, bus coverage and other campus-specific facts cannot be fabricated.
4. The Conversation Manager offers a relevant clarification only when useful (such as the requested grade for fees or campus for admissions), rather than running a fixed questionnaire.
5. The Lead Extractor stores bounded, non-contact metadata from the conversation—campus, grade, academic year, residential area (city/locality only), interest topics, and stated visit preference. The Lead Qualification Engine computes an interest score and recommended next action. No raw parent chat transcript is persisted.
6. Only when the parent wants an admissions callback or campus visit does the widget ask for optional parent name and contact number, explain the purpose, obtain explicit contact consent and validate the signed CAPTCHA. This creates or links a proper, school-scoped CRM lead and first-contact task. Student name and exact date of birth are **not required**.
7. The parent may separately opt to remember the CRM enquiry on this browser for a school-selected 15/30-day period. Return tokens are hashed in the CRM and browser storage holds only the opaque bearer and expiry. Session/tab-scoped anonymous conversation tokens require no persistent permission, and cannot recover private transcripts.

### Examples of legitimate public answers
- **Grade 2 fees**: answer only from verified, campus-specific class-wise fee information. Otherwise ask grade/campus or say the amount needs confirmation.
- **Age eligibility**: use published grade and academic year cutoffs. Otherwise explain typical policy and ask which class.
- **Transport**: identify *confirmed* routes from verified school facts, not guessed availability.
- **Saturday visit**: offer to request school confirmation, not a guaranteed opening or booking.
- **General admissions questions**: the optional AI can provide useful general advice without parent contact information.

## Dashboard
**SchoolFlow → Admissions AI** now presents five sections:
- **Overview**: AI publishing state, anonymous conversations, chatbot leads, topic coverage, pending reviews.
- **School Knowledge**: school-approved overview, FAQs, structured grade/campus-specific facts, HTTPS source URLs, scan-draft review and publishing.
- **Questions & Answers**: aggregated parent interest topics, separated by grade/year, counts, actual most recent displayed response, and explicit FAQ draft-to-review workflow; unanswered questions can be approved or rejected by school administrators.
- **Leads & Conversations**: anonymous question count and qualified converted Chatbot Leads; existing CRM pipeline remains the system of record.
- **Settings**: encrypted OpenRouter API key/model, live provider connection test, 15/30-day return policy, and GUID embed.

### Multiple school source URLs
School admins can add up to 20 distinct **HTTPS source URLs from registered school website domains**. Scan fetches *only* the requested page, over pinned, public IPv4 DNS, with TLS verification, no redirects, a 125 KB content cap and timeouts. HTML and plain-text are currently supported; PDF parsing, login-only pages and JavaScript-rendered sites are not yet supported.

The scanner creates at most 12 **unpublished proposed facts per scan**, including text evidence. An administrator must review, edit and explicitly approve each fact before publication. A source rescan with changed content flags linked facts as needing re-verification, immediately excludes them from answers and AI context, and requires a specific per-fact confirmation to restore them. It does not silently overwrite published records.

### Approved answer reuse and costs
The system tries a sufficiently precise approved FAQ match, then category/grade-matched school facts, before using paid AI. AI replies are not automatically trusted or published. Aggregated most-asked topic reports store the **actual parent-facing answer shown**, source category and count, but not raw private chat messages. Admins can create a draft FAQ from a topic, verify its facts, and save intentionally.

## Security notes
- Parents can receive answers and appear as anonymous conversation statistics before consent; no contact data is transmitted to the AI.
- A conversion requires school group website origin authorization, consent, phone validation, challenge verification, rate limits, and idempotency.
- Counsellor/return tokens are unguessable and site/campus bound; school-scoped access is enforced on every request.
- AI gets *topic-only* synopsis plus verified campus information, avoiding unknowable PII in raw free-text. Raw responses are not published as facts.
- The site HTML/JS embed and standard admission form remain backward-compatible; the separate enquiry popup still opens on click only.
- The JSON store is a single-file locked application database, so scan/AI network calls are deliberately outside transactions. Future scale-out should move analytics and anonymous sessions to dedicated database tables with retention/expiry jobs.
- Cloudways deployment is separate from successful GitHub CI packaging; do not claim the live site has changed without verification.

## QA
CI runs PHP lint, existing public form and CRM regression tests, the new `tests/test_counsellor.php`, frontend answer-first simulation `tests/check_conversational_chat.js`, and checks the DigiOps payload for missing dependencies or private artifacts.

## Admissions AI workspace redesign (October 2026)

The admin page is now a responsive, clearly grouped five-section workspace with consistent spacing, cards, navigation, destructive-action confirmations, and a school-scoped publishing status.

### 1. Overview
Review counts of active school knowledge files, approved answers, unanswered questions, converted chatbot leads and readiness. Open Knowledge Library and Settings directly.

### 2. School Knowledge
- **Upload knowledge document:** Select a UTF-8 `.md` or `.txt` file (40–120,000 bytes). Maximum 8 files per campus. The browser reads the local file; nothing is automatically published.
- **Review draft:** The server stores the file as `pending`, exposes filename, size, section outline and a limited preview **only to an authorized school administrator**.
- **Publish:** Explicitly confirm that the document applies to the selected school, campus and academic year. Its content becomes eligible for the counsellor's answer retrieval.
- **Unpublish/Delete:** Either action immediately removes the document from parent-facing retrieval. Delete is permanent and requires confirmation.
- **Website sources:** Existing registered HTTPS URLs remain supported, with staged scan candidates and editorial approval.
- **Manual structured facts:** Retained in a separate expandable advanced editor for precise individual records, and still require explicit review when original web sources change.

Current file upload accepts Markdown and plain text. It does **not** claim to extract PDF/DOCX text. Do not upload documents containing students' personal identifiers, admissions applications or financial account details. Knowledge files should contain only school-approved information suitable for parents.

### 3. Questions & Answers
- Approved FAQ cards show the question and answer; **Save changes** and **Delete answer** operate immediately through separate authorized, tenant-scoped API actions.
- A new approved answer can be published directly from the Add Answer editor.
- Most-asked topics show frequency, grade/session grouping and the actual anonymized answer shown to parents. **Prepare FAQ draft** copies an answer for human verification. **Delete topic** removes that school's aggregate, not any other school's.
- Unanswered requests support **Approve & publish**, **Dismiss**, and a distinct **Delete** operation.
- Destructive actions require confirmation and record an administrative event. Removing a FAQ does not delete its associated admissions leads.

### 4. Leads & Conversations
Provides linked Chatbot Leads view, consented callback counts and anonymous enquiry counts. Lead conversion and school permissions are unchanged.

### 5. Settings
Publishes the counsellor, enables/disables external AI, selects the visitor-memory period, manages the campus overview, tests OpenRouter, and shows the GUID-based website embed.

### School-specific knowledge retrieval
The public counsellor retrieves documents with `status=published` and the exact selected school ID. It prefers trusted FAQs and deterministic published-source fee/age rows for specific parent enquiries, then uses selected approved excerpts in the optional AI provider prompt. It does **not** treat unapproved files as facts or silently add AI output to published answers.

For the supplied **Lexicon Kalyani Nagar** example, the school-provided file distinguishes annual fees, term instalments and one-time admission fees, the 2026–27 and 2027–28 academic years, and a conflicting Class XI one-time admission fee. Year/grade or stream must be established before quoting an applicable figure; amounts are copied as provided, including stated totals with rounding discrepancies. These are school-supplied records, not live verification of current billing or seat availability.

The uploaded example file is a *reference used for importer testing* and has not been published into any live school automatically. An authorized administrator must upload and approve it for **the correct Kalyani Nagar campus** after the new release is deployed.

### Technical/API changes
- `GET /api/schools/{school}/public-chat/knowledge-files` lists school-scoped file metadata (without full source contents).
- `POST` to that route supports `upload`, `publish`, `unpublish` and `delete`, enforcing school Admin membership, MIME-by-extension validation, UTF-8 text validation and storage limits.
- `POST /api/schools/{school}/public-chat/faqs` supports `add`, `update`, `delete`.
- `POST /api/schools/{school}/public-chat/counsellor-report` deletes a specific school/topic/grade/year analytics record, not CRM leads.
- Existing `/public-chat/review` also supports deleting a pending suggestion; rejection remains available separately.
- School data remains in the existing JSON CRM store; this pilot-sized upload cap avoids unbounded per-file storage, but large multi-school scale should move uploaded source text and conversation analytics to specialized storage.

The existing extensionless GUID website embed and the separate, click-only enquiry form are unchanged. Deploying the new DigiOps artifact is a separate step from GitHub merge/build.
