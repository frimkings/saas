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

## Verification

- Production asset build and Blade compilation.
- Financial tenancy regression tests.
- Expenses workflow regression: form rendering, required validation, creation,
  editing, category creation, and pagination reset when page size changes.
- Browser review of the local component showcase; authenticated Expenses dialog,
  receipt upload, keyboard, and mobile checks should accompany pilot acceptance.

This increment does not migrate the full application or resolve unrelated changes
already present in the working tree.
