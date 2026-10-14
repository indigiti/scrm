# SchoolFlow Admission Upgrade — Pre-change Audit
Date: 2026-10-14
Baseline: `534e3390e171a2980b4a7453e91db0f9c3c073de` (main, post PR #15)

## Reviewed source
`app_private/api.php`, `app_private/store.php`, `app_private/messaging.php`, `public_html/static/app.js`, and existing PHP/JS regression tests.

## Findings
1. Single `status` attribute contains seven stages (New, Contacted, Counselling, Visit Scheduled, Applied, Enrolled, Lost); no separate temperature, contact disposition or structured lost reason. Current stage names are persisted as strings and must be preserved.
2. A call with No Answer or Busy sets `last_contact_at`, inaccurately representing successful connection. Follow-up is date-only; overdue reporting uses UTC in PHP and a browser day in JS; no durable follow-up task entity.
3. Each lead receives immutable GUID in existing code, but a guardian/family is inferred from telephone number and legitimate siblings may be flagged as duplicate.
4. Existing school-scoped staff permissions, school-specific source keys, group campus routes, auto-assignment, visits, call activity, audit history and email/WhatsApp explicit provider sends are present.
5. Existing service does not have scheduled delivery worker or webhook delivery reconciliation. The messaging API expressly records accepted, not delivered.
6. `store.json` is single-file lock-protected persistent storage; backward-compatible optional fields/collections and non-destructive writes are mandatory.

## Release gates
- A: independent classification and follow-up status; correct successful-contact semantics; reasoned overrides; legacy reads stable.
- B: durable idempotent school-scoped tasks, admission phase/substage and application actions; overdue cron-safe iteration.
- C: conservative family linking/opt-in identity, conversion reporting and explainable temperature suggestions.
- D: consent-gated automated communications, task queue and explicit secure worker execution; no automatic real sends by default.
- After each release: PHP syntax/tests, JS smoke, public artifact packaging, cross-campus permissions, idempotency, audit and credential non-disclosure.
- Deploy only after private backups and explicit DigiOps staging/live acceptance. GitHub merge/build is **not** a Cloudways deployment.
