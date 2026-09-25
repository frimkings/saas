<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

class LegacyImportCompatibilityValidator
{
    public const SUPPORTED_TABLES = [
        'users','categories','diagnoses','expense_categories','insurers','lens_options','referral_snippets','sms_templates','suppliers','patients','products','appointments','cashier_patient_clearances','consultations','refractions','carts','consultation_diagnosis','consultation_notes','lens_orders','expenses','sales','sale_items','payment_transactions','stock_movements','insurance_claims','quotations','quotation_items','purchase_orders','purchase_order_items','inventory_lots','branch_inventory_items','stock_transfers','stock_transfer_items','refund_logs','sale_adjustments','discount_approval_requests','clearance_revoke_logs','referrals','patient_documents','app_notifications','sms_logs','report_deliveries','staff_messages','login_logs','settings',
    ];

    public function validate(ConnectionInterface $source, string $sourceDatabase): array
    {
        $destinationDatabase = DB::connection()->getDatabaseName();
        $sourceTables = $this->tables($source, $sourceDatabase);
        $destinationTables = $this->tables(DB::connection(), $destinationDatabase);
        $issues = [];

        foreach (['users', 'patients'] as $requiredTable) {
            if (! in_array($requiredTable, $sourceTables, true)) {
                $issues[] = $this->issue('error', $requiredTable, null, 'required_table_missing', "Required table {$requiredTable} is missing.");
            }
        }

        foreach (array_diff($sourceTables, self::SUPPORTED_TABLES) as $table) {
            $issues[] = $this->issue('warning', $table, null, 'unsupported_table', 'This table is not currently imported.');
        }

        foreach (array_intersect($sourceTables, self::SUPPORTED_TABLES, $destinationTables) as $table) {
            $sourceColumns = $this->columns($source, $sourceDatabase, $table);
            $destinationColumns = $this->columns(DB::connection(), $destinationDatabase, $table);

            if (! isset($sourceColumns['id']) && $table !== 'consultation_diagnosis') {
                $issues[] = $this->issue('error', $table, 'id', 'required_column_missing', 'An id column is required for relationship remapping.');
            }

            foreach ($destinationColumns as $column => $metadata) {
                if (isset($sourceColumns[$column])) {
                    if ($this->isTextToJson($sourceColumns[$column]['data_type'], $metadata['data_type'])) {
                        // MariaDB and phpMyAdmin exports store JSON as longtext; the values must still parse.
                        $invalid = $this->invalidJsonCount($source, $table, $column);
                        if ($invalid > 0) {
                            $issues[] = $this->issue('error', $table, $column, 'invalid_json', "{$invalid} value(s) are not valid JSON for the destination json column.", $invalid);
                        }
                    } elseif (! $this->typesCompatible($sourceColumns[$column]['data_type'], $metadata['data_type'])) {
                        $issues[] = $this->issue('error', $table, $column, 'incompatible_type', "Source {$sourceColumns[$column]['data_type']} is incompatible with destination {$metadata['data_type']}.");
                    }
                    continue;
                }

                $providedByImporter = in_array($column, ['id','uuid','clinic_id','branch_id','home_branch_id','destination_branch_id'], true);
                $required = $metadata['is_nullable'] === 'NO' && $metadata['column_default'] === null
                    && ! str_contains(strtolower($metadata['extra']), 'auto_increment');
                if ($required && ! $providedByImporter) {
                    $issues[] = $this->issue('error', $table, $column, 'required_column_missing', 'The destination requires this column but the source does not provide it.');
                }
            }
        }

        foreach ($this->foreignKeys($source, $sourceDatabase) as $foreignKey) {
            if (! in_array($foreignKey->table_name, self::SUPPORTED_TABLES, true)) continue;
            $orphans = $this->orphanCount($source, $foreignKey);
            if ($orphans > 0) {
                $issues[] = $this->issue('error', $foreignKey->table_name, $foreignKey->column_name, 'orphaned_foreign_key', "{$orphans} row(s) reference missing {$foreignKey->referenced_table_name}.{$foreignKey->referenced_column_name} values.", $orphans);
            }
        }

        foreach (array_intersect($sourceTables, self::SUPPORTED_TABLES) as $table) {
            foreach ($this->duplicateUniqueValues($source, $sourceDatabase, $table) as $duplicate) $issues[] = $duplicate;
            foreach ($this->invalidDateValues($source, $sourceDatabase, $table) as $invalid) $issues[] = $invalid;
        }
        foreach ($this->businessKeyDuplicates($source, $sourceDatabase, $sourceTables) as $duplicate) $issues[] = $duplicate;
        foreach ($this->destinationCollisions($source, $sourceDatabase, $destinationDatabase, $sourceTables, $destinationTables) as $collision) $issues[] = $collision;

        $errors = collect($issues)->where('severity', 'error')->count();
        $warnings = collect($issues)->where('severity', 'warning')->count();

        return ['passed' => $errors === 0, 'error_count' => $errors, 'warning_count' => $warnings, 'issues' => $issues, 'checked_at' => now()->toIso8601String()];
    }

