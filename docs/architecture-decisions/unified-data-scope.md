# ADR: Unified role data scope

**Status:** Accepted (owner, 2026-10-26). Supersedes the Equipment-only data scope in [`features/roles-and-permissions.md`](../features/roles-and-permissions.md#data-scope) and the `.view_all` office rule in the [one-row-per-office pattern](../architecture.md#3-one-row-per-office-stakeholder-profile-internet-connectivity-survey).

## Context

`roles.data_scope` (`own`/`school`/`all`, migration `2026_10_20_170000`) only filtered Equipment, and it found "own" by matching `users.email` against `personnel.deped_email`. Stakeholder Profile and the Internet Connectivity Survey had a separate rule (`.view_all` permissions). Personnel and ISP Accounts weren't filtered at all. That gave two overlapping models for one question ("which records does this user reach?"), an access rule that depended on an editable email field, and `offices` holds division units (OSDS-*, CID-*, SGOD-*) as well as schools, so "school" was the wrong word.

## Decision

1. **One mechanism.** A role's `data_scope` decides which records its permissions reach, across Personnel, Equipment, ISP Accounts, Stakeholder Profile and the Internet Connectivity Survey. Permissions still decide which actions are allowed. Scope never grants an action. Stored values stay `own`/`school`/`all`. The display label for `school` is **"Own office"**.
2. **Explicit user-to-personnel link.** `personnel.user_id` (nullable, unique, FK `users`, `nullOnDelete`) replaces the email match. A migration backfills it from unambiguous email matches. `deped_email` no longer affects access.
3. **Reach per resource** (`all` is always unrestricted):

   | Resource | `own` | `school` (Own office) |
   |---|---|---|
   | Personnel | the linked record | personnel with `office_id` = user's office, plus the linked record |
   | Equipment | issued (officer or end user) to reachable personnel, or unissued and created by the user | same, and unissued items created by any user at the same office |
   | ISP Accounts | `office_id` = user's office | same |
   | Stakeholder Profile, Connectivity Survey | user's own office's row | same |

   The cross-office lists for Stakeholder Profile and the Connectivity Survey (index) are available only to `all`. Records with a null office or no link are visible only to `all`, so if the office data hasn't been backfilled yet, scoped users see less, not more.
4. **Equipment keeps scoping through personnel**, with no `equipment.office_id`. `equipment.created_by_user_id` (nullable FK) covers unissued items. The creator's office is read from `users.office_id` at request time and isn't stored on the item.
5. **Writes stay inside reach.** Transfer targets (officer, end user, received by) and the `/equipment/options` personnel list are limited to reachable personnel. Only `all` users may set or change `office_id` and `user_id` on Personnel, `office_id` on ISP Accounts, or the personnel link on a user. When a scoped user creates a Personnel record or an ISP account, its `office_id` is forced to the creator's office. `own` users cannot create Personnel, because the new record would be outside their reach as soon as it's saved.
6. **Out of reach = 404, missing permission = 403** (`Response::denyAsNotFound()`). A 404 means a scoped user can't tell whether an id exists.
7. **`.view_all` permissions are removed.** Their holders get `.view` + `.edit` instead, and their roles keep their current `data_scope`.
8. **Seeder no longer overwrites admin edits.** `RolePermissionSeeder` always syncs `super_administrator` (every permission, scope `all`) and creates every other default role only if it doesn't exist yet. Roles and permissions on existing databases change through migrations, the same way `2026_10_20_000002` did it, because the deploy script doesn't run seeders.
9. **Encoder split.** The existing `encoder` role is relabelled "Division Encoder" (scope `all`), so users who hold it keep their access. A new `office_encoder` role ("Office Encoder", scope `school`) gets the same permissions.

## Rejected alternatives

- **`equipment.office_id`.** It would be a second source of truth that drifts from who actually holds the item. The accountability-transfer pattern already says where an item belongs.
- **Keeping `.view_all` next to data scope.** That keeps two answers to the same question, and a role could hold `.view_all` with scope `own`.
- **A `DataScopeService` or a `Scopes/` layer.** The logic is a few methods on `User` (`dataScope()`, `scopedPersonnelIds()`, `reachesOffice()`) and a `visibleTo` scope on each model. At this size a service would just pass calls through.
- **Email match as a fallback for `own`.** Personnel editors can change `deped_email`, so anyone who could edit an email could grant access.

## Consequences

- An item that is returned to nobody (no officer, no end user) falls back to its creator's reach. A school encoder who didn't create the item stops seeing it. This is intended: returned items go back to division custody.
- Until the production office backfill runs, scoped users see little. All-scope admins are unaffected.
- A new permission added later doesn't reach `system_administrator` or the other non-protected roles automatically. The migration that adds the permission has to grant it.
- Deferred: retiring `personnel.sdo_office_id`, dropping `stakeholder_profiles.school_id`, missing office types, and the production backfill.

**Revisit if** a resource needs a reach rule that personnel, office or creator can't express (for example, regional or cluster-level scope), or if the number of scoped resources doubles. At that point, consider a dedicated scope abstraction.
