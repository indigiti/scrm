# SchoolFlow Forms v2 — Short lead embed (feature branch)

The existing one-tag popup remains unchanged.

## Short inline form

```html
<div id="schoolflow-admissions"></div>
<script defer src="https://stage.digiti.in/scrm/embed/YOUR_PUBLIC_GROUP_GUID"
  data-schoolflow-form="short"
  data-schoolflow-mode="inline"
  data-schoolflow-target="#schoolflow-admissions"></script>
```

## Compact popup

```html
<script defer src="https://stage.digiti.in/scrm/embed/YOUR_PUBLIC_GROUP_GUID"
  data-schoolflow-form="short"
  data-schoolflow-mode="popup"></script>
```

Use only one embed script per page. A form on another domain requires a group authorized for that domain. The GUID is public; no API keys are exposed. Configuration and campus choices always come from the approved group.

The compact flow asks for guardian name, 10-digit mobile, class, selected campus, and explicit consent. On the first submit, a server-issued math challenge appears; the follow-up submit creates the lead. The backend stores student_name as an empty string rather than claiming a student's identity. Staff should collect it during admissions follow-up.

Server endpoint: POST `/api/public/forms/{GUID}/short-enquiries` protected with origin check, campus check, consent, CAPTCHA, honeypot, idempotency, and rate limits.

**Limitations:** This is the compact embed, not a complete admin-editable form builder. No live deployment or end-to-end CRM test is implied. Before release, run PHP tests and preview both styles on a real authorized HTTPS host. Do not activate two popup scripts with the same GUID on one page. Do not drop the CAPTCHA check server-side.
