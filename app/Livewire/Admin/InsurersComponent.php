<?php

namespace App\Livewire\Admin;

use App\Models\AuditTrail;
use App\Models\Category;
use App\Models\Insurer;
use App\Models\InsurerCoverageRule;
use App\Models\Product;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class InsurersComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public string $search      = '';
    public string $schemeFilter = '';
    public int    $perPage     = 15;

    public bool   $showModal   = false;
    public bool   $isEditing   = false;
    public ?int   $insurerId   = null;

    public array $state = [
        'name'           => '',
        'code'           => '',
        'scheme_type'    => 'NHIS',
        'contact_person' => '',
        'contact_phone'  => '',
        'notes'          => '',
        'active'         => true,
        'patient_pays_difference' => true,
        'shortfall_action'        => 'bill_patient',
    ];

    // Coverage rules modal
    public ?int   $coverageInsurerId  = null;
    public string $ruleProductSearch  = '';
    public array  $ruleProductResults = [];
    public array  $ruleState = [
        'target'         => 'category',
        'category_id'    => '',
        'product_id'     => '',
        'product_name'   => '',
        'coverage_type'  => 'percent',
        'coverage_value' => '100',
        'tariff_price'   => '',
    ];

    protected $queryString = [
        'search'       => ['except' => ''],
        'schemeFilter' => ['except' => ''],
    ];

    public function updatingSearch(): void       { $this->resetPage(); }
    public function updatingSchemeFilter(): void { $this->resetPage(); }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->isEditing = false;
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $insurer = Insurer::findOrFail($id);
        $this->insurerId = $id;
        $this->state = [
            'name'           => $insurer->name,
            'code'           => $insurer->code ?? '',
            'scheme_type'    => $insurer->scheme_type,
            'contact_person' => $insurer->contact_person ?? '',
            'contact_phone'  => $insurer->contact_phone ?? '',
            'notes'          => $insurer->notes ?? '',
            'active'         => $insurer->active,
            'patient_pays_difference' => (bool) $insurer->patient_pays_difference,
            'shortfall_action'        => $insurer->shortfall_action ?: 'bill_patient',
        ];
        $this->isEditing = true;
        $this->showModal = true;
    }

    public function save(): void
    {
        $data = $this->validateForm();

        if ($this->isEditing) {
            $insurer = Insurer::findOrFail($this->insurerId);
            $old = $insurer->only(array_keys($data));
            $insurer->update($data);
            AuditTrail::record('insurer.updated', "Updated insurer: {$insurer->name}", $insurer, $old, $data);
            $this->dispatch('notify', ...['type' => 'success', 'message' => 'Insurer updated.']);
        } else {
            $insurer = Insurer::create($data);
            AuditTrail::record('insurer.created', "Created insurer: {$insurer->name}", $insurer, [], $data);
            $this->dispatch('notify', ...['type' => 'success', 'message' => 'Insurer added.']);
        }

        $this->showModal = false;
        $this->resetForm();
    }

    public function toggleActive(int $id): void
    {
        $insurer = Insurer::findOrFail($id);
        $insurer->update(['active' => !$insurer->active]);
        $status = $insurer->active ? 'activated' : 'deactivated';
        AuditTrail::record('insurer.toggled', "Insurer {$insurer->name} {$status}", $insurer);
        $this->dispatch('notify', ...['type' => 'info', 'message' => "Insurer {$status}."]);
    }

    public function delete(int $id): void
    {
        $insurer = Insurer::withCount('claims')->findOrFail($id);
        if ($insurer->claims_count > 0) {
            $this->dispatch('notify', ...[
                'type'    => 'error',
                'message' => "Cannot delete — {$insurer->claims_count} claim(s) exist for this insurer.",
            ]);
            return;
        }
        AuditTrail::record('insurer.deleted', "Deleted insurer: {$insurer->name}", $insurer, $insurer->toArray(), []);
        $insurer->delete();
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Insurer deleted.']);
    }

    // ── Coverage rules ────────────────────────────────────────────────────────

    public function openCoverage(int $id): void
    {
        $this->coverageInsurerId = Insurer::findOrFail($id)->id;
        $this->resetRuleForm();
    }

    public function closeCoverage(): void
    {
        $this->coverageInsurerId = null;
        $this->resetRuleForm();
    }

    public function updatedRuleProductSearch(): void
    {
        $term = trim($this->ruleProductSearch);
        $this->ruleProductResults = mb_strlen($term) < 2 ? [] : Product::with('category:id,name')
            ->where('name', 'like', "%{$term}%")
            ->orderBy('name')
            ->limit(10)
            ->get()
            ->map(fn (Product $product) => [
                'id'       => $product->id,
                'name'     => $product->name,
                'category' => $product->category?->name,
                'price'    => (float) $product->selling_price,
            ])->all();
    }

    public function selectRuleProduct(int $id): void
    {
        $product = Product::findOrFail($id);
        $this->ruleState['product_id']   = $product->id;
        $this->ruleState['product_name'] = $product->name;
        $this->ruleProductSearch  = '';
        $this->ruleProductResults = [];
    }

    public function saveRule(): void
    {
        $insurer = Insurer::findOrFail($this->coverageInsurerId);
        $data = $this->validate([
            'ruleState.target'         => 'required|in:category,product',
            'ruleState.category_id'    => 'required_if:ruleState.target,category',
            'ruleState.product_id'     => 'required_if:ruleState.target,product',
            'ruleState.coverage_type'  => ['required', Rule::in(array_keys(InsurerCoverageRule::TYPES))],
            'ruleState.coverage_value' => [
                Rule::requiredIf(fn () => $this->ruleState['coverage_type'] !== 'excluded'),
                'nullable', 'numeric', 'min:0',
                ...($this->ruleState['coverage_type'] === 'percent' ? ['max:100'] : []),
            ],
            'ruleState.tariff_price'   => 'nullable|numeric|min:0',
        ], [
            'ruleState.category_id.required_if' => 'Choose a category.',
            'ruleState.product_id.required_if'  => 'Choose a product or service.',
        ], [
            'ruleState.coverage_value' => 'coverage',
            'ruleState.tariff_price'   => 'tariff price',
        ])['ruleState'];

        // Resolve through the clinic-scoped models so another clinic's ids are refused.
        $category = $data['target'] === 'category' ? Category::find($data['category_id']) : null;
        $product  = $data['target'] === 'product' ? Product::find($data['product_id']) : null;
        if (!$category && !$product) {
            $this->addError('ruleState.' . ($data['target'] === 'category' ? 'category_id' : 'product_id'), 'That item is no longer available.');
            return;
        }

        $values = [
            'coverage_type'  => $data['coverage_type'],
            'coverage_value' => $data['coverage_type'] === 'excluded' ? 0 : round((float) $data['coverage_value'], 2),
            'tariff_price'   => $data['coverage_type'] === 'excluded' || $data['tariff_price'] === '' || $data['tariff_price'] === null
                ? null : round((float) $data['tariff_price'], 2),
        ];

        $rule = InsurerCoverageRule::where('insurer_id', $insurer->id)
            ->where('category_id', $category?->id)
            ->where('product_id', $product?->id)
            ->first();
        $old = $rule?->only(array_keys($values)) ?? [];

        $rule
            ? $rule->update($values)
            : $rule = InsurerCoverageRule::create($values + [
                'insurer_id'  => $insurer->id,
                'category_id' => $category?->id,
                'product_id'  => $product?->id,
            ]);

        $target = $category ? "category {$category->name}" : "{$product->name}";
        AuditTrail::record('insurer.coverage_saved', "{$insurer->name} coverage for {$target}: {$rule->describe()}", $insurer, $old, $values);

        $this->resetRuleForm();
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Coverage saved.']);
    }

    public function editRule(int $id): void
    {
        $rule = InsurerCoverageRule::with('product:id,name')->where('insurer_id', $this->coverageInsurerId)->findOrFail($id);
        $this->ruleState = [
            'target'         => $rule->product_id ? 'product' : 'category',
            'category_id'    => $rule->category_id ?? '',
            'product_id'     => $rule->product_id ?? '',
            'product_name'   => $rule->product?->name ?? '',
            'coverage_type'  => $rule->coverage_type,
            'coverage_value' => $rule->coverage_type === 'excluded' ? '' : (string) (float) $rule->coverage_value,
            'tariff_price'   => $rule->tariff_price !== null ? (string) (float) $rule->tariff_price : '',
        ];
        $this->resetValidation();
    }

    public function deleteRule(int $id): void
    {
        $rule = InsurerCoverageRule::with(['insurer', 'category', 'product'])->where('insurer_id', $this->coverageInsurerId)->findOrFail($id);
        $target = $rule->category ? "category {$rule->category->name}" : ($rule->product?->name ?? 'item');
        AuditTrail::record('insurer.coverage_removed', "{$rule->insurer->name} coverage removed for {$target}", $rule->insurer, $rule->only(['coverage_type', 'coverage_value', 'tariff_price']), []);
        $rule->delete();
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Coverage removed.']);
    }

    private function resetRuleForm(): void
    {
        $this->ruleState = [
            'target'         => 'category',
            'category_id'    => '',
            'product_id'     => '',
            'product_name'   => '',
            'coverage_type'  => 'percent',
            'coverage_value' => '100',
            'tariff_price'   => '',
        ];
        $this->ruleProductSearch  = '';
        $this->ruleProductResults = [];
        $this->resetValidation();
    }

    public function render()
    {
        $insurers = Insurer::withCount(['claims', 'coverageRules'])
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%")
                ->orWhere('code', 'like', "%{$this->search}%"))
            ->when($this->schemeFilter, fn ($q) => $q->where('scheme_type', $this->schemeFilter))
            ->orderBy('name')
            ->paginate($this->perPage);

        $coverageInsurer = $this->coverageInsurerId ? Insurer::find($this->coverageInsurerId) : null;
        $coverageRules = $coverageInsurer
            ? InsurerCoverageRule::with(['category:id,name', 'product:id,name,selling_price'])
                ->where('insurer_id', $coverageInsurer->id)
                ->get()
                ->sortBy(fn ($rule) => [$rule->product_id ? 1 : 0, $rule->category?->name ?? $rule->product?->name])
                ->values()
            : collect();
        $ruleCategories = $coverageInsurer ? Category::orderBy('name')->get(['id', 'name']) : collect();

        return view('livewire.admin.insurers-component', compact('insurers', 'coverageInsurer', 'coverageRules', 'ruleCategories'))
            ->layout('layouts.admin.admin-layout');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function validateForm(): array
    {
        return $this->validate([
            'state.name'        => 'required|string|max:120',
            'state.code'        => 'nullable|string|max:30',
            'state.scheme_type' => 'required|in:NHIS,Private,Corporate',
            'state.contact_person' => 'nullable|string|max:120',
            'state.contact_phone'  => 'nullable|string|max:30',
            'state.notes'       => 'nullable|string|max:500',
            'state.active'      => 'boolean',
            'state.patient_pays_difference' => 'boolean',
            'state.shortfall_action'        => ['required', Rule::in(array_keys(Insurer::SHORTFALL_ACTIONS))],
        ], [], [
            'state.name'        => 'Insurer Name',
            'state.code'        => 'Code',
            'state.scheme_type' => 'Scheme Type',
        ])['state'];
    }

    private function resetForm(): void
    {
        $this->insurerId = null;
        $this->state = [
            'name'           => '',
            'code'           => '',
            'scheme_type'    => 'NHIS',
            'contact_person' => '',
            'contact_phone'  => '',
            'notes'          => '',
            'active'         => true,
            'patient_pays_difference' => true,
            'shortfall_action'        => 'bill_patient',
        ];
        $this->resetValidation();
    }
}
