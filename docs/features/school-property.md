# School Property

Property counted in the RPCPPE that isn't kept as individual Equipment records: furniture, buildings, other structures, TLE, science and math, sports and music equipment, books and learning resources, and IT or office equipment that never went into the Equipment inventory. Page: **Records → School Property** (`/school-property`), with **one tab per RPCPPE sheet** (IT Equipment, Office Equipment, Furniture & Fixtures, School Buildings, Other Structures, TLE, Science & Math, Sports & Music, Books). **Add** pre-selects the open tab's category, **Export this tab** downloads the tab's filtered list, and **Download RPCPPE** builds the full report ([reports.md](reports.md#rpcppe)).

## Data

Table `property_items` (soft deletes, audited via `LogsActivity` as `property_item.created/updated/deleted`). One row may cover many identical units.

| Field | Notes |
|---|---|
| `office_id` | Required FK (restrict on delete). Scoped users are always forced to their own office. |
| `category` | `PropertyCategory` enum, one per RPCPPE sheet. Each category offers article suggestions from the division's RPCPPE lists (free text allowed). |
| `article`, `description`, `property_no`, `unit_of_measure`, `date_acquired` | For books, article = learning area and description = title/material. |
| `unit_value` | ₱50,000 and up goes on the PPE sheet, below that on the semi-expendable sheet (IT, Office, TLE). |
| `quantity` / `on_hand_count` | Balance per property card / latest physical count. Books: receipt (June) / actual (March). |
| `grade_level` | Kindergarten–Grade 12. Books: the grade the material is for. Furniture: the classroom's grade, used to group the per-teacher sheet. |
| `source` | `PropertySource`: Division, Regional/Central, LGU, PTA/Others (the RPCPPE "transferred / donations by" columns). |
| `condition` | `functional` or `unserviceable`. |
| `with_are`, `location`, `remarks` | |
| `accountable_officer_id`, `end_user_id` | Personnel of the item's office (validated). The end user is the teacher named on the furniture-per-teacher sheet. |

## Teachers

Teachers are Personnel records, never free text, so names stay consistent and transfers carry over. To build the RPCPPE furniture-per-teacher attachment, set **End user / teacher** and **Grade level** on Furniture and Fixtures items. Split a shared classroom's items into one row per adviser if both should be listed.

## API

| Method | Path | Permission |
|---|---|---|
| GET | `/api/property-items` (`search`, `category`, `office_id`, `sort`, `direction`, `per_page`) | `property_items.view` |
| GET | `/api/property-items/options[?office_id=]` | view, create or edit |
| GET | `/api/property-items/export` (same filters) | `property_items.view` |
| GET | `/api/property-items/{id}` | `property_items.view` |
| POST | `/api/property-items` | `property_items.create` |
| PUT | `/api/property-items/{id}` | `property_items.edit` |
| DELETE | `/api/property-items/{id}` | `property_items.delete` |

`PropertyItemPolicy` re-checks every action; records outside the role's data scope return 404. Export writes `property_item.exported` with the filters and row count. `2026_11_01_000003_add_property_item_permissions` grants view/create/edit/delete to roles holding the matching `equipment.*` permission.

## Tests

`backend/tests/Feature/PropertyItemControllerTest.php`.
