<?php

namespace App\Http\Controllers;

use App\Models\PlatformSetting;
use App\Models\PricingRequest;
use App\Services\PlatformRequestAlerts;
use App\Services\PricingCatalogue;
use App\Support\Messaging\PhoneNumber;
use App\Support\Pricing\AttendanceSuitePrices;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The public pricing page (hosted only): the plans side by side, a "build your own" calculator,
 * and "Request this plan", which lands with the platform as a pricing request. It sells two
 * products: the clinic plans, and the Attendance Suite (priced by staff, see AttendanceSuitePrices).
 */
class PricingController extends Controller
{
    public function show(PricingCatalogue $catalogue)
    {
        abort_unless(config('tenancy.enabled'), 404);
        $tiers = $catalogue->tiers();
        $support = PlatformSetting::support();
        $whatsapp = PhoneNumber::normalize((string) $support['whatsapp']);

        return view('pricing.show', [
            'tiers' => $tiers, 'extras' => $catalogue->extras($tiers), 'support' => $support,
            'whatsapp' => strlen($whatsapp) >= 8 ? $whatsapp : null,
            'currency' => $tiers->first()['currency'] ?? 'GHS',
            'attendance' => AttendanceSuitePrices::catalogue(),
        ]);
    }

    public function request(Request $request, PricingCatalogue $catalogue, PlatformRequestAlerts $alerts)
    {
        abort_unless(config('tenancy.enabled'), 404);
        $data = $request->validate([
            'clinic_name' => 'required|string|max:150', 'contact_name' => 'required|string|max:120',
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+()\s-]{9,}$/'], 'email' => 'nullable|email|max:150',
            'town' => 'nullable|string|max:100', 'message' => 'nullable|string|max:1000',
            'plan' => 'required|string|max:60', 'interval' => 'required|in:monthly,yearly',
            'features' => 'array', 'features.*' => 'string|max:60',
            'users' => 'nullable|integer|min:1|max:1000', 'storage_gb' => 'nullable|integer|min:0|max:1000',
            'visits' => 'nullable|integer|min:0|max:10000', 'branches' => 'nullable|integer|min:1|max:100',
            'staff' => 'nullable|integer|min:1|max:100000',
            'website' => 'nullable|max:0', // left empty by people; bots fill it in
        ], ['phone.regex' => 'Enter a phone number we can call.', 'website.max' => 'Please try again.']);

        if (str_starts_with($data['plan'], 'attendance')) return $this->requestAttendance($data, $request, $alerts);

        // Quote again here: the page's figures are only a preview.
        $quote = $catalogue->quote($data);
        $option = collect($quote['options'])->firstWhere('family', $data['plan']);
        if (! $option) throw ValidationException::withMessages(['plan' => 'Choose one of the plans on this page.']);
        if (! $option['ok']) throw ValidationException::withMessages(['plan' => "{$option['name']} doesn't cover what you chose: {$option['why']}. Pick the suggested plan instead."]);

        $pricing = PricingRequest::create([
            'clinic_name' => $data['clinic_name'], 'contact_name' => $data['contact_name'], 'phone' => $data['phone'],
            'email' => $data['email'] ?? null, 'town' => $data['town'] ?? null, 'message' => $data['message'] ?? null,
            'subscription_plan_id' => $option['id'], 'plan_name' => $option['name'], 'billing_interval' => $data['interval'],
            'selection' => $quote['selection'] + ['lines' => $option['lines']],
            'monthly_total' => $option['monthly'], 'quoted_total' => $data['interval'] === 'yearly' ? $option['yearly'] : $option['monthly'],
            'currency' => $option['currency'], 'ip' => $request->ip(),
        ]);
        $alerts->pricingRequested($pricing);

        return redirect()->route('pricing')
            ->with('pricing_sent', "Thank you, {$pricing->contact_name}. We've received your request for {$pricing->plan_name} and will call you on {$pricing->phone} shortly.");
    }

    /**
     * An Attendance Suite request: plan is "attendance:<package>" from a card, or "attendance" from
     * the calculator with its ticked modules. From SPECIAL_FROM staff it is a request for a quote.
     */
    private function requestAttendance(array $data, Request $request, PlatformRequestAlerts $alerts)
    {
        $package = str_contains($data['plan'], ':') ? substr($data['plan'], strlen('attendance:')) : null;
        if ($package !== null && ! isset(AttendanceSuitePrices::packages()[$package])) throw ValidationException::withMessages(['plan' => 'Choose one of the packages on this page.']);
        if (empty($data['staff'])) throw ValidationException::withMessages(['staff' => 'Enter how many staff you have.']);

        // Quote again here: the page's figures are only a preview.
        $quote = AttendanceSuitePrices::quote((int) $data['staff'], $package === null ? ($data['features'] ?? []) : [], $package);
        $pricing = PricingRequest::create([
            'clinic_name' => $data['clinic_name'], 'contact_name' => $data['contact_name'], 'phone' => $data['phone'],
            'email' => $data['email'] ?? null, 'town' => $data['town'] ?? null, 'message' => $data['message'] ?? null,
            'subscription_plan_id' => null, 'plan_name' => $quote['name'], 'billing_interval' => $data['interval'],
            'selection' => ['product' => 'attendance', 'staff' => $quote['staff'], 'size' => $quote['size'], 'package' => $quote['package'],
                'features' => $quote['modules'], 'special' => $quote['special'], 'lines' => $quote['lines']],
            'monthly_total' => $quote['monthly'], 'quoted_total' => $data['interval'] === 'yearly' ? $quote['yearly'] : $quote['monthly'],
            'currency' => 'GHS', 'ip' => $request->ip(),
        ]);
        $alerts->pricingRequested($pricing);

        $what = $quote['special'] ? 'a quote for ' . number_format($quote['staff']) . ' staff' : "your request for {$pricing->plan_name}";

        return redirect()->to(route('pricing') . '#attendance')
            ->with('pricing_sent', "Thank you, {$pricing->contact_name}. We've received {$what} and will call you on {$pricing->phone} shortly.");
    }
}
