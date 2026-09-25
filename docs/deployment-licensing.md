# Clinic licensing

The registered clinic's `deployment_mode` selects the authority: `hosted` uses its subscription, while `local` verifies a signed license without network access. Tenancy does not select the licensing method. Settings → License & Subscription displays the appropriate controls.

## Offline issuance

The developer dashboard has an **Offline Licenses** tab. Select a local clinic, enter its installation UUID, plan name and expiry, then check the premium features and generate the key. At least one premium feature is required. Issuance is restricted to platform administrators and records metadata and a key fingerprint in the platform audit log.

Configure `OFFLINE_LICENSE_SIGNING_KEY=C:\secure\license-secret.txt` in the developer server environment, pointing to the existing private key file. Do not configure or distribute this file on clinic installations. If configuration is cached, rebuild it after setting this value. The page reports a configuration error until the matching signing key is available.

On the developer machine, use the existing Ed25519 secret key corresponding to `config/license.php`'s public key. The key file contains the base64 secret key. Never distribute it to clinic installations or commit it.

```powershell
php artisan license:issue INSTALLATION-UUID 2027-09-14 --signing-key=C:\secure\license-secret.txt --plan="Offline Standard" --feature=appointments --feature=inventory
```

Omit `--feature` to include all features. The command prints the signed key for delivery by email, messaging, or removable media. A clinic administrator pastes it into the license page. Existing signed Pro keys without feature claims retain all-feature access. Each installation needs its own key. Issuance requires the existing private key; this change does not generate or replace signing keys.

Local licenses and the existing 30-day trial have no grace period. Offline keys remain valid through the expiry date; hosted subscriptions end at their recorded expiry timestamp. Legacy grace settings do not extend access. At expiry, web writes are blocked while permitted GET pages and exports remain accessible. Renewal activation remains available. Approved search, pagination, report filters, and CSV/print actions are listed in `config/license_read_only.php`; other Livewire actions remain blocked. A connection-level guard rejects clinic-context INSERT, UPDATE, DELETE, REPLACE and TRUNCATE statements, including bulk query-builder writes. Authentication, audit, infrastructure and renewal records are exempt. API bookings are checked after resolving the clinic, tenant jobs are checked before execution, and scheduled tenant commands skip restricted clinics. SMS and WhatsApp sends check write access before contacting providers. Hosted access uses subscription features and dates; explicit suspension and cancellation retain their existing restrictions.

Local installations do not use hosted subscription quotas. Branches are supported under the local installation license. License feature checks use the signed feature list; this format does not encode per-user or per-branch quotas.

Offline verification cannot immediately observe remote revocation. Developer changes reach the installation through a replacement key. No automatic synchronization or network dependency is introduced.

## Reminders and history

Clinic pages show a renewal reminder within 30 days, including the exact cutoff and timezone, and a read-only banner after expiry. Hosted billing notifications use 30, 14, 7 and 1 day reminders. Offline reminders appear locally without internet access.

Developer Dashboard ? Offline Licenses includes an access overview for hosted and local clinics and recent issuance history. Local activation history records the administrator, installation ID, plan, features, expiry and key fingerprint. A developer may record a clinic-reported activation; this is explicitly not remote verification. Issuance metadata alone is never presented as confirmed installation state.
