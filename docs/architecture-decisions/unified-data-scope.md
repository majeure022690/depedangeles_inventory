# ADR: Unified role data scope

**Status:** Accepted (owner, 2026-10-26). Supersedes the Equipment-only data scope in [`features/roles-and-permissions.md`](../features/roles-and-permissions.md#data-scope) and the `.view_all` office rule in the [one-row-per-office pattern](../architecture.md#3-one-row-per-office-stakeholder-profile-internet-connectivity-survey).

## Context

`roles.data_scope` (`own`/`school`/`all`, migration `2026_10_20_170000`) only filtered Equipment, and it found "own" by matching `users.email` against `personnel.deped_email`. Stakeholder Profile and the Internet Connectivity Survey had a separate rule (`.view_all` permissions). Personnel and ISP Accounts weren't filtered at all. That gave two overlapping models for one question ("which records does this user reach?"), an access rule that depended on an editable email field, and `offices` holds division units (OSDS-*, CID-*, SGOD-*) as well as schools, so "school" was the wrong word.

## Decision

1. **One mechanism.** A role's `data_scope` decides which records its permissions reach, across Personnel, Equipment, ISP Accounts, Stakeholder Profile and the Internet Connectivity Survey. Permissions still decide which actions are allowed. Scope never grants an action. Stored values stay `own`/`school`/`all`. The display label for `school` is **"Own office"**.
2. **User-to-personnel match by DepEd employee number** (amended 2026-10-26, see below). `users.employee_no` (nullable, unique, written only by the PRIME sync) matches `personnel.employee_id`. `User::personnel()` is a `hasOne` on those keys, and nothing is stored on `personnel`. `deped_email` doesn't affect access.
3. **Reach per resource** (`all` is always unrestricted):

   | Resource | `own` | `school` (Own office) |
   |---|---|---|
   | Personnel | the matched record | personnel with `office_id` = user's office, plus the matched record |
   | Equipment | issued (officer or end user) to reachable personnel, or unissued and created by the user | same, and unissued items created by any user at the same office |
   | ISP Accounts | `office_id` = user's office | same |
   | Stakeholder Profile, Connectivity Survey | user's own office's row | same |

   The cross-office lists for Stakeholder Profile and the Connectivity Survey (index) are available only to `all`. Records with a null office or no match are visible only to `all`, so if the office data hasn't been backfilled yet, scoped users see less, not more.
4. **Equipment keeps scoping through personnel**, with no `equipment.office_id`. `equipment.created_by_user_id` (nullable FK) covers unissued items. The creator's office is read from `users.office_id` at request time and isn't stored on the item.
5. **Writes stay inside reach.** Transfer targets (officer, end user, received by) and the `/equipment/options` personnel list are limited to reachable personnel. An out-of-reach target and a non-existent one get the same 422, so the error can't be used to probe ids. Only `all` users may set or change `office_id` on Personnel or ISP Accounts. `personnel.employee_id` decides whose record it is, so only `all` users may change it on an existing record. `users.employee_no` and `users.office_id` come from PRIME, and no IRIS request accepts them. `UserResource.personnel` (the matched record) is exposed only to `personnel.view` holders. **Scope tier:** nobody can create or edit a role scoped wider than their own, edit a role that is already wider, assign such a role, or manage an account holding one. Otherwise a scoped admin could widen their own reach. Admin permissions that aren't scoped (audit log, users list, reference data, offices, settings) should only be granted to `all` roles. When a scoped user creates a Personnel record or an ISP account, its `office_id` is forced to the creator's office. `own` users cannot create Personnel, because the new record would be outside their reach as soon as it's saved.
6. **Out of reach = 404, missing permission = 403** (`Response::denyAsNotFound()`). A 404 means a scoped user can't tell whether an id exists. Outside `local`/`testing`, every 404 body is the same generic `"Not Found."`, so the message doesn't give it away either.
7. **`.view_all` permissions are removed.** Their holders get `.view` + `.edit` instead, and their roles keep their current `data_scope`.
8. **Seeder no longer overwrites admin edits.** `RolePermissionSeeder` always syncs `super_administrator` (every permission, scope `all`) and creates every other default role only if it doesn't exist yet. Roles and permissions on existing databases change through migrations, the same way `2026_10_20_000002` did it, because the deploy script doesn't run seeders.
9. **Encoder split.** The existing `encoder` role is relabelled "Division Encoder" (scope `all`), so its holders keep their Personnel, Equipment and ISP reach. A new `office_encoder` role ("Office Encoder", scope `school`) gets the same permissions. Division Encoder **gains** reach: its `.view`/`.edit` on Stakeholder Profile and the Connectivity Survey used to cover only the user's own office, and under scope `all` they cover every office plus both lists. The owner accepted this because no production users held roles yet.

## Rejected alternatives

- **`equipment.office_id`.** It would be a second source of truth that drifts from who actually holds the item. The accountability-transfer pattern already says where an item belongs.
- **Keeping `.view_all` next to data scope.** That keeps two answers to the same question, and a role could hold `.view_all` with scope `own`.
- **A `DataScopeService` or a `Scopes/` layer.** The logic is a few methods on `User` (`dataScope()`, `scopedPersonnelIds()`, `reachesOffice()`) and a `visibleTo` scope on each model. At this size a service would just pass calls through.
- **Email match as a fallback for `own`.** Personnel editors can change `deped_email`, so anyone who could edit an email could grant access.
- **A stored link (`personnel.user_id`), whether set by an admin or written by the sync.** Superseded 2026-10-26. A link set by hand is a second, manually kept copy of a fact PRIME already holds. A link written by the sync is a cached copy of the key match and can go stale. Matching on the key is always current.
- **PRIME `users.user_emp_no_id`.** It is empty on every PRIME account. The employee number is on `employees.deped_id_no`, which is reached through `employees.user_id`.
- **Filling `users.office_id` only when it's empty.** A transfer recorded in PRIME would never reach IRIS, so the user would keep reach over their old office.

## Consequences

- An item that is returned to nobody (no officer, no end user) falls back to its creator's reach. A school encoder who didn't create the item stops seeing it. This is intended: returned items go back to division custody.
- Until the production office backfill runs, scoped users see little. All-scope admins are unaffected.
- A new permission added later doesn't reach `system_administrator` or the other non-protected roles automatically. The migration that adds the permission has to grant it.
- Deferred: retiring `personnel.sdo_office_id`, dropping `stakeholder_profiles.school_id`, missing office types, and the production backfill.

## Amendment (owner, 2026-10-26): employee number from PRIME replaces the stored link

- **Removed:** `personnel.user_id`, `personnel_id` on `PUT /api/users/{id}`, the `/users/options` personnel list, and the `user.personnel_linked`/`user.personnel_unlinked` events. Past audit rows are kept. Before dropping the column, the migration copies each existing link into `users.employee_no`, so `own` reach keeps working between the deploy and the first sync.
- **Employee number.** The PRIME sync follows PRIME `users.user_id` → `employees.user_id` → `employees.deped_id_no`, trimmed and compared as a string. A user gets a number only when (a) exactly one employee row with a number belongs to their PRIME account, and (b) no other PRIME employee or IRIS user resolves to the same number. Anything ambiguous stays null and is reported, not guessed.
- **Offices are tied to PRIME departments by id.** `offices.prime_department_id` (nullable, unique, no FK because PRIME is a different database) is never accepted by a request. `users:sync-external` syncs offices before users:
  - An unlinked office is linked once, when exactly one PRIME department has the same name (trimmed, case-insensitive, exact) and nothing else claims either side.
  - A PRIME department with no match gets a new IRIS office. Only on that new row, `office_type` is set from `departments.office_id` (1 = division office, 2 = school).
  - A linked office takes its `office_name` from `department_school` on every run. If another office already has that name, the rename is skipped and reported.
  - `office_type`, `school_id`, address, contacts and `is_active` stay IRIS data.
  - Offices with no PRIME department keep working as IRIS-only offices.
  - When a department disappears from PRIME, its IRIS office is reported. It is never deleted or deactivated automatically.
  - In IRIS, the name of a linked office is read-only and a linked office can't be deleted.
- **User office.** PRIME is authoritative for `users.office_id`. The sync reads `employees.department_school_id`, or `users.department_school_id` when the account has no employee row, and sets the office linked to that department. If no office is linked to it, the sync sets null, so a mismatch can only remove reach, never add it. `is_active` only controls pickers, not this mapping. Accounts with no PRIME row aren't touched. The Users page shows the office read-only.
- **Personnel office.** `personnel.office_id` stays IRIS data. A separate command, run by hand, fills it from the office linked to the matched employee's department, only where it's empty, and never overwrites a value.
- **Rejected: matching offices by name on every sync.** One rename on either side would quietly move every user in that office, or take their office away. With the id link, PRIME renames carry through and the match stays put.

**Revisit if** a resource needs a reach rule that personnel, office or creator can't express (for example, regional or cluster-level scope), or if the number of scoped resources doubles. At that point, consider a dedicated scope abstraction.
