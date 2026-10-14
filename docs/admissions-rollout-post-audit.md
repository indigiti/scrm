# Admissions A–D: deployment and post-change audit

Baseline: main at 534e3390; independent changes reviewed and merged as Release A PR #16, B PR #17, C PR #18. Release D PR #19 requires final CI.

## Release gates
- A: Independent lead stage, interest temperature, contact disposition, follow-up; No Answer does not record a successful contact.
- B: Idempotent tasks, application milestone history, enrollment confirmation/fee guard, school-specific retry policy.
- C: Separate parent profile identities, explicit sibling linking, conservative duplicate classification and explainable recommendations.
- D: Disabled-by-default task notifications, channel-specific consent, audited queuing, no blind retries on ambiguous provider failures.

## Privacy and separation
All lead and action records retain school-based permissions; immutable GUIDs and previous numeric IDs remain supported. SchoolFlow's private JSON runtime and messaging credentials must not be included in GitHub artifacts. Demo cleanup removes demo-only task/application/notification records without removing real enquiries.

## Automation operation
After separate DigiOps deployment, a Cloudways operator can schedule the deployed PHP gateway with the argument `automation-run`. Verify the correct CLI PHP interpreter and absolute deployed gateway path on Cloudways. Start with automation disabled and verify zero sends. Only enable an individual school after provider connection, opt-in and backup/restore testing. Acceptance of a message by Resend or Meta is not proof of delivery; manually reconcile using provider evidence. Automatic provider delivery webhooks are not implemented.

## Post-release acceptance
1. Preserve and test restoration of private SchoolFlow JSON records and encryption/signing keys.
2. Verify legacy IDs, GUIDs, admin permissions, school routing and WordPress/one-script enquiry intake.
3. Check No Answer, retries, temperature override reasons, Lost/Deferred guards and the task queue.
4. Validate same-parent sibling linking versus genuine repeated student enquiries.
5. Confirm dashboard totals by campus, stage and lead temperature.
6. Confirm automation defaults disabled, nonconsented/demo contacts blocked, provider acknowledgment semantics and idempotency.
7. Record the Cloudways deployment ID and run per-campus staging tests before any production activation.

## Remaining operational limitations
Cloudways deploy, cron configuration and live parent message delivery must be independently verified. Date-based follow-up tasks use the school-local day. Message delivery status is updated only after a recorded manual provider evidence review.
