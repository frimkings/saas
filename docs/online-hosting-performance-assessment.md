# Online hosting performance assessment

Assessment date: 1 October 2026. Scope: current local working tree, including existing uncommitted changes.

**Assessment:** The application has a useful foundation for hosted operation, but report generation and background-work behavior need attention before increasing tenant volume. The largest visible opportunities are reducing the number of records loaded for summaries, removing a confirmed query-inside-loop pattern, bounding exports, and isolating expensive background work from interactive requests. Adding server capacity alone would leave these growth patterns in place.

This is a static source assessment, not a production benchmark. No production database, query execution plans, actual index inventory, request traces, or hosting metrics were available to this review. No application code, database data, migrations, or configuration were changed. Migration files show intended schema, not proof that indexes are deployed. Priorities reflect code structure and likely exposure, not measured milliseconds or guaranteed savings.

**Prioritized findings**

P1 = address before substantial hosted growth; P2 = next optimization cycle; P3 = subsequent efficiency work. Effort is relative: S = localized change, M = several coordinated changes, L = architectural or reporting refactor.

| ID | Priority | Finding | Main consequence | Effort |
|---|---|---|---|---|
| 1 | P1 | Optical reports load full detail for summaries | PHP memory and database transfer grow with history | L |
| 2 | P1 | Legacy optical costing queries products inside a loop | Query count grows with allocation count | S |
| 3 | P1 | Sales PDF exports are synchronous and unbounded | Expensive requests occupy web workers | M |
| 4 | P1 | Messaging falls back to inline delivery; heartbeat is not queue-specific | Gateway delays can reach users; healthy imports can conceal stopped messaging workers | M |
| 5 | P1, conditional | Hosted queue/cache configuration must be coordinated | Multi-node inconsistencies and duplicate long jobs after a naive Redis switch | M |
| 6 | P2 | Owner summaries and tenant scheduled work run serially | Completion time grows across clinics and branches | M |
| 7 | P2 | Reporting indexes need workload-specific verification | Tenant/date scans remain possible in high-volume tables | M |
| 8 | P2 | Every optical report render computes all report sections | Repeat work for each component update | M |
| 9 | P2 | Global request bootstrap performs settings/schema work | Small repeated costs multiply across pages and polling | S–M |
| 10 | P2 | Polling can overlap slow requests | Avoidable sustained load during latency incidents | S |
| 11 | P2 | POS search uses contains matching and counted pagination | Search becomes more expensive as catalogues grow | M |
| 12 | P3 | Shared layouts load many widget libraries globally | Larger cold page loads on slower connections | M |

**1. Separate report totals from report detail**

Evidence: `app/Services/OpticalReportService.php`, `cash()` around line 107 and `owed()` around line 148; `app/Services/OpticalProfitService.php`, `statement()` at line 30; `app/Services/OwnerSummaryService.php:143`.

`cash()` retrieves all matching payments and refunds, then groups and sums them in PHP. `owed()` retrieves every outstanding lens order and related patient/consultation records, maps them into detail rows, and derives totals and age buckets from that collection. Owner summaries call `owed()` even though they consume totals and ageing figures rather than the customer list. Profit statements similarly hydrate orders, retail sales, expenses, and related products before calculating many totals. Owner payment summaries fetch all payment rows just to sum and group them.

These are confirmed unbounded result sets. Eager loading prevents some N+1 queries, but it does not bound memory usage. Large tenants and long periods amplify the cost.

Recommended change: introduce SQL aggregate methods for payment methods, staff totals, dates, balances, ageing buckets, revenue, and expense categories. Keep detail behind paginated queries. Use subqueries or joins instead of materializing large ID collections for `whereIn`. Batch the parts of historical costing that genuinely require PHP. Combine the clinic statement's separate revenue and cost scans where their identical joins and filters allow it (`app/Services/Finance/ClinicStatementService.php:44–63`).

Validate by comparing old/new monetary results for refunds, partial payments, cancellation fees, remakes, historical costs, branch scopes, and timezone boundaries. Measure peak memory for 1,000, 10,000 and 100,000 matching rows; summary response size should remain bounded even as matching data grows.

**2. Remove the confirmed legacy costing N+1 query**

Evidence: `app/Services/OpticalProfitService.php:137–139`. The nested sums over `lens_blank_allocations` call `OpticalProduct::withTrashed()->find(...)` for each allocation.

