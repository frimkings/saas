# Multi-Clinic and Multi-Branch Tenancy Audit

Status: implementation complete; staged cutover pending  
Prepared: 2026-09-06  
Scope: current Laravel application, online hosting, and NativePHP/offline deployment

Implementation checkpoint (2026-09-06): tenant ownership, backfill, constraints, scopes, branch inventory, transfers, public booking resolution, operational logs, private clinical documents, tenant caches, tenant-aware scheduling, administration, switching, and reconciliation are implemented. Existing data reconciles with no orphan or cross-branch mismatches. Production activation remains an explicit deployment step using `TENANCY_CONTEXT_ENABLED=true` after the runbook checks pass.

## 1. Objective

Convert the current single-clinic application into a hosted multi-tenant platform where:

- a clinic organization is a tenant;
- a clinic can operate one or more branches;
- clinic data is isolated from every other clinic;
- patients and clinical history can be shared within one clinic;
- operational and financial records remain attributable to the branch where they occurred;
- existing installations migrate in place to one clinic and one default branch; and
- offline installations continue to work without requiring unsafe two-way synchronization.

This document is an audit and design gate. It does not authorize production schema changes.

## 2. Current-state summary

The application is currently single-tenant.

- There are no `clinics`, `branches`, clinic memberships, branch memberships, or tenant keys.
- Most queries start directly from a model and have no clinic or branch constraint.
- `Setting::getSettings()` and `Setting::first()` assume one global clinic record.
- Patient numbers, transaction IDs, order IDs, product names, batches, and several financial constraints are globally unique.
- Product identity and inventory quantity are combined in the `products` table.
- Roles and permissions use Spatie Permission with `teams` disabled.
- cache keys such as dashboard totals, reports, settings, and lens options are global.
- scheduled commands process all matching records in the database.
- documents, logos, avatars, and expense receipts are stored without tenant-prefixed paths.
- reports, receipts, PDFs, notifications, backups, and audit archives have no tenant context.
- the public booking endpoint has no clinic/branch routing mechanism.
- the public booking conversion endpoint is currently public and must be protected before a hosted rollout.
- the NativePHP desktop copy command replaces the local SQLite contents with every table and row from MySQL. It is a one-way full copy, not conflict-safe synchronization.

## 3. Ownership policy

### Platform-wide

Data controlled by the software operator and safe to share as definitions:

- canonical permission definitions;
- platform plans/features and release metadata;
- system migration history;
- platform administrators;
- global job-failure infrastructure, provided payloads retain tenant context.

### Clinic-wide

Data shared by authorized branches inside one clinic:

- clinic identity, subscription, licensing, and clinic-wide preferences;
- patient identity and longitudinal clinical record;
- clinic staff membership and base roles;
- diagnoses, drugs, lens options, message templates, and reusable referral snippets;
- insurer directory and supplier directory when the clinic chooses a shared directory;
- product catalogue definitions, excluding stock quantities and lots;
- clinic-wide audit visibility and consolidated reports.

### Branch-specific

Data that represents an event, obligation, resource, or workflow at a physical branch:

- appointments, arrivals, queues, clearances, consultations, and refractions;
- spectacle/lens orders and dispensing;
- stock on hand, batches, movements, counts, purchase orders, and transfers;
- carts, sales, sale items, payments, discounts, refunds, and adjustments;
- quotations, expenses, cash summaries, insurance claims, and income statements;
- operational notifications, report deliveries, and branch-specific settings.

### User-specific

- login sessions and access tokens;
- user profile and avatar;
- selected active clinic and branch;
- login history;
- direct messages, notification read state, and personal preferences.

User-specific data must still carry clinic context when it was generated while working for a clinic.

## 4. Table-by-table classification

The recommended keys below are additive during migration. Required keys remain nullable until legacy backfill and reconciliation complete.

