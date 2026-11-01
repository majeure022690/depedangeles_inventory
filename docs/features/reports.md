# Excel reports

Two DepEd workbooks are built from IRIS data, one office at a time, each downloaded from its encoding module: the **GSP Part VI** from a [GSP ICT Profile](gsp-ict-profiles.md) (row button or **Download GSP** on the profile), and the **RPCPPE** from **Download RPCPPE** on [School Property](school-property.md). Both workbooks are generated entirely in code (`App\Services\GspIctReport`, `App\Services\RpcppeReport`, styled through `App\Services\WorkbookStyle`); no template file is involved, so school year and dates are always the requested ones and no sample data can leak in.

| Report | Permission | Endpoint |
|---|---|---|
| GSP Part VI – ICT in Schools | `reports.gsp_ict` | `GET /api/gsp-profiles/{id}/download` |
| RPCPPE – Physical Count of Property | `reports.rpcppe` | `GET /api/reports/rpcppe/{office}` |

`GET /api/reports/options[?office_id=]` (RPCPPE dialog) returns the offices the user can reach and, with `office_id`, the personnel there accountable for equipment or school property.

## Access

- Route `permission:` middleware fails fast; `AuthorizesReportOffice` (RPCPPE) and `GspIctProfilePolicy::download()` (GSP) re-check the permission and the office. An office outside the role's data scope returns **404**.
- Equipment, ISP accounts and School Property go through their `visibleTo()` scopes, so a report never shows more than the user could already see.
- Each download writes a `report.generated` activity row on the office with the report name and parameters.
- `2026_11_01_000001_add_report_permissions` grants both permissions to roles holding `equipment.create`. Generation routes are throttled to 20 a minute.

## Which equipment belongs to an office

`Equipment::scopeAtOffice()`: the accountable officer's office, else the end user's office, else (unissued) the office of the user who created the record.

## GSP Part VI – ICT in Schools

Everything the workbook needs beyond IRIS records comes from the GSP ICT Profile: school year, as-of date, level, Table 4 counts, Box 1 Q4 and reliability, and the cover signatories.

Sheets: **Cover** (title, school, table of contents, signature blocks), **Table 1**, **Table 1 Definitions**, **Tables 2 to 4** (each with its definitions), **Box 1**, **Box 1 Definitions**. The wording lives in `backend/resources/reports/gsp-ict-text.php`; `:period` there becomes "SY 2025–2026, as of March 31, 2026".

"Functional" = condition *Serviceable*, not flagged non-functional, disposition *Normal* (`Equipment::scopeFunctional()`).

| Section | Source |
|---|---|
| Table 1 | Desktop (package)/CPU, Laptop, Tablet, 2-in-1 Tablet (Tablet PC); linked peripherals not counted. *Learner*/*Teacher* (`used_by`) go under the chosen level, *Teaching-Related*/*Non-Teaching* under personnel. Items without `used_by` are left out. |
| Funding columns | DepEd DCP = `non_dcp` false; LGU/SEF = source LGU or fund SEF; PTA/Private = PTA, private corporation, NGO; Other Government Agencies = DICT, other NGA; DepEd Non-DCP = Central/Regional/Division Office or School; else Others. Table 2 folds all but DCP and Non-DCP into Others. |
| Table 2 | Standard TV (LED TV), Smart TV, Printer + Multifunction Printer, Projector, External Hard Drive, Lapel, Charging Cart. |
| Table 3 | DCP items with package *eLearning Cart Package*, by DCP year (the four years ending with the school year's start). |
| Table 4 | The profile's radio-based instruction counts per grade, with subtotals and totals. |
| Box 1 Q1, Q3 | The office's Internet Connectivity Survey, shown as ☑/☐. Unlisted providers/areas tick *Local provider* / *Other area/s*. |
| Box 1 Q2 | Active ISP accounts: provider, connection type, level (multi-level = *Shared*), purpose, monthly cost, funding source, subscribed speeds (falls back to `max_speed`), latest speed test, service quality per definition 9 (lower of speed and ping ratings), and the profile's reliability rating. |
| Box 1 Q4 | The profile's DICT Free WiFi answer and installation date. |

## RPCPPE

Parameters: `school_year`, optional `accountable_officer_id` (limits both equipment and School Property to that officer and prints their name and designation), `assumed_on`, `prepared_by` (property custodian), `school_head`, `supply_officer`.

One sheet per `PropertyCategory`, with a separate semi-expendable sheet (unit value under ₱50,000) for IT, Office and TLE equipment: **IT, IT Semi-Exp, Office Eqpt, Office Eqpt Semi-Exp, F&F, School Bldg, Other Structures, TLE, TLE Semi-Exp, Sci & Math, Sports-Music**, then **Books** and **F&F per Teacher**.

- **IRIS equipment**, one row per property number. Copiers, fax machines, microphones, cameras and voice recorders go to Office Equipment; PABX and computer tables to F&F; everything else to IT. Disposed, donated and transferred items are left out; lost and stolen items show on-hand 0.
- **School Property** items ([school-property.md](school-property.md)), one row per record with its quantity.
- Acquisition cost (`=unit value × balance`), shortage/overage (`=on hand − balance`) and its value are formulas; totals sum them.
- **Books**: learning area, grade, title, receipt (June), actual (March), variance.
- **F&F per Teacher**: furniture items with an end user, grouped by grade level (blank = *Subject Teachers / Others*), one column per article with summed quantities, plus a signature column.

## Tests

`backend/tests/Feature/{ReportControllerTest,GspIctProfileControllerTest}.php`.
