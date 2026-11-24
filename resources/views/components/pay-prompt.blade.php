{{-- How clinics pay the platform: "Dial *713*62# and follow the prompt". The code is set by the platform admin (Support Contact); empty hides this. --}}
@props(['compact' => false])
@php($payCode = \App\Models\PlatformSetting::paymentCode())
@if($payCode)
    @if($compact)
        <span {{ $attributes }}>Dial <a href="tel:{{ str_replace('#', '%23', $payCode) }}" class="font-semibold whitespace-nowrap" style="font-weight:700;white-space:nowrap">{{ $payCode }}</a> and follow the prompt to make payment.</span>
    @else
        <div {{ $attributes->merge(['class' => 'flex items-center gap-3 rounded-lg border border-teal-200 bg-teal-50 px-4 py-3 text-teal-900']) }} role="note" data-pay-prompt>
            <i class="fas fa-mobile-alt text-xl text-teal-700" aria-hidden="true"></i>
            <div class="min-w-0">
                <div class="text-sm">To pay, dial <a href="tel:{{ str_replace('#', '%23', $payCode) }}" class="text-lg font-bold tracking-wide text-teal-800 whitespace-nowrap">{{ $payCode }}</a></div>
                <div class="text-sm">and follow the prompt to make payment.</div>
            </div>
        </div>
    @endif
@endif
