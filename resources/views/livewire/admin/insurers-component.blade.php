<div class="clinic-ui ui-page">
  {{-- Page Header --}}
  <div class="content-header">
    <div class="w-full">
      <div class="flex flex-wrap -mx-2 mb-2 items-center">
        <div class="w-full sm:w-6/12 px-2">
          <h1 class="m-0"><i class="fas fa-building mr-2 text-sky-700"></i>Insurers</h1>
        </div>
        <div class="w-full sm:w-6/12 px-2">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.insurance.claims') }}">Insurance</a></li>
            <li class="breadcrumb-item active">Insurers</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="content">
    <div class="w-full">

      {{-- Filters + Actions --}}
      <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white card-info shadow-sm">
        <div class="card-header border-b border-slate-200 bg-slate-50 px-4 py-2 flex-wrap" style="gap:8px;">
          <div class="flex items-center flex-wrap w-full" style="gap:8px;">
            <div class="flex items-stretch" style="max-width:260px;">
              <div class="flex"><span class="flex items-center border border-slate-300 bg-slate-50 px-2 text-sm text-slate-600"><i class="fas fa-search"></i></span></div>
              <input wire:model.live.debounce.300ms="search" type="text" class="form-control ui-input" placeholder="Search name or code…">
            </div>
            <select wire:model.live="schemeFilter" class="form-control ui-input" style="max-width:160px;">
              <option value="">All Schemes</option>
              <option value="NHIS">NHIS</option>
              <option value="Private">Private</option>
              <option value="Corporate">Corporate</option>
            </select>
            <div class="ml-auto">
              <button wire:click="openCreate" class="btn ui-button ui-button-primary ui-button-sm">
                <i class="fas fa-plus mr-1"></i>Add Insurer
              </button>
            </div>
          </div>
        </div>

        <div class="card-body p-0">
          <div class="ui-table-wrap">
            <table class="table ui-table ui-table-sm mb-0">
              <thead class="">
                <tr>
                  <th>Name</th>
                  <th>Code</th>
                  <th>Scheme</th>
                  <th>Contact</th>
                  <th class="text-center">Coverage</th>
                  <th class="text-center">Claims</th>
                  <th class="text-center">Status</th>
                  <th class="text-center">Actions</th>
                </tr>
              </thead>
              <tbody wire:loading.class="opacity-50">
                @forelse($insurers as $insurer)
                <tr>
                  <td class="font-semibold">{{ $insurer->name }}</td>
                  <td class="text-slate-500 text-sm">{{ $insurer->code ?: '—' }}</td>
                  <td>
                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold {{ $insurer->schemeBadgeClass() }}">{{ $insurer->scheme_type }}</span>
                  </td>
                  <td class="text-sm">
                    @if($insurer->contact_person)
                      {{ $insurer->contact_person }}
                      @if($insurer->contact_phone)
                        <br><span class="text-slate-500">{{ $insurer->contact_phone }}</span>
                      @endif
                    @else
                      <span class="text-slate-500">—</span>
                    @endif
                  </td>
                  <td class="text-center">
                    <button wire:click="openCoverage({{ $insurer->id }})" class="btn ui-button ui-button-sm {{ $insurer->coverage_rules_count ? 'ui-button-secondary' : 'ui-button-secondary' }}">
                      <i class="fas fa-shield-alt mr-1"></i>{{ $insurer->coverage_rules_count ? $insurer->coverage_rules_count . ' rule' . ($insurer->coverage_rules_count === 1 ? '' : 's') : 'Set up' }}
                    </button>
                  </td>
                  <td class="text-center">
                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-100 text-slate-700">{{ $insurer->claims_count }}</span>
                  </td>
                  <td class="text-center">
                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold {{ $insurer->active ? 'bg-green-100 text-green-800' : 'bg-slate-50 text-slate-600 text-slate-500' }}">
                      {{ $insurer->active ? 'Active' : 'Inactive' }}
                    </span>
                  </td>
                  <td class="text-center" style="white-space:nowrap;">
                    <button wire:click="openEdit({{ $insurer->id }})" class="btn ui-button ui-button-sm ui-button-secondary" title="Edit">
                      <i class="fas fa-edit"></i>
                    </button>
                    <button wire:click="toggleActive({{ $insurer->id }})" class="btn ui-button ui-button-sm btn-outline-{{ $insurer->active ? 'warning' : 'success' }}" title="{{ $insurer->active ? 'Deactivate' : 'Activate' }}">
                      <i class="fas fa-{{ $insurer->active ? 'ban' : 'check' }}"></i>
                    </button>
                    <button wire:click="delete({{ $insurer->id }})"
                            wire:confirm="Delete {{ $insurer->name }}? This cannot be undone."
                            class="btn ui-button ui-button-sm ui-button-danger" title="Delete">
                      <i class="fas fa-trash"></i>
                    </button>
                  </td>
                </tr>
                @empty
                <tr>
                  <td colspan="8" class="text-center py-12 text-slate-500">
                    <i class="fas fa-building fa-3x mb-4 block text-slate-500"></i>
                    <h5>No insurers found</h5>
                    <button wire:click="openCreate" class="btn ui-button ui-button-primary ui-button-sm mt-2">
                      <i class="fas fa-plus mr-1"></i>Add your first insurer
                    </button>
                  </td>
                </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>

        @if($insurers->hasPages())
        <div class="border-t border-slate-200 bg-slate-50 px-4 py-2">{{ $insurers->links() }}</div>
        @endif
      </div>

    </div>
  </div>

  {{-- Create / Edit Modal --}}
  @if($showModal)
  <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 show block" tabindex="-1" role="dialog" style="background:rgba(0,0,0,.5);">
    <div class="mx-auto my-8 w-full max-w-3xl" role="document">
      <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl">
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 bg-sky-600 text-white">
          <h5 class="text-base font-semibold">
            <i class="fas fa-building mr-2"></i>
            {{ $isEditing ? 'Edit Insurer' : 'Add Insurer' }}
          </h5>
          <button wire:click="$set('showModal', false)" type="button" class="text-xl leading-none hover:text-slate-800 text-white"><span>&times;</span></button>
        </div>
        <div class="p-4">
          <div class="flex flex-wrap -mx-2">
            {{-- Insurer details --}}
            <div class="w-full md:w-6/12 px-2">
              <h6 class="font-semibold text-slate-500 uppercase text-sm mb-4">Details</h6>
              <div class="mb-4">
                <label>Insurer Name <span class="text-red-700">*</span></label>
                <input wire:model="state.name" type="text" class="form-control ui-input @error('state.name') is-invalid @enderror" placeholder="e.g. National Health Insurance Scheme">
                @error('state.name')<div class="ui-error">{{ $message }}</div>@enderror
              </div>
              <div class="flex flex-wrap -mx-2">
                <div class="mb-4 w-6/12 px-2">
                  <label>Code</label>
                  <input wire:model="state.code" type="text" class="form-control ui-input" placeholder="e.g. NHIS">
                </div>
                <div class="mb-4 w-6/12 px-2">
                  <label>Scheme Type <span class="text-red-700">*</span></label>
                  <select wire:model="state.scheme_type" class="form-control ui-input @error('state.scheme_type') is-invalid @enderror">
                    <option value="NHIS">NHIS</option>
                    <option value="Private">Private</option>
                    <option value="Corporate">Corporate</option>
                  </select>
                  @error('state.scheme_type')<div class="ui-error">{{ $message }}</div>@enderror
                </div>
              </div>
              <div class="flex flex-wrap -mx-2">
                <div class="mb-4 w-6/12 px-2">
                  <label>Contact Person</label>
                  <input wire:model="state.contact_person" type="text" class="form-control ui-input" placeholder="Name">
                </div>
                <div class="mb-4 w-6/12 px-2">
                  <label>Contact Phone</label>
                  <input wire:model="state.contact_phone" type="text" class="form-control ui-input" placeholder="Phone number">
                </div>
              </div>
              <div class="mb-4 md:mb-0">
                <label>Notes</label>
                <textarea wire:model="state.notes" class="form-control ui-input" rows="2" placeholder="Any additional notes…"></textarea>
              </div>
            </div>

            {{-- Billing --}}
            <div class="w-full md:w-6/12 px-2 border-l border-slate-200">
              <h6 class="font-semibold text-slate-500 uppercase text-sm mb-4">Billing</h6>
              <div class="mb-4">
                <div class="flex items-center gap-2">
                  <input wire:model="state.patient_pays_difference" type="checkbox" class="rounded border-slate-300 text-teal-700" id="insurerPaysDifference">
                  <label class="" for="insurerPaysDifference">Patient may pay the difference</label>
                </div>
                <small class="mt-1 block text-xs text-slate-500">
                  When a service has an insurer tariff below your price: on, the patient pays the gap;
                  off, you bill at the insurer's tariff and absorb the gap.
                </small>
              </div>
              <div class="mb-4">
                <label>When the insurer pays less than claimed</label>
                <select wire:model="state.shortfall_action" class="form-control ui-input @error('state.shortfall_action') is-invalid @enderror">
                  @foreach(\App\Models\Insurer::SHORTFALL_ACTIONS as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                  @endforeach
                </select>
                @error('state.shortfall_action')<div class="ui-error">{{ $message }}</div>@enderror
              </div>
              <div class="mb-0">
                <div class="flex items-center gap-2">
                  <input wire:model="state.active" type="checkbox" class="rounded border-slate-300 text-teal-700" id="insurerActive">
                  <label class="" for="insurerActive">Active</label>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3">
          <button wire:click="$set('showModal', false)" class="btn ui-button ui-button-secondary">Cancel</button>
          <button wire:click="save" class="btn ui-button ui-button-primary">
            <i class="fas fa-save mr-1"></i>{{ $isEditing ? 'Update' : 'Add Insurer' }}
          </button>
        </div>
      </div>
    </div>
  </div>
  @endif
  {{-- Coverage Rules Modal --}}
  @if($coverageInsurer)
  <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 show block" tabindex="-1" role="dialog" style="background:rgba(0,0,0,.5);overflow-y:auto;">
    <div class="mx-auto my-8 w-full max-w-3xl" role="document">
      <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl">
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 bg-sky-600 text-white">
          <h5 class="text-base font-semibold"><i class="fas fa-shield-alt mr-2"></i>{{ $coverageInsurer->name }} — Coverage</h5>
          <button wire:click="closeCoverage" type="button" class="text-xl leading-none hover:text-slate-800 text-white"><span>&times;</span></button>
        </div>
        <div class="p-4">
          <p class="text-slate-500 text-sm mb-4">
            Set what {{ $coverageInsurer->name }} pays for each category, and override single services or products where needed.
            Anything without a rule is paid by the patient.
            @unless($coverageInsurer->patient_pays_difference)
              <br><strong>Tariffs:</strong> this insurer does not allow charging the patient the difference, so items with a tariff are billed at the tariff.
            @endunless
          </p>

          <div class="ui-table-wrap mb-4">
            <table class="table ui-table ui-table-sm mb-0">
              <thead class="">
                <tr>
                  <th>Applies to</th>
                  <th>Insurer pays</th>
                  <th class="text-right">Tariff</th>
                  <th class="text-center">Actions</th>
                </tr>
              </thead>
              <tbody>
                @forelse($coverageRules as $rule)
                <tr>
                  <td>
                    @if($rule->product_id)
                      <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-50 text-slate-600 border border-slate-200 mr-1">Item</span>{{ $rule->product?->name ?? 'Deleted item' }}
                    @else
                      <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-sky-100 text-sky-800 mr-1">Category</span>{{ $rule->category?->name ?? 'Deleted category' }}
                    @endif
                  </td>
                  <td class="{{ $rule->coverage_type === 'excluded' ? 'text-red-700' : '' }}">{{ $rule->describe() }}</td>
                  <td class="text-right">{{ $rule->tariff_price !== null ? currency() . ' ' . number_format((float) $rule->tariff_price, 2) : '—' }}</td>
                  <td class="text-center" style="white-space:nowrap;">
                    <button wire:click="editRule({{ $rule->id }})" class="btn ui-button ui-button-sm ui-button-secondary" title="Edit"><i class="fas fa-edit"></i></button>
                    <button wire:click="deleteRule({{ $rule->id }})" wire:confirm="Remove this coverage rule?" class="btn ui-button ui-button-sm ui-button-danger" title="Remove"><i class="fas fa-trash"></i></button>
                  </td>
                </tr>
                @empty
                <tr><td colspan="4" class="text-center text-slate-500 py-4">No coverage set yet — patients with this insurer pay in full.</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>

          <div class="border border-slate-200 rounded-md p-4 bg-slate-50">
            <h6 class="font-semibold mb-4">Add or update coverage</h6>
            <div class="flex flex-wrap -mx-2">
              <div class="mb-4 w-full md:w-4/12 px-2">
                <label class="text-sm mb-1">Applies to</label>
                <select wire:model.live="ruleState.target" class="form-control ui-input ui-input-sm">
                  <option value="category">A whole category</option>
                  <option value="product">One service / product</option>
                </select>
              </div>
              <div class="mb-4 w-full md:w-8/12 px-2">
                @if($ruleState['target'] === 'category')
                  <label class="text-sm mb-1">Category</label>
                  <select wire:model="ruleState.category_id" class="form-control ui-input ui-input-sm @error('ruleState.category_id') is-invalid @enderror">
                    <option value="">Choose…</option>
                    @foreach($ruleCategories as $category)
                      <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                  </select>
                  @error('ruleState.category_id')<div class="ui-error">{{ $message }}</div>@enderror
                @else
                  <label class="text-sm mb-1">Service / product</label>
                  @if($ruleState['product_name'])
                    <div class="flex items-center" style="gap:6px;">
                      <strong>{{ $ruleState['product_name'] }}</strong>
                      <button type="button" class="btn ui-button ui-button-sm ui-button-link" wire:click="$set('ruleState.product_name', '')">Change</button>
                    </div>
                  @else
                    <div class="relative">
                      <input wire:model.live.debounce.300ms="ruleProductSearch" type="text" placeholder="Search by name…"
                             class="form-control ui-input ui-input-sm @error('ruleState.product_id') is-invalid @enderror">
                      @error('ruleState.product_id')<div class="ui-error">{{ $message }}</div>@enderror
                      @if($ruleProductResults)
                        <div class="overflow-hidden rounded-md border border-slate-200 bg-white absolute w-full shadow-sm" style="z-index:10;max-height:220px;overflow-y:auto;">
                          @foreach($ruleProductResults as $result)
                            <button type="button" class="list-group-item block w-full border-b border-slate-100 px-3 text-left hover:bg-slate-50 py-1 text-sm" wire:click="selectRuleProduct({{ $result['id'] }})">
                              {{ $result['name'] }}
                              <span class="text-slate-500">— {{ $result['category'] ?? 'No category' }}, {{ currency() }} {{ number_format($result['price'], 2) }}</span>
                            </button>
                          @endforeach
                        </div>
                      @endif
                    </div>
                  @endif
                @endif
              </div>
            </div>
            <div class="flex flex-wrap -mx-2">
              <div class="mb-4 w-full md:w-4/12 px-2">
                <label class="text-sm mb-1">Insurer pays</label>
                <select wire:model.live="ruleState.coverage_type" class="form-control ui-input ui-input-sm">
                  @foreach(\App\Models\InsurerCoverageRule::TYPES as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                  @endforeach
                </select>
              </div>
              @if($ruleState['coverage_type'] !== 'excluded')
                <div class="mb-4 w-full md:w-4/12 px-2">
                  <label class="text-sm mb-1">{{ $ruleState['coverage_type'] === 'percent' ? 'Percentage (%)' : 'Amount per item (' . currency() . ')' }}</label>
                  <input wire:model="ruleState.coverage_value" type="number" min="0" step="0.01"
                         class="form-control ui-input ui-input-sm @error('ruleState.coverage_value') is-invalid @enderror">
                  @error('ruleState.coverage_value')<div class="ui-error">{{ $message }}</div>@enderror
                </div>
                <div class="mb-4 w-full md:w-4/12 px-2">
                  <label class="text-sm mb-1">Insurer tariff ({{ currency() }}, optional)</label>
                  <input wire:model="ruleState.tariff_price" type="number" min="0" step="0.01" placeholder="Your price"
                         class="form-control ui-input ui-input-sm @error('ruleState.tariff_price') is-invalid @enderror">
                  @error('ruleState.tariff_price')<div class="ui-error">{{ $message }}</div>@enderror
                </div>
              @endif
            </div>
            <small class="text-slate-500 block mb-2">
              The percentage is taken of the tariff when one is set (never more than your price). A rule for a single item overrides its category's rule.
            </small>
            <button wire:click="saveRule" class="btn ui-button ui-button-primary ui-button-sm"><i class="fas fa-save mr-1"></i>Save coverage</button>
          </div>
        </div>
        <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3">
          <button wire:click="closeCoverage" class="btn ui-button ui-button-secondary">Done</button>
        </div>
      </div>
    </div>
  </div>
  @endif
</div>
