# LOADTEST-20: 20 clinics, one year of data (2026-11-18)

## What was measured
- **Data:** a separate local database (`eyeclinicproject_loadtest`) seeded with synthetic data, no real patients. Totals:
  - 20 clinics, 5 of them with two branches.
  - 80,000 patients and 125,000 visits with a consultation and refraction each.
  - 62,000 optical orders.
  - 188,000 sales and 246,000 payments.
  - 20,000 expenses.
  - A 1,476-cell lens stock grid per branch.
  - Size: 437 MB, of which sales 124 MB, sale items 74 MB and payments 64 MB.
- **How:** two clinics (one with one branch, one with two) were opened as the owner in headless Chrome, three rounds each. The first (cold) round is left out. Each request's server time, query count and size were logged.
- **Caveat:** these are local times. The Laravel Cloud Flex instance and the hosted database are slower, so read the numbers relative to each other.
- **Code:** generator `php artisan loadtest:seed` and profiler middleware `LoadTestProfile`. Both are on the local branch `tools/loadtest-20` (worktree `C:\laragon\www\saas-load`), which is not pushed. They are not part of the app.

## Results (median warm server time)

| Screen | ms | Queries | DB ms | Page KB |
|---|---:|---:|---:|---:|
| Optical reports | 513 | 44 | 219 | 129 |
| Optical dashboard | 447 | 61 | 235 | 97 |
| Optical profit & loss | 434 | 51 | 223 | 78 |
| Admin dashboard | 376 | 50 | 177 | 110 |
| Cash book | 351 | 40 | 133 | **372** |
| Orders | 307 | 37 | 141 | 121 |
| Order View panel (open) | 280 | 35 | 143 | 83 |
| Day close | 273 | 68 | 97 | 110 |
| Lab Workbench | 268 | 34 | 112 | 104 |
| Income statement | 253 | 37 | 86 | 95 |
| Awaiting Collection | 251 | 62 | 68 | 179 |
| Patient clearance | 225 | 34 | 66 | 144 |
| Outstanding balances | 217 | 23 | 57 | 127 |
| Job tracking | 208 | 28 | 61 | 107 |
| Clinic POS | 202 | 25 | 31 | 157 |
| Sales records | 200 | 33 | 38 | 128 |
| Patients | 197 | 25 | 44 | 131 |
| Expenses | 196 | 27 | 35 | 118 |
| Optical POS | 189 | 48 | 44 | 67 |
| Clinic reports | 183 | 24 | 38 | 97 |
| Lens stock grid | 168 | 31 | 35 | 78 |
| End of day | 162 | 19 | 31 | 78 |

- **Every screen is under about half a second, even with a year of data.** On the earlier 20-day sample the same screens took 200–370 ms, so growth adds roughly 100–150 ms on the money and report screens and little elsewhere.
- **Cold first opens are slower.** The first dashboard after sign-in took 2.6 s locally and the optical dashboard 0.6–1.8 s, because the database's cache was empty. On Cloud this is what someone sees after the app wakes from sleep.
- **The background poll** (`/pulse`, every 20 s per open tab) costs about 9 queries and 70 ms. With about 6 tabs open for 10 hours, that is about 11,000 polls per clinic per day. That is far more requests than page views, and it keeps the app awake all working day.

## What is slow and why
1. **Money screens over payments** (optical reports, optical profit & loss, cash book, day close).
   - Payments are filtered by clinic, branch and date or method, but the table has no index that covers clinic + branch + date, or clinic + branch + method.
   - Optical totals filter sales by `business_line`, which has no tenant-scoped index either.
2. **The optical dashboard counts orders by date range** over `lens_orders` without a tenant + date index. This is its slowest query when the cache is cold.
3. **The cash book page is 372 KB of HTML,** 2–3× any other page, because it lists every line for the period. On a slow mobile connection that alone is about 8–10 s on a first visit.

## Trial fix: three indexes (tested here, not in the app)

```sql
payment_transactions (clinic_id, branch_id, payment_method)
payment_transactions (clinic_id, branch_id, created_at)
sales (clinic_id, branch_id, business_line, created_at)
```

| Screen | Before | After |
|---|---:|---:|
| Cash book | 351 ms (DB 133) | 259 ms (DB 63) |
| Optical profit & loss | 434 ms (DB 223) | 340 ms (DB 142) |
| Optical reports | 513 ms (DB 219) | 405 ms (DB 147) |
| Day close | 273 ms (DB 97) | 233 ms (DB 64) |

Other screens changed by 0–10%.

