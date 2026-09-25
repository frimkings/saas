<?php

namespace Database\Seeders;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * One-command full demo seed for client demonstrations.
 *
 * Run: php artisan db:seed --class=MasterDemoSeeder
 *
 * Targets:
 *   patients                    80,000
 *   cashier_patient_clearances ~320,000
 *   consultations              250,000  (+ ~75k refractions inline)
 *   sales                      600,000  (+ ~1.2M sale_items, ~480k payment_transactions)
 *   appointments                80,000
 *   expenses                    ~900
 *   income_statement_entries    ~900
 *   referrals                    5,000
 *   quotations                   3,000  (+ ~7,500 quotation_items)
 *   login_logs                  20,000
 *
 * Each sub-seeder is idempotent — re-running only seeds the delta to its target.
 * Estimated runtime: 8–20 minutes depending on machine speed.
 */
class MasterDemoSeeder extends Seeder
{
    private const SUPER_ADMIN_EMAIL = 'frimkings@gmail.com';
    private const SUPER_ADMIN_PASS  = 'password';
    private const SUPER_ADMIN_NAME  = 'Clinic Admin';

    public function run(): void
    {
        $this->command->info('');
        $this->command->info('╔══════════════════════════════════════════════════════╗');
        $this->command->info('║         MASTER DEMO SEEDER — Eye Clinic System       ║');
        $this->command->info('║  Target: 80k patients · 250k consults · 600k sales  ║');
        $this->command->info('╚══════════════════════════════════════════════════════╝');
        $this->command->info('');

        $this->call([
            // ── Foundation (idempotent system seeders) ─────────────────
            UserSeeder::class,
            RolesAndPermissionsSeeder::class,
            DiagnosisSeeder::class,
            DefaultIncomeStatementTemplateSeeder::class,
        ]);

        // ── Super Admin account ─────────────────────────────────────────
        $this->ensureSuperAdmin();

        // ── PRO license (30-day trial, resets each run) ─────────────────
        $this->activateProTrial();

        $this->call([

            // ── Core clinical chain ────────────────────────────────────
            PatientDemoSeeder::class,           // 80,000 patients
            ClearanceDemoSeeder::class,         // ~320,000 clearances
            ConsultationDemoSeeder::class,      // 250,000 consultations + refractions + diagnoses

            // ── Sales ──────────────────────────────────────────────────
            PosDemoSeeder::class,               // 600,000 POS sales + items + payments

            // ── Supporting modules ─────────────────────────────────────
            AppointmentDemoSeeder::class,       // 80,000 appointments
            ExpenseDemoSeeder::class,           // Monthly expenses 2020–2026
            IncomeStatementDemoSeeder::class,   // Monthly P&L entries 2020–2026
            ReferralDemoSeeder::class,          // 5,000 referrals
            QuotationDemoSeeder::class,         // 3,000 quotations with line items
            LoginLogDemoSeeder::class,          // 20,000 login history records
        ]);

        $this->command->info('');
        $this->command->info('╔══════════════════════════════════════════════════════╗');
        $this->command->info('║                  SEEDING COMPLETE                    ║');
        $this->command->info('╚══════════════════════════════════════════════════════╝');
        $this->command->info('');
        $this->printSummary();
        $this->printCredentials();
    }

    // ────────────────────────────────────────────────────────────────────────────

    private function ensureSuperAdmin(): void
    {
        // Ensure Spatie roles exist before assigning
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $user = User::updateOrCreate(
            ['email' => self::SUPER_ADMIN_EMAIL],
            [
                'name'              => self::SUPER_ADMIN_NAME,
                'password'          => Hash::make(self::SUPER_ADMIN_PASS),
                'email_verified_at' => now(),
            ]
        );

        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin']);
        if (!$user->hasRole('Super Admin')) {
            $user->assignRole($superAdminRole);
        }

        $this->command->info('  Super Admin account: ' . self::SUPER_ADMIN_EMAIL . ' / ' . self::SUPER_ADMIN_PASS . ' [Super Admin]');
    }

    private function activateProTrial(): void
    {
        $trialStart = now()->toDateString();
        $trialEnd   = Carbon::now()->addDays(30)->toDateString();

        $setting = DB::table('settings')->first();

        if ($setting) {
            DB::table('settings')->where('id', $setting->id)->update([
                'installation_id'  => $setting->installation_id ?? (string) Str::uuid(),
                'trial_started_at' => $trialStart,
                'updated_at'       => now(),
            ]);
        } else {
            DB::table('settings')->insert([
                'installation_id'  => (string) Str::uuid(),
                'trial_started_at' => $trialStart,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        }

        \App\Services\LicenseService::clearCache();

        $this->command->info("  PRO license:         Trial active — full PRO access until {$trialEnd}");
        $this->command->info('');
    }

    private function printSummary(): void
    {
        $counts = [
            'patients'                   => 'patients',
            'cashier_patient_clearances' => 'clearances',
            'consultations'              => 'consultations',
            'refractions'                => 'refractions',
            'sales'                      => 'sales',
            'sale_items'                 => 'sale items',
            'payment_transactions'       => 'payment records',
            'appointments'               => 'appointments',
            'expenses'                   => 'expenses',
            'income_statement_entries'   => 'IS entries',
            'referrals'                  => 'referrals',
            'quotations'                 => 'quotations',
            'login_logs'                 => 'login logs',
        ];

        foreach ($counts as $table => $label) {
            try {
                $n = \Illuminate\Support\Facades\DB::table($table)->count();
                $this->command->info(sprintf('  %-32s %s', $label . ':', number_format($n)));
            } catch (\Throwable) {
                // skip tables that may not exist
            }
        }
    }

    private function printCredentials(): void
    {
        $trialEnd = Carbon::now()->addDays(30)->format('d M Y');
        $this->command->info('');
        $this->command->info('  ┌─────────────────────────────────────────────┐');
        $this->command->info('  │            DEMO LOGIN CREDENTIALS            │');
        $this->command->info('  ├─────────────────────────────────────────────┤');
        $this->command->info('  │ Email    : ' . sprintf('%-33s', self::SUPER_ADMIN_EMAIL) . '│');
        $this->command->info('  │ Password : ' . sprintf('%-33s', self::SUPER_ADMIN_PASS) . '│');
        $this->command->info('  │ Role     : ' . sprintf('%-33s', 'Super Admin') . '│');
        $this->command->info('  │ License  : ' . sprintf('%-33s', 'PRO Trial (expires ' . $trialEnd . ')') . '│');
        $this->command->info('  └─────────────────────────────────────────────┘');
        $this->command->info('');
    }
}
