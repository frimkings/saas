# Diagnostic Scans & Referral Commissions — Technical Design

Status: draft for review · Branch: `feature/diagnostic-scans`

## 1. Goal

Clinics that run diagnostic scans (OCT, VFT, fundus photography, …) receive patients referred
by other clinics and by their own doctors. They need to:

- price and sell scans from a catalogue,
- record **who referred** each scan,
- track each scan from registration to result sent,
- calculate **commission** for the referrer and pay it out,
- report referrals and commission per referrer for any period.

## 2. Decisions taken

| Topic | Decision |
|---|---|
| Placement | New **Front Desk → Diagnostic Scans** page with tabs |
| Licensing | Paid add-on, feature key `diagnostic_scans`. Nothing appears without it |
| Who pays for the scan | **Always the patient.** No partner billing or partner accounts receivable |
| Price | One catalogue price per scan, the same for every source |
| Commission basis | Per scan in the catalogue: `percent` or `fixed` |
| Partner referrals | Commission to the partner clinic |
| Internal orders (own doctor) | Commission to that doctor, **at the same rate as partners** |
| Self-referred | No commission |
| When it's earned | When the scan's bill is **fully paid**. Voided if the item is refunded |
| Staff payout | **Through payroll** (Attendance Payroll). Payout voucher only as a fallback |
| Partner payout | Payout voucher (cash, mobile money or bank), recorded as an expense |
| Sharing results | Only with a **consent checkbox** ticked on the order |

Not in scope: partner billing on account, separate partner prices, commission on non-scan items.

## 3. Navigation & licensing

- `App\Support\Feature::DIAGNOSTIC_SCANS = 'diagnostic_scans'`.
  Don't reuse `Feature::REFERRALS`: that one controls outgoing referral letters.
- Registered as a clinic extra in `PlanProduct::EXTRAS`, so it can be ticked on a plan or bought
  as an add-on. It is in `PlanProduct::EXPLICIT_ONLY`: '*' and legacy "everything" plans and
  offline trial keys do **not** include it, because most clinics don't do scans. A clinic has it only
  when its plan names it or it has the add-on (`SubscriptionService::access` merges the add-on
  even on '*' plans for these features).
- `ClinicNavigation` Front Desk group:
  `['label' => 'Diagnostic Scans', 'route' => 'frontdesk.scans', 'show' => $has(Feature::DIAGNOSTIC_SCANS)]`
- Doctor-side "Order scan" button in the consultation is shown only when the feature is on.

### Page tabs (`/front-desk/scans?tab=…`)

| Tab | Component | Roles |
|---|---|---|
| Scan Desk | `Scans\ScanDeskComponent` | Secretary, Cashier, Manager, Super Admin |
| Catalogue | `Scans\ScanCatalogueComponent` | Manager, Super Admin (others read-only) |
| Partner Clinics | `Scans\ScanPartnersComponent` (+ statement view) | Manager, Super Admin |
| Commissions | `Scans\ScanCommissionsComponent` | Manager, Super Admin |
| Reports | `Scans\ScanReportsComponent` | Manager, Super Admin |

## 4. Data model

All tables carry `clinic_id` (and `branch_id` where activity is branch-specific) and are added
to the tenant-column enforcement list, like the other clinical tables.

### 4.1 `diagnostic_scans` — catalogue

| Column | Type | Notes |
|---|---|---|
| id, clinic_id | | |
| name | string | "OCT Macula" — unique per clinic |
| code | string(20) null | "OCT-M" |
| product_id | FK products | Hidden service product, created and kept in sync automatically. Lets sales, receipts, refunds, insurance and reports keep working unchanged |
| price | decimal(12,2) | |
| per_eye | bool | true → OD/OS each charged; OU = 2 × price |
| commission_type | string(10) | `percent` \| `fixed` \| `none` |
| commission_value | decimal(12,2) | 10.00 (= 10%) or a fixed amount |
| turnaround | string(50) null | "Same day", "24 hrs" |
| is_active | bool | archive, never delete |
| timestamps | | |

One rate for partners and staff (decision above). If they ever need to differ, add
`staff_commission_*` columns. The service resolves the rate in one place (§6.2).

### 4.2 `scan_partner_clinics`

