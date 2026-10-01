<?php

namespace App\Livewire\Admin;

use App\Models\AuditTrail;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Sales;
use App\Support\PaymentMethods;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Settings → Payment methods: the clinic's clinical list (Settings page) or optical list
 * (Optical Settings). Methods can be switched off, renamed, reordered and added; a method
 * already used on a payment cannot be removed, only switched off.
 */
class PaymentMethodsComponent extends Component
{
    public bool $optical = false;
    public array $methods = [];
    public string $newLabel = '';

    public function mount(bool $optical = false): void
    {
        $this->optical = $optical;
        $this->authorizeAccess();
        $this->load();
    }

    public function add(): void
    {
        $this->authorizeAccess();
        $label = trim($this->newLabel);
        $this->validate(['newLabel' => 'required|string|max:40'], [], ['newLabel' => 'name']);

        if (collect($this->methods)->contains(fn ($m) => strcasecmp(trim($m['label']), $label) === 0)) {
            $this->addError('newLabel', 'There is already a method with this name.');
            return;
        }

        $key = PaymentMethods::newKey($this->line(), $label);
        while (collect($this->methods)->contains('key', $key)) {
            $key .= '_x';
        }
        $this->methods[] = ['key' => $key, 'label' => $label, 'is_active' => true, 'built_in' => false, 'used' => false];
        $this->newLabel = '';
    }

    public function remove(int $index): void
    {
        $method = $this->methods[$index] ?? null;
        if (!$method || $method['built_in'] || $method['used']) {
            return; // built-ins and methods already on payments can only be switched off
        }
        array_splice($this->methods, $index, 1);
    }

    public function move(int $index, int $direction): void
    {
        $to = $index + $direction;
        if (!isset($this->methods[$index], $this->methods[$to])) {
            return;
        }
        [$this->methods[$index], $this->methods[$to]] = [$this->methods[$to], $this->methods[$index]];
    }

    public function save(): void
    {
        $this->authorizeAccess();
        $this->validate([
            'methods'           => 'required|array|min:1',
            'methods.*.label'   => 'required|string|max:40',
        ], [], ['methods.*.label' => 'name']);

        if (!collect($this->methods)->contains(fn ($m) => !empty($m['is_active']))) {
            $this->addError('methods', 'Keep at least one payment method switched on.');
            return;
        }
        $labels = collect($this->methods)->map(fn ($m) => mb_strtolower(trim($m['label'])));
        if ($labels->unique()->count() !== $labels->count()) {
            $this->addError('methods', 'Two methods have the same name.');
            return;
        }

        $line = $this->line();
        $before = PaymentMethods::all($line)->map(fn ($m) => $m['label'] . ($m['is_active'] ? '' : ' (off)'))->all();

        DB::transaction(function () use ($line) {
            $keep = [];
            foreach (array_values($this->methods) as $position => $method) {
                PaymentMethod::updateOrCreate(
                    ['business_line' => $line, 'key' => $method['key']],
                    ['label' => trim($method['label']), 'is_active' => (bool) $method['is_active'], 'sort_order' => $position]
                );
                $keep[] = $method['key'];
            }
            PaymentMethod::where('business_line', $line)->whereNotIn('key', $keep)->delete();
        });

        PaymentMethods::flush();
        $after = PaymentMethods::all($line)->map(fn ($m) => $m['label'] . ($m['is_active'] ? '' : ' (off)'))->all();
        AuditTrail::record('payment_methods.updated', ucfirst($line) . ' payment methods updated', null, ['methods' => $before], ['methods' => $after]);

        $this->load();
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Payment methods saved.']);
    }

    private function load(): void
    {
        $line = $this->line();
        $used = PaymentTransaction::query()
            ->whereIn('sale_id', Sales::withTrashed()->where('business_line', $line)->select('id'))
            ->distinct()
            ->pluck('payment_method')
            ->all();

        $this->methods = PaymentMethods::all($line)
            ->map(fn ($m) => $m + ['used' => in_array($m['key'], $used, true)])
            ->values()
            ->all();
    }

    private function line(): string
    {
        return $this->optical ? PaymentMethods::OPTICAL : PaymentMethods::CLINIC;
    }

    private function authorizeAccess(): void
    {
        $user = auth()->user();
        // Clinic Settings is for Super Admins; Optical Settings also lets Managers in.
        abort_unless($user?->hasRole('Super Admin') || ($this->optical && $user?->hasRole('Manager')), 403);
    }

    public function render()
    {
        return view('livewire.admin.payment-methods-component');
    }
}
