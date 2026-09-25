<?php

namespace App\Http\Controllers;

use App\Models\PlatformSetting;
use App\Services\ClinicAccessService;
use App\Support\Licensing\AccessStage;
use App\Support\Messaging\WhatsAppLink;
use App\Support\Tenancy\TenantContext;

/** Page shown to clinic staff while their clinic is locked for non-payment. */
class SubscriptionLockedController extends Controller
{
    public function __invoke()
    {
        $stage = app(ClinicAccessService::class)->stage();
        if (!$stage || !$stage->blocksStaff()) {
            return redirect('/');
        }
        if (auth()->user()->hasRole('Super Admin')) {
            return redirect()->route($stage->renewalRoute());
        }

        $clinic  = app(TenantContext::class)->clinic()?->name ?? 'your clinic';
        $support = PlatformSetting::support();

        return view('subscription.locked', [
            'clinic'      => $clinic,
            'place'       => \App\Support\OpticalMode::opticalOnly() ? 'shop' : 'clinic',
            'paymentDue'  => $stage->stage === AccessStage::PAYMENT_DUE,
            'support'     => $support,
            'whatsappUrl' => WhatsAppLink::to($support['whatsapp'], "Hello, I'm contacting you from {$clinic} about our subscription."),
        ]);
    }
}
