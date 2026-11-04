# SchoolFlow / WaBridge optional WhatsApp OTP

## Available scope
SchoolFlow can require phone verification for the **short** inline and popup form on a registered group website. This uses the same server-enforced requirement for both surfaces; the public form's `form_display` value is not trusted to bypass verification.

Existing math CAPTCHA is mandatory before requesting OTP and still mandatory on lead submission. Detailed legacy forms remain Math CAPTCHA-only in this release. Existing schools default to WhatsApp OTP **OFF**; previously configured submission redirects and conversion tracking are unchanged.

## Dashboard
Log in as the owning school administrator and open **Landing Pages → [group form] → WhatsApp OTP**. Provide the name of a session that is allowed for a SchoolFlow-only WaBridge application credential. Leave the switch OFF until actual device delivery and the Cloudways PHP queue worker have been tested.

Server secrets must remain in private configuration. WaBridge credentials can be provisioned through `SCHOOLFLOW_WABRIDGE_API_KEY` on the SchoolFlow server; `SCHOOLFLOW_WABRIDGE_BASE_URL` defaults to `https://stage.digiti.in/wabridge`. Any override is controlled by server configuration and HTTPS-only, not by a public enquiry parameter. The API also supports an existing sealed per-group credential; it never returns it in admin or public responses.

In WaBridge create a restricted client for SchoolFlow and assign **only** the intended WhatsApp session(s). Use least-privilege and explicit rate limits. Do not embed bearer tokens in the landing-page HTML or frontend scripts.

## API and flow
1. The public group `/config` advertises only `otp.required`, expiry and resend cooldown. It contains no credentials.
2. After verifying Math CAPTCHA and consent, `POST /api/public/forms/{guid}/otp/request` creates a six-digit code, an HMAC-protected challenge, a rate-limit record, and then queues a message through the private WaBridge REST API.
3. WaBridge HTTP 202 means **queued**, not delivered. The browser must not report "sent" or "delivered" on a 202 response.
4. The parent types the code; `POST /otp/verify` checks the HMAC, caller origin, school/campus and mobile number, five-minute expiry and five-attempt limit. It returns an opaque one-use proof, not a reusable OTP.
5. On `/short-enquiries` the server consumes the proof atomically and saves `whatsapp_verified`, verification timestamp and the WaBridge job GUID with the lead. It never saves the code itself.
6. The existing approved thank-you redirect is performed only after a new enquiry is accepted. An idempotent replay does not produce a new advertising conversion.

## Limits and reliability
- Five-minute code validity; 60-second phone cooldown; three phone requests/hour/group; eight originating IP requests/hour/group, with additional campus/group caps.
- The PHP worker must be running and the selected Rust WhatsApp session logged in. A gateway-ready health endpoint does not confirm delivery.
- Uncertain dispatch results are **not automatically retried**, because the recipient may already have received the message.
- Keep the feature off until end-to-end mobile testing and a reliable authorized SMS/official WhatsApp fallback are available. The unofficial linked-device protocol can disconnect or restrict usage.
- WaBridge's own queue includes message content, so OTP texts may be retained under WaBridge's configured retention policy. Secure and shorten that retention for OTP use; never expose provider queues or logs to school staff or public forms.
- No server-side request is made to WaBridge when OTP is disabled. SchoolFlow stores only hashes and verification metadata; private records are bounded and expired entries are pruned when new OTP requests arrive.

## Release acceptance
Run `php tests/test_form_otp.php`, PHP lint, full public-form JavaScript checks and DigiOps build. On staging, test the actual connected session, delivery to consenting numbers, right/wrong code, five failures, expiry, resend cooldown, school switch, alternate website origin, duplicate submit, and redirect to `/lp/thank-you/`. Confirm GTM records exactly one accepted new lead.

**Repository validation does not establish live WaBridge delivery, worker uptime, provider policy approval or staging deployment.**
