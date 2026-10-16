# SchoolFlow staff and configuration management: pre/post audit

## Verified gap in Release #26
The Team page and `/api/schools/{school}/members` only exposed staff creation and read-only listing. There were no existing-user updates, password resets, disable/reactivate controls or last-admin safeguards. Existing website settings were primarily key rotation and enabled status; school names were immutable via the UI. The historical-import API accepted 2.5 MB workbooks, but the HTTP gateway rejected JSON over 32 KB.

## New administration controls
- Admin may edit single-school staff identity (name/email), school role and campus-specific enabled status.
- Disabled staff lose access to that school and are removed from auto-assignment. Their open leads and tasks are unassigned for manual reallocation. Their completed task/lead history and audit records remain intact.
- Inactive membership is checked server-side, including alternate GUID school routes; permission cannot be restored by manipulating frontend state.
- Last active school Admin cannot be disabled/demoted, and an Admin cannot disable/demote their own account.
- Credential reset generates a one-time temporary password, hashes it in the private JSON store, revokes sessions and requires a password change before school data can be accessed. It is never sent to parents or email automatically and is never placed in audit logs.
- User names/emails and passwords on accounts shared across campuses cannot be changed by a single campus Admin. Each campus can independently revoke or restore its membership.
- Own-password change validates the existing password and revokes sessions. All changes remain audit logged.
- School administrators can rename their own school; connected site owners can update site names, domains and platform metadata without changing their API keys. Existing query-parameter site activation is preserved.
- Historical CSV/XLSX import requests alone allow a bounded JSON body of 3.6 MB. All other API requests retain their 32 KB limit.

## Pre-merge regression gates
Tests cover direct and cross-school permissions, last-admin preservation, orphaned lead/task assignment, credential resets and forced change, role transitions, multi-campus shared accounts, school/site updates, and a packaged gateway CSV upload larger than 32 KB. The normal small-request cap, other existing CRM routes and the DigiOps bundle must continue passing.

## Staging acceptance after DigiOps deployment
1. Back up private `store.json`, `messaging.key` and `public-form.key` and confirm rollback procedure.
2. Sign in as the school Admin, edit a test staff member, disable/reactivate them, and verify login, session revocation and visibility.
3. Reset a test staff password using a protected communication channel; confirm that the temporary password must be changed before access is granted.
4. Confirm a second campus remains accessible for a shared member when the first campus disables that membership.
5. Edit a staging school and test website source; verify routing keys, lead history and live enquiry routes were not rewritten.
6. Preview and commit a non-sensitive historical spreadsheet larger than 32 KB through the staging browser.
7. Check responsive Team action controls, read-only access for Admissions/Counsellors and audit event history.
8. Verify staff account changes do not enable automated email or WhatsApp sends.

Source/GitHub tests alone do not constitute a live authenticated browser audit, full backup/restore drill or production deployment.
