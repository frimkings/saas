# Roles and permissions — plan (draft for review)

Status: draft, 2026-11-18, branch `feature/roles-permissions`. Nothing in this document is built yet.

Goal: roles built and owned by the Nexus team, permission checks in the code, per-person
adjustments by clinics, maker–checker on money, plan and permission both required, every change
audited.

**Decided 2026-11-18:**
- **The Nexus team builds roles.** Roles and what each can do are the same for every clinic and
  are changed only by the platform team. Clinics never create or edit roles.
- **Clinics adjust per person.** A clinic owner (or manager, within their own access) gives each
  staff member a role and can switch individual permissions on or off for that one person —
  only permissions Nexus has defined, and only those the clinic's plan includes.
- A clinic needing something different gets a new standard role from Nexus (e.g. Senior Cashier,
  Accountant) or a per-person adjustment.
- **Managers** do not get sales reports, the income / combined statement or clinic settings
  (Owner only). They keep the admin dashboard.
- **Opticians are bench-only:** lab workbench and job tracking, no till (no POS, sales records,
  balances or refund log).
- **Accepting a link from an optical centre** is for whoever manages optical centres (Owner,
  Manager), not the front desk.

---

## 0. Urgent: clinics can change roles for every clinic (live on main)

Roles and permissions are **shared by all clinics** (the `roles` table has no clinic column and
Spatie "teams" is off). But **Settings → Roles & Permissions** (`admin/roles-permissions`,
`RolePermissionManagerComponent`, on the Clinic Standard / Plus / Pro plans) lets any clinic's
"Super Admin" do the following:

| What a clinic owner can do there | Effect |
|---|---|
| Tick / untick permissions on Cashier, Secretary, Manager, Super Admin, … | Changes that role in **every clinic** |
| Rename or delete a permission (e.g. `optical sales`) | Breaks that access in **every clinic** |
| Assign a role to any user id (`User::findOrFail($this->userToAssign)`, no clinic check) | Can give a role to, or remove it from, a user of **another clinic** |
| Create new roles | They appear in every clinic's role list |

The audit trail in the local copy of live data shows that this has already happened:
clinic 3 updated "Secretary" (2026-09-26); clinic 4 updated "Super Admin", "Secretary" (2026-10-06) and
"Cashier" (2026-10-17), and removed a user from "Super Admin". Today Secretary and Cashier hold
`record insurer payments`, which is probably one of those edits — it now applies in every clinic.

**Hotfix — done 2026-11-18** (`hotfix/shared-roles-lock`, fdeecbe, pushed, not yet on main):
the page is read-only for clinics (platform admins edit), role assignment and the role's user
list cover only the current clinic's staff. Tests: `tests/Feature/SharedRolesLockTest.php`.
Since the Nexus team builds roles, this lock is permanent, not temporary. Edits clinics already
made to shared roles are **not** reverted yet — phase 1 resets roles to the agreed map.

## 1. How access works today

- 9 roles shared by all clinics: Super Admin (used as the clinic owner — all 18 holders are clinic
  staff), Manager, Secretary, Cashier, Doctor, Staff, Optician, Optical Assistant, Lab Technician.
- Access is decided mostly by **role name**: 32 route rules plus about 217 `hasRole` / `@role`
  checks in about 90 files (most in Expenses, ClinicNavigation, Attendance, the admin dashboard and approvals).
- 8 permissions exist: `manage users`, `record insurer payments`, `view consultations`,
  `view sales records`, `optical sales`, `optical lab`, `optical stock`, `optical reports`.
- The code also checks permissions that **do not exist**: `manage billing` (15 checks),
  `approve clearance revoke` (4), `approve discounts`, `view medical records`. They always say "no",
  so only the role names around them let anyone in.
- The optical side already works the way the whole app should: `app/Support/OpticalAccess.php`
  maps each screen (route) to the permissions that open it. Phase 1 generalises that pattern.
- Plan features are a separate gate (`feature:` middleware, `Feature`, `ClinicAccessService`).

## 2. Design

