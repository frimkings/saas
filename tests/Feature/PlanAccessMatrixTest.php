<?php

namespace Tests\Feature;

use App\Services\ClinicAccessService;
use App\Support\PlanProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * Every page a plan can lock, on each of the four Clinical Suite plans (18 Nov 2026).
 *
 * The table below is the intent, written from the tier decisions (24 Oct 2026), not read from the routes:
 * the lowest plan that should open each page. A new locked page fails this test until it is added here.
 */
class PlanAccessMatrixTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\ClinicOnPlan;

    /** Pages that need a selection in the query: checked by their locks, like pages for one record. */
    private const NEEDS_INPUT = ['doctor.visit-summaries.download'];

    private const PLANS = ['clinic-starter', 'clinic-standard', 'clinic-plus', 'clinic-pro'];

    /** Lowest plan that opens the page; 'none' = not on any clinic plan (add-ons, optical shop, offline only). */
    private const LOWEST_PLAN = [
        // Starter: products and stock receiving.
        'admin.stock-movements' => 'clinic-starter',
        // Standard.
        'secretary.appointments' => 'clinic-standard',
        'doctor.referrals' => 'clinic-standard',
        'doctor.referral.pdf' => 'clinic-standard',
        'admin.inventory-alerts' => 'clinic-standard',
        'admin.messages' => 'clinic-standard',
        'admin.whatsapp-settings' => 'clinic-standard',
        'admin.sms-settings' => 'clinic-standard',
        'admin.sms-logs' => 'clinic-standard',
        'admin.broadcast' => 'clinic-standard',
        'admin.patient-recall' => 'clinic-standard',
        'staff.messages' => 'clinic-standard',
        'attention' => 'clinic-standard',
        'attention.old-orders' => 'clinic-standard',
        'admin.roles-permissions' => 'clinic-standard',
        // Plus.
        'doctor.consultation.pdf' => 'clinic-plus',
        'doctor.medical-record.pdf' => 'clinic-plus',
        'doctor.medical-record.preview' => 'clinic-plus',
        'doctor.visit-summaries.download' => 'clinic-plus',
        'doctor.visit-summary.print' => 'clinic-plus',
        'cashier.outstanding-balances' => 'clinic-plus',
        'admin.quotations' => 'clinic-plus',
        'admin.quotations.pdf' => 'clinic-plus',
        'secretary.spectacles' => 'clinic-plus',
        'secretary.optical-sent' => 'clinic-plus',
        'secretary.optical-owed' => 'clinic-plus',
        'reports.export.pdf' => 'clinic-plus',
        'admin.expenses' => 'clinic-plus',
        'admin.expenses.previous' => 'clinic-plus',
        'admin.expenses.receipt' => 'clinic-plus',
        'admin.cash-book' => 'clinic-plus',
        'admin.day-close' => 'clinic-plus',
        'admin.purchase-orders' => 'clinic-plus',
        'admin.purchase-orders.pdf' => 'clinic-plus',
        'admin.suppliers' => 'clinic-plus',
        'admin.stock-transfers' => 'clinic-plus',
        'admin.income-statement' => 'clinic-plus',
        'admin.income-statement.preview' => 'clinic-plus',
        'admin.income-statement.export.csv' => 'clinic-plus',
        'admin.income-statement.export.pdf' => 'clinic-plus',
        // Pro.
        'admin.lens-outstanding-report' => 'clinic-pro',
        'admin.patient-ledger' => 'clinic-pro',
        'admin.insurance.claims' => 'clinic-pro',
        'admin.insurance.insurers' => 'clinic-pro',
        'admin.insurance.payments' => 'clinic-pro',
        'admin.insurance.receivables' => 'clinic-pro',
        'admin.audit-trail' => 'clinic-pro',
        'admin.login-history' => 'clinic-pro',
        // On no clinic plan: add-ons (attendance, scans), the optical shop, offline backups, unused report delivery.
        'admin.attendance' => 'none', 'admin.attendance.departments' => 'none', 'admin.attendance.export' => 'none',
        'admin.attendance.roster' => 'none', 'admin.attendance.roster-page' => 'none',
        'frontdesk.scans' => 'none', 'frontdesk.scans.report' => 'none',
        'admin.backup.download' => 'none', 'admin.report-delivery' => 'none',
        'admin.combined-statement' => 'none', 'admin.combined-statement.export' => 'none',
    ];

    /** Locked GET routes and the feature lists that lock them (each list: any one opens; every list must pass). */
    private function lockedRoutes(): array
    {
        $map = config('subscriptions.route_features', []);
        $routes = [];
        foreach (app('router')->getRoutes() as $route) {
            /** @var Route $route */
            $name = $route->getName();
            if (!$name || !in_array('GET', $route->methods(), true)) continue;
            $gates = [];
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && preg_match('/^feature(?:-or-leftover)?:(.+)$/', $middleware, $m)) $gates[] = explode(',', $m[1]);
            }
            foreach ($map as $prefix => $feature) {
                if ($name === $prefix || str_starts_with($name, $prefix.'.')) $gates[] = [$feature];
            }
            $tier = collect($gates)->flatten()->reject(fn ($f) => in_array($f, [PlanProduct::CLINIC, 'clinical'], true))->isNotEmpty();
            if ($tier) $routes[$name] = ['route' => $route, 'gates' => $gates];
        }

        return $routes;
    }

    public function test_every_locked_page_has_an_intended_plan(): void
    {
        $missing = array_diff(array_keys(array_filter($this->lockedRoutes(), fn ($r, $name) => !str_starts_with($name, 'optical.'), ARRAY_FILTER_USE_BOTH)), array_keys(self::LOWEST_PLAN));
        $this->assertSame([], array_values($missing), 'New locked pages: say which plan should open them in LOWEST_PLAN.');
        $gone = array_diff(array_keys(self::LOWEST_PLAN), array_keys($this->lockedRoutes()));
        $this->assertSame([], array_values($gone), 'These pages are in LOWEST_PLAN but are no longer locked (or were renamed).');
    }

    public static function plans(): array
    {
        return array_combine(self::PLANS, array_map(fn ($p) => [$p], self::PLANS));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('plans')]
    public function test_each_plan_opens_exactly_the_pages_intended_for_it(string $family): void
    {
        $routes = $this->lockedRoutes();
        $wrong = [];
        $rank = array_search($family, self::PLANS, true);
        {
            $this->ownerOn($family);
            $access = app(ClinicAccessService::class);
            foreach ($routes as $name => ['route' => $route, 'gates' => $gates]) {
                $lowest = str_starts_with($name, 'optical.') ? 'none' : (self::LOWEST_PLAN[$name] ?? null);
                if ($lowest === null) continue; // reported by the test above
                $should = $lowest !== 'none' && $rank >= array_search($lowest, self::PLANS, true);

                if (!str_contains($route->uri(), '{') && !in_array($name, self::NEEDS_INPUT, true)) {
                    $status = $this->get('/'.ltrim($route->uri(), '/'))->baseResponse->getStatusCode();
                    $opens = $status === 200;
                    if ($should && !$opens) $wrong[] = "$family should open $name (got $status)";
                    if (!$should && !in_array($status, [302, 403], true)) $wrong[] = "$family should not open $name (got $status)";
                } else {
                    // Pages for one record: check the route's locks (the request would stop at the missing record first).
                    $opens = collect($gates)->every(fn ($any) => collect($any)->contains(fn ($f) => $access->access($f)['allowed']));
                    if ($opens !== $should) $wrong[] = "$family ".($should ? 'should' : 'should not')." open $name (locks: ".json_encode($gates).')';
                }
            }
        }

        $this->assertSame([], $wrong, implode("\n", $wrong));
    }

    /**
     * Route names of the locked pages linked for the owner: the menu shows the current section only, so one page
     * of each section (administration, clinical, stock) plus what those pages link to.
     */
    private function linkedLockedPages(string $family): array
    {
        $this->ownerOn($family);
        $html = '';
        foreach (['admin.dashboard', 'admin.clinical-task-center', 'admin.product'] as $page) $html .= $this->get(route($page))->assertOk()->getContent();
        preg_match_all('/href="([^"#]+)"/', $html, $m);
        $names = [];
        foreach (array_unique($m[1]) as $href) {
            $path = parse_url(html_entity_decode($href), PHP_URL_PATH);
            if (!$path || (parse_url($href, PHP_URL_HOST) && parse_url($href, PHP_URL_HOST) !== parse_url(config('app.url'), PHP_URL_HOST))) continue;
            try {
                $name = app('router')->getRoutes()->match(\Illuminate\Http\Request::create($path, 'GET'))->getName();
            } catch (\Throwable) {
                continue;
            }
            if ($name && (isset(self::LOWEST_PLAN[$name]) || str_starts_with($name, 'optical.'))) $names[] = $name;
        }

        return array_values(array_unique($names));
    }

    public function test_no_plan_is_shown_links_to_pages_it_cannot_open_nor_misses_links_it_paid_for(): void
    {
        $rankOf = fn (string $name) => str_starts_with($name, 'optical.') || self::LOWEST_PLAN[$name] === 'none'
            ? PHP_INT_MAX : array_search(self::LOWEST_PLAN[$name], self::PLANS, true);
        $links = [];
        foreach (array_reverse(self::PLANS) as $family) {
            $links[$family] = $this->linkedLockedPages($family);
            $this->flushSession();
            $this->app['auth']->forgetGuards();
        }

        $wrong = [];
        foreach (self::PLANS as $rank => $family) {
            foreach ($links[$family] as $name) {
                if ($rankOf($name) > $rank) $wrong[] = "$family is shown a link to $name, which it cannot open";
            }
            // Every link Pro gets for a page this plan can open, this plan gets too.
            foreach ($links['clinic-pro'] as $name) {
                if ($rankOf($name) <= $rank && !in_array($name, $links[$family], true)) $wrong[] = "$family has no link to $name, which it can open";
            }
        }

        $this->assertNotEmpty($links['clinic-pro'], 'the dashboard links to locked pages on Pro');
        $this->assertSame([], $wrong, implode("\n", $wrong));
    }
}
