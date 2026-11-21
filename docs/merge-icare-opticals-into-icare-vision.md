# Merge ICARE OPTICALS into ICARE VISION CENTRE (run sheet)

Decided 20 Nov 2026: the optical shop **ICARE OPTICALS (clinic #18)** and the clinic **ICARE VISION CENTRE (clinic #7)**
are at the same place and become **one Clinic + Optical account**, with the shop folded into ICARE VISION's main branch.

Tool: `php artisan clinics:merge-optical-shop {shop} {clinic} [--commit]` (`app/Console/Commands/MergeOpticalShopCommand.php`).
Without `--commit` it is a dry run (everything inside a transaction, reported, rolled back).

## What moves, what stays

| Moves to ICARE VISION (main branch) | Stays with the retired ICARE OPTICALS account |
|---|---|
| Orders, order lines, order history, services on orders | Subscriptions, invoices, collection cases, agreements |
| Sales and payments | Usage figures, sign-in logs, owner emails, platform log |
| Optical products, stock, stock movements, stock counts | Its settings row, its guides/tours state |
| Lens prices and lens imports, purchase orders, suppliers | Its (now empty) branch |
| Partner clinics (FINNEY, ICARE VISION SCC, MILLENIUM) with their balances | |
| Optical settings, optical services, optical lens options | |
| Its audit trail and notifications | |
| Its staff, with their roles at the main branch | |

Overlaps: where both had the same optical set-up row (services, lens options), the shop's own version moves in and
ICARE VISION's unused default goes to the retired account. ICARE VISION keeps its own lens-type list (its doctors use it);
the shop's duplicates stay behind. Nothing is deleted. Any table it doesn't know, or any clash it can't resolve,
stops it with nothing changed.

Rehearsed on the live dump of 20 Nov 2026 20:09: 23 orders, 23 sales, 1,855 products and stock, 1,889 stock
movements, 168 count lines, 8 lens imports and prices, 1 purchase order, 3 suppliers, 3 partners, 1 staff member moved;
every Optical and Clinic page opened on the merged account.

## On the server, in order

1. **Deploy** the code with this command (and run `php artisan migrate` for the pending migrations).
2. **Back up** the database: a snapshot on Laravel Cloud, or a full export from phpMyAdmin as for the 20 Nov dump
   (there is no backup artisan command). Pick a quiet time; ask ICARE staff not to use either account for ~10 minutes.
3. **Dry run** and read the report:
   `php artisan clinics:merge-optical-shop 18 7`
   It must end with "Dry run only". If it stops ("Stopped, nothing changed: …"), don't go on; send me the message.
   The numbers should be close to the rehearsal above, plus anything the shop did since.
4. **Merge**: `php artisan clinics:merge-optical-shop 18 7 --commit`, then `php artisan cache:clear`.
5. **Plan**: run `php artisan pricing:clinical-suite` (creates the **Clinic + Optical** plan, GHS 500 a month, decided
   21 Nov 2026), then on Platform move ICARE VISION onto it. Until then the moved optical records are there but the
   Optical Suite is hidden.
6. **Retire ICARE OPTICALS**: on Platform, stop its renewal (30 Nov) and close its open invoices / collection cases so no
   debt builds up, then suspend the account. Its history stays on record.
7. **Check** as an ICARE VISION manager: Optical → Orders (the shop's FINNEY / MILLENIUM jobs are there with their
   balances), Products, Partner Clinics, Sales; Clinic → Spectacles still shows ICARE VISION's own front-desk orders.
8. Tell the optician (GYABAAH JACQUELINE) and the owner to sign in again; they now land in ICARE VISION.

## If something looks wrong after the merge

Restore the backup from step 2. Don't try to move rows back by hand.