| Current table/group | Ownership | Required target treatment |
|---|---|---|
| `users` | Platform identity | Keep global identity; add clinic and branch membership pivots. Do not use one `clinic_id` if a person may work for multiple clinics. |
| `roles`, `model_has_roles`, `model_has_permissions` | Platform + clinic | Keep permissions global; scope role assignments by clinic. Evaluate Spatie teams using `clinic_id`; branch access remains a separate membership concern. |
| `permissions`, `role_has_permissions` | Platform | Retain canonical permission names; allow clinic-scoped custom roles later. |
| `patients` | Clinic | Add `clinic_id` and optional `home_branch_id`; make patient-number uniqueness clinic-aware. |
| `patient_documents` | Clinic/clinical | Add `clinic_id`; derive branch from consultation when present; use private tenant-prefixed storage. |
| `cashier_patient_clearances` | Branch | Add `clinic_id`, `branch_id`; replace patient/date uniqueness with tenant-aware business rules. |
| `consultations`, `consultation_notes`, `consultation_diagnosis` | Branch clinical event | Add/derive `clinic_id`, `branch_id`; preserve clinic-wide patient visibility. |
| `refractions` | Branch clinical event | Add `clinic_id`, `branch_id`; normally inherit from consultation. |
| `referrals` | Branch clinical event | Add `clinic_id`, `branch_id`; retain issuing branch on printed letters. |
| `appointments` | Branch | Add `clinic_id`, `branch_id`; doctor availability and conflicts must be branch/timezone aware. |
| `online_bookings` | Intended branch | Require a clinic-facing public identifier and branch/service selection; never infer the first user. |
| `lens_orders` | Branch | Add `clinic_id`, `branch_id`; make order reference unique within clinic or globally generated. |
| `spectacles` | Legacy/unclear | Determine whether actively used; migrate to branch ownership or retire through a separate verified migration. |
| `diagnoses`, `drugs`, `lens_options` | Clinic catalogue | Add `clinic_id`; optionally support immutable platform defaults copied/overridden per clinic. |
| `categories` | Clinic catalogue | Add `clinic_id`; change name uniqueness to `clinic_id + name`. |
| `products` | Mixed today | Split clinic-wide catalogue identity from branch inventory and inventory lots. Remove quantity as the authoritative branch balance. |
| `stocks` | Legacy/unclear | The `Stock` model currently maps to `stock_movements`; verify whether the original `stocks` table is unused before retirement. |
| `stock_movements` | Branch | Add `clinic_id`, `branch_id`; use immutable movements and tenant-aware reference numbers. |
| `suppliers` | Clinic by default | Add `clinic_id`; attach branch where a supplier relationship is local. |
| `purchase_orders`, `purchase_order_items` | Branch | Add tenant keys to header; items inherit and must reference catalogue/branch inventory safely. |
| `quotations`, `quotation_items` | Branch | Add tenant keys to header; preserve issuing branch and currency. |
| `carts`, `orders` | Branch | Add tenant keys; carts and sessions must be invalidated or switched safely when active branch changes. |
| `sales`, `sale_items` | Branch financial | Add tenant keys to sale; items inherit. Scope transaction/idempotency uniqueness to the intended tenant boundary. |
| `payment_transactions` | Branch financial | Add or reliably derive tenant keys from sale; retain collecting branch and user. |
| `sale_adjustments`, `refund_logs`, `discount_approval_requests` | Branch financial | Add/derive tenant keys; approvers require authority in the sale's clinic and permitted scope. |
| `clearance_revoke_logs` | Branch audit | Add/derive tenant keys from clearance. |
| `expenses`, `expense_categories` | Branch + clinic catalogue | Expenses require branch; categories are clinic-wide unless explicitly branch-local. |
| `income_statement_entries`, `income_statement_period_locks` | Branch financial | Add tenant keys; period locks must be unique per branch and period. |
| `income_statement_templates` | Clinic | Add `clinic_id`; permit clinic templates with optional branch overrides. |
| `insurers` | Clinic directory | Add `clinic_id`; avoid cross-clinic exposure of contracts and contacts. |
| `insurance_claims` | Branch financial/clinical | Add tenant keys; preserve service branch and patient clinic. |
| `settings` | Mixed today | Split clinic settings, branch settings, and platform configuration. Eliminate `first()` semantics. |
| `sms_templates`, `referral_snippets` | Clinic | Add `clinic_id`; optional branch override only where required. |
| `sms_logs`, `report_deliveries` | Clinic/branch operation | Add `clinic_id` and nullable `branch_id`; record the exact scope used. |
| `app_notifications`, `staff_messages` | Clinic + user | Add `clinic_id`; branch may be nullable for clinic-wide messages. |
| `audit_trails` | Clinic security | Add mandatory `clinic_id` and contextual `branch_id`; tenant scope must survive polymorphic targets. |
| `login_logs` | User/platform security | Add nullable clinic/branch context because login can occur before branch selection. |
| archive tables | Same as source | Carry the same tenant keys before any further archival runs. |
| `password_reset_requests`, `password_resets` | User/platform | Keep identity-scoped; include clinic context only for approval workflows. |
| `sessions`, `personal_access_tokens` | User/platform | Store active clinic/branch in server-side session/token abilities; rotate context safely. |
| `settings` license fields | Clinic subscription | Move to clinic/subscription records; offline licenses bind to a clinic installation identifier. |
| `system_health_statuses` | Deployment + clinic | Add installation and clinic identity so online and offline health cannot collide. |
| `failed_jobs` | Platform infrastructure | Ensure serialized jobs contain tenant identity and workers restore/clear context for each job. |

