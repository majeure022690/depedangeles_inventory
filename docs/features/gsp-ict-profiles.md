# GSP ICT Profiles

The encoding module behind the Government School Profile, Part VI (ICT in Schools). Page: **Records → GSP ICT Profiles** (`/gsp-profiles`). One profile per office per school year, holding only what IRIS can't derive; computers, ICT equipment, e-cart components and ISP subscriptions are read live from Equipment, ISP Accounts and the Internet Connectivity Survey when the workbook is downloaded.

## Data

Table `gsp_ict_profiles` (unique `office_id` + `school_year`, audited as `gsp_ict_profile.created/updated/deleted`).

| Field | Notes |
|---|---|
| `office_id` | Fixed once created. Scoped users are forced to their own office. |
| `school_year`, `as_of` | `2025-2026`; the form defaults `as_of` to March 31 of the closing year. Printed under every table title. |
| `level` | `elementary`, `junior_high`, `senior_high`: the Table 1 rows learner and teacher computers are counted under. Defaults from the office type. |
| `radio_teachers` | Table 4: `{grade_1: {literacy, numeracy}, … grade_6}`. |
| `dict_free_wifi`, `dict_free_wifi_installed` | Box 1 Q4: `with_internet`, `without_internet` or `not_recipient`, and the month/year installed. |
| `isp_reliability` | Box 1 Q2 service reliability per ISP account id of the office (Highly Reliable, Reliable, Unstable, Poor). |
| `school_head_id`, `ito_id`, `planning_officer_id` | Cover page signatories, linked to Personnel (null on delete). The school head must belong to the profile's office; the options list that office's active personnel, principals and head teachers first (`Personnel::scopeSchoolHeadsFirst`). The ITO and Planning Officer lists are active personnel division-wide whose position contains "Information Technology Officer" / "Planning Officer". The cover prints each person's printed name and Personnel position, so changes synced from PRIME show on the next download. |

The form is tabbed like the workbook: **Profile, Table 1, Table 2, Table 3, Table 4, Box 1, Signatories**. Tables 1–3 and the survey/subscription parts of Box 1 are shown read-only from `GET /api/gsp-profiles/preview`, computed by the same `GspIctReport::irisData()` the workbook uses, with links to where each source is edited; Table 4, DICT Free WiFi, reliability and signatories are entered.

## API

| Method | Path | Permission |
|---|---|---|
| GET | `/api/gsp-profiles` (`search` = school name/ID, `school_year`, `sort`, `direction`, `per_page`) | `gsp_profiles.view` |
| GET | `/api/gsp-profiles/options[?office_id=]` (`itos`, `planning_officers`; with `office_id` also `school_heads` and the office's active ISP accounts) | view, create or edit |
| GET | `/api/gsp-profiles/preview?office_id=&school_year=&level=` (Tables 1–3 and Box 1 from IRIS; 404 outside the data scope) | view, create or edit |
| GET | `/api/gsp-profiles/{id}` | `gsp_profiles.view` |
| GET | `/api/gsp-profiles/{id}/download` | `reports.gsp_ict` (throttled 20/min, audited `report.generated`) |
| POST | `/api/gsp-profiles` | `gsp_profiles.create` |
| PUT | `/api/gsp-profiles/{id}` | `gsp_profiles.edit` |
| DELETE | `/api/gsp-profiles/{id}` | `gsp_profiles.delete` |

`GspIctProfilePolicy` re-checks every action; profiles outside the role's data scope return 404. Reliability ratings are only accepted for the office's own ISP accounts. `2026_11_01_000005_add_gsp_profile_permissions` grants the four permissions to roles holding the matching `equipment.*` permission.

What the workbook contains: [reports.md](reports.md#gsp-part-vi--ict-in-schools).

## Tests

`backend/tests/Feature/GspIctProfileControllerTest.php`.
