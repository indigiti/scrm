# SchoolFlow staging reset — browser-only (no console)

The feature is **only** offered on `https://stage.digiti.in/scrm/` when signed in as the pre-existing `in.stg.adm` administrator (email local-part `in.stg.adm` or matching login).

## Admin steps
1. In DigiOps, choose **sCRM → Check update → Deploy** once the GitHub CI artifact for this change is successful. This applies application code; it never wipes data by itself.
2. Sign into the staging CRM using the existing `in.stg.adm` email and password.
3. On **Dashboard → Demo data and testing tools**, choose **Preview full staging reset** (not Clear demo data).
4. Review the number of staff, schools, enquiries, website sources, tasks, sessions and other records that will be deleted.
5. Before approving, ensure you are on staging, have independently preserved any important existing school data, and can tolerate severing all WordPress / website API connections to the deleted schools. Stop any active external integrations and scheduled sends.
6. Re-enter the **current administrator password** and type exactly `RESET STAGING KEEP IN.STG.ADM`. Confirm the final browser warning.
7. The server creates a checksum-verified backup in the private data directory, resets the store and revokes all sessions. Sign in again using the original administrator credentials. You should now have one empty administrative workspace, with no enquiries.
8. Verify that previously connected websites no longer submit to old keys. Reconfigure only the intended schools and sites after the reset.

The reset is **not** automatic on deploy. It cannot be triggered from `main` or from GitHub. It requires a live, authenticated administrator click in the staging CRM.

## Server safety
- Hostname strictly matches `stage.digiti.in`. No reset control on other hosts.
- Only the unique preserved `in.stg.adm` account with an active Admin membership may access the API.
- Confirmed user must supply **their current password** plus the exact typed phrase.
- A preview SHA-256 fingerprint is checked under an exclusive file lock; if data changed, the reset is rejected until a new preview is obtained.
- A backup of `store.json` and existing `messaging.key` and `public-form.key` is created in a private 0700 directory, with files set to 0600 and SHA-256 compared before state replacement.
- The private store must reside under `private_html/scrm/data`.
- Existing numeric school counters are carried forward and all active tokens/sessions are revoked.

## Recovery / limitations
The server-side backup is a **local disaster-recovery aid**, not a substitute for a separately verified off-host backup. A Cloudways operator is still needed if restoration becomes necessary. Browser reset cannot restore deleted data.

Deleting the CRM's old school/source records does not erase WordPress plugins, form snippets, email/provider accounts or third-party data. Those integrations must be disconnected or reconfigured separately.

The web reset gate is specific to the **`stage.digiti.in` hostname**, not a cryptographic proof that the backend store is staging; operators must independently verify the Cloudways application is correctly wired before running it.

Tests: `php tests/test_web_staging_reset.php`, plus full DigiOps workflow.