## 5. Proposed foundation schema

### `clinics`

- `id` bigint primary key for local relations;
- `uuid` globally unique and immutable;
- `name`, `slug`, status, deployment mode, default timezone and default currency;
- subscription/license attributes or a relation to a later subscription table;
- timestamps and soft deletion where appropriate.

### `branches`

- `id`, globally unique `uuid`;
- required `clinic_id`;
- unique clinic-local `code`;
- name, address, contact details, timezone, status, and receipt prefix;
- one explicit default branch per clinic.

### `clinic_user`

- `clinic_id`, `user_id`, membership status;
- employment/staff identifier where it is clinic-specific;
- joined/left timestamps;
- unique `clinic_id + user_id`.

### `branch_user`

- `branch_id`, `user_id`;
- access status and optional default-branch flag;
- unique `branch_id + user_id`;
- application validation that branch and user membership belong to the same clinic.

### Inventory separation

- `products`: clinic catalogue identity, description, category, default pricing, active status;
- `branch_inventory_items`: branch/product availability and reorder policy;
- `inventory_lots`: branch, batch, expiry, cost, and remaining quantity;
- `stock_movements`: immutable receipt, sale, refund, adjustment, and transfer ledger;
- `stock_transfers`: source branch, destination branch, in-transit workflow and audit trail.

The existing `products.quantity` value remains authoritative during compatibility mode. It is reconciled into opening branch inventory before cutover.

## 6. Tenant resolution and enforcement

Create a request-scoped `TenantContext` containing:

- authenticated user;
- clinic;
- active branch, when a branch-scoped operation is required;
- authorized branch IDs;
- whether the request is platform administration, clinic-wide, or branch-specific.

Resolution order:

1. authenticate the user or validate a clinic-specific public integration credential;
2. resolve clinic membership;
3. resolve active branch from server-side session or signed route context;
4. verify branch membership and active status;
5. bind context for the request;
6. clear context after requests and queued jobs.

Enforcement must exist at multiple layers:

- middleware rejects absent or unauthorized context;
- model scopes protect normal Eloquent reads;
- model creation hooks assign tenant keys from context, never hidden form fields;
- policies verify tenant ownership for route-bound models and actions;
- services and repositories scope raw query-builder operations;
- unique rules include tenant columns;
- jobs, commands, exports, mail, notifications, and caches explicitly carry tenant context;
- tests attempt cross-tenant access using IDs, UUIDs, URLs, payloads, files, and queued work.

Global scopes alone are insufficient because this project uses raw queries, console commands, route closures, polymorphic audit records, and direct model lookups.

## 7. Roles and branch access

Recommended first release:

- platform administrator is distinct from clinic Super Admin/Owner;
- roles and permissions are assigned in clinic context;
- branch membership controls where a user may operate;
- role meaning remains consistent across a clinic initially;
- branch-specific role overrides are deferred until a demonstrated requirement exists.

Spatie teams can scope role assignments using `clinic_id`, but enabling it requires an explicit migration and careful cache reset. It does not replace `branch_user`.

Single-branch users receive their only branch automatically and see no branch switcher. Multi-branch users select among authorized branches. Switching branch clears branch-sensitive carts, cached page state, and temporary workflow state.

## 8. Identifiers and uniqueness

Preserve every historical identifier. New records should use immutable UUIDs for interchange and human-readable references for staff.

Recommended uniqueness:

