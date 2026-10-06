# Tailwind + Blade migration

## First increment: Expenses

The admin shell still uses AdminLTE. Expenses now uses the shared components in
`resources/views/components/ui`, styled with Tailwind `@apply` in
`resources/css/clinic-ui.css`. Every selector is scoped to `.clinic-ui`.
The existing global Tailwind configuration and preflight remain unchanged.
This avoids changing authentication pages and other legacy screens as part of
the pilot. Vite loads before AdminLTE, so scoped selectors also provide enough
specificity for the migrated components.

Visit `/design-system` in a local environment for the component showcase and
`/admin/expenses` for the working pilot using existing permissions and licensing.
The showcase contains sample data and is unavailable outside the local environment.

## Stage 1: clinic layout and reception

Clinic screens now move to `layouts/clinic.blade.php`, a Tailwind-only shell with no
Bootstrap or AdminLTE CSS (like `layouts/optical.blade.php`). Pages that still use Bootstrap
markup stay on their AdminLTE layout until they are converted, so a converted page never
has to fight Bootstrap's `!important` utilities (`p-3`, `border`, `rounded`, `shadow`, …).

- Moved in stage 1: the whole reception menu: dashboard, Patients (Registry Hub), Appointments,
  Patient Clearance, Spectacles, Point of Sale, Sales Records and Outstanding Balances. They use
  `->layout('layouts.clinic', ['menu' => 'reception'])` or `<x-clinic-layout>`.
- Sales Records is also an admin page, so its view works inside both layouts: scoped `ui-*`
  classes plus Tailwind names Bootstrap doesn't override (no bare `border`, `border-0`,
  `rounded-lg`, `shadow`, or `p-`/`m-` sizes 3–5). It picks its layout and pagination by area.
- POS checkout confirmations moved from `layouts/scripts` to `layouts/partials/till-scripts`
  (SweetAlert stays confined to the shared scripts; see ConfirmDialogTest).
- Sidebar menus are data in `App\Support\ClinicNavigation`; add a menu there when a role's
  screens move (the AdminLTE sidebars stay until then).
- Shared with the AdminLTE layouts: `layouts/partials/page-title`, `session-scripts` (idle
  logout, page poll, discount prompts), `toasts`, `confirm-dialog`, and the
  `subscription-notice` / `license-notice` components. The bell and messages menus take
  `theme="clinic"` for their Tailwind markup.
- Toasts come from the `notify` browser event (`layouts/partials/toasts`), so components keep
  dispatching `notify` as before. Livewire pagination uses the Tailwind theme.
- Dialogs are conditional Tailwind overlays driven by the component's existing boolean state,
  with Escape wired to the same close method.
- Tailwind is 3.0: no `aria-*` or arbitrary variants. Scoped `.clinic-ui .ui-*` classes
  (`ui-input`, `ui-badge`, …) outrank plain utilities, so override them with the important
  modifier (`!pr-10`), or use plain utilities instead of `ui-badge` for coloured badges.
- In inline scripts, write closing tags inside strings as `<\/…>`: Livewire's one-root check
  parses components with DOMDocument, which ends a script at the first `</`. Never put a literal
  closing head tag in a string: NativePHP injects a script before every one it finds.
- Shared pages (Needs Attention, Old Orders, Messages, Profile, Refund Logs) pick their layout with
  `ClinicNavigation::sharedLayout()`: clinic layout and own menu for reception and doctors, the
  admin layout for Super Admins and Managers until the admin screens move.
- Nothing uses AdminLTE now; see Cleanup.

## Stage 2: doctor screens

- Moved: doctor dashboard, Patient Queue, the consultation workspace (Patient Records, all
  tabs), All Records, Clinical Timeline and Referrals, with `['menu' => 'doctor']`. The
  doctor's new-clearance sound and toast moved to `layouts/partials/doctor-clearance-alert`.
- Heavy Bootstrap markup was converted with `python tools/bs2tw.py <views…>` (Bootstrap 4 utilities and
  components to Tailwind, grid columns to `w-N/12` fractions). It keeps `card`, `card-header`,
  `card-body`, `btn`, `form-control`, `table` and `list-group-item` as plain hooks because
  page CSS still targets them; CSS that targeted `.col-*` or `.d-flex` now targets children or
  the Tailwind class. Helpers it relies on: `ui-button-sm`, `ui-button-link`, `ui-input-sm`,
  `ui-table-sm` (clinic-ui.css).
