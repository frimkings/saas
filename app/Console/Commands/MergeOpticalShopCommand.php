<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Fold an Optical Suite shop's account into a clinic's account at the same place (20 Nov 2026, ICARE OPTICALS
 * into ICARE VISION CENTRE): the clinic becomes one Clinic + Optical business.
 *
 * The shop's business records (orders, sales, payments, products, stock, purchasing, partners, optical set-up,
 * its audit trail) move to the clinic and its main branch. The shop's account history (subscriptions, invoices,
 * usage, sign-ins, owner emails, settings) stays with the shop's account, which is then retired from the platform.
 * Nothing is deleted: where both accounts have the same optical set-up row, the clinic's unused default is swapped
 * over to the shop's account and the shop's own version moves in. Its staff join the clinic with their roles.
 *
 * Without --commit it is a dry run: everything is done inside a transaction, reported, and rolled back.
 * Any table it doesn't know, or any clash it can't resolve, stops it with nothing changed.
 *
 * The clinic's plan must include the Optical Suite for the moved records to show (Platform → Plans).
 */
class MergeOpticalShopCommand extends Command
{
    protected $signature = 'clinics:merge-optical-shop {shop : The optical shop clinic id} {clinic : The clinic id it joins} {--commit : Make the changes (otherwise a dry run)}';

    protected $description = 'Fold an Optical Suite shop account into a clinic at the same place (dry run unless --commit)';

    /** The shop's business: moved to the clinic (clinic_id, and branch_id to the clinic's main branch). */
    private const MOVE = [
        'app_notifications', 'audit_trails', 'lens_order_events', 'lens_orders', 'optical_lens_imports', 'optical_lens_options',
        'optical_lens_prices', 'optical_order_lens_lines', 'optical_order_services', 'optical_partner_clinics',
        'optical_product_stock_movements', 'optical_product_stocks', 'optical_products', 'optical_purchase_order_lines',
        'optical_purchase_orders', 'optical_services', 'optical_settings', 'optical_stock_count_lines', 'optical_stock_counts',
        'payment_transactions', 'sales', 'suppliers', 'lens_options',
    ];

    /** The shop's account history: stays with the retired account. */
    private const KEEP = [
        'billing_notification_logs', 'clinic_subscriptions', 'clinic_usage_daily', 'clinic_usage_users', 'clinic_user', 'login_logs',
        'owner_alert_items', 'owner_emails', 'platform_audit_logs', 'platform_invoices', 'settings', 'subscription_agreements',
        'subscription_changes', 'subscription_collection_cases', 'user_guides', 'branches', 'branch_user', 'branch_user_role',
    ];

    /** Set-up lists both accounts may hold; the match key, and whether the shop's row wins (swap) or the clinic's (shop's stays). */
    private const OVERLAP = [
        'optical_lens_options' => [['kind', 'code'], 'swap'],
        'optical_services' => [['code'], 'swap'],
        'optical_settings' => [[], 'swap'],
        // The clinic's lens types are what its doctors pick in refractions: keep the clinic's; the shop's duplicates stay behind.
        'lens_options' => [['family', 'display_name'], 'clinic'],
    ];

    /** Tables that point at a clinic from another column: anything here for the shop needs a decision first. */
    private const OTHER_CLINIC_COLUMNS = [
        'optical_order_requests' => ['optical_clinic_id', 'sender_clinic_id'],
        'optical_partner_clinics' => ['linked_clinic_id'],
        'optical_link_invites' => ['optical_clinic_id'],
    ];

    private array $report = [];

    /** Swapped rows waiting for their tag to come off: table => [column, ids]. */
    private array $parked = [];

    public function handle(): int
    {
        $shopId = (int) $this->argument('shop');
        $clinicId = (int) $this->argument('clinic');
        $commit = (bool) $this->option('commit');
        $shop = DB::table('clinics')->find($shopId);
        $clinic = DB::table('clinics')->find($clinicId);
        if (! $shop || ! $clinic || $shopId === $clinicId) {
            $this->error('Give two different clinic ids that exist.');
            return self::FAILURE;
        }
        $this->info(($commit ? 'MERGING' : 'DRY RUN').": {$shop->name} (#{$shopId}) into {$clinic->name} (#{$clinicId})");

        try {
            DB::beginTransaction();
            $this->merge($shopId, $clinicId);
            $this->verify($shopId);
            $commit ? DB::commit() : DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Stopped, nothing changed: '.$e->getMessage());
            return self::FAILURE;
        }

        $this->table(['What', 'Rows'], collect($this->report)->map(fn ($n, $what) => [$what, $n])->values()->all());
        $this->info($commit ? 'Done. Clear the cache (php artisan cache:clear), then retire the shop account on the platform.' : 'Dry run only: nothing was changed. Run again with --commit to merge.');

        return self::SUCCESS;
    }

