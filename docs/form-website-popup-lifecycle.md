# SchoolFlow form / popup / website lifecycle (November 2026)

## The four objects (do not conflate them)

| Object | Identity | Created in | Remove action |
| --- | --- | --- | --- |
| Individual school website connector | School-scoped site GUID + private API key | Websites & Forms | **Remove** revokes site key, removes active listing; keeps disabled source only if historic leads reference it |
| Public group form | Group form GUID + connected school routes | Schools & Routing | **Remove group form** revokes all public embeds and group API keys, removes popups and campaigns as configuration, cleans unused routes; preserves lead/audit attribution |
| Public embed surface | Inline, short group popup, legacy detailed popup | Group form's Form visibility | **Disable** is independently reversible; old installed scripts stop rendering that surface on the next config fetch |
| School website / page-specific popup | Independent campaign GUID under a public form | Popup Campaigns | **Remove** deletes only that campaign; embedded script stops displaying on its authorized scope |
| Approved additional URL | Exact approved HTTPS origin/path | Landing Pages | **Remove URL** revokes additional approval subject to normal primary-domain rules; never deletes existing leads |

Form removal is not deletion of historical CRM leads. A group form's three display surfaces share one form GUID and source routing; removing that group is an intentional all-surface operation. Disable one surface if the other two must continue working. Group-wide short popup disabling does not disable a separate GUID-based website popup.

## School and website scope

The selected campus filters the forms and campaigns in the dashboard, but a form connected to two schools correctly appears in each. API operations on a multi-campus form still require current Admin membership for **every** connected school and owner ID; switching the UI school cannot grant additional access. Counsellors do not gain form management.

An individual connected website with an `sfsite_` key continues to be a server-to-server lead connector. Administrators can now provision a **separate school-specific public popup form** directly from that existing website; it reuses the website's source record rather than creating a redundant source. This popup form is publication-disabled for legacy group-wide and inline surfaces by default. A new website-wide popup campaign can be installed on that website without an added landing-page approval. Additional campaign domains still require authorization. Public forms do not expose private `sfsite_` or `sfgroup_` secrets.

## Effect of admin removal

- Deleting a public group form: requires typing its exact group name in the UI; API requires current group form GUID; old \`/embed/{guid}\` and \`/api/public/forms/{guid}/config\` stop working; group key revocation is immediate; historical leads/audit retained.
- Removing a connector: requires typing its exact website name; API requires site GUID; old private API key stops working; unused connector records are deleted; referenced source records are archived and hidden from management.
- Removing a website popup: its campaign GUID is removed immediately; the embed loader on the website will not show its popup after config refresh. Remove the old script from the website's source as housekeeping.
- Disabling the group short popup: old scripts stay installed but the group popup trigger is hidden; inline and page-specific campaigns continue to function.
- Disabling the detailed popup: its existing embedded script no longer offers the manual button or auto trigger; inline continues.
- Disabling inline: inline embed is hidden without redirect or submission UI; reenable it before resuming LP lead collection.
- Removing an approval on the primary registered domain when \`restrict_primary=false\` does not block that path—the primary is already allowed. External form domains need explicit authorization and remain unavailable if their approval is revoked. Registered school website popups do not depend on the inline landing-page approval list.

## Tests and deployment

\`php tests/test_form_lifecycle.php\`: group and site deletion, key revocation, school roles, source attribution, audit preservation and per-surface switches.

\`node tests/check_short_popup_campaign.js\`: group short-popup disable hides old embed, but not page-specific popups.

\`node tests/check_conditional_enquiry_popup.js\`: detailed popup waits for backend policy, manual and automatic behavior, success stays inside popup.

\`php tests/test_page_popup_campaigns.php\`: page popup create/edit/delete and approval revocation.

Important: GitHub CI checks are not equivalent to a deployed browser test. After deployment, verify one inline lead still goes to the thank-you page and that disabled popup scripts do not render on the school website.