- select2 is replaced by `<x-ui.multi-select model="…" :options="…">` (Alpine, entangled with the
  Livewire array), Bootstrap dropdowns by small Alpine menus, and jQuery modals by overlays
  toggled from the same browser events.
- The unused, unconverted doctor components (ShowConsultation, UpdateConsultation,
  CreateConsultation, Users, Patients) were deleted in the cleanup.

## Stage 3: admin screens

- `layouts/admin/admin-layout.blade.php` now just renders the clinic layout, so all ~45 admin
  pages moved together. Super Admins and Managers get `ClinicNavigation::admin()` (the old
  AdminLTE sidebar as data, same workspace, role, permission and feature checks) on every page,
  including reception and doctor pages; anyone else on an admin page gets their own menu.
- Every admin view went through `tools/bs2tw.py`, which now also turns Bootstrap modals into
  overlays (a jQuery-opened `modal fade` starts hidden). `layouts/partials/ui-toggles` replaces
  the Bootstrap JavaScript those screens used: `uiModal(id, open)` instead of
  `$('#id').modal(...)`, plus `data-toggle="modal|collapse|dropdown"` and `data-dismiss="modal"`.
- clinic-ui.css styles the few Bootstrap/AdminLTE names kept on these screens: breadcrumbs,
  collapse, dropdown menus, info-box / small-box stat widgets and coloured card accents.
- Page roots got `clinic-ui ui-page`; a page embedded in another (approval queues, settings
  tabs) loses the second padding.

## Stage 4: platform console and chooser pages

- `layouts/platform`, `tenant/select-clinic` and `tenant/select-workspace-mode` load
  `layouts/partials/lean-head` (Tailwind build, icons, Livewire, session scripts) instead of
  `layouts/scripts`. Their own dark styling (pa-*, pp-*) never used Bootstrap classes.
- `.platform-ui` on their body restores Bootstrap's base type (heading sizes, paragraph and
  label spacing, inherited select font size) at the lowest priority, so the pages look as before.
- After this stage no routed page loaded `layouts/scripts` (Bootstrap, AdminLTE, jQuery, toastr,
  select2, tempusdominus, daterangepicker); see Cleanup.

## Cleanup

- Removed: `layouts/scripts`, the reception and doctor AdminLTE layouts, the AdminLTE sidebars,
  navbar, footer and profile, the optical-navigation partial, the Bootstrap versions of the bell
  and messages menus, and the unused jQuery components (date-picker, date-range-picker,
  direct-questions, search-input). The page-level event handlers still needed (delete
  confirmation, print refraction) live in `layouts/partials/app-events`.
- `public/backend` (the full AdminLTE distribution, 123 MB) is trimmed to what pages load: Font
  Awesome, SweetAlert, Chart.js, Pikaday's CSS and the local Nunito font (3.7 MB).
- Deleted as unused: the doctor ShowConsultation, UpdateConsultation, CreateConsultation, Users
  and Patients components, SellerDesk (the seller-desk route loads POS), BootstrapComponent,
  the appointment-booking-form and refraction-modal components and the referral-snippet-buttons
  partial. The `/cart` route still fails without a patient and nothing links to it.

## Conventions

- Use teal for primary actions, slate for neutral surfaces, and red for destructive
  actions and validation. Keep category labels readable rather than using arbitrary
  category colors as text backgrounds.
- Use `x-ui.button`, `panel`, `stat`, `badge`, `field`, and `modal`.
  Forward Livewire bindings through component attributes.
- Field names generate matching labels, input IDs, and validation descriptions.
- Modal components use native dialog focus containment and Escape handling.
  Render them conditionally from their Livewire boolean state.
- Use scoped `ui-table` markup with column headers and an overflow container.
- Disable submit actions during saves and receipt uploads. Keep authorization
  checks in the backend as well as presentation-level visibility checks.
- Prefer readable 44px controls, wrapping action groups, and stacked narrow forms.
- Colour classes built at runtime (`badge-{{ $tone }}`, `text-{{ $tone }}`, `bg-{{ $tone }}`,
  `alert-{{ $tone }}` with Bootstrap names such as `success` or `warning`) can't be
  converted by `tools/bs2tw.py`. `resources/css/clinic-ui.css` maps those names onto the
  clinic palette. In new code, build full Tailwind class names instead.
- Livewire pages use `$paginationTheme = 'tailwind'`; `tests/Unit/PaginationThemeTest.php`
  fails if a page goes back to the Bootstrap pager.

