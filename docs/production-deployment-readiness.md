# Production deployment readiness gate

Run the audit before a pilot migration and again immediately before production cutover:

```powershell
php artisan platform:deployment-readiness --strict
```

Use `--json` in deployment automation. A non-zero exit code means deployment must stop. Without `--strict`, warnings are reported but only critical failures return a failing exit code.

The scheduler records a platform-safe heartbeat every minute, runs this audit daily at 07:45, and queue workers update a shared heartbeat whenever they start a job. The Platform Admin can also review history and run the audit from `/platform/deployment-readiness`.

The release gate checks production environment mode, disabled debugging, application encryption key, HTTPS URL, tenancy activation, database connectivity and least-privilege credentials, pending migrations, persistent queues, worker and scheduler heartbeats, failed jobs, writable framework storage, backup freshness, mail transport, and the presence of a platform administrator.

## Background workers

Imports, billing emails, SMS and WhatsApp messages are queued (`QUEUE_CONNECTION=database`) and only go out while a worker runs. Reminders, recalls, billing runs and heartbeats need the scheduler.

**Server:** install `deploy/supervisor/eyeclinic.conf` (instructions at the top of the file). It runs two `default` workers, one `imports` worker (imports can take up to 15 minutes), and `schedule:work`. Run `php artisan queue:restart` after every deploy.

**Local (Laragon):** `C:\laragon\usr\Procfile` starts `EyeClinic Queue Worker` when Laragon starts (Menu → Procfile to stop or restart it). The scheduler is deliberately not started locally: it would send real reminder and recall SMS to patients in imported data. Keep `MAIL_MAILER=log` locally so emails are written to `storage/logs/laravel.log`.

Queued mail keeps the mailer that was configured when it was queued; after changing `MAIL_MAILER`, older queued emails still use the old one.
