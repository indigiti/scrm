# SchoolFlow Lead Attribution v1

## Purpose
Give each enquiry a source of record independent of its assignment campus. Source **type** is derived server-side from the authorized site route/action, never from a browser-submitted `source_type`. Campus routing and permission checks are unchanged.

### Fields
- `source_type`: `group_website`, `individual_website`, `chatbot`, `manual`, `import`, `other`.
- `form_type`: `inline`, `popup`, `api`, `chat`, `manual`, `import`, `unknown`. Inline/popup on compact forms is a *client-reported display hint*, not a security property.
- `form_variant`: `short`, `detailed`, or blank.
- `landing_page_version`: `v2` or `v3` only when a recognizable version is present in the submitted landing path. Otherwise blank.
- `landing_page`, `referrer`, `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, and `utm_content` were already supported.
- `first_touch` and `latest_touch`: snapshots at lead creation. When a new enquiry is a potential duplicate of a previous lead in the *same school*, it inherits only a **recorded** first-touch snapshot; the new enquiry stores its own latest-touch. The original lead is unchanged.

### Historical data
Existing rows are not mutated or backfilled with fabricated event history. Read APIs derive group vs individual vs chatbot from saved site and channel info. They return `form_type: unknown` where the old surface is not verifiable, and `first_touch: null` / `latest_touch: null` if unrecorded.

### Reporting
The school-scoped Enquiries page supports origin and form-surface filters. Details show source, landing path, referrer, campaign and touch snapshots. Reports count leads by origin, form surface, landing version and UTM source/campaign with enrollment conversion. These figures are **enquiry counts**, not deduplicated families or ad-platform conversions. Excel and CSV include attribution fields.

### Deploy and acceptance
1. Deploy the successful DigiOps artifact from a merged main-branch build.
2. On an authorized HTTPS host, submit a controlled short inline and short popup enquiry with distinct UTM tags; check selected campus, source type, form type, version, and first/latest touch.
3. Check detailed public popup, group API key, individual site key, manual intake and chatbot lead.
4. Test source filters, reports, Excel/CSV, staff/school permissions and older records.
5. Verify domain authorization. Do not introduce private API keys in browser scripts.

Known limitations: This release does not include configurable per-school attribution naming, browser tracking cookies, verified Google/Meta attribution integration, or a cross-school deduplication/identity graph. The saved landing URL and form surface are visitor-reported metadata.