## Fixes worth doing (you pick)
1. **Add the three indexes above** as a migration. This is cheap and safe, cutting 20–30% from the money screens.
2. **Add a `lens_orders (clinic_id, branch_id, created_at)` index** for the optical dashboard's counts.
3. **Paginate or summarise the cash book** (372 KB per page).
4. **Cache past-period report totals** (closed months don't change). Optical reports, profit & loss and the income statement would then only recompute the current month.
5. **Poll less when idle**, for example every 60 s after 5 minutes without activity. This cuts poll traffic by about 3× during quiet hours.

## Server sizing and cost
- **The database is the constraint, not the app.** A year of 20 busy clinics is about 440 MB, and the live database is MySQL Flex 512 MiB, already at 95–100% memory. Data larger than memory means more disk reads and slower cold screens. **Move to 1 GiB before onboarding many more clinics,** and to 2 GiB around year two.
- **App compute:**
  - Page views are about 1,500 per clinic per day × about 0.25 s, plus polls at about 11,000 × 0.07 s. That is roughly 20 minutes of server work per clinic per working day, or about 6–7 hours a day across 20 clinics, spread over the day.
  - A single small instance can handle that. In practice the poll keeps one instance running all working day for any open clinic, so expect "always on 06:00–20:00" billing rather than scale-to-zero savings.
### Rough monthly cost on the Growth plan
- **Prices used:** laravel.com/cloud/pricing and the Cloud pricing docs, read on 2026-11-18. The page doesn't list Laravel MySQL 1 GiB prices, so the database line uses the docs' MySQL Flex 2 GB rate ($0.03274/hr) as an upper bound.
- **Plan:** Growth already allows autoscaling up to 10 replicas and scale to zero, so 20 clinics need no plan change. The database size and the poll are what matter.

| Item | Assumption | Per month |
|---|---|---:|
| Growth base fee | $20, minus the $5 usage credit | $15 |
| App compute | Flex 1 GiB ($12/mo cap) or 2 GiB ($24/mo cap), awake about 14 h a day (poll keeps it up) | $7–24 |
| Database | MySQL Flex 2 GB, about 14 h a day, up to always on | $14–24 |
| DB storage | About 0.5 GB × $0.10, growing about 0.45 GB a year | under $1 |
| Bandwidth | About 900k page views × about 120 KB, minus the allowance earned by compute, then $0.10/GB | $0–5 |
| **Total for 20 clinics** | | **about $37–69** (about $2–3.50 per clinic) |

- **Not included:** managed queue workers, SMS and email providers, and object storage (R2, $0.02/GB-month). Queue worker prices weren't shown on the page.
- **Bandwidth:** turning on compression (if Cloud doesn't already compress) cuts page size about 5× and removes any overage.
- Set a spending limit and alerts, and compare with the real usage after the first month.

## All five fixes applied (branch `perf/loadtest-fixes`, 2026-11-19)
Re-measured on the same 20-clinic data, the same two clinics, warm medians:

| Screen | Before | After | DB time | Page |
|---|---:|---:|---:|---:|
| Optical reports | 513 ms | 325 ms | 219 → 89 ms | |
| Optical dashboard | 447 ms | 323 ms | 235 → 107 ms | |
| Optical profit & loss | 434 ms | 240 ms | 223 → 67 ms | |
| Cash book | 351 ms | 258 ms | 133 → 61 ms | 372 → 102 KB |
| Orders | 307 ms | 247 ms | 141 → 81 ms | |
| Order View panel | 280 ms | 222 ms | 143 → 82 ms | |
| Lab Workbench | 268 ms | 192 ms | 112 → 42 ms | |
| Income statement | 253 ms | 221 ms | 86 → 55 ms | |
| Job tracking | 208 ms | 169 ms | 61 → 31 ms | |

Other screens are unchanged within noise. The admin dashboard varies by about 10% between runs, depending on whether a periodic background check runs during that request.

**What was done:**
1. **Indexes:** payments (clinic, branch, method) and (clinic, branch, date); sales (clinic, branch, business line, date); optical orders (clinic, branch, date) and (clinic, branch, status). This is migration `2026_11_19_000002`, which took 4 s on 246k payments.
2. **Polling:** every 20 s while in use, every 60 s once a page has sat untouched for 5 minutes, and straight back to 20 s on the next click, key or tap.
3. **Cash book:** a period with more than 50 entries shows one row per day (in, out, closing balance and the number of entries). Click a day to see its entries. A shorter period lists every entry as before. The tour has a new step.
4. **Stored totals for past periods:**
   - Optical reports, optical profit & loss and the income statement keep their figures for a period that ended before today, for up to 30 minutes.
   - Any save or delete of a row dated in a past month (a refund, correction, void or back-dated expense) refreshes that month at once.
   - A period that includes today is always worked out live.
   - Imports write without those save events, so after one, stored past figures can lag by up to 30 minutes.
