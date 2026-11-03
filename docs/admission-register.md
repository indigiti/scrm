# SchoolFlow Admission Enquiry Register

## Access
Go to Insights → Admission Register, using the selected Active Campus.

The report has three tabs: Enquiry Register, Calling & Follow-up History, and Counsellor Performance. It reads the existing CRM lead, call and activity-note records without duplicating data. The read-only register is paginated with a maximum of 100 rows per API request and 50 rows per UI page. Sorting, date range, grade, staff, stage, call outcome, source and follow-up filters are applied server-side. Automatic refresh runs every 60 seconds while the register is open and visible.

Counsellors see only their own assigned enquiries in their one home school, including calls, history and exports. Admissions and Admin see data only from schools they have an active membership in. The browser does not decide access.

## Export
The existing filtered XLSX contains Admission Enquiries and complete Follow-up History worksheets; existing column formats are preserved. A new school-scoped authorized CSV download follows the spreadsheet's original column sequence: Timestamp, Name, Email, Phone, City, State, Class, Campus, Assigned To and Remarks / Follow-up Notes. Stage, follow-up, academic year, source and UTM campaign are appended. Full exports use the server, not the 2,000-lead dashboard cache. Excel/CSV exports honor school, date, staff, grade, call outcome, status and other current filters.

## Historical Assigned To import
Optional Assigned To may contain an employee's full name or work email. Matching is exact, case-insensitive, and restricted to active employees of the selected school. Blank cells remain unassigned. Unknown, disabled or ambiguous names are displayed as row-level import preview errors and block commit. Existing historical remarks are retained and messaging consent is never granted by import.

## Validation
- Run PHP lint and all regression tests, including tests/test_admission_register.php.
- Verify both GitHub workflows and DigiOps bundled release.
- Test school-level isolation (including direct API access), assignment-only Counsellor visibility, pagination, filters and CSV/XLSX exports.
- Confirm leading-zero phone numbers, long calling remarks, row sorting and IST timestamps.
- Deploy to protected Cloudways stage and test actual admissions workflows on desktop and mobile before production.

The report cannot establish that a live Cloudways deployment has occurred; GitHub merge and release artifact are separate steps.
