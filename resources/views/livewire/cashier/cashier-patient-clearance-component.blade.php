<div>
    <!-- Content Header -->
    <div class="content-header bg-gradient-info" style="padding:1.5rem 0;margin-bottom:1.5rem;">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-6">
                    <h1 class="m-0 text-white">
                        <i class="fas fa-cash-register mr-2"></i>Patient Clearance
                    </h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right bg-transparent mb-0">
                        <li class="breadcrumb-item"><a href="#" class="text-white">Dashboard</a></li>
                        <li class="breadcrumb-item text-white-50 active">Clearance</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="content">
        <div class="container-fluid">

            <!-- Stats Cards -->
            <div class="row mb-4">
                <div class="col-6 col-md-3 mb-3 mb-md-0">
                    <div class="small-box bg-warning">
                        <div class="inner">
                            <h3>{{ $pendingCount }}</h3>
                            <p>Pending Clearance</p>
                        </div>
                        <div class="icon"><i class="fas fa-hourglass-half"></i></div>
                        <a href="#" wire:click.prevent="switchTab('pending')" class="small-box-footer">
                            View <i class="fas fa-arrow-circle-right"></i>
                        </a>
                    </div>
                </div>
                <div class="col-6 col-md-3 mb-3 mb-md-0">
                    <div class="small-box bg-info">
                        <div class="inner">
                            <h3>{{ $clearedToday }}</h3>
                            <p>Cleared Today</p>
                        </div>
                        <div class="icon"><i class="fas fa-check-double"></i></div>
                        <a href="#" wire:click.prevent="switchTab('cleared')" class="small-box-footer">
                            View <i class="fas fa-arrow-circle-right"></i>
                        </a>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="small-box bg-success">
                        <div class="inner">
                            <h3>{{ $paidToday }}</h3>
                            <p>Paid Today</p>
                        </div>
                        <div class="icon"><i class="fas fa-money-bill-wave"></i></div>
                        <a href="#" wire:click.prevent="$set('statusFilter','Paid'); switchTab('cleared')" class="small-box-footer">
                            View <i class="fas fa-arrow-circle-right"></i>
                        </a>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="small-box bg-danger">
                        <div class="inner">
                            <h3>{{ $unpaidToday }}</h3>
                            <p>Unpaid Today</p>
                        </div>
                        <div class="icon"><i class="fas fa-exclamation-circle"></i></div>
                        <a href="#" wire:click.prevent="$set('statusFilter','Unpaid'); switchTab('cleared')" class="small-box-footer">
                            View <i class="fas fa-arrow-circle-right"></i>
                        </a>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
                    <strong><i class="fas fa-balance-scale mr-2 text-primary"></i>Today's Reconciliation</strong>
                    <span class="badge badge-dark">Total: {{ currency() }} {{ number_format($reconciliationTotal, 2) }}</span>
                </div>
                <div class="card-body py-2"><div class="row">
                    @forelse($reconciliation as $payment)
                        <div class="col-6 col-md-3 py-1"><div class="border rounded p-2 h-100">
                            <small class="text-muted text-uppercase font-weight-bold">{{ str_replace('_', ' ', $payment->payment_method) }}</small>
                            <div class="font-weight-bold">{{ currency() }} {{ number_format($payment->total_amount, 2) }}</div>
                            <small class="text-muted">{{ $payment->transaction_count }} transaction(s)</small>
                        </div></div>
                    @empty
                        <div class="col-12 text-center text-muted small py-2">No payments collected today.</div>
                    @endforelse
                </div></div>
            </div>

            <!-- Tab Card -->
            <div class="card shadow-sm">
                <!-- Tab Header -->
                <div class="card-header p-0 border-bottom-0">
                    <ul class="nav nav-tabs" id="clearanceTabs">
                        <li class="nav-item">
                            <button type="button"
                                    wire:click="switchTab('pending')"
                                    class="nav-link btn btn-link {{ $activeTab === 'pending' ? 'active' : '' }}"
                                    style="border-radius:0">
                                <i class="fas fa-hourglass-half mr-1 text-warning"></i>
                                Pending
                                <span class="badge badge-warning ml-1">{{ $pendingCount }}</span>
                            </button>
                        </li>
                        <li class="nav-item">
                            <button type="button"
                                    wire:click="switchTab('cleared')"
                                    class="nav-link btn btn-link {{ $activeTab === 'cleared' ? 'active' : '' }}"
                                    style="border-radius:0">
                                <i class="fas fa-check-double mr-1 text-info"></i>
                                Cleared
                                <span class="badge badge-info ml-1">{{ $clearedToday }}</span>
                            </button>
                        </li>
                    </ul>
                </div>

                <!-- ==================== PENDING TAB ==================== -->
                @if($activeTab === 'pending')
                <div class="card-body">
                    <div class="d-flex justify-content-end mb-3">
                        <div class="input-group" style="max-width:300px">
                            <input wire:model.live.debounce.300ms="searchTerm"
                                   type="search"
                                   class="form-control form-control-sm"
                                   placeholder="Search patient name, folder, contact…">
                            <div class="input-group-append">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead class="thead-light">
                                <tr>
                                    <th class="text-center" style="width:50px">#</th>
                                    <th><i class="fas fa-user mr-1 text-primary"></i>Patient Name</th>
                                    <th><i class="fas fa-phone mr-1 text-secondary"></i>Contact</th>
                                    <th><i class="fas fa-folder mr-1 text-info"></i>Folder #</th>
                                    <th><i class="fas fa-venus-mars mr-1 text-purple"></i>Gender</th>
                                    <th><i class="fas fa-calendar mr-1 text-success"></i>Registered</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody wire:loading.class="opacity-50">
                                @forelse($patients as $patient)
                                <tr>
                                    <td class="text-center align-middle">
                                        <span class="badge badge-secondary">{{ $loop->iteration }}</span>
                                    </td>
                                    <td class="align-middle font-weight-bold">{{ $patient->name }}</td>
                                    <td class="align-middle">
                                        @if($patient->contact)
                                            <a href="tel:{{ $patient->contact }}" class="text-muted">
                                                <i class="fas fa-phone-alt mr-1"></i>{{ $patient->contact }}
                                            </a>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="align-middle">
                                        <span class="badge badge-info">{{ $patient->pxnumber }}</span>
                                    </td>
                                    <td class="align-middle">
                                        @if($patient->gender === 'Male')
                                            <span class="badge badge-primary"><i class="fas fa-mars mr-1"></i>M</span>
                                        @elseif($patient->gender === 'Female')
                                            <span class="badge badge-pink" style="background:#e83e8c;color:#fff"><i class="fas fa-venus mr-1"></i>F</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="align-middle">
                                        <small class="text-muted">
                                            <i class="far fa-clock mr-1"></i>
                                            {{ \Carbon\Carbon::parse($patient->created_at)->format('d M Y') }}
                                        </small>
                                    </td>
                                    <td class="text-center align-middle">
                                        <button type="button"
                                                class="btn btn-sm btn-success"
                                                wire:click="openClearanceModal({{ $patient->id }})"
                                                title="Process Clearance">
                                            <i class="fas fa-check-circle mr-1"></i>Clear
                                        </button>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="7" class="text-center py-5">
                                        <i class="fas fa-check-circle fa-3x text-success mb-3 d-block"></i>
                                        <span class="text-muted">All patients have been cleared today!</span>
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end">
                    {{ $patients->links() }}
                </div>

                @else
                <!-- ==================== CLEARED TAB ==================== -->
                <div class="card-body">
                    <!-- Filters -->
                    <div class="row mb-3 align-items-end">
                        {{-- Search --}}
                        <div class="col-sm-6 col-md-4 mb-2">
                            <label class="small text-muted mb-1">Search Patient</label>
                            <div class="input-group input-group-sm">
                                <input wire:model.live.debounce.300ms="clearedSearch"
                                       type="search"
                                       class="form-control"
                                       placeholder="Name or folder number…">
                                <div class="input-group-append">
                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                </div>
                            </div>
                        </div>
                        {{-- Date From --}}
                        <div class="col-sm-6 col-md-2 mb-2">
                            <label class="small text-muted mb-1">From</label>
                            <input wire:model.blur="dateFrom"
                                   type="date"
                                   class="form-control form-control-sm"
                                   max="{{ now()->toDateString() }}">
                        </div>
                        {{-- Date To --}}
                        <div class="col-sm-6 col-md-2 mb-2">
                            <label class="small text-muted mb-1">To</label>
                            <input wire:model.blur="dateTo"
                                   type="date"
                                   class="form-control form-control-sm"
                                   min="{{ $dateFrom }}"
                                   max="{{ now()->toDateString() }}">
                        </div>
                        {{-- Payment Status --}}
                        <div class="col-sm-6 col-md-2 mb-2">
                            <label class="small text-muted mb-1">Payment</label>
                            <select wire:model.live="statusFilter" class="form-control form-control-sm">
                                <option value="">All</option>
                                <option value="Paid">Paid</option>
                                <option value="Unpaid">Unpaid</option>
                            </select>
                        </div>
                        {{-- Gender --}}
                        <div class="col-sm-6 col-md-1 mb-2">
                            <label class="small text-muted mb-1">Gender</label>
                            <select wire:model.live="genderFilter" class="form-control form-control-sm">
                                <option value="">All</option>
                                <option value="Male">M</option>
                                <option value="Female">F</option>
                            </select>
                        </div>
                        {{-- Reset --}}
                        <div class="col-sm-6 col-md-1 mb-2">
                            <label class="small text-muted mb-1 d-block">&nbsp;</label>
                            <button type="button"
                                    wire:click="$set('dateFrom','{{ now()->toDateString() }}');$set('dateTo','{{ now()->toDateString() }}');$set('statusFilter','');$set('genderFilter','');$set('clearedSearch','')"
                                    class="btn btn-sm btn-outline-secondary w-100"
                                    title="Reset filters">
                                <i class="fas fa-undo"></i>
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead class="thead-light">
                                <tr>
                                    <th class="text-center" style="width:50px">#</th>
                                    <th><i class="fas fa-user mr-1 text-primary"></i>Patient Name</th>
                                    <th><i class="fas fa-folder mr-1 text-info"></i>Folder #</th>
                                    <th><i class="fas fa-money-bill-wave mr-1 text-success"></i>Payment</th>
                                    <th><i class="fas fa-stethoscope mr-1 text-purple"></i>Doctor</th>
                                    <th><i class="fas fa-user-check mr-1 text-secondary"></i>Cleared By</th>
                                    <th><i class="far fa-clock mr-1 text-muted"></i>Time</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody wire:loading.class="opacity-50">
                                @forelse($clearances as $clearance)
                                <tr>
                                    <td class="text-center align-middle">
                                        <span class="badge badge-secondary">{{ $loop->iteration }}</span>
                                    </td>
                                    <td class="align-middle font-weight-bold">
                                        {{ $clearance->patient->name ?? '—' }}
                                        @if($clearance->patient?->contact)
                                            <br><small class="text-muted font-weight-normal">
                                                <i class="fas fa-phone-alt mr-1"></i>{{ $clearance->patient->contact }}
                                            </small>
                                        @endif
                                    </td>
                                    <td class="align-middle">
                                        <span class="badge badge-info">{{ $clearance->patient->pxnumber ?? '—' }}</span>
                                    </td>
                                    <td class="align-middle">
                                        @if($editingClearanceId === $clearance->id)
                                            <div class="d-flex align-items-center" style="gap:4px">
                                                <select wire:model.live="editingPaymentStatus"
                                                        class="form-control form-control-sm"
                                                        style="width:90px">
                                                    <option value="Paid">Paid</option>
                                                    <option value="Unpaid">Unpaid</option>
                                                </select>
                                                <button type="button" wire:click="saveStatus"
                                                        class="btn btn-xs btn-success" title="Save">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button type="button" wire:click="cancelEditStatus"
                                                        class="btn btn-xs btn-secondary" title="Cancel">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        @else
                                            <div>
                                                <span class="badge badge-{{ $clearance->payment_status === 'Paid' ? 'success' : 'danger' }} cursor-pointer"
                                                      wire:click="startEditStatus({{ $clearance->id }})"
                                                      title="Click to change status"
                                                      style="cursor:pointer">
                                                    <i class="fas fa-{{ $clearance->payment_status === 'Paid' ? 'check' : 'times' }} mr-1"></i>
                                                    {{ $clearance->payment_status }}
                                                    <i class="fas fa-pencil-alt ml-1" style="font-size:.65rem"></i>
                                                </span>
                                                @if($clearance->service)
                                                    <br><small class="text-muted">{{ $clearance->service->name }}</small>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                    <td class="align-middle text-center">
                                        @if($clearance->doctor_status)
                                            <span class="badge badge-success"><i class="fas fa-check"></i></span>
                                        @else
                                            <span class="badge badge-secondary">Pending</span>
                                        @endif
                                    </td>
                                    <td class="align-middle">
                                        <small>{{ $clearance->user->name ?? 'System' }}</small>
                                    </td>
                                    <td class="align-middle">
                                        <small class="text-muted">
                                            {{ \Carbon\Carbon::parse($clearance->created_at)->format('H:i') }}
                                        </small>
                                    </td>
                                    <td class="text-center align-middle">
                                        <div class="d-flex justify-content-center" style="gap:4px;">
                                            @if($clearance->sale_id && $visitReceipts)
                                                <a href="javascript:void(0)"
                                                   onclick="window.open('{{ route('cashier.visit-receipt.sale', $clearance->sale_id) }}','_blank','width=302,height=600')"
                                                   class="btn btn-xs btn-outline-success"
                                                   title="Print visit receipt">
                                                    <i class="fas fa-file-invoice"></i>
                                                </a>
                                            @elseif($clearance->sale_id)
                                                <a href="javascript:void(0)"
                                                   onclick="window.open('{{ route('cashier.receipt.show', $clearance->sale_id) }}','_blank','width=302,height=600')"
                                                   class="btn btn-xs btn-outline-info"
                                                   title="View Receipt">
                                                    <i class="fas fa-receipt"></i>
                                                </a>
                                            @endif
                                            @if($clearance->pendingRevokeLog)
                                                <span class="badge badge-warning px-2 py-1"
                                                      title="Revoke request awaiting manager approval">
                                                    <i class="fas fa-hourglass-half mr-1"></i>Revoke Pending
                                                </span>
                                            @else
                                                <button type="button"
                                                        class="btn btn-xs btn-outline-danger"
                                                        wire:click="openRevokeModal({{ $clearance->id }})"
                                                        title="Request Revoke">
                                                    <i class="fas fa-undo mr-1"></i>Request Revoke
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="8" class="text-center py-5">
                                        <i class="far fa-folder-open fa-3x text-muted mb-3 d-block"></i>
                                        <span class="text-muted">No clearances found for the selected filters.</span>
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end">
                    {{ $clearances->links() }}
                </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Clearance Modal -->
    <div id="addClearanceModal" class="modal fade" tabindex="-1" role="dialog" wire:ignore.self>
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-money-check-alt mr-2"></i>Process Payment Clearance
                    </h5>
                    <button type="button" class="close text-white" wire:click="closeModal">&times;</button>
                </div>
                <div class="modal-body p-4">
                    <div class="row">
                        {{-- Patient, earlier balance and insurance --}}
                        <div class="col-md-5">
                            <div class="d-flex align-items-center mb-3">
                                <i class="fas fa-user-check fa-2x text-info mr-3"></i>
                                <div>
                                    <h5 class="mb-0">{{ $patientName }}</h5>
                                    <small class="text-muted">Confirm payment status before clearing</small>
                                </div>
                            </div>
                            @if($outstandingBalance > 0)
                                <div class="alert alert-warning small">
                                    <strong><i class="fas fa-exclamation-triangle mr-1"></i>Previous outstanding balance:</strong>
                                    {{ currency() }} {{ number_format($outstandingBalance, 2) }}
                                    <div class="small mt-1">This is from earlier visits and is not included in the service total below.</div>
                                </div>
                            @endif
                            @if(!empty($insuranceSummary))
                                <div class="border rounded p-3 mb-3 mb-md-0 bg-light">
                                    <div class="font-weight-bold text-primary mb-2"><i class="fas fa-shield-alt mr-1"></i>Insurance Details</div>
                                    <div class="row small">
                                        <div class="col-6 mb-2"><span class="text-muted">Insurer</span><br><strong>{{ $insuranceSummary['insurer'] }}</strong></div>
                                        <div class="col-6 mb-2"><span class="text-muted">Policy</span><br><strong>{{ $insuranceSummary['policy_number'] ?: 'N/A' }}</strong></div>
                                        <div class="col-12"><span class="text-muted">Member</span><br><strong>{{ $insuranceSummary['member_name'] ?: 'N/A' }} ({{ $insuranceSummary['member_id'] ?: 'No ID' }})</strong></div>
                                    </div>
                                    @unless($insuranceSummary['active'])
                                        <div class="small text-danger mt-2">This insurer is inactive, so the patient pays the full amount.</div>
                                    @endunless
                                </div>
                            @endif
                        </div>

                        {{-- Service and payment --}}
                        <div class="col-md-7 border-left">
                            <div class="form-group">
                                <label class="font-weight-bold">
                                    <i class="fas fa-concierge-bell mr-2 text-success"></i>Service
                                </label>
                                {{-- Type to search; the hidden select below holds the choice for the payment script --}}
                                <div class="position-relative" wire:ignore>
                                    <input type="text" id="clr-service-search" class="custom-select" autocomplete="off"
                                           placeholder="Search service…"
                                           onfocus="window.openServiceList()" onclick="window.openServiceList()"
                                           oninput="window.filterServiceList()" onkeydown="window.serviceListKeydown(event)">
                                    <div id="clr-service-list" class="list-group position-absolute w-100 shadow-sm"
                                         style="display:none;z-index:1060;max-height:260px;overflow-y:auto;">
                                        @foreach($services as $svc)
                                            <button type="button" class="list-group-item list-group-item-action py-1 small d-flex justify-content-between clr-service-option"
                                                    data-id="{{ $svc->id }}"
                                                    data-label="{{ $svc->name }} — {{ currency() }} {{ number_format($svc->selling_price, 2) }}"
                                                    data-search="{{ strtolower($svc->name) }}"
                                                    onmousedown="event.preventDefault()" onclick="window.pickService(this)">
                                                <span>{{ $svc->name }}</span>
                                                <span class="text-muted text-nowrap ml-2">{{ currency() }} {{ number_format($svc->selling_price, 2) }}</span>
                                            </button>
                                        @endforeach
                                        <button type="button" class="list-group-item list-group-item-action py-1 small text-danger clr-service-option"
                                                data-id="unpaid" data-label="✗ Unpaid (no charge)" data-search="unpaid no charge"
                                                onmousedown="event.preventDefault()" onclick="window.pickService(this)">
                                            ✗ Unpaid (no charge)
                                        </button>
                                        <div id="clr-service-empty" class="list-group-item py-1 small text-muted" style="display:none;">No service matches</div>
                                    </div>
                                </div>
                                <select class="d-none"
                                        wire:model="selectedServiceId"
                                        id="selectedServiceId"
                                        onchange="window.toggleClearancePaymentMethod(this.value)">
                                    <option value="">Select service…</option>
                                    @foreach($services as $svc)
                                        <option value="{{ $svc->id }}">{{ $svc->name }} — {{ currency() }} {{ number_format($svc->selling_price, 2) }}</option>
                                    @endforeach
                                    <option value="unpaid">✗ Unpaid (no charge)</option>
                                </select>
                                @error('selectedServiceId')
                                    <div class="text-danger mt-1"><small>{{ $message }}</small></div>
                                @enderror
                            </div>

                            {{-- Split payment section --}}
                            <div id="clearancePaymentSection" style="display:none;">
                                <hr class="my-2">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="font-weight-bold mb-0">
                                        <i class="fas fa-credit-card mr-1 text-primary"></i>Payment
                                    </label>
                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                            onclick="window.addClearancePaymentRow()">
                                        <i class="fas fa-plus mr-1"></i>Split
                                    </button>
                                </div>

                                {{-- Insurance split (insured patients only); read by the script below --}}
                                <div id="clr-insurance-data" class="d-none"
                                     data-insurer="{{ $insuranceSummary['insurer'] ?? '' }}"
                                     data-splits='@json((object) $insuranceSplits)'></div>
                                <div id="clr-insurance-box" wire:ignore class="border rounded p-2 mb-2" style="display:none;font-size:.85rem;">
                                    <div class="custom-control custom-switch mb-2">
                                        <input type="checkbox" class="custom-control-input" id="clr-bill-insurer" checked
                                               onchange="window.recalcClearanceInsurance()">
                                        <label class="custom-control-label font-weight-bold" for="clr-bill-insurer">
                                            Bill <span id="clr-insurer-name">insurer</span>
                                        </label>
                                    </div>
                                    <div class="d-flex align-items-center" style="gap:6px;">
                                        <label for="clr-insurer-amount" class="mb-0 text-muted">Insurer pays</label>
                                        <input type="number" id="clr-insurer-amount" class="form-control form-control-sm" min="0" step="0.01"
                                               style="width:110px;" oninput="window.recalcClearanceInsurance()">
                                    </div>
                                    <div id="clr-insurance-reason-row" class="mt-2" style="display:none;">
                                        <input type="text" id="clr-insurance-reason" class="form-control form-control-sm" maxlength="200"
                                               placeholder="Reason for changing what the insurer pays (required)">
                                    </div>
                                </div>

                                <div id="clearancePaymentRows"></div>

                                <div class="mt-2 p-2 rounded" wire:ignore style="background:#f8f9fa;font-size:.85rem;">
                                    <div class="clr-insured-row d-flex justify-content-between" style="display:none !important;">
                                        <span>Bill Total</span>
                                        <strong id="clr-bill-total">0.00</strong>
                                    </div>
                                    <div class="clr-insured-row d-flex justify-content-between text-info" style="display:none !important;">
                                        <span>Insurer Pays</span>
                                        <strong id="clr-insurer-total">0.00</strong>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span id="clr-svc-total-label">Service Total</span>
                                        <strong id="clr-svc-total">0.00</strong>
                                    </div>
                                    <div class="d-flex justify-content-between text-success">
                                        <span>Amount Entered</span>
                                        <strong id="clr-entered">0.00</strong>
                                    </div>
                                    <div class="d-flex justify-content-between text-danger" id="clr-balance-row">
                                        <span>Remaining</span>
                                        <strong id="clr-remaining">0.00</strong>
                                    </div>
                                </div>
                                <div id="clr-payment-error" class="text-danger small mt-1" style="display:none;"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="closeModal">
                        <i class="fa fa-times mr-1"></i>Cancel
                    </button>
                    <button type="button" id="clearanceConfirmBtn"
                            onclick="window.submitClearance()"
                            wire:loading.attr="disabled" wire:target="createClearance"
                            class="btn btn-success">
                        <span wire:loading.remove wire:target="createClearance"><i class="fa fa-check mr-1"></i>Confirm & Save</span>
                        <span wire:loading wire:target="createClearance"><i class="fas fa-spinner fa-spin mr-1"></i>Saving...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Clearance Receipt Modal -->
    <div class="modal fade" id="clearanceReceiptModal" tabindex="-1" role="dialog" wire:ignore.self>
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-light py-2">
                    <h6 class="modal-title font-weight-bold text-dark">
                        <i class="fas fa-receipt mr-1"></i>Clearance Receipt
                    </h6>
                    <button type="button" class="close"
                            onclick="$('#clearanceReceiptModal').modal('hide')">&times;</button>
                </div>
                <div class="modal-body p-3" id="clrReceiptModalContent"></div>
                <div class="modal-footer bg-light py-2 border-0">
                    <button type="button" class="btn btn-sm btn-secondary"
                            onclick="$('#clearanceReceiptModal').modal('hide')">Close</button>
                    <button onclick="window.printClearanceReceipt()" class="btn btn-sm btn-primary px-4">
                        <i class="fas fa-print mr-1"></i>Print Receipt
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Revoke Request Modal -->
    <div id="revokeRequestModal" class="modal fade" tabindex="-1" role="dialog" wire:ignore.self>
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-undo mr-2"></i>Request Clearance Revoke
                    </h5>
                    <button type="button" class="close text-white" onclick="@this.call('cancelRevokeRequest')"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    @if($requestingRevokeName)
                        <p class="mb-3">Submitting revoke request for <strong>{{ $requestingRevokeName }}</strong>.
                            A manager or Super Admin must approve before the clearance is removed.</p>
                    @endif
                    <div class="form-group mb-0">
                        <label class="font-weight-bold small">Reason <span class="text-danger">*</span></label>
                        <textarea wire:model="revokeReason"
                                  class="form-control @error('revokeReason') is-invalid @enderror"
                                  rows="3"
                                  placeholder="Explain why this clearance should be revoked…"></textarea>
                        @error('revokeReason')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" onclick="@this.call('cancelRevokeRequest')">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="@this.call('submitRevokeRequest')">
                        <i class="fas fa-paper-plane mr-1"></i>Submit Request
                    </button>
                </div>
            </div>
        </div>
    </div>

    <style>
        .bg-gradient-info { background: linear-gradient(135deg, #17a2b8 0%, #138496 100%); }
        .card { border: none; border-radius: 8px; overflow: hidden; }
        .shadow-sm { box-shadow: 0 2px 4px rgba(0,0,0,.08); }
        .table-hover tbody tr:hover { background-color: #f8f9fa; }
        .thead-light th { background-color: #f8f9fa; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057; }
        .opacity-50 { opacity: .5; }
        .icon-box { width:70px;height:70px;margin:0 auto;border-radius:50%;background:#e3f7fb;display:flex;align-items:center;justify-content:center; }
        .btn-xs { padding:.2rem .45rem; font-size:.75rem; line-height:1.2; }
        .small-box-footer:hover { opacity:.8; }
    </style>

    @script
    <script>
        window.addEventListener('show-addClearanceModal-form', function () {
            $('#addClearanceModal').modal('show');
            window.toggleClearancePaymentMethod(document.getElementById('selectedServiceId')?.value || '');
        });
        window.addEventListener('hide-addClearanceModal-modal', function () {
            $('#addClearanceModal').modal('hide');
        });
        window.addEventListener('show-revokeRequestModal', () => $('#revokeRequestModal').modal('show'));
        window.addEventListener('hide-revokeRequestModal', () => $('#revokeRequestModal').modal('hide'));

        var _clrPrintUrl = '';

        window.addEventListener('show-clearance-receipt-modal', function(e) {
            var d = e.detail;
            _clrPrintUrl = d.printUrl;

            var currency = '{{ currency() }}';

            // Payment rows
            var paymentHtml = '';
            if (d.payments && d.payments.length) {
                d.payments.forEach(function(p) {
                    paymentHtml +=
                        '<div class="d-flex justify-content-between text-muted" style="font-size:.85rem;">' +
                        '<span>PAID (' + p.method.toUpperCase() + ')</span>' +
                        '<span>' + currency + ' ' + p.amount + '</span>' +
                        '</div>';
                });
                if (d.payments.length > 1) {
                    var totalPaid = d.payments.reduce(function (sum, p) { return sum + (parseFloat(String(p.amount).replace(/,/g, '')) || 0); }, 0);
                    paymentHtml +=
                        '<div class="d-flex justify-content-between font-weight-bold" style="font-size:.85rem;">' +
                        '<span>TOTAL PAID</span><span>' + currency + ' ' + totalPaid.toFixed(2) + '</span></div>';
                }
            }
            if (d.insurer) {
                paymentHtml +=
                    '<div class="d-flex justify-content-between text-info" style="font-size:.85rem;">' +
                    '<span>BILLED TO ' + String(d.insurer).toUpperCase() + '</span>' +
                    '<span>' + currency + ' ' + d.insurerAmount + '</span></div>';
            }

            var statusClass = d.status === 'Paid' ? 'text-success' : 'text-danger';

            var html =
                '<div class="d-flex justify-content-between mb-2">' +
                '<span class="text-muted" style="font-size:.8rem;">Patient</span>' +
                '<span class="font-weight-bold" style="font-size:.85rem;">' + d.patient + '</span>' +
                '</div>' +
                '<div class="d-flex justify-content-between mb-2">' +
                '<span class="text-muted" style="font-size:.8rem;">ID</span>' +
                '<span style="font-size:.85rem;">' + d.pxnumber + '</span>' +
                '</div>' +
                '<div class="d-flex justify-content-between mb-3">' +
                '<span class="text-muted" style="font-size:.8rem;">TXN</span>' +
                '<span class="font-weight-bold" style="font-size:.85rem;">' + d.txn + '</span>' +
                '</div>' +
                '<hr class="my-2">' +
                '<table class="table table-sm table-bordered mb-2" style="font-size:.85rem;">' +
                '<thead class="thead-light"><tr><th>Service</th><th class="text-right">Amount</th></tr></thead>' +
                '<tbody><tr><td>' + d.service + '</td><td class="text-right">' + currency + ' ' + d.amount + '</td></tr></tbody>' +
                '</table>' +
                '<div class="d-flex justify-content-between font-weight-bold mb-1">' +
                '<span>Grand Total</span><span class="text-primary">' + currency + ' ' + d.amount + '</span>' +
                '</div>' +
                (paymentHtml ? '<hr class="my-1">' + paymentHtml : '') +
                '<hr class="my-2">' +
                '<div class="d-flex justify-content-between">' +
                '<span class="font-weight-bold" style="font-size:.85rem;">Payment Status</span>' +
                '<span class="font-weight-bold ' + statusClass + '">' + d.status.toUpperCase() + '</span>' +
                '</div>';

            document.getElementById('clrReceiptModalContent').innerHTML = html;
            $('#clearanceReceiptModal').modal('show');
        });

        window.printClearanceReceipt = function () {
            var w = window.open(_clrPrintUrl, '_blank', 'width=302,height=600');
            if (!w) {
                alert('Please allow popups for this site to print receipts.');
            }
        };

        // Service price map (populated server-side)
        var _clrPrices = {
            @foreach($services as $svc)
            {{ $svc->id }}: {{ (float) $svc->selling_price }},
            @endforeach
        };
        var _clrServiceTotal = 0; // what the patient pays now
        var _clrSplit = null;     // insurer split for the selected service, if insured

        function clearanceSplits() {
            var el = document.getElementById('clr-insurance-data');
            if (!el) return null;
            try {
                var splits = JSON.parse(el.dataset.splits || '{}');
                return Object.keys(splits).length ? splits : null;
            } catch (e) {
                return null;
            }
        }

        function setInsuredRowsVisible(visible) {
            document.querySelectorAll('.clr-insured-row').forEach(function (row) {
                row.style.setProperty('display', visible ? 'flex' : 'none', 'important');
            });
        }

        function resetClearanceRows() {
            document.getElementById('clearancePaymentRows').innerHTML = '';
            window.addClearancePaymentRow();
            window.updateClearanceTotals();
        }

        window.recalcClearanceInsurance = function () {
            var svc = document.getElementById('selectedServiceId').value;
            var box = document.getElementById('clr-insurance-box');
            if (!_clrSplit) {
                box.style.display = 'none';
                setInsuredRowsVisible(false);
                _clrServiceTotal = _clrPrices[svc] || 0;
                document.getElementById('clr-svc-total-label').textContent = 'Service Total';
                document.getElementById('clr-svc-total').textContent = _clrServiceTotal.toFixed(2);
                return;
            }

            var bill = document.getElementById('clr-bill-insurer').checked;
            var input = document.getElementById('clr-insurer-amount');
            var billTotal = bill ? _clrSplit.price : (_clrPrices[svc] || 0);
            var insurer = 0;
            if (bill) {
                insurer = parseFloat(input.value);
                if (isNaN(insurer)) insurer = _clrSplit.insurer;
                insurer = Math.min(Math.max(insurer, 0), billTotal);
            }
            input.disabled = !bill;

            var changed = !bill || Math.abs(insurer - _clrSplit.insurer) > 0.005;
            document.getElementById('clr-insurance-reason-row').style.display = changed ? 'block' : 'none';

            _clrServiceTotal = Math.round((billTotal - insurer) * 100) / 100;
            setInsuredRowsVisible(true);
            document.getElementById('clr-bill-total').textContent = billTotal.toFixed(2);
            document.getElementById('clr-insurer-total').textContent = insurer.toFixed(2);
            document.getElementById('clr-svc-total-label').textContent = 'Patient Pays';
            document.getElementById('clr-svc-total').textContent = _clrServiceTotal.toFixed(2);
            resetClearanceRows();
        };

        // Searchable service picker: filters the list, then sets the hidden select.
        var _clrServiceActive = -1;

        function serviceOptions(visibleOnly) {
            var all = Array.prototype.slice.call(document.querySelectorAll('#clr-service-list .clr-service-option'));
            return visibleOnly ? all.filter(function (o) { return o.style.display !== 'none'; }) : all;
        }

        function highlightService(index) {
            var options = serviceOptions(true);
            options.forEach(function (o) { o.classList.remove('active'); });
            _clrServiceActive = options.length ? Math.max(0, Math.min(index, options.length - 1)) : -1;
            if (_clrServiceActive >= 0) {
                options[_clrServiceActive].classList.add('active');
                options[_clrServiceActive].scrollIntoView({ block: 'nearest' });
            }
        }

        window.openServiceList = function () {
            document.getElementById('clr-service-list').style.display = 'block';
            window.filterServiceList();
        };

        window.closeServiceList = function () {
            var list = document.getElementById('clr-service-list');
            if (list) list.style.display = 'none';
        };

        window.filterServiceList = function () {
            var input = document.getElementById('clr-service-search');
            var selected = document.getElementById('selectedServiceId').value;
            var current = serviceOptions(false).find(function (o) { return o.dataset.id === selected; });
            // While the box shows the chosen service, list everything.
            var term = current && input.value === current.dataset.label ? '' : input.value.trim().toLowerCase();
            var shown = 0;
            serviceOptions(false).forEach(function (o) {
                var match = !term || o.dataset.search.indexOf(term) !== -1;
                o.style.display = match ? '' : 'none';
                if (match) shown++;
            });
            document.getElementById('clr-service-empty').style.display = shown ? 'none' : 'block';
            highlightService(0);
        };

        window.pickService = function (option) {
            var select = document.getElementById('selectedServiceId');
            select.value = option.dataset.id;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            document.getElementById('clr-service-search').value = option.dataset.label;
            window.closeServiceList();
        };

        window.serviceListKeydown = function (e) {
            var list = document.getElementById('clr-service-list');
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (list.style.display === 'none') { window.openServiceList(); return; }
                highlightService(_clrServiceActive + (e.key === 'ArrowDown' ? 1 : -1));
            } else if (e.key === 'Enter') {
                e.preventDefault();
                var options = serviceOptions(true);
                if (list.style.display !== 'none' && options[_clrServiceActive]) window.pickService(options[_clrServiceActive]);
            } else if (e.key === 'Escape') {
                window.closeServiceList();
            }
        };

        document.addEventListener('click', function (e) {
            if (!e.target.closest || !e.target.closest('#clr-service-search, #clr-service-list')) window.closeServiceList();
        });

        window.toggleClearancePaymentMethod = function (val) {
            var section = document.getElementById('clearancePaymentSection');
            if (!section) return;
            if (val && val !== '' && val !== 'unpaid') {
                var splits = clearanceSplits();
                _clrSplit = splits && splits[val] ? splits[val] : null;
                if (_clrSplit) {
                    var data = document.getElementById('clr-insurance-data');
                    document.getElementById('clr-insurer-name').textContent = data.dataset.insurer || 'insurer';
                    document.getElementById('clr-bill-insurer').checked = true;
                    document.getElementById('clr-insurer-amount').value = _clrSplit.insurer.toFixed(2);
                    document.getElementById('clr-insurance-reason').value = '';
                    document.getElementById('clr-insurance-box').style.display = 'block';
                }
                section.style.display = 'block';
                window.recalcClearanceInsurance();
                resetClearanceRows();
            } else {
                _clrSplit = null;
                section.style.display = 'none';
                document.getElementById('clearancePaymentRows').innerHTML = '';
            }
            var error = document.getElementById('clr-payment-error');
            if (error) {
                error.textContent = '';
                error.style.display = 'none';
            }
        };

        var _clrRowIdx = 0;
        // The clinic's payment methods (Settings → Payment methods).
        var _clrMethods = @js(\App\Support\PaymentMethods::active(\App\Support\PaymentMethods::CLINIC));

        function clearanceMethodOptions() {
            return Object.keys(_clrMethods).map(function (key) {
                var option = document.createElement('option');
                option.value = key;
                option.textContent = _clrMethods[key];
                return option.outerHTML;
            }).join('');
        }
        window.addClearancePaymentRow = function () {
            var idx = _clrRowIdx++;
            var remaining = _clrServiceTotal - getClearanceEntered();
            var amount = remaining > 0 ? remaining.toFixed(2) : '';
            var row = document.createElement('div');
            row.className = 'd-flex align-items-center mb-2';
            row.style.gap = '6px';
            row.id = 'clr-row-' + idx;
            row.innerHTML =
                '<select class="custom-select custom-select-sm clr-method" style="width:140px;" onchange="window.updateClearanceTotals()">' +
                    clearanceMethodOptions() +
                '</select>' +
                '<input type="number" class="form-control form-control-sm clr-amount" min="0.01" step="0.01" ' +
                       'placeholder="Amount" value="' + amount + '" oninput="window.updateClearanceTotals()" style="width:110px;">' +
                '<button type="button" class="btn btn-sm btn-outline-danger" onclick="window.removeClearanceRow(' + idx + ')">' +
                    '<i class="fas fa-times"></i>' +
                '</button>';
            document.getElementById('clearancePaymentRows').appendChild(row);
            window.updateClearanceTotals();
        };

        window.removeClearanceRow = function (idx) {
            var rows = document.getElementById('clearancePaymentRows');
            if (rows.children.length <= 1) return; // keep at least one row
            var row = document.getElementById('clr-row-' + idx);
            if (row) rows.removeChild(row);
            window.updateClearanceTotals();
        };

        function getClearanceEntered() {
            var total = 0;
            document.querySelectorAll('.clr-amount').forEach(function(inp) {
                total += parseFloat(inp.value) || 0;
            });
            return total;
        }

        window.updateClearanceTotals = function () {
            var entered   = getClearanceEntered();
            var remaining = _clrServiceTotal - entered;
            document.getElementById('clr-entered').textContent   = entered.toFixed(2);
            document.getElementById('clr-remaining').textContent = Math.max(0, remaining).toFixed(2);
            document.getElementById('clr-balance-row').style.display = remaining > 0.005 ? 'flex' : 'none';
        };

        window.submitClearance = function () {
            var svc = document.getElementById('selectedServiceId').value;
            var button = document.getElementById('clearanceConfirmBtn');
            var error = document.getElementById('clr-payment-error');
            var payments = [];

            if (!svc) {
                $wire.createClearance('', '[]');
                return;
            }

            if (svc !== 'unpaid') {
                var entered   = getClearanceEntered();
                var remaining = _clrServiceTotal - entered;

                if (remaining > 0.005) {
                    error.textContent = 'Total payments (' + entered.toFixed(2) + ') are less than the amount due (' + _clrServiceTotal.toFixed(2) + ').';
                    error.style.display = 'block';
                    return;
                }
                if (remaining < -0.005) {
                    error.textContent = 'Total payments cannot exceed the amount due of ' + _clrServiceTotal.toFixed(2) + '.';
                    error.style.display = 'block';
                    return;
                }
                error.style.display = 'none';

                // Collect payments
                document.querySelectorAll('#clearancePaymentRows > div').forEach(function(row) {
                    var method = row.querySelector('.clr-method').value;
                    var amount = parseFloat(row.querySelector('.clr-amount').value) || 0;
                    if (amount > 0) payments.push({method: method, amount: amount});
                });

            }

            var insurance = {};
            if (_clrSplit && svc !== 'unpaid') {
                insurance = {
                    bill: document.getElementById('clr-bill-insurer').checked,
                    amount: document.getElementById('clr-insurer-amount').value,
                    reason: document.getElementById('clr-insurance-reason').value
                };
            }

            if (button) button.disabled = true;
            Promise.resolve($wire.createClearance(svc, JSON.stringify(payments), JSON.stringify(insurance)))
                .finally(function () {
                    var currentButton = document.getElementById('clearanceConfirmBtn');
                    if (currentButton) currentButton.disabled = false;
                });
        };

        // Reset when modal closes
        $('#addClearanceModal').on('hidden.bs.modal', function () {
            document.getElementById('clearancePaymentSection')?.style.setProperty('display', 'none');
            if (document.getElementById('clearancePaymentRows')) document.getElementById('clearancePaymentRows').innerHTML = '';
            if (document.getElementById('selectedServiceId')) document.getElementById('selectedServiceId').value = '';
            if (document.getElementById('clr-service-search')) document.getElementById('clr-service-search').value = '';
            window.closeServiceList();
            if (document.getElementById('clr-payment-error')) document.getElementById('clr-payment-error').style.display = 'none';
            if (document.getElementById('clr-insurance-box')) document.getElementById('clr-insurance-box').style.display = 'none';
            setInsuredRowsVisible(false);
            _clrSplit = null;
            _clrRowIdx = 0;
        });
    </script>
    @endscript
</div>
