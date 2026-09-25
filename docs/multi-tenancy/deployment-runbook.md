# Multi-clinic deployment runbook

## What happens to existing clinics

The migrations preserve existing IDs, references, dates, users, patients, money, and files. Each existing installation is converted into one clinic with one `Main Branch`; every existing user and record is assigned to it. The feature flag remains off during migration, so the current workflow continues while reconciliation is performed.

Offline installations remain single-clinic deployments and can create local branches. They do not participate in automatic two-way cloud synchronization. The legacy desktop copy command is not a safe synchronization protocol and must not be used to merge independently edited databases.

## Staged deployment

1. Put the application in maintenance mode and take a verified database plus uploaded-file backup.
2. Record the current patient count, stock quantities, sales totals, payment totals, and expense totals.
3. Deploy code with `TENANCY_CONTEXT_ENABLED=false` and run `php artisan migrate --force`.
4. Run `php artisan tenancy:reconcile --json`. Do not continue unless it exits successfully.
5. Verify the generated clinic, Main Branch, user memberships, public booking key, settings, and branch inventory opening balances.
6. Test login and one read-only page for Super Admin, Manager, Doctor, Secretary, and Cashier.
7. Set `TENANCY_CONTEXT_ENABLED=true`, then run `php artisan config:clear`, `php artisan cache:clear`, and restart queue workers/scheduler processes.
8. Test patient registration, consultation, POS checkout, refund, stock receipt, purchase order receipt, reports, uploads, and branch switching.
9. Run `php artisan tenancy:reconcile --json` again and compare the captured totals.
10. Leave the rollback backup untouched until the clinic signs off.

## Hosted setup

- Create each clinic and its first branch before inviting users.
- Give staff both an active clinic membership and at least one active branch membership.
- Share only a branch's random `public_booking_key` with that clinic's website. Send it as `X-Clinic-Booking-Key`; never expose numeric clinic or branch IDs.
- Booking conversion requires an authenticated Sanctum user who belongs to the booking branch.
- Full database backup/restore controls are hidden from hosted clinic administrators because a full installation backup contains every tenant.
- Run the scheduler once per deployment. Tenant-aware scheduled commands deliberately iterate active clinics/branches.

## Storage and offline notes

New patient documents are private and downloaded through an authenticated, tenant-scoped route. Branding and expense receipts use clinic/branch-prefixed paths. Existing public patient documents retain their recorded disk for backward-compatible access through the same protected download route.

For offline/cloud interoperability, use export/import packages only after a separate sync design adds global record UUIDs, an outbox, tombstones, idempotency, versioning, device identity, encryption, and conflict rules. Financial, inventory, and clinical amendments must be append-only rather than last-write-wins.

## Rollback

Before the feature flag is enabled, normal migration rollback is possible, though the verified pre-migration backup is preferred. After live multi-branch records exist, do not roll back the schema: disabling tenancy would combine branch visibility and is not a data migration strategy. Restore the complete pre-cutover database and files as one unit instead.
