<div>
  {{-- Page Header --}}
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2 align-items-center">
        <div class="col-sm-6">
          <h1 class="m-0"><i class="fas fa-building mr-2 text-info"></i>Insurers</h1>
        </div>
        <div class="col-sm-6">
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
    <div class="container-fluid">

      {{-- Filters + Actions --}}
      <div class="card card-outline card-info shadow-sm">
        <div class="card-header flex-wrap" style="gap:8px;">
          <div class="d-flex align-items-center flex-wrap w-100" style="gap:8px;">
            <div class="input-group" style="max-width:260px;">
              <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search"></i></span></div>
              <input wire:model.live.debounce.300ms="search" type="text" class="form-control" placeholder="Search name or code…">
            </div>
            <select wire:model.live="schemeFilter" class="form-control" style="max-width:160px;">
              <option value="">All Schemes</option>
              <option value="NHIS">NHIS</option>
              <option value="Private">Private</option>
              <option value="Corporate">Corporate</option>
            </select>
            <div class="ml-auto">
              <button wire:click="openCreate" class="btn btn-info btn-sm">
                <i class="fas fa-plus mr-1"></i>Add Insurer
              </button>
            </div>
          </div>
        </div>

        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
              <thead class="thead-light">
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
                  <td class="font-weight-bold">{{ $insurer->name }}</td>
                  <td class="text-muted small">{{ $insurer->code ?: '—' }}</td>
                  <td>
                    <span class="badge {{ $insurer->schemeBadgeClass() }}">{{ $insurer->scheme_type }}</span>
                  </td>
                  <td class="small">
                    @if($insurer->contact_person)
                      {{ $insurer->contact_person }}
                      @if($insurer->contact_phone)
                        <br><span class="text-muted">{{ $insurer->contact_phone }}</span>
                      @endif
                    @else
                      <span class="text-muted">—</span>
                    @endif
                  </td>
                  <td class="text-center">
                    <button wire:click="openCoverage({{ $insurer->id }})" class="btn btn-xs {{ $insurer->coverage_rules_count ? 'btn-outline-info' : 'btn-outline-warning' }}">
                      <i class="fas fa-shield-alt mr-1"></i>{{ $insurer->coverage_rules_count ? $insurer->coverage_rules_count . ' rule' . ($insurer->coverage_rules_count === 1 ? '' : 's') : 'Set up' }}
                    </button>
                  </td>
                  <td class="text-center">
                    <span class="badge badge-secondary">{{ $insurer->claims_count }}</span>
                  </td>
                  <td class="text-center">
                    <span class="badge {{ $insurer->active ? 'badge-success' : 'badge-light text-muted' }}">
                      {{ $insurer->active ? 'Active' : 'Inactive' }}
                    </span>
                  </td>
                  <td class="text-center" style="white-space:nowrap;">
                    <button wire:click="openEdit({{ $insurer->id }})" class="btn btn-xs btn-outline-secondary" title="Edit">
                      <i class="fas fa-edit"></i>
                    </button>
                    <button wire:click="toggleActive({{ $insurer->id }})" class="btn btn-xs btn-outline-{{ $insurer->active ? 'warning' : 'success' }}" title="{{ $insurer->active ? 'Deactivate' : 'Activate' }}">
                      <i class="fas fa-{{ $insurer->active ? 'ban' : 'check' }}"></i>
                    </button>
                    <button wire:click="delete({{ $insurer->id }})"
                            wire:confirm="Delete {{ $insurer->name }}? This cannot be undone."
                            class="btn btn-xs btn-outline-danger" title="Delete">
                      <i class="fas fa-trash"></i>
                    </button>
                  </td>
                </tr>
                @empty
                <tr>
                  <td colspan="8" class="text-center py-5 text-muted">
                    <i class="fas fa-building fa-3x mb-3 d-block text-secondary"></i>
                    <h5>No insurers found</h5>
                    <button wire:click="openCreate" class="btn btn-info btn-sm mt-2">
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
        <div class="card-footer">{{ $insurers->links() }}</div>
        @endif
      </div>

    </div>
  </div>

  {{-- Create / Edit Modal --}}
  @if($showModal)
  <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background:rgba(0,0,0,.5);">
    <div class="modal-dialog modal-lg" role="document">
      <div class="modal-content">
        <div class="modal-header bg-info text-white">
          <h5 class="modal-title">
            <i class="fas fa-building mr-2"></i>
            {{ $isEditing ? 'Edit Insurer' : 'Add Insurer' }}
          </h5>
          <button wire:click="$set('showModal', false)" type="button" class="close text-white"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="row">
            {{-- Insurer details --}}
            <div class="col-md-6">
              <h6 class="font-weight-bold text-muted text-uppercase small mb-3">Details</h6>
              <div class="form-group">
                <label>Insurer Name <span class="text-danger">*</span></label>
                <input wire:model="state.name" type="text" class="form-control @error('state.name') is-invalid @enderror" placeholder="e.g. National Health Insurance Scheme">
                @error('state.name')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="form-row">
                <div class="form-group col-6">
                  <label>Code</label>
                  <input wire:model="state.code" type="text" class="form-control" placeholder="e.g. NHIS">
                </div>
                <div class="form-group col-6">
                  <label>Scheme Type <span class="text-danger">*</span></label>
                  <select wire:model="state.scheme_type" class="form-control @error('state.scheme_type') is-invalid @enderror">
                    <option value="NHIS">NHIS</option>
                    <option value="Private">Private</option>
                    <option value="Corporate">Corporate</option>
                  </select>
                  @error('state.scheme_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
              </div>
              <div class="form-row">
                <div class="form-group col-6">
                  <label>Contact Person</label>
                  <input wire:model="state.contact_person" type="text" class="form-control" placeholder="Name">
                </div>
                <div class="form-group col-6">
                  <label>Contact Phone</label>
                  <input wire:model="state.contact_phone" type="text" class="form-control" placeholder="Phone number">
                </div>
              </div>
              <div class="form-group mb-md-0">
                <label>Notes</label>
                <textarea wire:model="state.notes" class="form-control" rows="2" placeholder="Any additional notes…"></textarea>
              </div>
            </div>

            {{-- Billing --}}
            <div class="col-md-6 border-left">
              <h6 class="font-weight-bold text-muted text-uppercase small mb-3">Billing</h6>
              <div class="form-group">
                <div class="custom-control custom-switch">
                  <input wire:model="state.patient_pays_difference" type="checkbox" class="custom-control-input" id="insurerPaysDifference">
                  <label class="custom-control-label" for="insurerPaysDifference">Patient may pay the difference</label>
                </div>
                <small class="form-text text-muted">
                  When a service has an insurer tariff below your price: on, the patient pays the gap;
                  off, you bill at the insurer's tariff and absorb the gap.
                </small>
              </div>
              <div class="form-group">
                <label>When the insurer pays less than claimed</label>
                <select wire:model="state.shortfall_action" class="form-control @error('state.shortfall_action') is-invalid @enderror">
                  @foreach(\App\Models\Insurer::SHORTFALL_ACTIONS as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                  @endforeach
                </select>
                @error('state.shortfall_action')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="form-group mb-0">
                <div class="custom-control custom-switch">
                  <input wire:model="state.active" type="checkbox" class="custom-control-input" id="insurerActive">
                  <label class="custom-control-label" for="insurerActive">Active</label>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button wire:click="$set('showModal', false)" class="btn btn-secondary">Cancel</button>
          <button wire:click="save" class="btn btn-info">
            <i class="fas fa-save mr-1"></i>{{ $isEditing ? 'Update' : 'Add Insurer' }}
          </button>
        </div>
      </div>
    </div>
  </div>
  @endif
  {{-- Coverage Rules Modal --}}
  @if($coverageInsurer)
  <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background:rgba(0,0,0,.5);overflow-y:auto;">
    <div class="modal-dialog modal-lg" role="document">
      <div class="modal-content">
        <div class="modal-header bg-info text-white">
          <h5 class="modal-title"><i class="fas fa-shield-alt mr-2"></i>{{ $coverageInsurer->name }} — Coverage</h5>
          <button wire:click="closeCoverage" type="button" class="close text-white"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small mb-3">
            Set what {{ $coverageInsurer->name }} pays for each category, and override single services or products where needed.
            Anything without a rule is paid by the patient.
            @unless($coverageInsurer->patient_pays_difference)
              <br><strong>Tariffs:</strong> this insurer does not allow charging the patient the difference, so items with a tariff are billed at the tariff.
            @endunless
          </p>

          <div class="table-responsive mb-3">
            <table class="table table-sm table-hover mb-0">
              <thead class="thead-light">
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
                      <span class="badge badge-light border mr-1">Item</span>{{ $rule->product?->name ?? 'Deleted item' }}
                    @else
                      <span class="badge badge-info mr-1">Category</span>{{ $rule->category?->name ?? 'Deleted category' }}
                    @endif
                  </td>
                  <td class="{{ $rule->coverage_type === 'excluded' ? 'text-danger' : '' }}">{{ $rule->describe() }}</td>
                  <td class="text-right">{{ $rule->tariff_price !== null ? currency() . ' ' . number_format((float) $rule->tariff_price, 2) : '—' }}</td>
                  <td class="text-center" style="white-space:nowrap;">
                    <button wire:click="editRule({{ $rule->id }})" class="btn btn-xs btn-outline-secondary" title="Edit"><i class="fas fa-edit"></i></button>
                    <button wire:click="deleteRule({{ $rule->id }})" wire:confirm="Remove this coverage rule?" class="btn btn-xs btn-outline-danger" title="Remove"><i class="fas fa-trash"></i></button>
                  </td>
                </tr>
                @empty
                <tr><td colspan="4" class="text-center text-muted py-3">No coverage set yet — patients with this insurer pay in full.</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>

          <div class="border rounded p-3 bg-light">
            <h6 class="font-weight-bold mb-3">Add or update coverage</h6>
            <div class="form-row">
              <div class="form-group col-md-4">
                <label class="small mb-1">Applies to</label>
                <select wire:model.live="ruleState.target" class="form-control form-control-sm">
                  <option value="category">A whole category</option>
                  <option value="product">One service / product</option>
                </select>
              </div>
              <div class="form-group col-md-8">
                @if($ruleState['target'] === 'category')
                  <label class="small mb-1">Category</label>
                  <select wire:model="ruleState.category_id" class="form-control form-control-sm @error('ruleState.category_id') is-invalid @enderror">
                    <option value="">Choose…</option>
                    @foreach($ruleCategories as $category)
                      <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                  </select>
                  @error('ruleState.category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                @else
                  <label class="small mb-1">Service / product</label>
                  @if($ruleState['product_name'])
                    <div class="d-flex align-items-center" style="gap:6px;">
                      <strong>{{ $ruleState['product_name'] }}</strong>
                      <button type="button" class="btn btn-xs btn-link" wire:click="$set('ruleState.product_name', '')">Change</button>
                    </div>
                  @else
                    <div class="position-relative">
                      <input wire:model.live.debounce.300ms="ruleProductSearch" type="text" placeholder="Search by name…"
                             class="form-control form-control-sm @error('ruleState.product_id') is-invalid @enderror">
                      @error('ruleState.product_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                      @if($ruleProductResults)
                        <div class="list-group position-absolute w-100 shadow-sm" style="z-index:10;max-height:220px;overflow-y:auto;">
                          @foreach($ruleProductResults as $result)
                            <button type="button" class="list-group-item list-group-item-action py-1 small" wire:click="selectRuleProduct({{ $result['id'] }})">
                              {{ $result['name'] }}
                              <span class="text-muted">— {{ $result['category'] ?? 'No category' }}, {{ currency() }} {{ number_format($result['price'], 2) }}</span>
                            </button>
                          @endforeach
                        </div>
                      @endif
                    </div>
                  @endif
                @endif
              </div>
            </div>
            <div class="form-row">
              <div class="form-group col-md-4">
                <label class="small mb-1">Insurer pays</label>
                <select wire:model.live="ruleState.coverage_type" class="form-control form-control-sm">
                  @foreach(\App\Models\InsurerCoverageRule::TYPES as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                  @endforeach
                </select>
              </div>
              @if($ruleState['coverage_type'] !== 'excluded')
                <div class="form-group col-md-4">
                  <label class="small mb-1">{{ $ruleState['coverage_type'] === 'percent' ? 'Percentage (%)' : 'Amount per item (' . currency() . ')' }}</label>
                  <input wire:model="ruleState.coverage_value" type="number" min="0" step="0.01"
                         class="form-control form-control-sm @error('ruleState.coverage_value') is-invalid @enderror">
                  @error('ruleState.coverage_value')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-group col-md-4">
                  <label class="small mb-1">Insurer tariff ({{ currency() }}, optional)</label>
                  <input wire:model="ruleState.tariff_price" type="number" min="0" step="0.01" placeholder="Your price"
                         class="form-control form-control-sm @error('ruleState.tariff_price') is-invalid @enderror">
                  @error('ruleState.tariff_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
              @endif
            </div>
            <small class="text-muted d-block mb-2">
              The percentage is taken of the tariff when one is set (never more than your price). A rule for a single item overrides its category's rule.
            </small>
            <button wire:click="saveRule" class="btn btn-info btn-sm"><i class="fas fa-save mr-1"></i>Save coverage</button>
          </div>
        </div>
        <div class="modal-footer">
          <button wire:click="closeCoverage" class="btn btn-secondary">Done</button>
        </div>
      </div>
    </div>
  </div>
  @endif
</div>