1. **One permission catalogue** — `app/Support/Access/Permissions.php`: each permission has a key,
   a plain-English label, a group (for the tick grid) and the plan feature it needs, if any.
2. **One screen map** — route name → permission(s), like `OpticalAccess::SCREENS`. Route
   middleware, the menu (`ClinicNavigation`), tours and the plan-access tests all read it. A test
   fails if a clinic route has no entry.
3. **Checks ask for permissions, never role names.** `Owner` passes every check (Gate::before),
   but plan features still apply.
4. **Two gates:** allowed = plan includes the feature **and** the person has the permission.
5. **Roles stay shared and owned by Nexus.** Defined in code (the catalogue + a role map), synced
   to the database by a command/migration, edited only by the platform team. No per-clinic role
   copies, no Spatie teams.
6. **Per-person adjustments (phase 4), stored per clinic:** a table such as
   `clinic_user_permissions` (clinic, user, permission, granted on/off, who, when). A person's
   access in a clinic = their role's permissions, plus switched-on extras, minus switched-off
   ones — and only where the plan allows. Stored per clinic because one person can work at
   two clinics (Spatie's own direct permissions are global, so they are not used for this).
7. **Who may adjust:** the Owner, and Managers only for permissions they hold themselves.
   Approval permissions (approve refunds, voids…) only the Owner may grant. Every change is
   audited, and the Owner is emailed when someone's access goes up.

## 3. Permission list

Keys are short and stable; labels are what owners see. "Plan feature" = the tick also needs this
feature on the clinic's plan.

### Patients & front desk
| Key | Label | Today's screens | Plan feature |
|---|---|---|---|
| `patients.view` | Patients: register and look up patients | secretary/patients, secretary/dashboard | – |
| `appointments.manage` | Appointments | secretary/appointments | appointments |
| `clearance.manage` | Patient clearance (send to doctor) | secretary/patient-clearance | – |
| `spectacles.manage` | Spectacles & sent to optical | secretary/spectacles, sent-to-optical | spectacle_orders |
| `optical_centres.manage` | Optical centres: links, invitations, money owed | secretary/optical-centres, owed-to-optical, optical-links/accept | spectacle_orders |
| `scans.desk` | Diagnostic scans: front desk | front-desk/scans | diagnostic_scans |
| `scans.export` | Diagnostic scans: export report | front-desk/scans/report.csv | diagnostic_scans |

### Clinical
| Key | Label | Today's screens | Plan feature |
|---|---|---|---|
| `clinical.queue` | Doctor's queue | doctor/dashboard, patient-awaiting | – |
| `clinical.records` | Consultations & refraction | doctor/patientrecords-*, doctor/allrecords | – |
| `clinical.referrals` | Referrals | doctor/referrals | referrals |
| `clinical.print` | Clinical PDFs & visit cards | doctor/…/pdf, print | clinical_pdfs |
| `clinical.task_center` | Clinical task centre | admin/clinical-task-center | – |
| `diagnoses.manage` | Diagnosis list | admin/diagnoses | – |

### Sales & money
| Key | Label | Today's screens | Plan feature |
|---|---|---|---|
| `pos.sell` | Point of sale | cashier/seller-desk, cart, cashier/dashboard | – |
| `sales.view` | Sales records | cashier/sales-records, admin/sales-records | – |
| `sales.manage` | Sales records: all, incl. previous software | admin/sales-records | – |
| `balances.view` | Outstanding balances | cashier/outstanding-balances | outstanding_balances |
| `refunds.view` | Refund log | cashier/refund-logs | – |
| `dayclose.manage` | Day close | cashier/day-close | cash_book |
| `cashbook.view` | Cash book & daily cash summary | admin/cash-book, daily-cash-summary | cash_book |
| `expenses.manage` | Expenses | admin/expenses | expense_tracking |
| `quotations.manage` | Quotations | admin/quotations | quotations |
| `ledger.view` | Patient ledger | admin/patient-ledger | patient_ledger |
| `insurance.manage` | Insurers, claims, receivables | admin/insurance/* | insurance |
| `insurance.payments` | Record insurer payments | admin/insurance/payments | insurance |

### Approvals (maker–checker: never your own request)
| Key | Label | Today's screens |
|---|---|---|
| `approve.discounts` | Approve discounts | admin/discount-approvals |
| `approve.refunds` | Approve refunds | admin/refund-approvals |
| `approve.revokes` | Approve clearance revokes | admin/clearance-revoke-approvals |
| `approve.corrections` | Approve payment corrections / voids | admin/approvals (corrections) |
| `approve.password_resets` | Approve password resets | admin/password-reset-approvals |

### Sensitive actions (phase 3 — inside pages, checked on the server)
| Key | Label |
|---|---|
| `sale.refund` | Request a refund |
| `sale.void` | Void or correct a payment |
| `discount.give` | Give discounts (up to the role's limit; above it needs approval) |
| `prices.edit` | Change product prices |
| `patients.delete` | Delete or merge patients |
| `data.export` | Export lists to CSV / PDF |

### Stock & purchasing
| Key | Label | Today's screens | Plan feature |
|---|---|---|---|
| `inventory.manage` | Products & categories | admin/product, admin/category | – |
| `inventory.movements` | Stock movements & alerts | admin/stock-movements, inventory-alerts | inventory |
| `purchasing.manage` | Suppliers, purchase orders, transfers | admin/suppliers, purchase-orders, stock-transfers | purchasing |
| `lens_report.view` | Lens outstanding report | admin/lens-outstanding-report | spectacles_pro |

### Reports
| Key | Label | Today's screens | Plan feature |
|---|---|---|---|
| `dashboard.view` | Admin dashboard (business overview) | admin/dashboard | – |
| `reports.sales` | Sales reports | admin/reports | – (PDF export: sales_reports) |
| `reports.finance` | Income statement & combined statement | admin/income-statement, combined-statement | advanced_reports |
| `reports.delivery` | Scheduled report emails | admin/report-delivery | – |

### Communications
| Key | Label | Today's screens | Plan feature |
|---|---|---|---|
| `messages.patients` | Patient SMS & WhatsApp settings | admin/communications/* | patient_messages |
| `messages.broadcast` | Broadcast SMS | admin/communications/broadcast | sms_campaigns |
| `messages.recall` | Patient recall | admin/patient-recall | sms_campaigns |
| `messages.logs` | SMS logs | admin/sms-logs | patient_messages |

### Staff, settings, billing
| Key | Label | Today's screens | Plan feature |
|---|---|---|---|
| `staff.manage` | Staff & their roles | admin/users | – |
| `roles.view` | See what each role can do (editing is Nexus-only) | admin/roles-permissions | custom_roles |
| `attendance.manage` | Attendance, roster, departments | admin/attendance/* | attendance |
| `audit.view` | Audit trail & login history | admin/audit-trail, login-history | audit_trail |
| `branches.manage` | Branches | admin/branches | – |
| `settings.manage` | Clinic settings, receipts, SMS settings | admin/settings | – |
| `billing.manage` | Subscription, licence, invoices | admin/subscription, admin/license | – |
| `system.manage` | Backups, mail settings, offline health | admin/backups, mail-settings, offline-health | – |

### Optical (already exist, kept as they are)
`optical sales`, `optical lab`, `optical stock`, `optical reports`, plus `optical.manage` for the
screens that are manager-only today (purchasing, expenses, profit, settings, audit).

## 4. Role templates (phase 1 = exactly today's access)

✓ = has it. Phase 1 must not change what anyone can open; the tests prove it role by role.

| Permission group | Owner (Super Admin) | Manager | Secretary | Cashier | Doctor | Optician | Optical Asst | Lab Tech | Staff |
|---|---|---|---|---|---|---|---|---|---|
| Patients & front desk | all | all | all except optical_centres, scans.export | scans.desk only | – | – | – | – | – |
| Clinical queue, records, referrals, print | ✓ | **– (today)** | – | – | ✓ | – | – | – | – |
| Clinical task centre, diagnoses | ✓ | ✓ | – | – | – | – | – | – | – |
| POS, sales records, balances, refund log | ✓ | ✓ | ✓ | ✓ | – | ✓ | – | – | – |
| Day close | ✓ | ✓ | – | ✓ | – | – | – | – | – |
| Cash book, expenses, quotations, ledger, insurance | ✓ | ✓ | – | – | – | – | – | – | – |
| Record insurer payments | ✓ | ✓ | ✓ * | ✓ * | – | – | – | – | – |
| Approvals (discounts, refunds, revokes, corrections) | ✓ | ✓ | – | – | – | – | – | – | – |
| Approve password resets | ✓ | – | – | – | – | – | – | – | – |
| Stock & purchasing | ✓ | ✓ | – | – | – | – | – | – | – |
| Reports (sales, finance) | ✓ | ✓ | – | – | – | – | – | – | – |
| Report delivery | ✓ | – | – | – | – | – | – | – | – |
| Communications: SMS/WhatsApp, broadcast | ✓ | – | – | – | – | – | – | – | – |
| Communications: recall, SMS logs | ✓ | ✓ | – | – | – | – | – | – | – |
| Staff, audit, branches, settings | ✓ | ✓ | – | – | – | – | – | – | – |
| Roles, billing, system | ✓ | – | – | – | – | – | – | – | – |
| Optical | all | all | optical sales | optical sales | – | optical lab | optical sales | optical lab + stock | – |

\* Probably from a clinic's edit of the shared role (section 0); to confirm with you.

Questions this table raises (decide before phase 2, not phase 1):
- **Manager can't open the doctor's screens today.** Should managers see clinical records?
- **Staff** has no access to anything but login-only pages. ~~Remove it and move its 8 users?~~ Kept (phase 2): it is the role for attendance-only clinics and for clock-in-only people at full clinics.
- **Secretary / Cashier recording insurer payments** — keep or remove?

## 5. Phases

| Phase | What | Visible to clinics? |
|---|---|---|
| **Hotfix ✓** | Lock the shared Roles & Permissions page (section 0) — done, awaiting merge | Page read-only |
| **1. Foundation** (part A done: routes) | Permission catalogue + screen map + role map in code; routes, menu, tours and tests read it; role names replaced by permission checks; roles = today's access (minus the clinic edits you choose to undo); tests prove no one gains or loses access | No |
| **2. Owner rename, tidy roles ✓** | Clinics see "Owner" for Super Admin (stored name kept for imports, old records and the platform side); Staff kept and labelled "Staff (attendance only)" — see section 7 | Role names |
| **3. Money rules ✓** | Maker–checker (never approve your own request, Owner excepted); approval permissions per queue; discount limit per role at both tills and on optical orders | Yes |
| **4. Access tab (per person) ✓** | On the staff page: role + per-person switches grouped like the menu, "from role / this person" columns, "what can they see" preview, plan-locked switches greyed out, audit lines, owner email when access goes up | Yes |
| **5. Platform role tool** | The Roles & Permissions page moves to the platform side for the Nexus team, built on the catalogue; clinics keep a read-only "what each role can do" page | Clinics: read-only page |

## 6. Decisions needed from you

1. ~~Do the hotfix now?~~ Done. ~~Who builds roles?~~ The Nexus team; clinics adjust per person.
2. Are the **permission list** (section 3) and **today's map** (section 4) right? Anything missing or named wrongly?
3. The three questions under section 4 (Manager and clinical records, Staff role, insurer payments for desk staff).
4. Which **clinic edits to shared roles** to undo in phase 1 (section 0) — or reset every role to the section 4 map.

## 7. Progress

**Phase 1 part A — done 2026-11-18 (routes).**
- `app/Support/Access/`: `Permissions` (catalogue), `RoleMap` (what each role can do, built by
  Nexus), `Screens` (route → permissions), `Access` (the one check).
- `access` middleware (`EnsureScreenAccess`) replaced all 32 `role:` / `role_or_permission:`
  rules in `routes/web.php`; it also re-checks Livewire actions on those pages.
- Old database permissions (`manage users`, `record insurer payments`, `manage billing`,
  `approve clearance revoke`) still grant their screens, so live access is unchanged.
- Proof: `tests/Feature/AccessScreensTest.php` compares all 9 roles on all 114 guarded routes
  with the old rules saved in `tests/Fixtures/route-access-before.json` — no differences.
  Full suite: 1270 tests pass.

**Phase 1 part B, part 1 — done 2026-11-18 (pages and menu).**
- Decisions applied in `RoleMap`: Managers lose sales reports, income / combined statement and
  settings; Opticians lose the till; the admin dashboard has its own permission (`dashboard.view`).
- The ~25 page-entry role checks (settings, approvals, expenses, reports, income statement, insurer
  payments, subscription, licence, cashier dashboard, sales records, balances, refund log, legacy
  pages, optical owed) now ask `Access::allows` for the same permission as their route, so a route
  and its page can no longer disagree. `FinanceStatements` uses the same permissions.
- The menu (`ClinicNavigation::visible`) hides any link to a page the person can't open, using the
  same screen map — fixes Staff seeing four cashier links it couldn't open.
- Fixes: optical-link accept page now needs `optical_centres.manage` (it lands on that page); the
  old optical "customers" link is refused cleanly for bench roles; the dead
  `/livewire/ajax-patients` route is removed.
- Tests: `AccessPagesTest` loads every page as every role in a test clinic (opens what the map
  allows, refused or redirected otherwise) and checks every role's menu only links to pages it can
  open; `AccessScreensTest` lists the deliberate changes against the old snapshot.

**Phase 1 part B, part 2 — done 2026-11-18 (actions inside pages).**
- About 150 action checks moved from role names to permissions across ~75 files (Livewire
  components, services, controllers, middleware, views). Views use `@access('perm') … @endaccess`
  or `Access::can('perm')`.
- New action permissions (who has them by default):

| Permission | What | Default |
|---|---|---|
| `records.delete` | Delete patient documents, letters, quotations; cancel any scan order | Owner |
| `stock.write_off` | Write off expired / lost stock | Owner |
| `cashbook.correct` | Correct the cash book, reopen closed days | Owner |
| `approve.corrections` | Approve payment corrections and voids | Owner |
| `approve.spectacle_renewals` | Approve early spectacle renewals | Owner |
| `attendance.payroll` | Payroll and pay rates (incl. staff import with pay) | Owner |
| `attendance.emails` | Clock-in emails | Owner |
| `staff.grant_owner` | Give or take the Owner role | Owner |
| `sales.refund_any` | Request refunds on any sale | Owner, Manager |
| `sales.refund_own` | Request refunds on own sales | Cashier |
| `pos.all_open_sales` | Continue other staff's open sales at the till | Owner, Manager |
| `receipts.view_all` | See every receipt, not only their own | Owner, Manager, Secretary |
| `scans.manage` | Scan prices, commission, partners | Owner, Manager |
| `spectacles.override` | Override a spectacle order status (with a reason) | Owner, Manager |

- Existing permissions now also guard their actions: optical manager actions → `optical.manage`;
  expense edits → `expenses.manage` (`optical.manage` on the optical side); discounts above the
  limit and approver checks → `approve.discounts`; insurer payments → `insurance.payments`;
  SMS / WhatsApp settings → `messages.patients`; billing, add-ons, licence notices →
  `billing.manage`; clinic settings, receipts, owner emails, links → `settings.manage`.
- Layout and workspace choices (admin layout, doctor vs reception menu, workspace switcher,
  profile quick links, communications menu, setup guide) follow permissions.
- Kept on purpose (who someone *is*, not what they may do): the owner passes every check; platform
  news goes to clinic owners; "is the person being edited an owner"; a doctor opening a record
  needs its patient; landing page by role. `AccessActionsTest` fails if a new role-name check
  appears anywhere else.
- Visible change: a Manager who holds the old "manage users" permission now sees **Users** in the
  menu (they could already open it).

### Browser check per role — 2026-11-18 (after phase 1A)

One local test user per role in a local all-features test clinic (clinic 20, "RC Role Check"),
headless Chrome: landing page, every menu link opened, all 101 guarded pages without
parameters requested, and the role's main search typed.

| Role | Lands on | Menu links | Guarded pages as expected | Search |
|---|---|---|---|---|
| Super Admin | Admin dashboard | 32, all open | 99 / 101 | POS, patients, clearance ✓ |
| Manager | Admin dashboard | 17, all open | 94 / 101 | POS, patients, clearance ✓ |
| Secretary | Front desk dashboard | 10, all open | 98 / 101 | POS, patients, clearance ✓ |
| Cashier | POS | 7, all open | 99 / 101 | POS ✓ |
| Doctor | Doctor dashboard | 6, all open | 101 / 101 | – |
| Optician | Lab workbench | 6, all open | 95 / 101 | POS ✓ |
| Optical Assistant | Optical orders | 10, all open | 101 / 101 | not found by the script |
| Lab Technician | Lab workbench | 8, all open | 100 / 101 | – |
| Staff | Profile | 6, **4 refused** | 101 / 101 | – |

None of the differences come from phase 1A; all were there before (the routes give the same
answers as the old rules). Found:
1. **Page refuses what its route allows** (in-page role checks, phase 1B): Manager on Reports,
   Income / Combined statement and Settings (pages admit Super Admin only); Optician on cashier
   dashboard, sales records, outstanding balances and refund log (pages leave Optician out). The
   menu already hides these, so people don't see broken links.
2. **Staff's menu shows four cashier links it cannot open.**
3. **Secretary:** accepting an optical-centre link redirects to Optical Centres, a Manager-only page.
4. **Old optical "customers" link** redirects to Partners, which Optician / Lab Technician can't open.
5. **`/livewire/ajax-patients` (500):** the route points to a POS method that no longer exists; nothing uses it.
6. Scans refused everywhere: the add-on isn't in "all features" — a plan gate, not a role gate.
7. Optician can open the clinic POS (route and page both allow it).

### Browser check per role — after phase 1B part 1 (2026-11-18)

Same nine single-role users in local test clinic 20. Every menu link opened for every role, and
all 100 guarded pages answered as the screen map says. The only difference: Diagnostic Scans is
refused for everyone because the scans add-on isn't part of "all features" — a plan gate.
Opticians are now refused every till page; Staff's menu shows only Dashboard and Needs Attention;
Managers' menu no longer lists Reports, Income / Combined statement or Settings.
Full suite: 1272 tests pass.

### Browser check per role — after phase 1B part 2 (2026-11-19)

Nine single-role users, local test clinic 20: every menu link opened for every role; all 100
guarded pages answered as the screen map says (Diagnostic Scans refused for everyone: plan
add-on). Main searches work (POS, patients, clearance). Managers' menu now lists Users.
Full suite: 1275 tests pass.

**Phase 2 — done 2026-11-19 (Owner name, Staff kept).**
- `App\Support\Access\RoleNames` turns stored names into what clinics see: Super Admin → **Owner**,
  Staff → **Staff (attendance only)**. Role lists put Owner first, then Manager, then A–Z.
- Clinic screens use it: Users page (filter, badges, role ticks, branch roles, CSV export), admin
  dashboard sign-ins, profile, the read-only Roles page. Wording in messages and hints now says
  "Owner" / "the clinic owner" (POS discount approval, clearance revoke, password reset, stock
  expiry, scans/user limits, delete buttons).
- **The stored role is still `Super Admin`** (`RoleMap::OWNER`): imports, `clinic_user.clinic_role`,
  notifications and Gate::before all key on it, so renaming the data would touch 60+ places and old
  imported records for no visible gain. The platform screens keep saying "Super Admin".
- **Staff was not removed.** The data showed it is in use: attendance-only clinics offer only
  Staff / Manager / Owner, and 14 people at full clinics hold only Staff (clock-in-only staff such
  as cleaners or drivers). There is no job role to move them to, so it stays with a clearer label.
- Manager and clinical records: unchanged (no access), as today. Secretary / Cashier insurer
  payments: still the clinic edits from section 0, undecided.

**Phase 3 — done 2026-11-19 (money rules).** Needs `migrate` (2026_11_19_000001: `settings.discount_limits`).
- **Discount limits** (`App\Support\Access\DiscountLimits`): the Owner sets, per role, the most each
  may take off a sale without approval at the clinic till and the optical till (Settings → Discount
  Limits, and Optical Settings → Discount limits). Owner: no limit. Two roles: the higher limit.
  Defaults keep the old behaviour: Manager 100%; Secretary, Cashier, Optical Assistant 0% at the
  clinic till and the shop's old "largest POS discount" setting (10%) at the optical till. That old
  field left Optical Settings; it is only the default now. Roles not in the table get the same
  default as desk roles (a role with no till access never reaches a till anyway).
- **Clinic till:** within the limit, Apply uses the discount at once (recorded as approved by the
  seller); above it, the usual request goes to a Manager or the Owner. Managers no longer approve
  every discount of their own automatically — only within their limit (100% unless lowered).
- **Optical till and optical orders:** above the limit the sale or order is refused with "a Manager
  or the Owner must…". Orders made from a quotation keep the quotation's discount. Agreed trade
  discounts don't count.
- **Maker–checker** (`MakerChecker`): nobody approves their own discount, refund/void or clearance
  revoke — on the queues, the managers' pop-up and the manager sign-in at the till. The Owner is
  the exception, so a one-owner clinic never gets stuck. Own requests show "Your request" in the
  queues instead of Approve. Payment corrections, password resets and spectacle renewals are
  Owner-only queues already.
- Approval queues each check their own permission on every action (clearance revokes now too); the
  till sign-in checks the approver's roles in this branch through the role map, not role names.
- Unchanged: plans without approvals still apply discounts and refunds at once. Optical order
  cancellations refund at once by whoever may cancel (not an approval queue).
- Tests: `MoneyRulesTest` (9); one optical order test now gives its user a desk role. Full suite: 1286 tests, the 2 failures (unlisted-role default, role-less test user) fixed and their file rerun. Tours: clinic POS, optical POS, clinic dashboard bumped.

**Phase 4 — done 2026-11-19 (per-person access).** Needs `migrate` (2026_11_19_000002:
`clinic_user_permissions`).
- **Access page per person:** Users → shield button → `admin/users/{user}/access`. Every permission
  in the catalogue, grouped like section 3, with "From role" (✓ / –) and "This person" (As role / On
  for them / Off for them). "Not in your plan" rows are greyed and can't be changed. "What they can
  see" shows their menu with the saved access. "Back to role" clears the switches you may change.
- **How it counts:** `Access::allows` = Owner → always; else this person's switch in this clinic,
  if any; else their role (and legacy database permissions). Pages, menus and actions all follow,
  because they all ask `Access`. Switches are per clinic (`clinic_user_permissions`, read once per
  request) and survive role changes; removing someone from a clinic clears theirs there.
- **Who may switch** (decided 2026-11-19, design defaults): the Owner, anything; Managers, only
  permissions they hold themselves and never approvals; nobody changes their own access or the
  Owner's. "Give or take the Owner role" is never switchable.
- **Audit and email:** each save writes `staff.access_changed` (before/after) to the audit trail;
  when someone gains access their role didn't give, the Owner gets "Staff member given more access".
- **Not changed:** the optical screens' own permissions (optical sales / lab / stock / reports) are
  still role-based through `OpticalAccess`; only `optical.manage` is switchable. The old clinic
  edits to shared roles (section 0) are left as they are.
- Tests: `PersonalAccessTest` (8). Browser: Owner switched Expenses on for the test Cashier → their
  menu preview gained Expenses and they could open it; back to role → refused again.