Same shape as `optical_partner_clinics`. Kept separate so the Optical module is untouched and
the add-on stands on its own.

| Column | Notes |
|---|---|
| id, clinic_id | |
| code | "RC-014", unique per clinic, auto-numbered. Printed on referral slips |
| name, contact_person, phone, email, address | |
| notification_phone, notify_via | `sms` \| `whatsapp` \| `email` \| `none`, for sending results |
| payout_method | `cash` \| `mobile_money` \| `bank` |
| payout_account | encrypted cast. Visible to managers only |
| is_active | |

### 4.3 `scan_partner_doctors` (optional list)

`id, clinic_id, scan_partner_clinic_id, name, phone, is_active`. Used for "top referring doctors".
Commission still goes to the **clinic**.

### 4.4 `scan_partner_rates` — per-partner overrides

`id, clinic_id, scan_partner_clinic_id, diagnostic_scan_id, commission_type, commission_value`
— unique (partner, scan). When there's no row, the catalogue rate applies.

### 4.5 `scan_orders`

| Column | Notes |
|---|---|
| id, clinic_id, branch_id | |
| order_number | "SCN-2026-000123" (unique, uses the clinic's counters) |
| patient_id | **required**. Quick-register creates a real patient so results attach to the record |
| source | `partner` \| `internal` \| `self` |
| scan_partner_clinic_id | required when source = partner |
| scan_partner_doctor_id | optional |
| referrer_note | free text (the partner's letter reference, etc.) |
| ordered_by | FK users. Required when source = internal (the doctor) |
| consultation_id | set for internal orders made from a consultation |
| sale_id | FK sales, set at checkout |
| status | `registered` → `paid` → `scanned` → `reported` → `sent` (+ `cancelled`) |
| share_consent | bool |
| consent_by, consent_at | who ticked it, when |
| scanned_at, reported_at, results_sent_at | timestamps for turnaround reports |
| created_by, timestamps, softDeletes | |

### 4.6 `scan_order_items`

`id, clinic_id, scan_order_id, diagnostic_scan_id, eye (OD|OS|OU|null), quantity, unit_price,
line_total, sale_item_id (null until checkout), document_id (result file, null)`

### 4.7 `scan_commissions` — one row per commissionable item

| Column | Notes |
|---|---|
| id, clinic_id, branch_id | |
| scan_order_item_id | unique: at most one live commission per item |
| payee_type | `partner` \| `staff` |
| scan_partner_clinic_id / user_id | the payee (one of them is set) |
| base_amount | the item's net paid amount (after discount/refund) |
| rate_type, rate_value | **copied** when the commission is recorded. Later rate changes never rewrite history |
| amount | the commission |
| earned_on | date the bill was fully paid. Drives the period filters and payroll |
| status | `accrued` \| `paid` \| `void` |
| scan_commission_payout_id | set when paid |
| void_reason, voided_at, voided_by | |

### 4.8 `scan_commission_payouts`

| Column | Notes |
|---|---|
| id, clinic_id, branch_id | |
| voucher_number | "COM-2026-0042" |
| payee_type, scan_partner_clinic_id / user_id | |
| route | `voucher` (cash/MoMo/bank) \| `payroll` |
| period_from, period_to | the period this settles (payroll = calendar month) |
| amount, method, reference, notes | |
| expense_id | FK expenses (voucher route) so the **cash book** balances |
| paid_by, paid_at | |

## 5. Flows

### 5.1 Partner referral / self-referred (Scan Desk)

1. **New scan order** → find the patient, or quick-register (name, phone, sex, DOB/age).
2. Source: Partner (picker searches name/code) + optional doctor, or Self-referred.
3. Add scans; for `per_eye` scans choose OD / OS / OU. Totals come from the catalogue.
4. Consent checkbox (shown for partner orders): *"Patient consents to sharing results with the
   referring clinic."*
5. **Checkout** → creates a normal `sales` row (and `sale_items` against each scan's hidden product)
   through the existing payment code: methods, part payment, discounts with approval, insurance.
   Order → `paid` once the sale is fully paid.
6. Technician marks **Scanned**, uploads the result (clinical documents / scan storage), marks
   **Reported**.
7. **Send to partner** (needs consent) → SMS/WhatsApp/email with a time-limited link to the
   result PDF. Order → `sent`. Logged in the audit trail.

No `cashier_patient_clearances` row is created, so scan-only patients don't appear in
*Patients Awaiting*, and the one-clearance-per-day rule doesn't block a patient who also has
a consultation that day.

### 5.2 Internal order (doctor)

1. In the consultation, **Order scan** → choose scans/eyes → creates a `scan_order` with
   `source = internal`, `ordered_by = doctor`, `consultation_id`, status `registered`.
2. It appears in the Scan Desk queue as "Ordered by Dr X". Reception checks out as in 5.1 step 5.
3. Results attach to the patient record and the consultation. No partner sharing.
4. Commission → the doctor (`payee_type = staff`).

### 5.3 Commission lifecycle

```
sale fully paid ──▶ accrue (status accrued, earned_on = today)
item refunded   ──▶ accrued → void
                    paid    → void + negative "clawback" row, netted off the next payout
partner changed after payment (manager approval) ──▶ void old row, accrue new row
payout          ──▶ accrued rows in the period → paid, linked to the payout
```

## 6. Services

### 6.1 `App\Services\Scans\ScanOrderService`
`create()`, `addItem()`, `checkout(ScanOrder, payments)` (delegates to the existing sale/payment code),
`markScanned()`, `markReported(document)`, `sendResults()`, `cancel()`.

### 6.2 `App\Services\Scans\ScanCommissionService`
- `rateFor(ScanOrderItem)`: partner override → catalogue → none. Self-referred → none.
- `accrueForSale(Sales)`: idempotent (unique on item). Base = item net paid amount.
- `voidForRefund(RefundLog)`: on processed refunds of scan items.
- `balance(payee)`, `statement(payee, from, to)`.
- `payPartner(partner, period, method, ref)`: creates payout + `Expense` (category
  "Referral commissions") in one transaction.
- `settleThroughPayroll(Y-m)`: marks staff rows for that month paid, with `route = payroll`.

### 6.3 Hooks into existing code
| Event | Where | Action |
|---|---|---|
| Sale becomes fully paid | `Sales` observer on `payment_status` → `paid` (covers POS, Outstanding Balances, Scan Desk) | `accrueForSale` if the sale belongs to a scan order |
| Refund processed | `RefundLog` status → processed (existing model hook) | `voidForRefund` |
| Scan product kept in sync | `DiagnosticScan` saved | create/update its hidden `products` row (name, price, type service, hidden from POS lists) |

## 7. Payroll integration (staff commission)

`AttendancePayroll::for()` computes pay on the fly from attendance. Add:

- A **Scan commission** amount per staff line: the sum of the staff's `scan_commissions`
  (via `attendance_staff.user_id`) with `earned_on` in the period and status `accrued`/`paid`.
- Included in `gross`. A new CSV column, "Scan commission", before "Gross pay".
- Only when the clinic has both `DIAGNOSTIC_SCANS` and `ATTENDANCE_PAYROLL`.

Closing: in **Commissions**, the manager clicks **"Settle staff commission through payroll —
<month>"** after running payroll. That creates one `payroll`-route payout per doctor and marks
their rows paid, so commission can't be paid twice. A refund after that becomes a clawback
row that lowers next month's figure.

Fallback: a doctor with no `attendance_staff` record, or a clinic without payroll, is paid by
voucher like a partner. The Commissions tab shows which route applies per doctor.

## 8. Reports (Reports tab + exports)

- **Referrals by partner**: patients, orders, scans, gross, commission, net to clinic. Any dates, CSV/PDF.
- **By scan type**: count and revenue per scan, split by source (partner / internal / self).
- **Top referring doctors**: partner doctors and internal doctors.
- **Dormant partners**: no referral in N days (also a Needs Attention item).
- **Turnaround**: paid → reported → sent, average and late items.
- **Commission statement**: per partner or doctor. PDF and WhatsApp text, like the optical partner statement.

Needs Attention additions: scans paid but not reported after the turnaround, results reported
but not sent (with consent), commission accrued and unpaid past month end.

## 9. Permissions & audit

- Catalogue prices/rates, partner rates and payout details: Manager / Super Admin.
- Payouts and payroll settlement: Manager / Super Admin. Audit trail entries
  `scan.commission.payout`, `scan.commission.void`.
- Changing an order's referrer after payment: approval request (same pattern as discount approvals).
- Consent tick and result sends: audited with the user and time.

## 10. Build phases

| Phase | Scope | Done when |
|---|---|---|
| 1 ✅ | Feature key + add-on registration, menu, migrations (catalogue, partners, partner doctors, partner rates), **Catalogue** and **Partner Clinics** tabs | Manager can maintain scans and partners behind the add-on |
| 2 ✅ | **Scan Desk**: orders, quick-register, hidden service product per scan (`diagnostic_scans.product_id`, kept out of POS search and the Products page), checkout through existing sales, status tracking | Referred and self-referred scans can be sold and tracked |
| 3 ✅ | Commission accrual/void hooks, **Commissions** tab, partner payout + expense | Partner commissions accrue, reverse on refund, and are paid out with a voucher |
| 4 | Internal orders from consultation + payroll integration | Doctors earn commission that appears in the payroll CSV and is settled monthly |
| 5 | Results upload + consented sharing, Reports, Needs Attention items | Full loop and reporting |

Each phase ships with feature tests (tenancy isolation, add-on gating, accrual idempotency,
refund voiding, payroll totals), following the existing `tests/Feature` style.

## 10a. As built in phase 2

- **Payment is not an order status.** The order's `status` tracks the work (`registered → scanned →
  reported → sent`, or `cancelled`); whether it is paid is read from its sale (`paid` / `partial`).
  "To bill" = registered with no sale. A scan can be marked scanned once billed, even part-paid.
- **Checkout** creates a separate clinic sale (not merged into an open visit bill), joined to the
  patient's visit by `PatientVisits::attach`. Full payment → `paid`/`finalized`; part payment →
  `partial`/`open`, 30-day expiry, balance collected on Outstanding Balances. Payments may not exceed
  the bill. No discounts or insurance split at the Scan Desk yet.
- **Cancel** only before billing, with a reason. After billing the sale is refunded the usual way.
- **Hidden products**: `App\Services\Scans\ScanCatalogue` creates/updates "Scan: <name>" (made to
  order, cost 0) in an inactive category of type `diagnostic_scan`. `Product::inActiveCategory`
  excludes that type, so the till and prescribing never offer them; `Product::notScans()` keeps them
  off the Products page, and the Categories page hides the category.
- **Internal orders** can already be recorded at the desk (choose the doctor). Ordering from the
  consultation screen comes in phase 4.

## 10b. As built in phase 3

- **One rule, `ScanCommissionService::syncForSale`.** Called after checkout and from model events
  (`Sales` saved with `payment_status`/`is_refunded` changed; `SaleItem` `refunded_quantity` changed),
  so Outstanding Balances, Sales Records refunds and any later path all land in the same place. Per
  scan it works out what is due (nothing unless the bill is fully paid; only on the quantity not
  refunded) and corrects the difference: unpaid rows are voided and rewritten, paid rows are never
  touched — a negative `correction` row is added and the next payout nets it off. Idempotent.
- **Rates frozen** on a scan's first commission row (partner's agreed rate, else catalogue; our
  doctors always the catalogue rate). Percent of the scan's paid amount; fixed = per scan/eye charged.
- **Payout** (`payOut`): everything accrued up to a chosen day, if the total is above zero. Creates a
  `COM-YYYY-NNNN` payout and an `Expense` in "Referral commissions" (operating), so the cash book and
  day close balance. Methods are the expense methods (cash from till, MoMo, card, bank transfer).
- **Commissions tab** is managers only (hidden, and `setTab` refused, for other roles).
- **Tour** `clinic.scans` (v1) on the page; manager-only step for Commissions.
- Payouts can't be cancelled yet; a wrong payout is corrected by hand (expense + note).

## 11. Open points

- Does a discount on the scan reduce the commission base? (Proposed: yes, commission on the net paid amount.)
- Insurance-covered scans: commission on the patient's share only, or on the insurer's share
  once the claim is paid? (Proposed: on the full net amount, earned when fully settled.)
- Mark a minimum payout amount, or always pay the full balance?