    private function tables(ConnectionInterface $connection, string $database): array
    {
        return collect($connection->select('SELECT TABLE_NAME AS table_name FROM information_schema.tables WHERE table_schema = ? AND TABLE_TYPE = ?', [$database, 'BASE TABLE']))->pluck('table_name')->all();
    }

    private function columns(ConnectionInterface $connection, string $database, string $table): array
    {
        return collect($connection->select('SELECT COLUMN_NAME AS column_name, DATA_TYPE AS data_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default, EXTRA AS extra FROM information_schema.columns WHERE table_schema = ? AND table_name = ?', [$database, $table]))->mapWithKeys(fn ($column) => [$column->column_name => (array) $column])->all();
    }

    private function foreignKeys(ConnectionInterface $connection, string $database): array
    {
        return $connection->select('SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, REFERENCED_TABLE_NAME AS referenced_table_name, REFERENCED_COLUMN_NAME AS referenced_column_name FROM information_schema.key_column_usage WHERE table_schema = ? AND REFERENCED_TABLE_NAME IS NOT NULL', [$database]);
    }

    private function orphanCount(ConnectionInterface $source, object $key): int
    {
        foreach ([$key->table_name,$key->column_name,$key->referenced_table_name,$key->referenced_column_name] as $identifier) if (! preg_match('/^[A-Za-z0-9_]+$/', $identifier)) return 0;
        return (int) ($source->selectOne("SELECT COUNT(*) AS aggregate FROM `{$key->table_name}` c LEFT JOIN `{$key->referenced_table_name}` p ON p.`{$key->referenced_column_name}` = c.`{$key->column_name}` WHERE c.`{$key->column_name}` IS NOT NULL AND c.`{$key->column_name}` <> 0 AND p.`{$key->referenced_column_name}` IS NULL")->aggregate ?? 0);
    }

    private function duplicateUniqueValues(ConnectionInterface $source, string $database, string $table): array
    {
        $indexes = collect($source->select("SELECT INDEX_NAME AS index_name, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_csv FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY' GROUP BY INDEX_NAME", [$database, $table]));
        $issues = [];
        foreach ($indexes as $index) {
            $columns = explode(',', $index->columns_csv);
            if (collect($columns)->contains(fn ($column) => ! preg_match('/^[A-Za-z0-9_]+$/', $column))) continue;
            $group = collect($columns)->map(fn ($column) => "`{$column}`")->implode(',');
            // MySQL permits repeated unique keys whenever any indexed column is NULL.
            $notNull = collect($columns)->map(fn ($column) => "`{$column}` IS NOT NULL")->implode(' AND ');
            $duplicates = (int) ($source->selectOne("SELECT COUNT(*) AS aggregate FROM (SELECT 1 FROM `{$table}` WHERE {$notNull} GROUP BY {$group} HAVING COUNT(*) > 1) duplicate_groups")->aggregate ?? 0);
            if ($duplicates > 0) $issues[] = $this->issue('error', $table, $index->columns_csv, 'duplicate_unique_value', "{$duplicates} duplicate unique value group(s) were found.", $duplicates);
        }
        return $issues;
    }

    private function invalidDateValues(ConnectionInterface $source, string $database, string $table): array
    {
        $dateColumns = collect($source->select("SELECT COLUMN_NAME AS column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND DATA_TYPE IN ('date','datetime','timestamp')", [$database, $table]));
        $issues = [];
        foreach ($dateColumns as $column) {
            if (! preg_match('/^[A-Za-z0-9_]+$/', $column->column_name)) continue;
            $invalid = (int) ($source->selectOne("SELECT COUNT(*) AS aggregate FROM `{$table}` WHERE `{$column->column_name}` IS NOT NULL AND CAST(`{$column->column_name}` AS CHAR) LIKE '0000-00-00%'")->aggregate ?? 0);
            if ($invalid > 0) $issues[] = $this->issue('error', $table, $column->column_name, 'invalid_date', "{$invalid} zero/invalid date value(s) were found.", $invalid);
        }
        return $issues;
    }