For A allocations, this path can issue A product lookups in addition to the report's other queries. Repeated product IDs still produce repeated lookups.

Collect unique product IDs, fetch them in one query or bounded batches, key them by ID, and perform cost lookup in memory. Preserve tenant scope, soft-deleted product handling, missing-product behavior, and the existing quantity/cost fallback. A focused regression check should demonstrate that repeated allocations no longer increase query count linearly.

**3. Bound and queue large PDF exports**

Evidence: `app/Http/Controllers/ReportExportController.php:11–76`. User-supplied dates feed an unrestricted `get()` with nested sale-item/product/category relationships, followed by Dompdf rendering in the HTTP request. No maximum range or row threshold is enforced in this method.

Provide strict date validation and a measured threshold for synchronous export. Queue larger exports, save the result privately, and return a completion/download workflow that rechecks tenant authorization. Use chunked/streamed CSV for large raw data exports. Merely chunking database reads will not solve memory pressure if one enormous HTML/PDF document is still assembled afterward; split large PDFs or offer summarized output.

The existing clinic report CSV path already uses chunked processing (`app/Livewire/ReportsComponent.php`, around line 476); reuse that bounded approach where appropriate. Queueing expensive work is supported by [Laravel's queue documentation](https://laravel.com/framework/docs/13.x/queues).

**4. Keep hosted messaging independent of request latency**

Evidence: `app/Services/Messaging/MessageDispatcher.php:22–40`, `app/Services/SmsService.php:159`, `app/Services/WhatsAppService.php:68`, and `app/Providers/AppServiceProvider.php:76–83`.

Messaging is queued only when hosted mode, a non-sync driver, and a recent worker heartbeat all pass. Otherwise it is sent inline. WhatsApp's HTTP client allows a 15-second timeout. A stopped worker or unavailable heartbeat can therefore turn messaging into slow web work.

The heartbeat is also shared by all looping workers. An `imports` worker can keep it fresh even if no worker is consuming `default`. Conversely, a worker busy inside a long job does not execute its next loop heartbeat until that job finishes.

For hosted mode, durably enqueue delivery or explicitly return a retryable unavailable result if durable storage fails. Show queued/delayed/failed status instead of silently shifting delivery into the request. Monitor heartbeats and oldest-job age by connection and queue. Preserve credit reservation/refund behavior and after-commit dispatch. Do not return a successful delivery status merely because a job was accepted.

**5. Establish a coherent hosted configuration**

Evidence: `.env.example` uses `QUEUE_CONNECTION=sync`, `CACHE_DRIVER=file`, `SESSION_DRIVER=file`, and `APP_DEBUG=true`. These are template values, not a finding about the actual hosted environment. `config/cache.php` specifically reads `CACHE_DRIVER`; setting only `CACHE_STORE` would not configure this application's default store.

File cache/session may be adequate on one persistent server, but separate web and worker nodes need shared state for sessions, cache invalidation, locks, and heartbeat visibility. Consider shared Redis cache/session and a properly operated asynchronous queue. Size PHP-FPM concurrency using measured worker memory and database connection capacity. Enable OPcache and deploy optimized configuration/routes/views; disable production debug output. These deployment practices are described in [Laravel's deployment documentation](https://laravel.com/framework/docs/13.x/deployment).

Important configuration coupling: `deploy/supervisor/eyeclinic.conf` explicitly runs `queue:work database`, while `config/queue.php` gives database jobs a 960-second retry interval and Redis jobs only 90 seconds. The import worker timeout is 930 seconds, with a documented import job timeout of 900 seconds. Changing only `QUEUE_CONNECTION=redis` would leave workers consuming the old backend; updating the worker alone without changing Redis retry timing could allow a long job to become available again before its first execution finishes. Configure each connection, worker, job timeout, and retry interval together. Laravel requires worker timeout to be shorter than retry-after; see [job expiration and timeout guidance](https://laravel.com/framework/docs/13.x/queues#job-expirations-and-timeouts).

**6. Dispatch bounded work per clinic or branch**

Evidence: `app/Console/Commands/SendOwnerSummariesCommand.php:21`, `app/Console/Commands/RunTenantCommand.php:31`, `app/Services/OwnerSummaryService.php:105–110`, `app/Services/OwnerMailer.php:81`, and `app/Console/Kernel.php`.

The commands iterate clinics/branches and execute work serially. Owner summaries calculate current and previous figures per branch and send mail synchronously. Iteration is chunked by Eloquent's `each()`, which helps memory, but elapsed time still accumulates across tenants. Several commands become due at the same hourly/daily boundaries. Scheduled commands lack background execution here, so a long command can delay later due commands in the scheduler invocation.

Have scheduled commands dispatch small, idempotent per-tenant jobs into separately capacity-limited queues. Preserve explicit tenant-context restoration and cleanup. Schedule only one active scheduler or add deliberate distributed single-run coordination. Existing `withoutOverlapping()` helps prevent overlap but does not by itself make the work fast or provide a complete multi-node scheduling design.

Before parallelizing owner mail, fix its concurrency boundary: `OwnerMailer` checks `firstOrNew`, sends, and only then saves. A unique dedupe key at save time cannot prevent two concurrent executions from sending the same mail first. Atomically claim the work before delivery, with recovery for abandoned claims and provider idempotency where available.

**7. Verify composite indexes against actual queries**

Evidence: the existing `2026_10_01_000001_add_tenant_date_indexes.php` adds tenant/date indexes to seven tables. Earlier migrations already add standalone dates, financial indexes, and notification/message recipient/read indexes. Do not treat the database as unindexed or add duplicates indiscriminately.

Candidates for plan testing include `(clinic_id, branch_id, created_at)` on `payment_transactions` and `lens_orders`, and tenant/date or tenant/status/date combinations for processed refunds and cancelled orders. These are candidates, not approved DDL: inspect all deployed indexes, global-scope predicates, selectivity, and write overhead first. Choose equality columns followed by the useful range/order columns for each concrete query. Clinic-wide queries without a branch predicate may need a different index from branch-specific queries because of leftmost-prefix behavior; see [MySQL composite index documentation](https://dev.mysql.com/doc/refman/8.4/en/multiple-column-indexes.html).

Capture representative SQL and use `EXPLAIN`, then `EXPLAIN ANALYZE` on staging where execution is safe. Compare rows examined, rows returned, sort/temp-table work, and elapsed time. Index builds on large hosted tables need an operational rollout plan. Rewriting every `DATE()` expression is unnecessary: date functions in grouping are different from wrapping an indexed column in a WHERE predicate.

**8. Compute only the report sections needed**

Evidence: `app/Livewire/Optical/OpticalReportsComponent.php:20–48`. Every render calls sales and cash for both current and previous periods, plus owed balances, trend, remakes, top products, and lab turnaround. The previous-period cash result supplies only its net figure, yet the full method builds detailed breakdowns.

Split lightweight comparison totals from detailed reports. Load expensive sections on demand, and cache suitable aggregate arrays for a short, explicit freshness window. Include clinic, branch, normalized period, timezone, and relevant filters in keys; invalidate on relevant financial changes or display the refresh time. Avoid caching financial authorization decisions as if they were harmless chart totals. Existing clinic reports already conditionally calculate selected analytics and cache chart arrays, providing a useful local pattern.

**9. Reduce fixed overhead on every authenticated request**

Evidence: `app/Providers/AppServiceProvider.php:104–113` runs `Schema::hasTable('settings')` and `Setting::first()` for non-console requests. This happens during provider boot, before request middleware resolves tenant context. With tenancy enabled and no context, `BelongsToClinic` adds `1 = 0`, so this lookup cannot provide the selected tenant's settings in that situation.

Move tenant settings resolution after tenant middleware, fetch once per request, and share the result with views. Move routine schema-readiness checks out of the hot request path. `ResolveTenantContext` also loads all available clinics and branches on each request, although it already reuses those collections for the switcher. Profile membership/role costs before introducing cross-request caching; access revocation must remain reliable. Start with request-local reuse rather than a long-lived authorization cache.

**10. Make polling resilient under slow responses**

Evidence: `resources/views/layouts/scripts.blade.php:484` defines a consolidated 20-second pulse. It correctly pauses when the tab is hidden, but the timer schedules another poll without awaiting the previous fetch. There is no in-flight guard or exponential error backoff.

At 300 visible tabs, a 20-second interval represents approximately 15 pulse requests/second before user actions or other polling. This is arithmetic, not measured traffic. Responses taking longer than the interval can overlap.

Schedule the next poll after completion, add an in-flight guard, bounded jitter, and error backoff. Retain immediate refresh when a tab becomes visible. The doctor queue still has 30-second Livewire polling, imports 2-second progress polling, and pending POS approvals 15-second polling; profile these separately. Retain authentication and tenant checks on the lightweight endpoint.

**11. Improve catalogue search semantics and pagination**

Evidence: `app/Livewire/PosProductGrid.php:38–53` searches both name and batch number with `%term%`, then calls `paginate(12)` and reloads all categories. Leading-wildcard matching generally cannot use an ordinary B-tree index as a selective text-prefix lookup; tenant/category filters may still narrow the scan.

Skip empty search predicates. Provide an exact indexed lookup for identifiers and a prefix lookup where the user experience permits it. Evaluate full-text search only if its tokenization and matching behavior satisfy product-name searches; it is not a drop-in replacement for substring matching. Use `simplePaginate` if a total page count is unnecessary, or cursor pagination with stable unique ordering for suitable deep lists. Debounce input and reuse category data with tenant-safe invalidation. The existing product child component already avoids requerying the catalogue on unrelated cart actions.

**12. Load page-specific browser assets only where needed**

Evidence: `resources/views/layouts/scripts.blade.php:68–120` includes Select2, Moment, tags input, several date/time libraries, Chart.js, and related styles in the shared layout.

Move chart/date-picker/widget libraries to pages that actually use them, consolidate overlapping date-picker dependencies where practical, and verify versioned production assets, compression, and cache headers. Measure transferred bytes and main-thread time before and after on a throttled connection. Do not remove scripts solely because their filenames look redundant; some legacy pages depend on global initialization.

**Existing strengths to preserve**

- New tenant/date index migration and the `whereDateIndexed` helper improve the current query design; deployed state still needs checking.
- Admin dashboard work already uses grouped daily sales and tenant-scoped cached counts.
- The POS product grid is isolated from unrelated cart renders.
- Notifications/notices share one visibility-aware pulse request, with existing unread-message indexes.
- Many detail lists use pagination and eager loading; clinic report CSV uses chunked reads.
- Inventory changes use transactions and row locks. Optimize time inside those critical sections rather than removing the correctness guarantees.
- Supervisor separates imports from default messaging work; tenancy mode uses hourly database backups instead of five-minute platform-wide dumps.

**Recommended implementation sequence**

1. Establish a staging baseline, confirm deployed indexes and effective hosted settings, and verify worker/queue alignment. No hosting-size recommendation is defensible without these measurements.
2. Remove legacy costing N+1 queries, replace owner-payment collection totals with SQL aggregates, fix pulse overlap, and remove pre-tenancy settings bootstrap work.
3. Split aggregate/detail reporting, lazy-load optical report sections, and bound/queue exports. Add financial parity and tenant-isolation regression coverage.
4. Introduce queue-specific health monitoring and robust hosted delivery behavior. Parallelize tenant jobs only after atomic work claiming is in place.
5. Add only indexes supported by query plans, then optimize search and browser assets using measured bottlenecks.

**Validation and acceptance plan**

The repository's `stress-test.ps1` targets `eyeclinicproject.test`, uses an XAMPP Apache Bench path, shares one login session, and requests a handful of GET routes. It does not establish capacity for this hosted SaaS, interactive Livewire flows, multiple tenants, exports, or concurrent stock changes. It was inspected, not executed.

Use anonymized or synthetic staging data with small, medium, and large tenants, including skewed large branches and historical allocations. Run distinct user sessions at stepped concurrency (for example 10, 30, and 100 users), with realistic think time and a sustained run. Include POS search/checkout, patient search, clinical queue, dashboard refresh, pulse, monthly/yearly reports, concurrent exports, imports, and scheduled summaries.

Record p50/p95/p99 latency, error rate, query count and SQL time per request, rows examined, PHP peak memory, response size, worker saturation, database connections/lock waits, queue age, and browser timings. Test cold and warm caches and a stopped messaging worker. Check response contents and authorization so redirects/errors cannot be mistaken for fast successful pages.

Suggested initial targets for agreement, not measured results: p95 below 500 ms for pulse/search, below 1 second for ordinary screens, below 2 seconds for bounded report summaries, and prompt job acknowledgement for large exports. Tailor those targets to the deployment and actual users. No duplicate financial writes, stock overselling, cross-tenant results, or duplicate messages are acceptable tradeoffs for speed.

The first optimization should make query count and summary memory independent of record count wherever the requested output is only a small set of totals. Confirm that behavior with query-count checks, financial parity fixtures, and staging load measurements before increasing capacity.
