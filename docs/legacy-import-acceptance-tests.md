# Legacy Clinic Import Acceptance Suite

Run the production-style acceptance gate with:

```powershell
php artisan test tests\Feature\LegacyImportEndToEndAcceptanceTest.php
```

It can also be selected from the complete test inventory with `php artisan test --group=legacy-import-acceptance`.

The suite is destructive only to the dedicated `eyeclinicproject_testing` database. `tests/TestCase.php` refuses to run it against any other database. MySQL must be running, the `mysql` client must be on `PATH`, and the configured legacy-import administrative account must be able to create and drop temporary `legacy_analyze_*` and `legacy_*` databases.

The deterministic fixture is `tests/Fixtures/legacy-clinic.sql`. The primary scenario restores that dump through the production analyzer, deliberately interrupts the first import during patient copy, confirms the transaction removed partial clinics and users, retries the same batch, and verifies mappings, memberships, validation, readiness, cutover, monitoring, CSV/PDF downloads, evidence integrity, cross-clinic drift detection, lifecycle closure, and rollback protection.

This suite should run before releasing changes to tenancy, authentication, patients, consultations, POS, inventory, subscriptions, background jobs, or the Legacy Clinic Import Manager. It is intentionally slower than unit tests because each scenario rebuilds the Laravel test schema and exercises real MySQL staging databases.