    private function merge(int $shopId, int $clinicId): void
    {
        $shopBranches = DB::table('branches')->where('clinic_id', $shopId)->pluck('id')->all();
        $mainBranch = DB::table('branches')->where('clinic_id', $clinicId)->orderByDesc('is_default')->orderBy('id')->value('id');
        if (! $mainBranch) throw new RuntimeException("The clinic has no branch.");

        // Run twice (or on an empty shop): its business is already gone, so there is nothing to do.
        $business = ['lens_orders', 'sales', 'optical_products', 'optical_product_stock_movements', 'optical_partner_clinics', 'suppliers'];
        if (collect($business)->every(fn ($t) => ! Schema::hasTable($t) || ! DB::table($t)->where('clinic_id', $shopId)->exists())) {
            throw new RuntimeException('The shop has no orders, sales, products or partners left: it is already merged (or empty).');
        }

        // 1. Every table holding the shop's rows must be known.
        $unknown = [];
        foreach ($this->tablesWithShopRows($shopId, $shopBranches) as $table) {
            if (! in_array($table, self::MOVE, true) && ! in_array($table, self::KEEP, true)) $unknown[] = $table;
        }
        if ($unknown) throw new RuntimeException('Tables with the shop\'s rows that this command does not know: '.implode(', ', $unknown).'. Decide whether each moves or stays, then add it.');
        foreach (self::OTHER_CLINIC_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) continue;
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column) && DB::table($table)->where($column, $shopId)->exists()) {
                    throw new RuntimeException("{$table}.{$column} points at the shop (online links or requests): unlink or move those first.");
                }
            }
        }

        // 2. Set-up lists both hold.
        foreach (self::OVERLAP as $table => [$key, $winner]) {
            if (! Schema::hasTable($table)) continue;
            $clash = $this->clashingIds($table, $key, $shopId, $clinicId);
            if (! $clash['clinic']) continue;
            if ($winner === 'swap') {
                if (! $key) throw new RuntimeException("Both accounts have {$table}: merge those by hand first.");
                $this->assertUnreferenced($table, $clash['clinic']);
                // The clinic's rows go to the shop account with a short tag on their key, so the key never sees two
                // at once; the tag comes off once the shop's own rows have moved out (step 4).
                $tagged = end($key);
                DB::table($table)->whereIn('id', $clash['clinic'])->update(['clinic_id' => $shopId, $tagged => DB::raw("CONCAT({$tagged}, '~p')")] + $this->touch($table));
                $this->parked[$table] = [$tagged, $clash['clinic']];
                $this->report["{$table}: clinic's unused defaults swapped to the shop account"] = count($clash['clinic']);
            } else {
                $this->report["{$table}: shop duplicates left with the shop account"] = count($clash['shop']);
            }
        }

        // 3. Any other clash on a key with clinic_id stops the merge.
        foreach (self::MOVE as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'clinic_id')) continue;
            foreach ($this->uniqueKeys($table) as $cols) {
                if (! in_array('clinic_id', $cols, true)) continue;
                $others = array_values(array_diff($cols, ['clinic_id']));
                if (! $others || (isset(self::OVERLAP[$table]) && self::OVERLAP[$table][1] === 'clinic')) continue;
                $clashes = DB::table("{$table} as s")->join("{$table} as c", function ($join) use ($others, $clinicId) {
                    $join->where('c.clinic_id', $clinicId);
                    foreach ($others as $col) $join->whereColumn("c.{$col}", "s.{$col}");
                })->where('s.clinic_id', $shopId)->count();
                if ($clashes) throw new RuntimeException("{$clashes} row(s) in {$table} clash on (".implode(', ', $cols).').');
            }
        }

        // 4. Move the business.
        foreach (self::MOVE as $table) {
            if (! Schema::hasTable($table)) continue;
            $hasClinic = Schema::hasColumn($table, 'clinic_id');
            $hasBranch = Schema::hasColumn($table, 'branch_id');
            $query = DB::table($table);
            if ($hasClinic) {
                $query->where('clinic_id', $shopId);
                // The shop's duplicates of lists the clinic keeps stay behind.
                if (isset(self::OVERLAP[$table]) && self::OVERLAP[$table][1] === 'clinic') {
                    $query->whereNotIn('id', $this->clashingIds($table, self::OVERLAP[$table][0], $shopId, $clinicId)['shop'] ?: [0]);
                }
                if (isset($this->parked[$table])) $query->whereNotIn('id', $this->parked[$table][1]);
            } elseif ($hasBranch) {
                $query->whereIn('branch_id', $shopBranches ?: [0]);
            } else {
                continue;
            }
            $set = ($hasClinic ? ['clinic_id' => $clinicId] : []) + ($hasBranch ? ['branch_id' => DB::raw("CASE WHEN branch_id IN (".implode(',', $shopBranches ?: [0]).") THEN {$mainBranch} ELSE branch_id END")] : []);
            $moved = $query->update($set + $this->touch($table));
            if ($moved) $this->report["moved: {$table}"] = $moved;
        }
        // The swapped clinic defaults (step 2) lose their tag, now that the shop's own rows have moved out.
        foreach ($this->parked as $table => [$tagged, $ids]) {
            DB::table($table)->whereIn('id', $ids)->update([$tagged => DB::raw("LEFT({$tagged}, CHAR_LENGTH({$tagged}) - 2)")]);
        }

        // 5. Staff: the shop's people join the clinic, with their roles at its main branch.
        $added = 0;
        foreach (DB::table('clinic_user')->where('clinic_id', $shopId)->get() as $membership) {
            if (DB::table('clinic_user')->where('clinic_id', $clinicId)->where('user_id', $membership->user_id)->exists()) continue;
            $row = (array) $membership;
            unset($row['id']);
            $row['clinic_id'] = $clinicId;
            if (! empty($row['staff_identifier']) && DB::table('clinic_user')->where('clinic_id', $clinicId)->where('staff_identifier', $row['staff_identifier'])->exists()) $row['staff_identifier'] = null;
            DB::table('clinic_user')->insert($row);
            $added++;
        }
        $this->report['staff added to the clinic'] = $added;
        $branchUsers = $roles = 0;
        foreach (DB::table('branch_user')->whereIn('branch_id', $shopBranches ?: [0])->get() as $member) {
            if (DB::table('branch_user')->where('branch_id', $mainBranch)->where('user_id', $member->user_id)->exists()) continue;
            $row = (array) $member;
            unset($row['id']);
            $row['branch_id'] = $mainBranch;
            $row['is_default'] = DB::table('branch_user')->where('user_id', $member->user_id)->where('is_default', true)->whereNotIn('branch_id', $shopBranches)->exists() ? false : ($row['is_default'] ?? true);
            DB::table('branch_user')->insert($row);
            $branchUsers++;
        }
        foreach (DB::table('branch_user_role')->whereIn('branch_id', $shopBranches ?: [0])->get() as $role) {
            if (DB::table('branch_user_role')->where('branch_id', $mainBranch)->where('user_id', $role->user_id)->where('role_id', $role->role_id)->exists()) continue;
            $row = (array) $role;
            unset($row['id']);
            $row['branch_id'] = $mainBranch;
            DB::table('branch_user_role')->insert($row);
            $roles++;
        }
        $this->report['staff added to the main branch'] = $branchUsers;
        $this->report['roles added at the main branch'] = $roles;
    }

    /** Nothing the shop runs on may be left behind (account history excepted). */
    private function verify(int $shopId): void
    {
        foreach (self::MOVE as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'clinic_id') || isset(self::OVERLAP[$table])) continue;
            if ($left = DB::table($table)->where('clinic_id', $shopId)->count()) throw new RuntimeException("{$left} row(s) of {$table} were not moved.");
        }
    }

    private function tablesWithShopRows(int $shopId, array $branches): array
    {
        $tables = [];
        foreach (DB::select('SHOW TABLES') as $t) {
            $table = array_values((array) $t)[0];
            if ((Schema::hasColumn($table, 'clinic_id') && DB::table($table)->where('clinic_id', $shopId)->exists())
                || ($branches && Schema::hasColumn($table, 'branch_id') && DB::table($table)->whereIn('branch_id', $branches)->exists())) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /** Ids of rows in both accounts that share the key (case-insensitive): ['shop' => [...], 'clinic' => [...]]. */
    private function clashingIds(string $table, array $key, int $shopId, int $clinicId): array
    {
        $norm = fn ($row) => implode('|', array_map(fn ($col) => mb_strtolower(trim((string) $row->{$col})), $key));
        $shop = DB::table($table)->where('clinic_id', $shopId)->get(['id', ...$key]);
        $clinic = DB::table($table)->where('clinic_id', $clinicId)->get(['id', ...$key]);
        if (! $key) return ['shop' => $shop->isNotEmpty() ? $shop->pluck('id')->all() : [], 'clinic' => $shop->isNotEmpty() ? $clinic->pluck('id')->all() : []];
        $shared = $shop->map($norm)->intersect($clinic->map($norm))->all();

        return ['shop' => $shop->filter(fn ($r) => in_array($norm($r), $shared, true))->pluck('id')->all(),
            'clinic' => $clinic->filter(fn ($r) => in_array($norm($r), $shared, true))->pluck('id')->all()];
    }

    /** A clinic default swapped to the shop account must not be in use by the clinic. */
    private function assertUnreferenced(string $table, array $ids): void
    {
        $db = DB::getDatabaseName();
        foreach (DB::select('SELECT table_name AS t, column_name AS c FROM information_schema.key_column_usage WHERE table_schema=? AND referenced_table_name=?', [$db, $table]) as $ref) {
            if (DB::table($ref->t)->whereIn($ref->c, $ids)->exists()) throw new RuntimeException("The clinic uses its own {$table} rows ({$ref->t}.{$ref->c}): merge those by hand first.");
        }
    }

    private function uniqueKeys(string $table): array
    {
        $rows = DB::select("SELECT index_name AS i, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics WHERE table_schema=? AND table_name=? AND non_unique=0 AND index_name<>'PRIMARY' GROUP BY index_name", [DB::getDatabaseName(), $table]);

        return array_map(fn ($r) => explode(',', $r->cols), $rows);
    }

    private function touch(string $table): array
    {
        return Schema::hasColumn($table, 'updated_at') ? ['updated_at' => now()] : [];
    }
}
