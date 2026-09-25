<?php

namespace App\Services;

use App\Models\OpticalCategory;
use App\Models\OpticalProduct;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OpticalProductCsvService
{
    public const HEADERS = [
        'sku', 'name', 'category_code', 'brand', 'cost_price', 'selling_price',
        'quantity', 'reorder_level', 'status', 'specifications',
    ];

    public function writeCsv($handle, bool $template = false): void
    {
        fputcsv($handle, self::HEADERS);
        if ($template) {
            $categoryCode = OpticalCategory::where('is_active', true)->orderBy('code')->value('code') ?? 'YOUR-CATEGORY-CODE';
            fputcsv($handle, ['SKU-FRM-001', 'Example frame', $categoryCode, 'Example brand', '100.00', '150.00', '10', '5', 'active', 'Frame size and material']);
            return;
        }

        OpticalProduct::with(['category', 'stocks'])->orderBy('sku')->chunk(500, function ($products) use ($handle) {
            foreach ($products as $product) {
                $stock = $product->stocks->first();
                fputcsv($handle, [
                    $this->safeCell($product->sku), $this->safeCell($product->name),
                    $this->safeCell($product->category?->code ?? ''), $this->safeCell($product->brand ?? ''),
                    $product->cost_price, $product->selling_price,
                    $stock?->quantity ?? 0, $stock?->reorder_level ?? 5,
                    $product->is_active ? 'active' : 'inactive', $this->safeCell($product->specifications ?? ''),
                ]);
            }
        });
    }

    public function import(string $path): int
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['importFile' => 'Could not read the CSV file.']);
        }
        try {
            $headers = fgetcsv($handle);
            if (! is_array($headers)) {
                throw ValidationException::withMessages(['importFile' => 'The CSV file is empty.']);
            }
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
            $headers = array_map(fn ($header) => strtolower(trim((string) $header)), $headers);
            if ($headers !== self::HEADERS) {
                throw ValidationException::withMessages(['importFile' => 'CSV columns must match the downloaded template, in the same order.']);
            }

            $categories = OpticalCategory::where('is_active', true)->get()->keyBy(fn ($category) => strtoupper($category->code));
            $rows = [];
            $seen = [];
            $errors = [];
            $line = 1;
            while (($values = fgetcsv($handle)) !== false) {
                $line++;
                if (count($values) === 1 && trim((string) $values[0]) === '') continue;
                if ($line > 2001) {
                    $errors[] = 'Maximum 2,000 product rows per import.';
                    break;
                }
                if (count($values) !== count(self::HEADERS)) {
                    $errors[] = "Row {$line}: expected ".count(self::HEADERS).' columns.';
                    continue;
                }
                $row = array_combine(self::HEADERS, array_map(function ($value) {
                    $value = trim((string) $value);
                    return preg_match('/^\x27[=+\-@]/', $value) ? substr($value, 1) : $value;
                }, $values));
                $row['sku'] = strtoupper($row['sku']);
                $row['category_code'] = strtoupper($row['category_code']);
                $row['status'] = strtolower($row['status']);
                $validator = Validator::make($row, [
                    'sku' => ['required', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/', 'max:80'],
                    'name' => 'required|string|max:180',
                    'category_code' => 'required|string|max:40',
                    'brand' => 'nullable|string|max:120',
                    'cost_price' => 'required|numeric|min:0|max:9999999999',
                    'selling_price' => 'required|numeric|min:0|max:9999999999|gte:cost_price',
                    'quantity' => 'required|integer|min:0|max:100000000',
                    'reorder_level' => 'required|integer|min:0|max:100000000',
                    'status' => 'required|in:active,inactive',
                    'specifications' => 'nullable|string|max:3000',
                ]);
                if ($validator->fails()) {
                    $errors[] = "Row {$line}: ".$validator->errors()->first();
                    continue;
                }
                if (! isset($categories[$row['category_code']])) {
                    $errors[] = "Row {$line}: category code {$row['category_code']} is not active in this subscriber.";
                    continue;
                }
                if (isset($seen[$row['sku']])) {
                    $errors[] = "Row {$line}: duplicate SKU {$row['sku']} in this file.";
                    continue;
                }
                $seen[$row['sku']] = true;
                $row['optical_category_id'] = $categories[$row['category_code']]->id;
                $rows[] = $row;
            }
            if (! $rows && ! $errors) $errors[] = 'The CSV file has no product rows.';
            if ($errors) {
                throw ValidationException::withMessages(['importFile' => implode(' ', array_slice($errors, 0, 10)).(count($errors) > 10 ? ' Additional errors: '.(count($errors) - 10).'.' : '')]);
            }
        } finally {
            fclose($handle);
        }

        $clinicId = app(TenantContext::class)->clinicId();
        $archived = OpticalProduct::withTrashed()->where('clinic_id', $clinicId)->whereIn('sku', array_keys($seen))
            ->whereNotNull('deleted_at')->pluck('sku')->all();
        if ($archived) {
            throw ValidationException::withMessages(['importFile' => 'Archived SKU(s) cannot be imported: '.implode(', ', array_slice($archived, 0, 10)).'.']);
        }

        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                $product = OpticalProduct::where('sku', $row['sku'])->lockForUpdate()->first() ?? new OpticalProduct();
                $product->fill([
                    'sku' => $row['sku'], 'name' => $row['name'],
                    'optical_category_id' => $row['optical_category_id'],
                    'brand' => $row['brand'] ?: null,
                    'cost_price' => round((float) $row['cost_price'], 2),
                    'selling_price' => round((float) $row['selling_price'], 2),
                    'is_active' => $row['status'] === 'active',
                    'specifications' => $row['specifications'] ?: null,
                ]);
                $product->save();
                app(OpticalProductInventoryService::class)->setBalance(
                    $product, (int) $row['quantity'], (int) $row['reorder_level'], 'Optical product CSV import'
                );
            }
        });

        return count($rows);
    }

    private function safeCell(string $value): string
    {
        return preg_match('/^[\s]*[=+\-@]/', $value) ? "'".$value : $value;
    }
}
