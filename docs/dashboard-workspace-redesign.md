# SchoolFlow CRM dashboard and navigation redesign

## Information architecture
**Workspace:** Dashboard, My Work.
**Admissions:** Enquiries, Chatbot Leads, Pipeline, Follow-ups, Tasks, School Visits, Families, Seat Planning.
**Communications:** Communications, Email Templates, Automations.
**Insights:** Reports, Admissions AI.
**Management:** Websites & Forms, Schools & Routing, Team, Import Enquiries.

Existing role and school filters stay in place. Server permissions and API handlers are unchanged.

## Dashboard
- Admissions KPI cards linked to relevant modules
- Today's priorities including due follow-ups, first contact pending, unassigned leads and SLA breaches
- Priority follow-up and recent enquiry rows opening existing lead details
- Funnel across the five existing application phases
- Connected website sources, future school visits and family profiles
- Collapsible administrator demo data controls
- Responsive navigation drawer, consistent SVG icons and direct search shortcut

## Deployment
Frontend changes only. Branch: `feature/scrm-dashboard-workspace-20261021`. Review and merge to main after GitHub Actions checks pass, then deploy the DigiOps artifact to staging. No live storage modifications. Verify desktop and mobile, all role types, multi-campus switching, zero data, and enquiry drawer behavior before production rollout.
