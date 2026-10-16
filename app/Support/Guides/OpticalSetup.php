<?php

namespace App\Support\Guides;

use App\Models\OpticalLensImport;
use App\Models\OpticalLensPrice;
use App\Models\OpticalPartnerClinic;
use App\Models\OpticalProduct;
use App\Models\OpticalService;
use App\Models\OpticalSetting;
use App\Models\Supplier;
use App\Models\User;

/**
 * The first-steps checklist on the Optical dashboard for the owner and managers. Each step ticks itself once
 * the branch has done it (a frame is added, lens stock arrives...), so there is nothing to mark by hand. Once
 * the first five are done, or the person hides it, the checklist is gone.
 */
final class OpticalSetup
{
    public const GUIDE = 'optical.setup';

    /** ['title', 'steps' => [...], 'extras' => [...], 'done', 'total'] or null when there is nothing to show. */
    public static function for(): ?array
    {
        if (Guides::seen(self::GUIDE)) return null;
        $steps = [
            ['key' => 'frames', 'title' => 'Add your frames', 'body' => 'Each frame with its brand, model, colour, size and prices, so it can go on an order or be sold at the counter. A long list can be brought in from a spreadsheet.',
                'done' => OpticalProduct::query()->where('type', 'frame')->exists(), 'url' => route('optical.products'), 'button' => 'Products'],
            ['key' => 'lenses', 'title' => 'Receive your lens stock', 'body' => 'Enter the lenses you hold on the power grid, or bring in the supplier\'s Excel sheet. Orders then take lenses from stock by themselves.',
                'done' => OpticalProduct::query()->whereNotNull('lens_specs')->exists() || OpticalLensImport::query()->exists(), 'url' => route('optical.stock'), 'button' => 'Receiving'],
            ['key' => 'prices', 'title' => 'Set lens prices', 'body' => 'A price per pair for each lens type and power range, used when an order is priced.',
                'done' => OpticalLensPrice::query()->exists(), 'url' => route('optical.catalogue', ['activeTab' => 'lens-prices']), 'button' => 'Lens prices'],
            ['key' => 'services', 'title' => 'Price your services', 'body' => 'Glazing, repairs, tinting and the rest: switch on the ones you offer and give each a price.',
                'done' => OpticalService::query()->where('is_active', true)->exists(), 'url' => route('optical.catalogue', ['activeTab' => 'services']), 'button' => 'Services'],
            ['key' => 'staff', 'title' => 'Invite your staff', 'body' => 'Give each person a role: Optical Assistant for the front desk, Lab Technician for the lab, Optician for both. Each then sees only their part of the menu.',
                'done' => User::query()->inCurrentClinic()->count() > 1, 'url' => \App\Support\OpticalNavigation::url('admin.users'), 'button' => 'Staff & roles'],
        ];
        $extras = [
            ['key' => 'settings', 'title' => 'Order rules', 'body' => 'Deposit asked for, warranty, quotation validity and pickup reminders.', 'done' => OpticalSetting::query()->exists(), 'url' => route('optical.settings'), 'button' => 'Settings'],
            ['key' => 'suppliers', 'title' => 'Suppliers and outside labs', 'body' => 'For special-order lenses and jobs sent out.', 'done' => Supplier::query()->exists(), 'url' => route('optical.purchasing'), 'button' => 'Purchasing'],
            ['key' => 'partners', 'title' => 'Partner clinics', 'body' => 'Clinics that send you work, billed to them or to the customer.', 'done' => OpticalPartnerClinic::query()->exists(), 'url' => route('optical.partners'), 'button' => 'Partners'],
        ];
        $done = count(array_filter($steps, fn ($step) => $step['done']));
        if ($done === count($steps)) return null;

        return ['title' => 'Getting started with the Optical Suite', 'steps' => $steps, 'extras' => $extras, 'done' => $done, 'total' => count($steps)];
    }
}