- patient number: unique within clinic, with UUID as global identity;
- transaction and receipt reference: unique within clinic and prefixed by branch;
- spectacle order reference: unique within clinic and prefixed by branch;
- product/category names: unique within clinic, not platform-wide;
- batch/lot identity: unique within branch/product/supplier context;
- idempotency keys: include clinic and operation scope;
- period locks: unique by clinic, branch, and reporting period.

Do not encode mutable database IDs as the only offline interchange identity.

## 9. Query and integration hotspots

The following areas require explicit conversion and dedicated tests:

- every Livewire component that queries patients, queues, sales, inventory, approvals, or reports;
- receipt, refund, referral, medical record, income statement, and report export controllers;
- public booking creation and conversion;
- birthday, recall, appointment, spectacle renewal, report, and visit-bill scheduled commands;
- financial report and messaging services;
- settings and license lookup;
- patient-document, logo, avatar, and expense-receipt storage;
- audit creation and archive jobs;
- dashboard/report/lens-option/permission cache keys;
- backup creation, download, restore, pruning, and external-drive copies;
- Sanctum tokens, route model binding, and download routes;
- notification and messaging recipient queries.

Before hosted launch, the booking conversion route must require authenticated clinic staff or a narrowly scoped signed/API credential. Public booking creation must resolve an intended clinic/branch through a non-guessable public booking key or clinic-specific hostname—not a client-submitted numeric tenant ID.

## 10. Cache, storage, queue, and scheduler requirements

### Cache

All clinic-derived keys use a namespace such as:

`clinic:{clinic_uuid}:branch:{branch_uuid}:feature:{key}`

Clinic-wide keys omit the branch segment. Never cache Eloquent results under the current global keys after tenancy is enabled.

### Storage

Clinical documents must not remain publicly enumerable. Target paths:

- `clinics/{clinic_uuid}/patients/{patient_uuid}/documents/...`
- `clinics/{clinic_uuid}/branches/{branch_uuid}/expenses/...`
- `clinics/{clinic_uuid}/branding/...`
- `users/{user_uuid}/avatars/...`

Downloads use authorization-controlled routes or temporary signed object-store URLs.

### Queues and scheduled commands

Jobs carry `clinic_id` and optional `branch_id`. Workers establish context before handling and clear it afterward. Scheduled commands iterate active clinics deliberately, acquire tenant-specific overlap locks, and record per-tenant success/failure.

## 11. Online and offline boundary

Release one should support two modes:

1. hosted multi-tenant cloud;
2. local single-clinic installation with one or more local branches and encrypted backups.

The current `clinic:sync-desktop` command must not be used as multi-branch synchronization. It truncates/replaces tables, has no change ledger, no conflict rules, no tombstones, and no tenant filter. The configured `nativephp` connection also needs verification because it is referenced by the command but is not defined in the inspected database configuration.

True two-way offline sync is a later product:

- UUIDs on all synchronized aggregates;
- installation/device identity;
- append-only change/outbox log;
- server idempotency keys;
- per-record version or logical clock;
- deletion tombstones;
- deterministic ownership and conflict rules;
- encryption, retry, audit, and revocation;
- explicit treatment of financial and stock conflicts.

Financial postings, inventory movements, refunds, and clinical amendments should use append-only conflict policies rather than last-write-wins.

## 12. Existing-clinic migration

For each installation:

1. take database and uploaded-file backups;
2. record schema version and application version;
3. create one clinic with immutable UUID;
4. create one default branch with immutable UUID and code;
5. attach all users to the clinic and default branch;
6. backfill clinic-wide tables;
7. backfill branch event/financial tables;
8. derive child tenant keys from their parent where possible;
9. migrate file paths or maintain a verified compatibility map;
10. run reconciliation and orphan checks;
11. smoke-test every staff role;
12. retain a tested rollback package until clinic sign-off.

Legacy IDs, dates, references, soft deletes, audit records, and monetary values must not be regenerated.

## 13. Reconciliation baseline

Capture before and after counts and totals per clinic:

- active and deleted patients;
- appointments by status;
- clearances, consultations, refractions, referrals, and lens orders;
- products by status and total quantity by product/batch;
- stock movement net quantity;
- sales count, gross total, discount total, paid total, balances, refunds, and adjustments;
- payment transaction total compared with sale amount paid;
- expenses and income statement entries;
- insurance claims by status and amount;
- documents by database count, object count, and total bytes;
- users, role assignments, notifications, messages, and audit records;
- orphaned foreign keys and duplicate tenant-local identifiers.

