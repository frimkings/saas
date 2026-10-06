@php
    $label = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500';
    $small = 'ui-input !py-1.5 !text-sm';
    $badge = 'inline-flex items-center whitespace-nowrap rounded-md px-2 py-0.5 text-xs font-semibold';
    $iconButton = 'inline-flex h-8 items-center justify-center gap-1 rounded-md border bg-white px-2 text-xs font-semibold no-underline hover:bg-slate-50';
    $today = now()->toDateString();
@endphp
<div class="clinic-ui ui-page space-y-5">
    <div class="ui-heading">
        <div>
            <h1>Patient Clearance</h1>
            <p class="ui-muted">Take payment for today's visits before patients see the doctor.</p>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach([
            ['Pending clearance', $pendingCount, 'fa-hourglass-half', 'text-amber-500', "switchTab('pending')"],
            ['Cleared today', $clearedToday, 'fa-check-double', 'text-sky-600', "switchTab('cleared')"],
            ['Paid today', $paidToday, 'fa-money-bill-wave', 'text-green-600', "\$set('statusFilter','Paid'); switchTab('cleared')"],
            ['Unpaid today', $unpaidToday, 'fa-exclamation-circle', 'text-red-600', "\$set('statusFilter','Unpaid'); switchTab('cleared')"],
        ] as [$statLabel, $value, $icon, $colour, $action])
            <button type="button" wire:click.prevent="{{ $action }}" class="ui-panel flex items-center gap-3 p-4 text-left hover:border-teal-300">
                <i class="fas {{ $icon }} {{ $colour }} w-7 text-center text-2xl" aria-hidden="true"></i>
                <span>
                    <span class="block text-2xl font-semibold leading-tight text-slate-900">{{ $value }}</span>
                    <span class="block text-xs font-semibold text-slate-500">{{ $statLabel }}</span>
                </span>
            </button>
        @endforeach
    </div>

    <section class="ui-panel">
        <div class="ui-panel-heading !py-3">
            <h2><i class="fas fa-balance-scale mr-2 text-teal-700" aria-hidden="true"></i>Today's reconciliation</h2>
            <span class="{{ $badge }} bg-slate-800 text-white">Total: {{ currency() }} {{ number_format($reconciliationTotal, 2) }}</span>
        </div>
        <div class="grid grid-cols-2 gap-2 p-3 md:grid-cols-4">
            @forelse($reconciliation as $payment)
                <div class="rounded-lg border border-slate-200 p-2">
                    <p class="text-xs font-semibold uppercase text-slate-500">{{ str_replace('_', ' ', $payment->payment_method) }}</p>
                    <p class="font-semibold">{{ currency() }} {{ number_format($payment->total_amount, 2) }}</p>
                    <p class="text-xs text-slate-500">{{ $payment->transaction_count }} transaction(s)</p>
                </div>
            @empty
                <p class="col-span-full py-2 text-center text-sm text-slate-500">No payments collected today.</p>
            @endforelse
        </div>
    </section>

    <section class="ui-panel">
        <div class="flex overflow-x-auto border-b border-slate-200" role="tablist" aria-label="Clearance lists">
            @foreach(['pending' => ['Pending', 'fa-hourglass-half', $pendingCount], 'cleared' => ['Cleared', 'fa-check-double', $clearedToday]] as $key => [$tabLabel, $icon, $count])
                <button type="button" role="tab" aria-selected="{{ $activeTab === $key ? 'true' : 'false' }}" wire:click="switchTab('{{ $key }}')"
                        @class(['flex items-center gap-2 whitespace-nowrap border-b-2 px-4 py-3 text-sm font-semibold',
                                'border-teal-700 text-teal-800' => $activeTab === $key,
                                'border-transparent text-slate-500 hover:text-slate-800' => $activeTab !== $key])>
                    <i class="fas {{ $icon }}" aria-hidden="true"></i>{{ $tabLabel }}
                    <span class="min-w-[1.25rem] rounded-full bg-slate-100 px-1.5 text-center text-xs text-slate-700">{{ $count }}</span>
                </button>
            @endforeach
        </div>

        {{-- ==================== PENDING ==================== --}}
        @if($activeTab === 'pending')
            <div class="flex justify-end border-b border-slate-200 bg-slate-50 px-4 py-3">
                <input wire:model.live.debounce.300ms="searchTerm" type="search" class="{{ $small }} max-w-xs" placeholder="Search patient name, folder, contact…" aria-label="Search pending patients">
            </div>
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th class="w-12 text-center">#</th>
                            <th>Patient name</th>
                            <th>Contact</th>
                            <th>Folder #</th>
                            <th>Gender</th>
                            <th>Registered</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody wire:loading.class="opacity-50">
                        @forelse($patients as $patient)
                            <tr>
                                <td class="text-center text-slate-500">{{ $loop->iteration }}</td>
                                <td class="font-semibold">{{ $patient->name }}</td>
                                <td>
                                    @if($patient->contact)
                                        <a href="tel:{{ $patient->contact }}" class="whitespace-nowrap text-slate-600 no-underline hover:text-teal-700"><i class="fas fa-phone-alt mr-1" aria-hidden="true"></i>{{ $patient->contact }}</a>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td><span class="{{ $badge }} bg-sky-50 font-mono text-sky-800">{{ $patient->pxnumber }}</span></td>
                                <td>
                                    @if($patient->gender === 'Male')
                                        <span class="{{ $badge }} bg-blue-50 text-blue-700"><i class="fas fa-mars mr-1" aria-hidden="true"></i>M</span>
                                    @elseif($patient->gender === 'Female')
                                        <span class="{{ $badge }} bg-pink-50 text-pink-700"><i class="fas fa-venus mr-1" aria-hidden="true"></i>F</span>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap text-xs text-slate-500">{{ \Carbon\Carbon::parse($patient->created_at)->format('d M Y') }}</td>
                                <td class="text-center">
                                    <button type="button" class="ui-button ui-button-primary" wire:click="openClearanceModal({{ $patient->id }})" title="Process clearance">
                                        <i class="fas fa-check-circle" aria-hidden="true"></i>Clear
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="ui-empty text-slate-500">
                                    <i class="fas fa-check-circle mb-3 block text-4xl text-green-500" aria-hidden="true"></i>
                                    All patients have been cleared today!
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-4 py-2">{{ $patients->links() }}</div>

        {{-- ==================== CLEARED ==================== --}}
        @else
            <div class="flex flex-wrap items-end gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3">
                <div class="min-w-[12rem] flex-1">
                    <label for="clr-cleared-search" class="{{ $label }}">Search patient</label>
                    <input id="clr-cleared-search" wire:model.live.debounce.300ms="clearedSearch" type="search" class="{{ $small }}" placeholder="Name or folder number…">
                </div>
                <div class="w-60">
                    <span class="{{ $label }}">Date</span>
                    <x-date-range from="dateFrom" to="dateTo" presets="activity" class="w-full" />
                </div>
                <div class="w-36">
                    <label for="clr-status" class="{{ $label }}">Payment</label>
                    <select id="clr-status" wire:model.live="statusFilter" class="{{ $small }}">
                        <option value="">All</option>
                        <option value="Paid">Paid</option>
                        <option value="Unpaid">Unpaid</option>
                    </select>
                </div>
                <div class="w-28">
                    <label for="clr-gender" class="{{ $label }}">Gender</label>
                    <select id="clr-gender" wire:model.live="genderFilter" class="{{ $small }}">
                        <option value="">All</option>
                        <option value="Male">M</option>
                        <option value="Female">F</option>
                    </select>
                </div>
                <button type="button"
                        wire:click="$set('dateFrom','{{ $today }}');$set('dateTo','{{ $today }}');$set('statusFilter','');$set('genderFilter','');$set('clearedSearch','')"
                        class="ui-button ui-button-secondary shrink-0" title="Reset filters" aria-label="Reset filters">
                    <i class="fas fa-undo" aria-hidden="true"></i>
                </button>
            </div>
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th class="w-12 text-center">#</th>
                            <th>Patient name</th>
                            <th>Folder #</th>
                            <th>Payment</th>
                            <th class="text-center">Doctor</th>
                            <th>Cleared by</th>
                            <th>Time</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody wire:loading.class="opacity-50">
                        @forelse($clearances as $clearance)
                            @php $paid = $clearance->payment_status === 'Paid'; @endphp
                            <tr>
                                <td class="text-center text-slate-500">{{ $loop->iteration }}</td>
                                <td>
                                    <p class="font-semibold">{{ $clearance->patient->name ?? '—' }}</p>
                                    @if($clearance->patient?->contact)
                                        <p class="text-xs text-slate-500"><i class="fas fa-phone-alt mr-1" aria-hidden="true"></i>{{ $clearance->patient->contact }}</p>
                                    @endif
                                </td>
                                <td><span class="{{ $badge }} bg-sky-50 font-mono text-sky-800">{{ $clearance->patient->pxnumber ?? '—' }}</span></td>
                                <td>
                                    @if($editingClearanceId === $clearance->id)
                                        <div class="flex items-center gap-1">
                                            <select wire:model.live="editingPaymentStatus" class="{{ $small }} !w-24" aria-label="Payment status">
                                                <option value="Paid">Paid</option>
                                                <option value="Unpaid">Unpaid</option>
                                            </select>
                                            <button type="button" wire:click="saveStatus" class="{{ $iconButton }} border-green-300 text-green-700" title="Save" aria-label="Save status"><i class="fas fa-check" aria-hidden="true"></i></button>
                                            <button type="button" wire:click="cancelEditStatus" class="{{ $iconButton }} border-slate-300 text-slate-600" title="Cancel" aria-label="Cancel"><i class="fas fa-times" aria-hidden="true"></i></button>
                                        </div>
                                    @else
                                        <button type="button" wire:click="startEditStatus({{ $clearance->id }})" title="Change payment status"
                                                class="{{ $badge }} {{ $paid ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                            <i class="fas fa-{{ $paid ? 'check' : 'times' }} mr-1" aria-hidden="true"></i>{{ $clearance->payment_status }}
                                            <i class="fas fa-pencil-alt ml-1 text-[10px]" aria-hidden="true"></i>
                                        </button>
                                        @if($clearance->service)
                                            <p class="mt-0.5 text-xs text-slate-500">{{ $clearance->service->name }}</p>
                                        @endif
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if($clearance->doctor_status)
                                        <span class="{{ $badge }} bg-green-100 text-green-800" title="Seen by the doctor"><i class="fas fa-check" aria-hidden="true"></i><span class="sr-only">Seen</span></span>
                                    @else
                                        <span class="{{ $badge }} bg-slate-100 text-slate-600">Pending</span>
                                    @endif
                                </td>
                                <td class="text-xs">{{ $clearance->user->name ?? 'System' }}</td>
                                <td class="text-xs text-slate-500">{{ \Carbon\Carbon::parse($clearance->created_at)->format('H:i') }}</td>
                                <td>
                                    <div class="flex justify-center gap-1">
                                        @if($clearance->sale_id && $visitReceipts)
                                            <button type="button" onclick="window.open('{{ route('cashier.visit-receipt.sale', $clearance->sale_id) }}','_blank','width=302,height=600')"
                                                    class="{{ $iconButton }} border-green-300 text-green-700" title="Print visit receipt" aria-label="Print visit receipt"><i class="fas fa-file-invoice" aria-hidden="true"></i></button>
                                        @elseif($clearance->sale_id)
                                            <button type="button" onclick="window.open('{{ route('cashier.receipt.show', $clearance->sale_id) }}','_blank','width=302,height=600')"
                                                    class="{{ $iconButton }} border-sky-300 text-sky-700" title="View receipt" aria-label="View receipt"><i class="fas fa-receipt" aria-hidden="true"></i></button>
                                        @endif
                                        @if($clearance->pendingRevokeLog)
                                            <span class="{{ $badge }} bg-amber-100 text-amber-800" title="Revoke request awaiting manager approval"><i class="fas fa-hourglass-half mr-1" aria-hidden="true"></i>Revoke pending</span>
                                        @else
                                            <button type="button" class="{{ $iconButton }} border-red-300 text-red-700" wire:click="openRevokeModal({{ $clearance->id }})" title="Request revoke">
                                                <i class="fas fa-undo" aria-hidden="true"></i>Request revoke
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="ui-empty text-slate-500">
                                    <i class="far fa-folder-open mb-3 block text-4xl text-slate-300" aria-hidden="true"></i>
                                    No clearances found for the selected filters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-4 py-2">{{ $clearances->links() }}</div>
        @endif
    </section>

    {{-- Clearance dialog (stays in the page; shown and hidden by the script below) --}}
    <div id="addClearanceModal" wire:ignore.self class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
        <div class="flex max-h-[calc(100vh-2rem)] w-full max-w-3xl flex-col overflow-hidden rounded-xl bg-white shadow-xl" role="dialog" aria-modal="true" aria-labelledby="clearance-dialog-title">
            <div class="ui-panel-heading">
                <h2 id="clearance-dialog-title"><i class="fas fa-money-check-alt mr-2 text-teal-700" aria-hidden="true"></i>Process payment clearance</h2>
                <button type="button" class="ui-button ui-button-secondary" wire:click="closeModal" aria-label="Close dialog">Close</button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto p-5">
                <div class="grid gap-5 md:grid-cols-[5fr_7fr]">
                    {{-- Patient, earlier balance and insurance --}}
                    <div class="space-y-3">
                        <div class="flex items-center gap-3">
                            <i class="fas fa-user-check text-3xl text-teal-700" aria-hidden="true"></i>
                            <div>
                                <p class="text-lg font-semibold">{{ $patientName }}</p>
                                <p class="text-xs text-slate-500">Confirm payment status before clearing</p>
                            </div>
                        </div>
                        @if($outstandingBalance > 0)
                            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="status">
                                <strong><i class="fas fa-exclamation-triangle mr-1" aria-hidden="true"></i>Previous outstanding balance:</strong>
                                {{ currency() }} {{ number_format($outstandingBalance, 2) }}
                                <p class="mt-1 text-xs">This is from earlier visits and is not included in the service total below.</p>
                            </div>
                        @endif
                        @if(!empty($insuranceSummary))
                            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
                                <p class="mb-2 font-semibold text-teal-800"><i class="fas fa-shield-alt mr-1" aria-hidden="true"></i>Insurance details</p>
                                <div class="grid grid-cols-2 gap-2">
                                    <div><span class="block text-xs text-slate-500">Insurer</span><strong>{{ $insuranceSummary['insurer'] }}</strong></div>
                                    <div><span class="block text-xs text-slate-500">Policy</span><strong>{{ $insuranceSummary['policy_number'] ?: 'N/A' }}</strong></div>
                                    <div class="col-span-2"><span class="block text-xs text-slate-500">Member</span><strong>{{ $insuranceSummary['member_name'] ?: 'N/A' }} ({{ $insuranceSummary['member_id'] ?: 'No ID' }})</strong></div>
                                </div>
                                @unless($insuranceSummary['active'])
                                    <p class="mt-2 text-xs text-red-700">This insurer is inactive, so the patient pays the full amount.</p>
                                @endunless
                            </div>
                        @endif
                    </div>

                    {{-- Service and payment --}}
                    <div class="space-y-3 md:border-l md:border-slate-200 md:pl-5">
                        <div>
                            <label for="clr-service-search" class="{{ $label }}"><i class="fas fa-concierge-bell mr-1 text-teal-700" aria-hidden="true"></i>Service</label>
                            {{-- Search and an open list; picking one fills the hidden select below for the payment script --}}
                            <div wire:ignore>
                                <div id="clr-service-chosen" class="hidden items-center justify-between gap-3 rounded-lg border border-teal-200 bg-teal-50 px-3 py-2.5">
                                    <span id="clr-service-chosen-label" class="font-semibold text-teal-900"></span>
                                    <button type="button" class="text-sm font-semibold text-teal-700 underline hover:text-teal-900" onclick="window.changeService()">Change</button>
                                </div>
                                <div id="clr-service-picking">
                                    <input type="search" id="clr-service-search" class="ui-input" autocomplete="off" placeholder="Search service…"
                                           role="combobox" aria-controls="clr-service-list" aria-expanded="true" aria-autocomplete="list"
                                           oninput="window.filterServiceList()" onkeydown="window.serviceListKeydown(event)">
                                    <div id="clr-service-list" role="listbox" aria-label="Services" class="mt-2 max-h-48 overflow-y-auto rounded-lg border border-slate-200 bg-white text-sm">
                                        @foreach($services as $svc)
                                            <button type="button" role="option" class="clr-service-option flex w-full justify-between gap-2 border-b border-slate-100 px-3 py-2 text-left hover:bg-slate-50"
                                                    data-id="{{ $svc->id }}"
                                                    data-label="{{ $svc->name }} — {{ currency() }} {{ number_format($svc->selling_price, 2) }}"
                                                    data-search="{{ strtolower($svc->name) }}"
                                                    onmousedown="event.preventDefault()" onclick="window.pickService(this)">
                                                <span>{{ $svc->name }}</span>
                                                <span class="whitespace-nowrap text-slate-500">{{ currency() }} {{ number_format($svc->selling_price, 2) }}</span>
                                            </button>
                                        @endforeach
                                        <p id="clr-service-empty" class="px-3 py-4 text-center text-slate-500" style="display:none;">No service matches</p>
                                    </div>
                                    <button type="button" class="mt-2 text-sm font-semibold text-red-700 hover:underline" data-id="unpaid" data-label="✗ Unpaid (no charge)"
                                            onclick="window.pickService(this)">✗ Clear as unpaid (no charge)</button>
                                </div>
                            </div>
                            <select class="hidden" wire:model="selectedServiceId" id="selectedServiceId" onchange="window.toggleClearancePaymentMethod(this.value)" aria-hidden="true" tabindex="-1">
                                <option value="">Select service…</option>
                                @foreach($services as $svc)
                                    <option value="{{ $svc->id }}">{{ $svc->name }} — {{ currency() }} {{ number_format($svc->selling_price, 2) }}</option>
                                @endforeach
                                <option value="unpaid">✗ Unpaid (no charge)</option>
                            </select>
                            @error('selectedServiceId')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <p id="clr-payment-hint" class="rounded-lg border border-dashed border-slate-200 px-3 py-3 text-center text-sm text-slate-500">Pick a service to see the amount.</p>

                        {{-- Split payment --}}
                        <div id="clearancePaymentSection" class="border-t border-slate-200 pt-3" style="display:none;">
                            <div class="mb-2 flex items-center justify-between">
                                <p class="{{ $label }} !mb-0"><i class="fas fa-credit-card mr-1 text-teal-700" aria-hidden="true"></i>Payment</p>
                                <button type="button" class="ui-button ui-button-secondary !py-1" onclick="window.addClearancePaymentRow()"><i class="fas fa-plus" aria-hidden="true"></i>Split</button>
                            </div>

                            {{-- Insurance split (insured patients only); read by the script below --}}
                            <div id="clr-insurance-data" class="hidden"
                                 data-insurer="{{ $insuranceSummary['insurer'] ?? '' }}"
                                 data-splits='@json((object) $insuranceSplits)'></div>
                            <div id="clr-insurance-box" wire:ignore class="mb-2 rounded-lg border border-slate-200 p-2 text-sm" style="display:none;">
                                <label class="mb-2 flex items-center gap-2 font-semibold">
                                    <input type="checkbox" id="clr-bill-insurer" class="rounded border-slate-300 text-teal-700" checked onchange="window.recalcClearanceInsurance()">
                                    Bill <span id="clr-insurer-name">insurer</span>
                                </label>
                                <div class="flex items-center gap-2">
                                    <label for="clr-insurer-amount" class="text-slate-500">Insurer pays</label>
                                    <input type="number" id="clr-insurer-amount" class="w-28 rounded-lg border-slate-300 py-1.5 text-sm" min="0" step="0.01" oninput="window.recalcClearanceInsurance()">
                                </div>
                                <div id="clr-insurance-reason-row" class="mt-2" style="display:none;">
                                    <input type="text" id="clr-insurance-reason" class="w-full rounded-lg border-slate-300 py-1.5 text-sm" maxlength="200"
                                           placeholder="Reason for changing what the insurer pays (required)" aria-label="Reason for changing what the insurer pays">
                                </div>
                            </div>

                            <div id="clearancePaymentRows"></div>

                            <div class="mt-2 space-y-0.5 rounded-lg bg-slate-50 p-2 text-sm" wire:ignore>
                                <div class="clr-insured-row flex justify-between" style="display:none !important;"><span>Bill total</span><strong id="clr-bill-total">0.00</strong></div>
                                <div class="clr-insured-row flex justify-between text-sky-700" style="display:none !important;"><span>Insurer pays</span><strong id="clr-insurer-total">0.00</strong></div>
                                <div class="flex justify-between"><span id="clr-svc-total-label">Service Total</span><strong id="clr-svc-total">0.00</strong></div>
                                <div class="flex justify-between text-green-700"><span>Amount entered</span><strong id="clr-entered">0.00</strong></div>
                                <div class="flex justify-between text-red-700" id="clr-balance-row"><span>Remaining</span><strong id="clr-remaining">0.00</strong></div>
                            </div>
                            <p id="clr-payment-error" class="mt-1 text-sm text-red-700" role="alert" style="display:none;"></p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
                <button type="button" class="ui-button ui-button-secondary" wire:click="closeModal">Cancel</button>
                <button type="button" id="clearanceConfirmBtn" onclick="window.submitClearance()" wire:loading.attr="disabled" wire:target="createClearance" class="ui-button ui-button-primary">
                    <span wire:loading.remove wire:target="createClearance"><i class="fa fa-check mr-1" aria-hidden="true"></i>Confirm &amp; save</span>
                    <span wire:loading wire:target="createClearance"><i class="fas fa-spinner fa-spin mr-1" aria-hidden="true"></i>Saving…</span>
                </button>
            </div>
        </div>
    </div>

    {{-- Clearance receipt --}}
    <div id="clearanceReceiptModal" wire:ignore.self class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4" onclick="if (event.target === this) window.clearanceDialog('clearanceReceiptModal', false)">
        <div class="w-full max-w-md overflow-hidden rounded-xl bg-white shadow-xl" role="dialog" aria-modal="true" aria-labelledby="clearance-receipt-title">
            <div class="ui-panel-heading !py-3">
                <h2 id="clearance-receipt-title"><i class="fas fa-receipt mr-1 text-teal-700" aria-hidden="true"></i>Clearance receipt</h2>
                <button type="button" class="ui-button ui-button-secondary" onclick="window.clearanceDialog('clearanceReceiptModal', false)" aria-label="Close dialog">Close</button>
            </div>
            <div class="p-4" id="clrReceiptModalContent"></div>
            <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3">
                <button type="button" class="ui-button ui-button-secondary" onclick="window.clearanceDialog('clearanceReceiptModal', false)">Close</button>
                <button type="button" onclick="window.printClearanceReceipt()" class="ui-button ui-button-primary"><i class="fas fa-print" aria-hidden="true"></i>Print receipt</button>
            </div>
        </div>
    </div>

    {{-- Revoke request --}}
    <div id="revokeRequestModal" wire:ignore.self class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
        <div class="w-full max-w-md overflow-hidden rounded-xl bg-white shadow-xl" role="dialog" aria-modal="true" aria-labelledby="revoke-dialog-title">
            <div class="ui-panel-heading bg-red-50">
                <h2 id="revoke-dialog-title" class="text-red-800"><i class="fas fa-undo mr-2" aria-hidden="true"></i>Request clearance revoke</h2>
                <button type="button" class="ui-button ui-button-secondary" wire:click="cancelRevokeRequest" aria-label="Close dialog">Close</button>
            </div>
            <div class="space-y-3 p-5">
                @if($requestingRevokeName)
                    <p class="text-sm">Submitting a revoke request for <strong>{{ $requestingRevokeName }}</strong>. A manager or Super Admin must approve it before the clearance is removed.</p>
                @endif
                <div>
                    <label for="revoke-reason" class="{{ $label }}">Reason <span class="text-red-600">*</span></label>
                    <textarea id="revoke-reason" wire:model="revokeReason" class="ui-input" rows="3" placeholder="Explain why this clearance should be revoked…"
                              aria-invalid="{{ $errors->has('revokeReason') ? 'true' : 'false' }}"></textarea>
                    @error('revokeReason')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
                <button type="button" class="ui-button ui-button-secondary" wire:click="cancelRevokeRequest">Cancel</button>
                <button type="button" class="ui-button ui-button-danger" wire:click="submitRevokeRequest"><i class="fas fa-paper-plane" aria-hidden="true"></i>Submit request</button>
            </div>
        </div>
    </div>

    <style>
        .clr-service-option.active { background: #0f766e; color: #fff; }
        .clr-service-option.active span { color: inherit; }
    </style>

    @script
    <script>
    // Livewire runs this block inside Alpine's evaluator, where named functions declared at the top
    // level aren't reliably visible; wrapping it keeps every name below in this function's scope.
    (function () {
        // The three dialogs stay in the page (wire:ignore.self); these show and hide them.
        function clearanceDialog(id, open) {
            var dialog = document.getElementById(id);
            if (!dialog) return;
            dialog.classList.toggle('hidden', !open);
            dialog.classList.toggle('flex', open);
            if (!open && id === 'addClearanceModal') resetClearanceDialog();
        }
        window.clearanceDialog = clearanceDialog;
        function clearanceDialogOpen(id) {
            var dialog = document.getElementById(id);
            return dialog && !dialog.classList.contains('hidden');
        }

        window.addEventListener('show-addClearanceModal-form', function () {
            clearanceDialog('addClearanceModal', true);
            window.toggleClearancePaymentMethod(document.getElementById('selectedServiceId')?.value || '');
            window.changeService();
        });
        window.addEventListener('hide-addClearanceModal-modal', function () {
            clearanceDialog('addClearanceModal', false);
        });
        window.addEventListener('show-revokeRequestModal', () => clearanceDialog('revokeRequestModal', true));
        window.addEventListener('hide-revokeRequestModal', () => clearanceDialog('revokeRequestModal', false));
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if (clearanceDialogOpen('clearanceReceiptModal')) clearanceDialog('clearanceReceiptModal', false);
            else if (clearanceDialogOpen('revokeRequestModal')) $wire.cancelRevokeRequest();
            else if (clearanceDialogOpen('addClearanceModal')) $wire.closeModal();
        });

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
                        '<div class="flex justify-between text-sm text-slate-500">' +
                        '<span>PAID (' + p.method.toUpperCase() + ')</span>' +
                        '<span>' + currency + ' ' + p.amount + '</span>' +
                        '</div>';
                });
                if (d.payments.length > 1) {
                    var totalPaid = d.payments.reduce(function (sum, p) { return sum + (parseFloat(String(p.amount).replace(/,/g, '')) || 0); }, 0);
                    paymentHtml +=
                        '<div class="flex justify-between text-sm font-semibold">' +
                        '<span>TOTAL PAID</span><span>' + currency + ' ' + totalPaid.toFixed(2) + '</span></div>';
                }
            }
            if (d.insurer) {
                paymentHtml +=
                    '<div class="flex justify-between text-sm text-sky-700">' +
                    '<span>BILLED TO ' + String(d.insurer).toUpperCase() + '</span>' +
                    '<span>' + currency + ' ' + d.insurerAmount + '</span></div>';
            }

            var statusClass = d.status === 'Paid' ? 'text-green-700' : 'text-red-700';

            // Values come from the server; escape them before building the receipt preview.
            var esc = function (value) { var span = document.createElement('span'); span.textContent = value == null ? '' : String(value); return span.innerHTML; };
            var html =
                '<div class="mb-2 flex justify-between text-sm"><span class="text-xs text-slate-500">Patient</span><span class="font-semibold">' + esc(d.patient) + '</span></div>' +
                '<div class="mb-2 flex justify-between text-sm"><span class="text-xs text-slate-500">ID</span><span>' + esc(d.pxnumber) + '</span></div>' +
                '<div class="mb-3 flex justify-between text-sm"><span class="text-xs text-slate-500">TXN</span><span class="font-semibold">' + esc(d.txn) + '</span></div>' +
                '<table class="mb-2 w-full border border-slate-200 text-sm">' +
                '<thead class="bg-slate-50"><tr><th class="px-2 py-1 text-left">Service</th><th class="px-2 py-1 text-right">Amount</th></tr></thead>' +
                '<tbody><tr><td class="border-t border-slate-200 px-2 py-1">' + esc(d.service) + '</td><td class="border-t border-slate-200 px-2 py-1 text-right">' + currency + ' ' + esc(d.amount) + '</td></tr></tbody>' +
                '</table>' +
                '<div class="mb-1 flex justify-between font-semibold"><span>Grand total</span><span class="text-teal-800">' + currency + ' ' + esc(d.amount) + '</span></div>' +
                (paymentHtml ? '<div class="border-t border-slate-200 pt-1">' + paymentHtml + '</div>' : '') +
                '<div class="mt-2 flex justify-between border-t border-slate-200 pt-2"><span class="text-sm font-semibold">Payment status</span>' +
                '<span class="font-semibold ' + statusClass + '">' + esc(d.status).toUpperCase() + '</span></div>';

            document.getElementById('clrReceiptModalContent').innerHTML = html;
            clearanceDialog('clearanceReceiptModal', true);
        });

        window.printClearanceReceipt = function () {
            var w = window.open(_clrPrintUrl, '_blank', 'width=302,height=600');
            if (!w) {
                window.appAlert('Pop-up blocked', 'Please allow pop-ups for this site to print receipts.', 'warning');
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

        // Service picker: the list stays open while picking; a pick collapses it to one line (Change reopens it).
        var _clrServiceActive = -1;

        function serviceOptions(visibleOnly) {
            var all = Array.prototype.slice.call(document.querySelectorAll('#clr-service-list .clr-service-option'));
            return visibleOnly ? all.filter(function (o) { return o.style.display !== 'none'; }) : all;
        }

        function highlightService(index) {
            var options = serviceOptions(true);
            options.forEach(function (o) { o.classList.remove('active'); o.setAttribute('aria-selected', 'false'); });
            // -1 means nothing highlighted (an empty search shows the whole list unselected).
            _clrServiceActive = options.length && index >= 0 ? Math.min(index, options.length - 1) : -1;
            if (_clrServiceActive >= 0) {
                options[_clrServiceActive].classList.add('active');
                options[_clrServiceActive].setAttribute('aria-selected', 'true');
                options[_clrServiceActive].scrollIntoView({ block: 'nearest' });
            }
        }

        function showServicePicker(picking) {
            var chosen = document.getElementById('clr-service-chosen');
            document.getElementById('clr-service-picking').style.display = picking ? '' : 'none';
            chosen.classList.toggle('hidden', picking);
            chosen.classList.toggle('flex', !picking);
        }

        window.filterServiceList = function () {
            var term = document.getElementById('clr-service-search').value.trim().toLowerCase();
            var shown = 0;
            serviceOptions(false).forEach(function (o) {
                var match = !term || o.dataset.search.indexOf(term) !== -1;
                o.style.display = match ? '' : 'none';
                if (match) shown++;
            });
            document.getElementById('clr-service-empty').style.display = shown ? 'none' : 'block';
            highlightService(term ? 0 : -1);
        };

        window.pickService = function (option) {
            var select = document.getElementById('selectedServiceId');
            select.value = option.dataset.id;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            document.getElementById('clr-service-chosen-label').textContent = option.dataset.label;
            showServicePicker(false);
        };

        window.changeService = function () {
            var search = document.getElementById('clr-service-search');
            search.value = '';
            window.filterServiceList();
            showServicePicker(true);
            search.focus();
        };

        window.serviceListKeydown = function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                highlightService(Math.max(0, _clrServiceActive + (e.key === 'ArrowDown' ? 1 : -1)));
            } else if (e.key === 'Enter') {
                e.preventDefault();
                var options = serviceOptions(true);
                if (options[_clrServiceActive]) window.pickService(options[_clrServiceActive]);
                else if (options.length === 1) window.pickService(options[0]);
            } else if (e.key === 'Escape' && e.target.value) {
                // First Escape clears the search; the next one closes the dialog.
                e.preventDefault();
                e.stopPropagation();
                e.target.value = '';
                window.filterServiceList();
            }
        };

        window.toggleClearancePaymentMethod = function (val) {
            var section = document.getElementById('clearancePaymentSection');
            if (!section) return;
            var hint = document.getElementById('clr-payment-hint');
            hint.style.display = val && val !== 'unpaid' ? 'none' : '';
            hint.textContent = val === 'unpaid' ? 'No charge: the patient is cleared as unpaid.' : 'Pick a service to see the amount.';
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
            row.className = 'mb-2 flex items-center gap-1.5';
            row.id = 'clr-row-' + idx;
            row.innerHTML =
                '<select class="clr-method w-36 rounded-lg border-slate-300 py-1.5 text-sm" aria-label="Payment method" onchange="window.updateClearanceTotals()">' +
                    clearanceMethodOptions() +
                '</select>' +
                '<input type="number" class="clr-amount w-28 rounded-lg border-slate-300 py-1.5 text-sm" min="0.01" step="0.01" aria-label="Amount" ' +
                       'placeholder="Amount" value="' + amount + '" oninput="window.updateClearanceTotals()">' +
                '<button type="button" class="rounded-md border border-red-200 px-2.5 py-1.5 text-red-700 hover:bg-red-50" aria-label="Remove payment" onclick="window.removeClearanceRow(' + idx + ')">' +
                    '<i class="fas fa-times" aria-hidden="true"></i>' +
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

        // Reset when the dialog closes
        function resetClearanceDialog() {
            document.getElementById('clearancePaymentSection')?.style.setProperty('display', 'none');
            if (document.getElementById('clearancePaymentRows')) document.getElementById('clearancePaymentRows').innerHTML = '';
            if (document.getElementById('selectedServiceId')) document.getElementById('selectedServiceId').value = '';
            if (document.getElementById('clr-service-search')) document.getElementById('clr-service-search').value = '';
            if (document.getElementById('clr-service-picking')) showServicePicker(true);
            if (document.getElementById('clr-payment-error')) document.getElementById('clr-payment-error').style.display = 'none';
            if (document.getElementById('clr-insurance-box')) document.getElementById('clr-insurance-box').style.display = 'none';
            setInsuredRowsVisible(false);
            _clrSplit = null;
            _clrRowIdx = 0;
        }
    })();
    </script>
    @endscript
</div>
