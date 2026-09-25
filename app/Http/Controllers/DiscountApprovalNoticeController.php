<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\DiscountApprovalRequest;
use Illuminate\Http\Request;

class DiscountApprovalNoticeController extends Controller
{
    public function pending()
    {
        abort_if(!$this->canHandleDiscountApprovals(), 403);

        $request = DiscountApprovalRequest::with(['cashier', 'patient'])
            ->where('status', DiscountApprovalRequest::STATUS_PENDING)
            ->latest()
            ->first();

        if (!$request) {
            return response()->json(['request' => null]);
        }

        if (!$this->requestCartIsStillOpen($request)) {
            $request->delete();

            return response()->json(['request' => null]);
        }

        return response()->json([
            'request' => [
                'id' => $request->id,
                'cashier_name' => $request->cashier->name ?? 'Cashier',
                'patient_name' => $request->patient->name ?? 'Walk-in patient',
                'discount_type' => $request->discount_type,
                'discount_value' => number_format((float) $request->discount_value, 2),
                'discount_amount' => number_format((float) $request->discount_amount, 2),
                'gross_amount' => number_format((float) $request->gross_amount, 2),
                'final_amount' => number_format((float) $request->final_amount, 2),
                'created_at' => optional($request->created_at)->format('d M Y, h:i A'),
            ],
        ]);
    }

    public function approve(Request $httpRequest, DiscountApprovalRequest $discountRequest)
    {
        abort_if(!$this->canHandleDiscountApprovals(), 403);

        if ($discountRequest->status !== DiscountApprovalRequest::STATUS_PENDING) {
            return response()->json([
                'ok' => false,
                'message' => 'This discount request has already been handled.',
            ], 409);
        }

        if (!$this->requestCartIsStillOpen($discountRequest)) {
            $discountRequest->delete();

            return response()->json([
                'ok' => false,
                'message' => 'This cart was already sold or removed, so the request was deleted.',
            ], 409);
        }

        if ($this->hasApprovedDuplicate($discountRequest)) {
            $discountRequest->update([
                'status' => DiscountApprovalRequest::STATUS_REJECTED,
                'rejected_by' => auth()->id(),
                'rejected_at' => now(),
                'notes' => trim(($discountRequest->notes ? $discountRequest->notes . "\n" : '') . 'Auto-rejected: an approved discount already exists for the same patient/product.'),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'This duplicate request was rejected because the product already has an approved discount.',
            ], 409);
        }

        $discountRequest->update([
            'status' => DiscountApprovalRequest::STATUS_APPROVED,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        $rejectedDuplicates = $this->rejectPendingDuplicates($discountRequest);

        return response()->json([
            'ok' => true,
            'message' => 'Discount request approved.' . ($rejectedDuplicates > 0 ? " {$rejectedDuplicates} duplicate request(s) rejected." : ''),
        ]);
    }

    public function reject(Request $httpRequest, DiscountApprovalRequest $discountRequest)
    {
        abort_if(!$this->canHandleDiscountApprovals(), 403);

        if ($discountRequest->status !== DiscountApprovalRequest::STATUS_PENDING) {
            return response()->json([
                'ok' => false,
                'message' => 'This discount request has already been handled.',
            ], 409);
        }

        $discountRequest->update([
            'status' => DiscountApprovalRequest::STATUS_REJECTED,
            'rejected_by' => auth()->id(),
            'rejected_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Discount request rejected.',
        ]);
    }

    private function requestProductIds(DiscountApprovalRequest $request)
    {
        return collect($request->cart_snapshot ?? [])
            ->pluck('product_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    private function canHandleDiscountApprovals(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->hasRole(['Manager', 'Super Admin']) || $user?->can('manage billing') || $user?->can('approve discounts'));
    }

    private function requestCartIds(DiscountApprovalRequest $request)
    {
        return collect($request->cart_snapshot ?? [])
            ->pluck('cart_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    private function requestCartIsStillOpen(DiscountApprovalRequest $request): bool
    {
        $cartIds = $this->requestCartIds($request);

        if ($cartIds->isNotEmpty()) {
            $query = Cart::whereIn('id', $cartIds)
                ->where('purchased', false)
                ->where('status', 'pending');

            if ($request->patient_id) {
                $query->where('patient_id', $request->patient_id);
            }

            return (int) $query->count() === $cartIds->count();
        }

        $productIds = $this->requestProductIds($request);

        if ($productIds->isEmpty() || !$request->patient_id) {
            return false;
        }

        $openProductIds = Cart::where('patient_id', $request->patient_id)
            ->where('purchased', false)
            ->where('status', 'pending')
            ->whereIn('product_id', $productIds)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->unique();

        return $productIds->diff($openProductIds)->isEmpty();
    }

    private function overlapsRequestProducts(DiscountApprovalRequest $request, $productIds): bool
    {
        return $this->requestProductIds($request)->intersect($productIds)->isNotEmpty();
    }

    private function hasApprovedDuplicate(DiscountApprovalRequest $request): bool
    {
        $productIds = $this->requestProductIds($request);

        if ($productIds->isEmpty()) {
            return false;
        }

        return DiscountApprovalRequest::where('id', '!=', $request->id)
            ->where('status', DiscountApprovalRequest::STATUS_APPROVED)
            ->where('patient_id', $request->patient_id)
            ->get()
            ->contains(fn ($other) => $this->overlapsRequestProducts($other, $productIds));
    }

    private function rejectPendingDuplicates(DiscountApprovalRequest $approvedRequest): int
    {
        $productIds = $this->requestProductIds($approvedRequest);

        if ($productIds->isEmpty()) {
            return 0;
        }

        $duplicates = DiscountApprovalRequest::where('id', '!=', $approvedRequest->id)
            ->where('status', DiscountApprovalRequest::STATUS_PENDING)
            ->where('patient_id', $approvedRequest->patient_id)
            ->get()
            ->filter(fn ($other) => $this->overlapsRequestProducts($other, $productIds));

        foreach ($duplicates as $duplicate) {
            $duplicate->update([
                'status' => DiscountApprovalRequest::STATUS_REJECTED,
                'rejected_by' => auth()->id(),
                'rejected_at' => now(),
                'notes' => trim(($duplicate->notes ? $duplicate->notes . "\n" : '') . 'Auto-rejected: duplicate discount for the same patient/product.'),
            ]);
        }

        return $duplicates->count();
    }
}