Financial reconciliation must use stored decimal values and compare both aggregate totals and record-level invariants.

## 14. Principal risks

| Risk | Current evidence | Required control |
|---|---|---|
| Cross-clinic data leak | Unscoped model queries and route binding | Context middleware, scopes, policies, service-level constraints, adversarial tests |
| Wrong clinic settings/branding | `Setting::first()` and global static currency cache | Clinic-keyed settings service and cache |
| Incorrect dashboard/report totals | Global queries and cache keys | Tenant-aware query scopes and cache namespaces |
| Stock corruption | Quantity stored directly on product | Branch inventory ledger, opening-balance reconciliation, transactional movements |
| Duplicate identifiers | Global and mixed unique constraints | Explicit tenant-aware constraints and UUIDs |
| Public conversion abuse | Booking conversion route lacks authentication | Protect conversion; tenant-bound public booking credential |
| File exposure | Public disk and non-tenant paths | Private storage, tenant-prefixed keys, authorized downloads |
| Background cross-processing | Commands iterate all records | Per-clinic scheduler loop and context-bearing jobs |
| Unsafe offline merge | Desktop command copies/truncates all data | Keep as controlled one-way bootstrap only; design sync separately |
| Restore across tenants | Backups represent entire installation | Platform-only full restore; tenant-scoped export/import with validation |

## 15. Delivery sequence and gates

### Phase 0 — baseline

- approve this ownership policy;
- stabilize and commit the current application;
- capture full test, schema, data, and file baselines;
- create two-clinic security fixtures.

Gate: no schema work until ownership questions are approved.

### Phase 1 — additive foundation

- create clinics, branches, and membership tables;
- create `TenantContext` and context middleware behind a feature flag;
- seed one clinic/default branch for existing installations;
- add architecture tests without changing normal UI behavior.

Gate: existing single-clinic workflows and tests remain green.

### Phase 2 — nullable tenant keys and backfill

- add keys in domain groups;
- backfill parent tables, then children;
- add non-unique indexes;
- generate reconciliation reports.

Gate: zero unassigned in-scope records and all reconciliations pass.

### Phase 3 — enforced isolation

- scope models, policies, services, routes, jobs, files, caches, and exports;
- protect public integrations;
- add cross-tenant attack tests.

Gate: Clinic A cannot observe or mutate Clinic B through any tested surface.

### Phase 4 — branch operations

- introduce branch selection and memberships;
- split inventory from product catalogue;
- make operational workflows and references branch-aware;
- add consolidated reporting.

Gate: branch totals reconcile to clinic totals without duplication.

### Phase 5 — pilot and hardening

- migrate a production copy;
- test rollback;
- pilot one clinic;
- make required keys non-null;
- add tenant-aware unique constraints and remove compatibility fallbacks.

Gate: signed pilot reconciliation and operational acceptance.

### Phase 6 — offline evolution

- retain independent local deployments first;
- design and threat-model synchronization separately;
- pilot read-only or one-way exchanges before two-way sync.

## 16. Decisions required before Phase 1

1. Can one login belong to more than one independent clinic, or only multiple branches of one clinic?
2. Is a patient shared across all branches of a clinic by default?
3. Can a clinic restrict sensitive patient records to selected branches?
4. Are product prices clinic-wide, branch-specific, or both?
5. Are diagnoses, drugs, insurers, suppliers, and templates centrally managed or clinic-owned?
6. Does each branch require its own legal receipt sequence, currency, timezone, tax settings, and bank/mobile-money accounts?
7. Can managers approve refunds/discounts across all branches or assigned branches only?
8. Which offline behavior is required: independent local use, periodic one-way upload, or genuine two-way operation?
9. Will existing online clinics share one database, or will enterprise clinics be offered dedicated databases?
10. What is the required recovery point, recovery time, retention, and data residency policy?

## 17. Recommended next task

The foundation, disabled request resolver, and first clinic-owned catalogue group are complete. The next task is patient identity and branch-origin groundwork:

- add nullable `clinic_id` and `home_branch_id` to patients;
- backfill existing patients to the default clinic and branch;
- preserve UUIDs and existing patient numbers;
- prepare clinic-aware patient-number uniqueness without changing historical identifiers;
- add cross-clinic patient lookup and route-binding tests.

Consultations and other clinical events should remain unchanged until patient ownership and reconciliation are verified.
