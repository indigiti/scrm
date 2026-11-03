# SchoolFlow — school-scoped staff assignment

## Administrators: how to assign staff
1. Select the target school in **Active Campus**.
2. Open **Administration → Team**.
3. **Create staff account** creates a new login within the selected school.
4. **Assign existing staff** reuses an eligible employee's login from another school you also administer. It does not create a duplicate user or change passwords.
5. Choose the employee and assign the role **Admissions** or **Counsellor**. Server validation and audit logging are mandatory.
6. Re-login is required for employees newly assigned to a school. The prior sessions are revoked as part of the permission change.

## Role scope
- **Counsellor**: exactly one active home school and only their individually assigned school enquiries. A legacy duplicate school membership cannot grant access to another campus.
- **Admissions**: school-wide admissions access only to explicitly assigned schools. They may be assigned to multiple schools when an administrator administers both.
- **Admin**: admin controls only in schools with an active Admin membership. The linking endpoint never grants Admin.
- Existing users with an active Counsellor home school cannot gain another active school membership through the link flow.
- **Transfer Counsellor**: first disable their old campus membership as the administrator of that school, then select the target campus and use **Assign existing staff** with role Counsellor. Historical leads stay in the original school; open assigned tasks/leads are released for reassignment by the existing staff-disable workflow.
- Disabled memberships cannot be reactivated when that would violate the one-active-school Counsellor constraint.
- If an old account has conflicting active assignments, the Counsellor home-school restriction is enforced at the API membership gate and the school-picker response, not just in the UI.

## Security
- \`GET /api/schools/{id}/members/link-candidates\`: available to admins of target campus. Only staff affiliated with *other* campuses they themselves administer are visible. No global user directory or cross-tenant search.
- \`POST /api/schools/{id}/members/link\`: JSON object \`{"user_id":123,"role":"Admissions"}\` (or \`"Counsellor"\`), admin-only with all candidate eligibility rechecked transactionally; audited on target school. The original password hash and user ID are preserved.
- No cross-tenant account linking based on email knowledge. An administrator who controls only the target school cannot discover or link staff at another school.
- Role changes, session revocation, and cross-school authorization are server-side. Existing school-lead access controls still apply.
- Even when multi-school Admissions is enabled, individual enquiry records and source API keys are separated by school membership.

## Verification
- \`php tests/test_school_staff_assignment.php\`
- \`php tests/test_staff_management.php\`
- \`node tests/check_group_routing.js\`
- Full Public Form PR Checks and DigiOps Release CI.
- Stage browser tests: login as Counsellor to verify only assigned home school appears; direct URL/API for another school must return 403; only assigned enquiries listed. Login as Admissions assigned two schools to confirm switching and independent lead lists. Test transfer, duplicate-login prevention and revoked sessions.

Code merge alone is not hosting deployment. Confirm a fresh DigiOps build and deploy to Cloudways staging before announcing UI changes live.
