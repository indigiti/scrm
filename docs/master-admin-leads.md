# Master Admin Leads — Phase 1

## Scope
Read-only consolidated enquiry list for an authenticated user with active **Admin** memberships in one or more campuses. This is **not** a cross-tenant superuser: users only see schools they administer. Counsellor and Admissions roles cannot use the master listing.

## UI
Open **Administration → Master Leads** in the existing SchoolFlow sidebar. The page provides:
- KPI counts computed for the current filter, not only the current page.
- Campus, salesperson, stage, follow-up timing, date range and text search.
- Server-side pagination of 50 rows, with Prev/Next navigation.
- A **View** action that switches the active campus, reloads authorized school context and uses the existing enquiry detail drawer.
- A read-only list. Updating details in the existing drawer follows existing school-scoped permissions, validation and audit events.

The page requires a school Admin membership. Switching the active campus never expands access to the master API.

## Endpoint
`GET /scrm/api/admin/master-leads`

Optional parameters: `school_id` (numeric or `all`), `assigned_to` (numeric, `unassigned` or `all`), `status` (existing CRM status), `search`, `from`, `to`, `due` (`overdue|today|upcoming|unscheduled|all`), `page`, `per_page` (maximum 100), `sort` (`created|followup|name`), `direction` (`asc|desc`).

Response: `rows`, `total`, `page`, `per_page`, `pages`, `summary`, `schools`, `staff`.

Lead rows are a bounded selection of fields; they do not include notes, audit logs, communication secrets or full lead payloads. The underlying school lead view retains existing permissions.

## Data and deployment
The feature reuses `SchoolFlowStore` and the original `leads`, `memberships`, `users` and `schools` arrays. No records are copied, no new JSON format is required and no database migration is performed. Existing campus enquiry listing and exports are unchanged.

GitHub Actions generates the DigiOps artifact after checks pass. Merge and subsequent DigiOps staging deployment are separate operations; the feature is not live merely because a PR exists.

## Verification
- `php tests/test_master_admin_leads.php`
- `bash tools/run-regressions.sh`
- Stage as multi-campus admin and open records from both campuses; verify drawer continues to use the correct active campus.
- Stage as single-campus admin, Admissions and Counsellor and verify API access.
- Confirm no unrelated school names, staff or rows are exposed.
- Verify 0, 1, 50 and 51+ lead behavior, filters and narrow-screen layout.