## Audit findings and remaining rollout

1. Shared scripts load Tailwind, AdminLTE, Bootstrap JavaScript, jQuery, Select2,
   date pickers, Toastr, and SweetAlert. Do not remove these before their consumers
   migrate.
2. The admin layout owns the navbar, role-aware sidebar, footer, and content wrapper.
   Migrate that shell as a separate increment after pilot review.
3. Existing authentication Blade components use unscoped Tailwind classes.
   Consolidate them after the new component APIs settle.
4. Next migrate sales and financial screens, then patient and appointment workflows,
   inventory, and the role dashboards. Preserve role and feature gates per screen.
5. Add dedicated print layouts where reports and receipts need them; screen styles
   are not a replacement for print/export templates.
6. Remove AdminLTE, Bootstrap, and unused plugins only after a consumer inventory
   confirms they are unused. Revisit global preflight at that point.

## Next stage: single-page navigation (after the Tailwind release)

Goal: page changes without a full reload, so the sidebar, top bar and bell stay in place,
using Livewire's built-in `wire:navigate`. The PHP components stay as they are; no API or
JavaScript front end is needed. Start this only after the Tailwind work is live, because it
touches the same views.

Estimate: 3 to 5 days, done menu by menu (reception, then doctor, then admin).

**Optical: done (commit 58e41d7, 2026-10-05).** Menu and in-page links use `wire:navigate`
(`OpticalNavigation::navigable()`); documents, exports, Staff & roles and Sales Records stay
ordinary links. Sales Records waits for the clinic stage: its shared script declares a
top-level `let currentSaleId` and adds window/keydown listeners each run. Lessons from optical:
top-level `let`/`const` in a page script throws on the second visit (use `var` or an IIFE);
guard unsaved work with Alpine `x-on:livewire:navigate.document` (prevent, confirm, then
`Livewire.navigate(url)`); mark run-once layout scripts `data-navigate-once`.

### Inventory (2026-10-05)

- 125 Livewire views; 36 of them, plus shared components and layout partials, contain
  `<script>` blocks.
- 32 views add `window` or `document` listeners. Without a page reload these would stack
  up and fire twice, then three times (for example the clearance dialog, chart redraws and
  printRefraction).
- 4 scripts wait for `DOMContentLoaded`, which never fires after a navigate.
- 1 `setInterval`, plus the idle-logout timer in `layouts/partials/session-scripts`.
- Chart.js charts (Reports, dashboards) must be destroyed before they are drawn again.
- No view uses `wire:navigate` yet.

### Steps

1. **Links.** Add `wire:navigate` in `layouts/partials/clinic-nav-link` (the sidebar), then
   to in-page links. Links that change layout (clinic app ↔ platform console, workspace
   switch) and file downloads (CSV/PDF exports, receipts) stay ordinary links.
2. **Persistent shell.** Wrap the sidebar, top bar, notification bell and staff messages
   dropdown in `@persist(...)` so their state and scroll position survive page changes.
3. **Page scripts.** Use one pattern everywhere:
   - set up on `livewire:navigated`, not `DOMContentLoaded`;
   - register each `window`/`document` listener once (guard with a flag on `window`), or
     remove it on `livewire:navigating`;
   - clear timers and destroy charts on `livewire:navigating`;
   - make shared scripts (session timers, toasts, `ui-toggles`, `app-events`) idempotent
     so a second run does nothing.
4. **NativePHP.** Check that the script the desktop build injects into every `</head>`
   behaves when the head is merged instead of reloaded.
5. **Progress bar.** Keep Livewire's navigate progress bar, coloured with the clinic teal.

### Checks

- Headless-browser sweep (CDP, as used for the clearance dialog): visit every menu entry,
  go back and forth, and assert no console errors and that each dialog, print and chart
  handler fires exactly once.
- A feature test that the sidebar links render `wire:navigate` and download links don't.
- Manual click-through on Laragon and the desktop build before release.

## Verification

- Production asset build and Blade compilation.
- Financial tenancy regression tests.
- Expenses workflow regression: form rendering, required validation, creation,
  editing, category creation, and pagination reset when page size changes.
- Browser review of the local component showcase; authenticated Expenses dialog,
  receipt upload, keyboard, and mobile checks should accompany pilot acceptance.

This increment does not migrate the full application or resolve unrelated changes
already present in the working tree.
