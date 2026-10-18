<?php

namespace App\Support\Guides;

use App\Models\CashMovement;
use App\Models\Insurer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;

/**
 * The first-steps checklist on the Clinic dashboard for the owner and managers. Each step ticks itself once
 * the clinic has done it, so there is nothing to mark by hand. Once the first four are done, or the person
 * hides it, the checklist is gone.
 */
final class ClinicSetup
{
    public const GUIDE = 'clinic.setup';

    /** ['title', 'steps' => [...], 'extras' => [...], 'done', 'total'] or null when there is nothing to show. */
    public static function for(): ?array
    {
        if (Guides::seen(self::GUIDE)) return null;
        $steps = [
            ['key' => 'details', 'title' => 'Your clinic\'s details', 'body' => 'Address, phone number, email and logo. They go on receipts, reports and the texts patients get.',
                'done' => ! Setting::getSettings()->needsSetup(), 'url' => route('admin.settings'), 'button' => 'Settings'],
            ['key' => 'products', 'title' => 'Add what you charge for', 'body' => 'Consultation fees, drugs, drops and other items, each in a category with its price and stock.',
                'done' => Product::query()->exists(), 'url' => route('admin.product'), 'button' => 'Products'],
            ['key' => 'staff', 'title' => 'Invite your staff', 'body' => 'Give each person a role: Secretary for the front desk, Cashier for payments, Doctor for consultations, Manager to run the clinic. Each then sees only their part of the menu.',
                'done' => User::query()->inCurrentClinic()->count() > 1, 'url' => route('admin.users'), 'button' => 'Users'],
            ['key' => 'cash-book', 'title' => 'Start the cash book', 'body' => 'Count the cash drawer once and enter it. From then on the cash book and the day close know what each till should hold.',
                'done' => CashMovement::query()->where('type', CashMovement::OPENING)->whereNull('cancelled_at')->exists(), 'url' => route('admin.cash-book'), 'button' => 'Cash book'],
        ];
        $extras = [
            ['key' => 'payments', 'title' => 'Payment methods and receipts', 'body' => 'The methods your tills take (cash, MoMo, card...) and what a receipt says.', 'done' => PaymentMethod::query()->exists(), 'url' => route('admin.settings'), 'button' => 'Settings'],
            ['key' => 'insurers', 'title' => 'Insurers', 'body' => 'The schemes you bill, and what each covers.', 'done' => Insurer::query()->exists(), 'url' => route('admin.insurance.insurers'), 'button' => 'Insurers'],
        ];
        $done = count(array_filter($steps, fn ($step) => $step['done']));
        if ($done === count($steps)) return null;

        return ['title' => 'Getting started with the Clinic Suite', 'steps' => $steps, 'extras' => $extras, 'done' => $done, 'total' => count($steps)];
    }
}
