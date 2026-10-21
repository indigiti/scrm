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
