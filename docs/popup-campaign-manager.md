# SchoolFlow Short Popup Campaign Manager (V1 + V2)

## Scope
Existing short inline and popup embeds retain their script tags and GUIDs. Automatic popup display is explicitly **disabled by default** for existing and new group websites. Each group website has one independently configurable campaign. Only its owner, who must retain administrator access to the connected school group, may update settings.

Dashboard: **Schools & Routing → Group websites → Popup settings**.

Admin REST:
- `GET /api/group-sites/{groupId}/popup-settings` (authenticated group owner/admin)
- `PATCH /api/group-sites/{groupId}/popup-settings` (replace validated settings atomically)

Public REST:
- Existing authorized-origin `GET /api/public/forms/{GUID}/config` also returns `popup` with allowed school choice codes, not private school IDs or keys.
- The public config is origin checked; browser supplies no private API key.

## Settings
- `enabled`: off by default
- `trigger`: `delay`, `scroll`, `either`, `exit`, `manual`
- `delay_seconds`: 0..60, default 8
- `scroll_percent`: 10..90, default 40
- `exit_intent`: desktop fallback, default on
- `mobile_enabled`: default on; mouse exit intent is never used for coarse pointers
- `repeat_after`: `session`, `1`, `7`, `30`, `never`
- `position`: `center`, `right`, `bottom`
- `include_paths`, `exclude_paths`: up to 20 normalized path prefixes each; all paths must begin with /; query strings and external domains forbidden
- `start_date` / `end_date`: optional ISO dates, interpreted in Asia/Kolkata
- `start_time` / `end_time`: optional 24-hour HH:MM daily operating window in Asia/Kolkata; both required when either is set, including overnight campaigns
- `school_ids`: empty means all current group destinations; otherwise an admin-validated subset

## Behavior
- Manual Enquire Now stays usable regardless of automatic triggers.
- The short inline form remains inline; campaign triggering is popup-specific.
- Browser storage contains only suppression timestamps, never applicant information.
- Showing the popup counts as a prompt; suppress repeats according to policy.
- Closing dismisses the popup. Successful short-form submission suppresses future automatic prompts for 30 days per browser even if the configured dismissal period is shorter.
- Escape, backdrop click, close button, keyboard tab containment, and focus restoration are supported.
- Disabled campaigns do not auto-open, preserving previous short popup operation.
- Popup auto-displays are suppressed on /scrm admin paths.
- Browser-reported trigger is `manual`, `delay`, `scroll`, or `exit`; server derives the campaign identifier from the approved group record. Neither field is trusted for routing or authorization.
- Campus choices are server-derived from approved routes; a visitor-provided school ID cannot authorize a destination.

## Release verification
1. Run PHP syntax checks, `php tests/test_popup_settings.php`, existing group/public form regressions, `node --check public_html/static/app.js` and `node --check public_html/static/schoolflow-short-form.js`.
2. Build the **bundled** DigiOps artifact with `php tools/build-digiops.php`; verify bundled PHP includes the settings module and leaves no `require_once` to a non-existent private path.
3. Deploy to protected stage with a registered authorized HTTPS school domain.
4. Confirm unchanged inline, short popup and legacy detailed form embeds; no auto-popup before opt-in.
5. Test each trigger on desktop, mobile and keyboard; check schedules, path exceptions, session and 7-day suppression, after-submit suppression, and popup positions.
6. Test admin attempting to target unconnected school; test non-owner, staff roles and foreign origins.
7. Submit controlled enquiries from each group and confirm school routing, source attribution, popup trigger, campaign id and idempotency.
8. Verify changes to group routes do not grant disallowed campus access; test rollback with the prior release artifact.

This feature is not considered production deployed until the stage checks above pass.