    private function businessKeyDuplicates(ConnectionInterface $source, string $database, array $tables): array
    {
        $rules = [
            'users' => [['email']],
            'patients' => [['pxnumber'], ['uuid']],
            'cashier_patient_clearances' => [['patient_id','clearance_date'], ['uuid']],
            'sales' => [['transaction_id'], ['idempotency_key']],
            'payment_transactions' => [['idempotency_key']],
            'stock_movements' => [['reference_no']],
            'purchase_orders' => [['po_number'], ['invoice_number']],
            'quotations' => [['quotation_number']],
            'refund_logs' => [['refund_number']],
            'inventory_lots' => [['uuid']],
            'stock_transfers' => [['transfer_number'], ['uuid']],
            'report_deliveries' => [['delivery_key']],
        ];
        $issues = [];
        foreach ($rules as $table => $groups) {
            if (! in_array($table, $tables, true)) continue;
            $available = $this->columns($source, $database, $table);
            foreach ($groups as $columns) {
                if (array_diff($columns, array_keys($available))) continue;
                $quoted = collect($columns)->map(fn ($column) => "`{$column}`")->implode(',');
                $notBlank = collect($columns)->map(fn ($column) => "`{$column}` IS NOT NULL AND CAST(`{$column}` AS CHAR) <> ''")->implode(' AND ');
                $duplicates = (int) ($source->selectOne("SELECT COALESCE(SUM(group_count),0) AS aggregate FROM (SELECT COUNT(*) AS group_count FROM `{$table}` WHERE {$notBlank} GROUP BY {$quoted} HAVING COUNT(*) > 1) duplicate_groups")->aggregate ?? 0);
                if ($duplicates > 0) {
                    $key = implode('+', $columns);
                    $issues[] = $this->issue('error', $table, $key, 'duplicate_business_key', "{$duplicates} row(s) share duplicate {$key} values. Resolve them before import.", $duplicates);
                }
            }
        }
        return $issues;
    }

    private function destinationCollisions(ConnectionInterface $source, string $sourceDatabase, string $destinationDatabase, array $sourceTables, array $destinationTables): array
    {
        $rules = [
            'users' => ['email' => 'Merge, replace the email, or skip the identity.'],
            'patients' => ['uuid' => 'The importer will generate replacement UUIDs.', 'pxnumber' => 'Patient numbers remain isolated by clinic.'],
            'cashier_patient_clearances' => ['uuid' => 'The importer will generate replacement UUIDs.'],
            'sales' => ['transaction_id' => 'Transaction IDs will be namespaced to the imported clinic.'],
            'stock_movements' => ['reference_no' => 'References remain isolated by clinic.'],
            'purchase_orders' => ['po_number' => 'Purchase-order numbers remain isolated by clinic.', 'invoice_number' => 'Review the supplier invoice before cutover.'],
            'inventory_lots' => ['uuid' => 'The importer will generate replacement UUIDs.'],
            'stock_transfers' => ['uuid' => 'The importer will generate replacement UUIDs.', 'transfer_number' => 'Transfer numbers remain isolated by clinic.'],
        ];
        $issues = [];
        foreach ($rules as $table => $columns) {
            if (! in_array($table, $sourceTables, true) || ! in_array($table, $destinationTables, true)) continue;
            $sourceColumns = $this->columns($source, $sourceDatabase, $table);
            $destinationColumns = $this->columns(DB::connection(), $destinationDatabase, $table);
            foreach ($columns as $column => $action) {
                if (! isset($sourceColumns[$column], $destinationColumns[$column])) continue;
                $collisions = (int) ($source->selectOne("SELECT COUNT(*) AS aggregate FROM `{$table}` s INNER JOIN `{$destinationDatabase}`.`{$table}` d ON LOWER(CAST(d.`{$column}` AS CHAR)) = LOWER(CAST(s.`{$column}` AS CHAR)) WHERE s.`{$column}` IS NOT NULL AND CAST(s.`{$column}` AS CHAR) <> ''")->aggregate ?? 0);
                if ($collisions > 0) $issues[] = $this->issue('warning', $table, $column, 'destination_collision', "{$collisions} value(s) already exist in the hosted database. {$action}", $collisions);
            }
        }
        return $issues;
    }

    private function isTextToJson(string $source, string $destination): bool
    {
        return $destination === 'json' && in_array($source, ['char','varchar','text','tinytext','mediumtext','longtext'], true);
    }

    private function invalidJsonCount(ConnectionInterface $source, string $table, string $column): int
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $table) || ! preg_match('/^[A-Za-z0-9_]+$/', $column)) return 0;
        return (int) ($source->selectOne("SELECT COUNT(*) AS aggregate FROM `{$table}` WHERE `{$column}` IS NOT NULL AND JSON_VALID(`{$column}`) = 0")->aggregate ?? 0);
    }

    private function typesCompatible(string $source, string $destination): bool
    {
        $families = [['tinyint','smallint','mediumint','int','bigint','decimal','float','double'],['char','varchar','text','tinytext','mediumtext','longtext','enum'],['date','datetime','timestamp'],['json']];
        foreach ($families as $family) if (in_array($source,$family,true) && in_array($destination,$family,true)) return true;
        return $source === $destination;
    }

    private function issue(string $severity, string $table, ?string $column, string $code, string $message, int $affected = 0): array
    {
        return compact('severity','table','column','code','message','affected');
    }
}
