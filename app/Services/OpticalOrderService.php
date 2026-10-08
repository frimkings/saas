<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\LensOrder;
use App\Models\OpticalCategory;
use App\Models\OpticalProduct;
use App\Models\OpticalPrescription;
use App\Models\OpticalSetting;
use App\Models\Patient;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\Refractions;
use App\Models\Sales;
use App\Models\SaleItem;
use App\Models\OpticalService;
use App\Models\OpticalPartnerClinic;
use App\Support\Tenancy\TenantContext;
use App\Services\Inventory\BranchInventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OpticalOrderService
{
    public function updateQuotation(int $id, array $data): LensOrder
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        $data = $this->normalizeParty($data);
        $services = $this->normalizeServices($data);
        $data = $this->normalizeFrameData($data, $services);
        $data = $this->normalizeLensFulfilment($data);
        $this->rejectClinicStock($data);

        return DB::transaction(function () use ($id, $data, $services) {
            $order = LensOrder::lockForUpdate()->findOrFail($id);
            if ($order->status !== 'Quotation') {
                throw ValidationException::withMessages(['order' => 'Only quotations can be edited.']);
            }
            $before = $this->auditedPrices($order);
            $patient = ! empty($data['patient_id']) ? Patient::findOrFail($data['patient_id']) : null;
            $prescription = $this->needsPrescription($data, $services) && $patient ? $this->prescription($patient, $data) : null;
            if ($this->needsPrescription($data, $services) && ! $patient) $this->validateManualMeasurements($data['measurements'] ?? []);
            $frame = ! empty($data['frame_product_id']) ? Product::findOrFail($data['frame_product_id']) : null;
            $lens = ! empty($data['lens_product_id']) ? Product::findOrFail($data['lens_product_id']) : null;
            $opticalFrame = ! empty($data['frame_optical_product_id']) ? OpticalProduct::where('is_active', true)->findOrFail($data['frame_optical_product_id']) : null;
            $opticalLens = ! empty($data['lens_optical_product_id']) ? OpticalProduct::where('is_active', true)->findOrFail($data['lens_optical_product_id']) : null;
            if ($frame && ! $this->isFrameCategory($frame)) {
                throw ValidationException::withMessages(['frame_product_id' => 'Select a frame product.']);
            }
            if ($lens && ! $this->isLensCategory($lens)) {
                throw ValidationException::withMessages(['lens_product_id' => 'Select a lens product.']);
            }
            $this->validateOpticalProducts($frame, $lens, $opticalFrame, $opticalLens);
            $framePrice = $opticalFrame ? (float) $opticalFrame->selling_price : (float) $data['frame_price'];
            $lensPrice = $opticalLens ? (float) $opticalLens->selling_price : (float) $data['lens_price'];
            $snapshot = $this->withFitting($prescription?->measurements ?? ($this->needsPrescription($data, $services) ? $data['measurements'] : []), $data);
            $lensOption = $this->stockLensOption($data, $snapshot, $order->id);
            if ($lensOption) $lensPrice = $lensOption['price'];
            $serviceTotal = $this->serviceTotal($services);
            $total = $framePrice + $lensPrice + $serviceTotal
                + (float) ($data['glazing_fee'] ?? 0) - (float) ($data['discount_amount'] ?? 0);
            if ($total < 0) {
                throw ValidationException::withMessages(['discount_amount' => 'Discount cannot exceed the order total.']);
            }
            $order->update([
                'patient_id' => $patient?->id,
                'partner_clinic_id' => $data['partner_clinic_id'],
                'partner_billing_terms' => $data['partner_billing_terms'],
                'order_source' => $data['order_source'],
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'optical_prescription_id' => $prescription?->id,
                'prescription_snapshot' => $snapshot,
                'frame_product_id' => $frame?->id,
                'lens_product_id' => $lens?->id,
                'frame_optical_product_id' => $opticalFrame?->id,
                'lens_optical_product_id' => $opticalLens?->id,
                'lens_supply_source' => $data['lens_fulfilment_source'] === 'stock' ? 'stock' : ($data['lens_fulfilment_source'] === 'customer' ? 'customer' : ($opticalLens ? 'catalogue' : 'outside')),
                'stock_lens_index' => $data['lens_fulfilment_source'] === 'stock' ? data_get($data, 'docket.lens_details.index') : null,
                'stock_lens_coating' => $data['lens_fulfilment_source'] === 'stock' ? data_get($data, 'docket.lens_details.stock_coating') : null,
                'frame_model_number' => $data['frame_model_number'],
                'frame_price' => $framePrice,
                'lens_price' => $lensPrice,
                'glazing_fee' => $data['glazing_fee'] ?? 0,
                'discount_amount' => $data['discount_amount'] ?? 0,
                'service_total' => $serviceTotal,
                'work_type' => $data['work_type'] ?? 'prescription',
                'partner_clinic_name' => $data['partner_clinic_name'] ?? null,
                'bill_to' => $data['bill_to'] ?? 'customer',
                'pickUpDate' => $data['pickup_date'],
                'notes' => json_encode($data['docket'] ?? [], JSON_THROW_ON_ERROR),
                // Prices were just worked out again, so the quotation is valid from today.
                'quote_valid_until' => today()->addDays(OpticalSetting::quoteValidityDays())->toDateString(),
            ]);
            $order->serviceLines()->delete();
            $this->storeServices($order, $services);
            $order->lensLines()->delete();
            if ($lensOption) app(OpticalLensAvailabilityService::class)->writeLensLines($order, $lensOption, false);
            AuditTrail::record('optical.quotation_edited', "Quotation {$order->order_id} edited for {$order->display_customer_name}",
                $order, $before, $this->auditedPrices($order), $order->patient_id);
            return $order;
        });
    }

    /**
     * Heights and monocular PDs are measured at fitting, with the frame, so they are taken
     * from the order even when the powers come from a saved prescription.
     */
    private function withFitting(array $snapshot, array $data): array
    {
        if (! $snapshot) return $snapshot;
        foreach (['od', 'os'] as $eye) foreach (['hgt', 'pd'] as $field) {
            $value = data_get($data, "measurements.{$eye}.{$field}");
            if (filled($value)) $snapshot[$eye][$field] = $value;
        }
        return $snapshot;
    }

    /** The money on an order, as recorded in the audit trail. */
    private function auditedPrices(LensOrder $order): array
    {
        return ['frame_price' => round((float) $order->frame_price, 2), 'lens_price' => round((float) $order->lens_price, 2),
            'glazing_fee' => round((float) $order->glazing_fee, 2), 'service_total' => round((float) $order->service_total, 2),
            'discount_amount' => round((float) $order->discount_amount, 2), 'total' => round($order->total, 2)];
    }

    public function create(array $data, bool $quotation = false): LensOrder
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        $data = $this->normalizeParty($data);
        $services = $this->normalizeServices($data);
        $data = $this->normalizeFrameData($data, $services);
        $data = $this->normalizeLensFulfilment($data);
        $this->rejectClinicStock($data);

        return DB::transaction(function () use ($data, $quotation, $services) {
            $patient = ! empty($data['patient_id']) ? Patient::findOrFail($data['patient_id']) : null;
            $prescription = $this->needsPrescription($data, $services) && $patient ? $this->prescription($patient, $data) : null;
            if ($this->needsPrescription($data, $services) && ! $patient) $this->validateManualMeasurements($data['measurements'] ?? []);
            $frame = isset($data['frame_product_id']) ? Product::findOrFail($data['frame_product_id']) : null;
            $lens = isset($data['lens_product_id']) ? Product::findOrFail($data['lens_product_id']) : null;
            $opticalFrame = ! empty($data['frame_optical_product_id']) ? OpticalProduct::where('is_active', true)->findOrFail($data['frame_optical_product_id']) : null;
            $opticalLens = ! empty($data['lens_optical_product_id']) ? OpticalProduct::where('is_active', true)->findOrFail($data['lens_optical_product_id']) : null;
            if ($frame && ! $this->isFrameCategory($frame)) {
                throw ValidationException::withMessages(['frame_product_id' => 'Select a frame product.']);
            }
            if ($lens && ! $this->isLensCategory($lens)) {
                throw ValidationException::withMessages(['lens_product_id' => 'Select a lens product.']);
            }
            $this->validateOpticalProducts($frame, $lens, $opticalFrame, $opticalLens);
            $framePrice = $opticalFrame ? (float) $opticalFrame->selling_price : (float) $data['frame_price'];
            $lensPrice = $opticalLens ? (float) $opticalLens->selling_price : (float) $data['lens_price'];
            $snapshot = $this->withFitting($prescription?->measurements ?? ($this->needsPrescription($data, $services) ? $data['measurements'] : []), $data);
            $lensOption = $this->stockLensOption($data, $snapshot);
            if ($lensOption) $lensPrice = $lensOption['price'];
            $glazingFee = (float) ($data['glazing_fee'] ?? 0);
            $discount = (float) ($data['discount_amount'] ?? 0);
            $serviceTotal = $this->serviceTotal($services);
            $remake = $this->remakeDetails($data, $quotation);
            if ($remake && $remake['remake_charge'] === 'free') {
                // Free remakes (warranty, lab error): nothing is charged, but stock
                // and services are still recorded so the cost of remakes is visible.
                $framePrice = $lensPrice = $glazingFee = $discount = $serviceTotal = 0.0;
                $services = array_map(fn ($line) => ['unit_price' => 0, 'line_total' => 0] + $line, $services);
            }
            $total = round($framePrice + $lensPrice + $glazingFee + $serviceTotal - $discount, 2);
            $paid = $quotation ? 0 : (float) ($data['paid_amount'] ?? 0);
            if ($total < 0 || $paid > $total) {
                throw ValidationException::withMessages(['paid_amount' => 'Payment cannot exceed the order total.']);
            }
            $minimumPercent = $data['partner_billing_terms'] === 'on_account' ? 0 : (int) (OpticalSetting::first()?->min_deposit_percentage ?? 0);
            if (! $quotation && $paid < round($total * $minimumPercent / 100, 2)) {
                throw ValidationException::withMessages(['paid_amount' => "A {$minimumPercent}% deposit is required."]);
            }

            if (! $quotation && ($frame || $lens || $opticalFrame || $opticalLens)) {
                $inventory = app(BranchInventoryService::class);
                foreach (array_filter([$frame, $lens]) as $product) {
                    $inventory->decrease($product, 1);
                }
                foreach (array_filter([$opticalFrame, $opticalLens]) as $product) {
                    app(OpticalProductInventoryService::class)->decrease($product, 1, 'Optical order stock reservation');
                }
            }

            $sale = null;
            if (! $quotation) {
                $sale = Sales::create([
                    'business_line' => 'optical',
                    'user_id' => auth()->id(), 'patient_id' => $patient?->id,
                    'customer_name' => ($data['bill_to'] ?? 'customer') === 'partner' ? $data['partner_clinic_name'] : $data['customer_name'],
                    'transaction_id' => 'OPT-' . Str::upper(Str::random(14)),
                    'total_amount' => $total, 'amount_paid' => $paid,
                    'payment_status' => $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
                    'discount_amount' => $discount,
                ]);
                if ($paid > 0) {
                    PaymentTransaction::create([
                        'sale_id' => $sale->id, 'amount' => $paid,
                        'payment_method' => $data['payment_method'] ?? 'cash',
                        'collected_by' => auth()->id(),
                        'notes' => 'Optical order deposit',
                    ]);
                }
                foreach ([[$frame, $framePrice], [$lens, $lensPrice], [$opticalFrame, $framePrice], [$opticalLens, $lensPrice]] as [$product, $price]) {
                    if ($product) SaleItem::create([
                        'sale_id' => $sale->id,
                        'product_id' => $product instanceof Product ? $product->id : null,
                        'optical_product_id' => $product instanceof OpticalProduct ? $product->id : null,
                        'prescribed_quantity' => 0, 'dispensed_quantity' => 1,
                        'selling_price' => $price, 'subtotal' => $price,
                    ]);
                }
            }

            $order = LensOrder::create([
                'patient_id' => $patient?->id,
                'partner_clinic_id' => $data['partner_clinic_id'],
                'partner_billing_terms' => $data['partner_billing_terms'],
                'order_source' => $data['order_source'],
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'optical_prescription_id' => $prescription?->id,
                // Legacy refraction_id is unique per lens order. New orders use
                // the reusable optical prescription and its immutable snapshot.
                'refraction_id' => null,
                'sale_id' => $sale?->id,
                'prescription_snapshot' => $snapshot,
                'user_id' => auth()->id(),
                'order_id' => ($quotation ? 'QTN-' : 'OPT-') . Str::upper(Str::random(12)),
                'frame_model_number' => $data['frame_model_number'],
                'frame_product_id' => $frame?->id,
                'lens_product_id' => $lens?->id,
                'frame_optical_product_id' => $opticalFrame?->id,
                'lens_optical_product_id' => $opticalLens?->id,
                'lens_supply_source' => $data['lens_fulfilment_source'] === 'stock' ? 'stock' : ($data['lens_fulfilment_source'] === 'customer' ? 'customer' : ($opticalLens ? 'catalogue' : 'outside')),
                'stock_lens_index' => $data['lens_fulfilment_source'] === 'stock' ? data_get($data, 'docket.lens_details.index') : null,
                'stock_lens_coating' => $data['lens_fulfilment_source'] === 'stock' ? data_get($data, 'docket.lens_details.stock_coating') : null,
                'frame_price' => $framePrice, 'lens_price' => $lensPrice,
                'glazing_fee' => $glazingFee, 'discount_amount' => $discount,
                'service_total' => $serviceTotal,
                'work_type' => $data['work_type'] ?? 'prescription',
                'partner_clinic_name' => $data['partner_clinic_name'] ?? null,
                'bill_to' => $data['bill_to'] ?? 'customer',
                'paid_amount' => $paid,
                'lab_cost' => (float) ($data['lab_cost'] ?? 0),
                'stock_reserved_at' => (! $quotation && ($frame || $lens || $opticalFrame || $opticalLens)) ? now() : null,
                'pickUpDate' => $data['pickup_date'],
                'status' => $quotation ? 'Quotation' : 'Pending',
                'status_changed_at' => now(),
                'quote_valid_until' => $quotation ? today()->addDays(OpticalSetting::quoteValidityDays())->toDateString() : null,
                'notes' => json_encode($data['docket'] ?? [], JSON_THROW_ON_ERROR),
            ] + ($remake ?? []));
            $this->storeServices($order, $services);
            if ($lensOption && $quotation) app(OpticalLensAvailabilityService::class)->writeLensLines($order, $lensOption, false);
            if (! $quotation) app(OpticalLensAvailabilityService::class)->reserveForOrder($order);
            if (! $quotation) app(OpticalLensAvailabilityService::class)->reportTypedPrices($order);
            if ($remake && $remake['remake_charge'] === 'free') $order->lensLines()->update(['unit_price' => 0]);
            if ((float) $order->discount_amount > 0) {
                AuditTrail::record('optical.discount_given', 'Discount of '.currency().' '.number_format((float) $order->discount_amount, 2)." on {$order->order_id} for {$order->display_customer_name}",
                    $order, [], $this->auditedPrices($order), $order->patient_id);
            }
            if ($paid > 0) {
                AuditTrail::record('optical.payment_recorded', 'Deposit of '.currency().' '.number_format($paid, 2).' ('.($data['payment_method'] ?? 'cash').") on new order {$order->order_id}",
                    $order, [], ['paid_amount' => $paid, 'payment_method' => $data['payment_method'] ?? 'cash'], $order->patient_id);
            }
            return $order;
        });
    }

    /**
     * Stock lenses are priced per eye on the server: a stocked lens at its own
     * price, a special-ordered eye at the catalogue price for that power.
     */
    private function stockLensOption(array $data, array $snapshot, ?int $orderId = null): ?array
    {
        $key = (string) data_get($data, 'docket.lens_details.stock_key', '');
        if (($data['lens_fulfilment_source'] ?? '') !== 'stock' || $key === '') return null;
        return app(OpticalLensAvailabilityService::class)->resolveStockOption(
            $snapshot, $key, (array) data_get($data, 'docket.lens_details.stock_split', []), $orderId,
            (array) data_get($data, 'docket.lens_details.special_prices', []),
        );
    }

    /** @return array{remake_of_id: int, remake_reason: string, remake_charge: string}|null */
    private function remakeDetails(array $data, bool $quotation): ?array
    {
        $remake = $data['remake'] ?? null;
        if (! $remake) return null;
        if ($quotation) throw ValidationException::withMessages(['remake' => 'Place a remake as an order, not a quotation.']);
        $original = LensOrder::whereIn('status', ['Ready for Collection', 'Ready', 'Collected'])->find((int) ($remake['of'] ?? 0));
        if (! $original) throw ValidationException::withMessages(['remake' => 'Remakes can only be made for glasses that are ready or collected.']);
        if (! array_key_exists($remake['reason'] ?? '', LensOrder::REMAKE_REASONS) || ! in_array($remake['charge'] ?? '', ['free', 'charged'], true)) {
            throw ValidationException::withMessages(['remake' => 'Choose the remake reason and whether it is free or charged.']);
        }
        return ['remake_of_id' => $original->id, 'remake_reason' => $remake['reason'], 'remake_charge' => $remake['charge']];
    }

    private function rejectClinicStock(array $data): void
    {
        if (! empty($data['frame_product_id']) || ! empty($data['lens_product_id'])) {
            throw ValidationException::withMessages([
                'frame_product_id' => 'Clinic stock cannot be sold through Optical. Select an Optical Product SKU.',
            ]);
        }
    }

    private function normalizeFrameData(array $data, array $services): array
    {
        if (($data['work_type'] ?? 'prescription') === 'service') {
            $data['lens_product_id'] = null;
            $data['lens_optical_product_id'] = null;
            $data['lens_price'] = 0;
        }
        if (($data['work_type'] ?? 'prescription') === 'service' && ! collect($services)->contains(fn ($line) => $line['requires_frame'])) {
            $data['frame_product_id'] = null;
            $data['frame_optical_product_id'] = null;
            $data['frame_model_number'] = null;
            $data['frame_price'] = 0;
            $data['docket']['frame_source'] = 'custom';
            return $data;
        }
        $source = data_get($data, 'docket.frame_source')
            ?: (! empty($data['frame_product_id']) || ! empty($data['frame_optical_product_id']) ? 'stock' : 'custom');
        if (! in_array($source, ['stock', 'customer', 'custom'], true)) {
            throw ValidationException::withMessages(['frame_source' => 'Select a valid frame source.']);
        }
        if ($source === 'stock' && empty($data['frame_product_id']) && empty($data['frame_optical_product_id'])) {
            throw ValidationException::withMessages(['frame_product_id' => 'Select a stock frame.']);
        }
        if ($source !== 'stock') {
            $data['frame_product_id'] = null;
            $data['frame_optical_product_id'] = null;
        }
        if ($source === 'customer') {
            $data['frame_price'] = 0;
            $data['frame_model_number'] = trim((string) ($data['frame_model_number'] ?? ''))
                ?: 'Customer-supplied frame';
        }
        $data['docket']['frame_source'] = $source;
        return $data;
    }

    private function normalizeLensFulfilment(array $data): array
    {
        $categoryId = data_get($data, 'docket.lens_details.category_id');
        if ($categoryId) {
            $category = OpticalCategory::where('is_active', true)->findOrFail((int) $categoryId);
            abort_unless(in_array($category->group, ['single_vision', 'progressive', 'bifocal'], true), 404);
            if (($data['work_type'] ?? 'prescription') !== 'prescription') {
                throw ValidationException::withMessages(['optical_category_id' => 'Lens categories are only available for prescription orders.']);
            }
            $data['docket']['lens_details']['category_name'] = $category->name;
        }
        $source = $data['lens_fulfilment_source'] ?? 'external';
        if (! in_array($source, ['stock', 'external', 'catalogue', 'customer'], true)) {
            throw ValidationException::withMessages(['lens_fulfilment_source' => 'Choose a valid lens fulfilment option.']);
        }
        if ($source === 'stock' && ($data['work_type'] ?? 'prescription') !== 'prescription') {
            throw ValidationException::withMessages(['lens_fulfilment_source' => 'Lens blank stock is only available for prescription orders.']);
        }
        $data['lens_fulfilment_source'] = $source;
        if ($source === 'stock') {
            $data['lens_product_id'] = null;
            $data['lens_optical_product_id'] = null;
        }
        if ($source === 'catalogue' && empty($data['lens_optical_product_id'])) {
            throw ValidationException::withMessages(['lens_optical_product_id' => 'Select a stocked optical lens product.']);
        }
        if (in_array($source, ['external', 'customer'], true)) $data['lens_optical_product_id'] = null;
        if ($source === 'customer') {
            $data['lens_product_id'] = null;
            $data['lens_price'] = 0;
        }
        return $data;
    }

    private function validateOpticalProducts(?Product $frame, ?Product $lens, ?OpticalProduct $opticalFrame, ?OpticalProduct $opticalLens): void
    {
        if (($frame && $opticalFrame) || ($lens && $opticalLens)) {
            throw ValidationException::withMessages(['product' => 'Choose one catalogue item per frame or lens.']);
        }
        if ($opticalFrame && $opticalFrame->category?->group !== 'frames') {
            throw ValidationException::withMessages(['frame_optical_product_id' => 'Select an optical frame SKU.']);
        }
        if ($opticalLens && ! in_array($opticalLens->category?->group, ['single_vision', 'progressive', 'bifocal'], true)) {
            throw ValidationException::withMessages(['lens_optical_product_id' => 'Select an optical lens SKU.']);
        }
        // A stock lens item is a single lens; stock lenses are taken per eye from the prescription match.
        if ($opticalLens && $opticalLens->lens_specs !== null) {
            throw ValidationException::withMessages(['lens_optical_product_id' => 'Stock lenses are matched to the prescription for each eye. Choose "Branch lens stock" instead of a catalogue lens.']);
        }
    }

    private function isFrameCategory(Product $product): bool
    {
        if ($product->opticalCategory) return $product->opticalCategory->group === 'frames';
        $category = $product->category;
        return $category && ($category->type === 'frame' || str_contains(strtolower($category->name), 'frame'));
    }

    private function isLensCategory(Product $product): bool
    {
        if ($product->opticalCategory) return in_array($product->opticalCategory->group, ['single_vision', 'progressive', 'bifocal'], true);
        $category = $product->category;
        return $category && ($category->type === 'lens' || str_contains(strtolower($category->name), 'lens'));
    }

    private function normalizeParty(array $data): array
    {
        $source = $data['order_source'] ?? 'in_clinic';
        if (! in_array($source, ['in_clinic', 'partner', 'walk_in'], true)) {
            throw ValidationException::withMessages(['order_source' => 'Select a valid order source.']);
        }
        $data['order_source'] = $source;
        $data['partner_clinic_id'] = null;
        $data['partner_billing_terms'] = null;
        if ($source === 'in_clinic') {
            $patient = Patient::findOrFail($data['patient_id'] ?? 0);
            $data['customer_name'] = $patient->name;
            $data['customer_phone'] = $patient->contact;
        } elseif ($source === 'partner') {
            $partner = OpticalPartnerClinic::where('is_active', true)->findOrFail($data['partner_id'] ?? 0);
            $data['patient_id'] = null;
            $data['partner_clinic_id'] = $partner->id;
            $data['partner_clinic_name'] = $partner->name;
            $data['partner_billing_terms'] = $partner->billing_terms;
            $data['customer_name'] = trim((string) ($data['customer_name'] ?? '')) ?: null;
            $data['customer_phone'] = trim((string) ($data['customer_phone'] ?? '')) ?: null;
            $data['bill_to'] ??= 'partner';
            if ($data['bill_to'] === 'customer' && ! $data['customer_name']) {
                throw ValidationException::withMessages(['customer_name' => 'Enter the wearer name when billing the customer.']);
            }
            if (! $data['customer_name'] && ! trim((string) data_get($data, 'docket.reference', ''))) {
                throw ValidationException::withMessages(['reference' => 'Enter a clinic job reference when no wearer name is supplied.']);
            }
        } else {
            $data['patient_id'] = null;
            $data['partner_clinic_name'] = null;
            $data['customer_name'] = trim((string) ($data['customer_name'] ?? ''));
            $data['customer_phone'] = trim((string) ($data['customer_phone'] ?? '')) ?: null;
            $data['bill_to'] = 'customer';
            if (! $data['customer_name']) {
                throw ValidationException::withMessages(['customer_name' => 'Enter the walk-in customer name for a work order.']);
            }
        }
        if (mb_strlen((string) ($data['customer_name'] ?? '')) > 255 || mb_strlen((string) ($data['customer_phone'] ?? '')) > 50) {
            throw ValidationException::withMessages(['customer_name' => 'Customer details are too long.']);
        }
        if ($source !== 'in_clinic' && (! empty($data['optical_prescription_id']) || ! empty($data['refraction_id']))) {
            throw ValidationException::withMessages(['optical_prescription_id' => 'Clinic prescriptions are available only for in-clinic orders.']);
        }
        return $data;
    }

    private function validateManualMeasurements(array $measurements): void
    {
        Validator::make($measurements, [
            'od.sph' => 'required|numeric|between:-30,30', 'os.sph' => 'required|numeric|between:-30,30',
            'od.cyl' => 'nullable|numeric|between:-15,15', 'os.cyl' => 'nullable|numeric|between:-15,15',
            'od.axis' => 'required_with:od.cyl|nullable|integer|between:0,180',
            'os.axis' => 'required_with:os.cyl|nullable|integer|between:0,180',
            'od.add' => 'nullable|numeric|between:0,8', 'os.add' => 'nullable|numeric|between:0,8',
        ])->validate();
    }

    private function normalizeServices(array $data): array
    {
        $workType = $data['work_type'] ?? 'prescription';
        if (! in_array($workType, ['prescription', 'service'], true)) {
            throw ValidationException::withMessages(['work_type' => 'Select a valid work type.']);
        }
        // Prescription orders may carry extra services too (glazing, tinting…).
        $lines = $data['services'] ?? [];
        if ($workType === 'service' && (! is_array($lines) || count($lines) === 0)) {
            throw ValidationException::withMessages(['services' => 'Add at least one optical service.']);
        }
        if (count($lines) > 20) {
            throw ValidationException::withMessages(['services' => 'Too many services on one order.']);
        }
        $normalized = [];
        $ids = collect($lines)->pluck('service_id')->map(fn ($id) => (int) $id);
        if ($ids->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['services' => 'Select each service once and use quantity for repeat work.']);
        }
        $catalogue = OpticalService::where('clinic_id', app(TenantContext::class)->clinicId())
            ->whereIn('id', $ids)->where('is_active', true)->get()->keyBy('id');
        foreach ($lines as $line) {
            $service = $catalogue->get((int) ($line['service_id'] ?? 0));
            if (! $service) throw ValidationException::withMessages(['services' => 'Select an active service from this subscriber’s catalogue.']);
            $quantity = filter_var($line['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($quantity === false || $quantity < 1 || $quantity > 1000) {
                throw ValidationException::withMessages(['services' => 'Enter a valid quantity for each service.']);
            }
            $normalized[] = [
                'optical_service_id' => $service->id,
                'service_code' => $service->code, 'description' => $service->name, 'quantity' => $quantity,
                'unit_price' => round((float) $service->price, 2),
                'line_total' => round($quantity * (float) $service->price, 2),
                'requires_rx' => $service->requires_rx, 'requires_frame' => $service->requires_frame,
            ];
        }
        if (($data['bill_to'] ?? 'customer') === 'partner' && trim((string) ($data['partner_clinic_name'] ?? '')) === '') {
            throw ValidationException::withMessages(['partner_clinic_name' => 'Enter the referring clinic name.']);
        }
        if (mb_strlen((string) ($data['partner_clinic_name'] ?? '')) > 255) {
            throw ValidationException::withMessages(['partner_clinic_name' => 'The referring clinic name is too long.']);
        }
        if (! in_array($data['bill_to'] ?? 'customer', ['customer', 'partner'], true)) {
            throw ValidationException::withMessages(['bill_to' => 'Select a valid bill-to party.']);
        }
        return $normalized;
    }

    private function needsPrescription(array $data, array $services): bool
    {
        return ($data['work_type'] ?? 'prescription') === 'prescription'
            || collect($services)->contains(fn ($line) => $line['requires_rx']);
    }

    private function serviceTotal(array $services): float
    {
        return round(array_sum(array_column($services, 'line_total')), 2);
    }

    private function storeServices(LensOrder $order, array $services): void
    {
        foreach ($services as $line) $order->serviceLines()->create($line);
    }

    private function prescription(Patient $patient, array $data): ?OpticalPrescription
    {
        if (! empty($data['optical_prescription_id'])) {
            return OpticalPrescription::where('patient_id', $patient->id)
                ->findOrFail($data['optical_prescription_id']);
        }

        if (empty($data['refraction_id'])) {
            $measurements = $data['measurements'] ?? [];
            $this->validateManualMeasurements($measurements);
            return OpticalPrescription::create([
                'patient_id' => $patient->id,
                'created_by' => auth()->id(),
                'source' => 'entered',
                'prescribed_at' => now()->toDateString(),
                'measurements' => $measurements,
            ]);
        }
        abort_if(\App\Support\OpticalMode::opticalOnly(), 403);

        $refraction = Refractions::whereHas('consultation', fn ($query) =>
            $query->where('patient_id', $patient->id))
            ->findOrFail($data['refraction_id']);
        if (! $refraction->dispensing_required || ! $refraction->dispensing_authorized_at) {
            throw ValidationException::withMessages([
                'refraction_id' => 'This clinic prescription is not authorized for dispensing.',
            ]);
        }

        return OpticalPrescription::firstOrCreate(
            ['refraction_id' => $refraction->id],
            [
                'patient_id' => $patient->id, 'created_by' => auth()->id(),
                'source' => 'clinic', 'prescriber_name' => $refraction->user?->name,
                'prescribed_at' => $refraction->created_at?->toDateString() ?? now()->toDateString(),
                'measurements' => [
                    'od' => ['sph' => $refraction->subjective_od_sphere, 'cyl' => $refraction->subjective_od_cylinder,
                        'axis' => $refraction->subjective_od_axis, 'add' => $refraction->subjective_od_add],
                    'os' => ['sph' => $refraction->subjective_os_sphere, 'cyl' => $refraction->subjective_os_cylinder,
                        'axis' => $refraction->subjective_os_axis, 'add' => $refraction->subjective_os_add],
                    'pd' => $refraction->pd,
                ],
                'verified_at' => $refraction->dispensing_authorized_at,
                'verified_by' => $refraction->dispensing_authorized_by,
            ]
        );
    }
}
