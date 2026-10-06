<div>
<style>
/* Thermal job card is printed via a dedicated popup window — no @media print needed here */
#spectacle-job-preview-print { display: block; }
</style>

@php
    $statuses = [
        'Pending' => ['label' => 'Pending', 'class' => 'pending'],
        'In Lab' => ['label' => 'In Lab', 'class' => 'lab'],
        'Ready' => ['label' => 'Ready', 'class' => 'ready'],
        'Collected' => ['label' => 'Collected', 'class' => 'collected'],
        'Cancelled' => ['label' => 'Cancelled', 'class' => 'cancelled'],
    ];
@endphp

<div class="so-page">
    <section class="so-topbar">
        <div>
            <div class="so-kicker">Optical dispensing</div>
            <h1>Spectacle Orders</h1>
            <p>Manage refractions, lab work, patient communication, pickup readiness, and job cards.</p>
        </div>

        <div class="so-top-actions">
            <a href="{{ route('optical.orders.create') }}" class="so-btn text-white" style="background:#087e83;">
                <i class="fas fa-glasses"></i>
                Start Optical Order
            </a>
            <button type="button" wire:click="resetFilters" class="so-btn so-btn-light">
                <i class="fas fa-sync-alt"></i>
                Reset
            </button>
            <button type="button" wire:click="exportCSV" wire:loading.attr="disabled" class="so-btn so-btn-primary">
                <span wire:loading.remove wire:target="exportCSV"><i class="fas fa-file-csv"></i> Export CSV</span>
                <span wire:loading wire:target="exportCSV"><i class="fas fa-circle-notch fa-spin"></i> Exporting</span>
            </button>
        </div>
    </section>

    <section class="so-metrics" aria-label="Spectacle order summary">
        <button type="button" wire:click="setRecordType('orders')" class="so-metric {{ $recordType === 'orders' && !$statusFilter ? 'is-active' : '' }}">
            <span class="so-metric-icon neutral"><i class="fas fa-list-ul"></i></span>
            <span class="so-metric-value">{{ $stats['pending'] + $stats['ordered'] + $stats['in_lab'] + $stats['ready'] + $stats['collected'] }}</span>
            <span class="so-metric-label">All orders</span>
        </button>
        <button type="button" wire:click="setStatusFilter('Pending')" class="so-metric {{ $statusFilter === 'Pending' ? 'is-active' : '' }}">
            <span class="so-metric-icon neutral"><i class="fas fa-glasses"></i></span>
            <span class="so-metric-value">{{ $stats['pending'] }}</span>
            <span class="so-metric-label">Need order</span>
        </button>
        <button type="button" wire:click="setStatusFilter('Ordered')" class="so-metric {{ $statusFilter === 'Ordered' ? 'is-active' : '' }}">
            <span class="so-metric-icon warning"><i class="fas fa-receipt"></i></span>
            <span class="so-metric-value">{{ $stats['ordered'] }}</span>
            <span class="so-metric-label">Pending</span>
        </button>
        <button type="button" wire:click="setStatusFilter('In Lab')" class="so-metric {{ $statusFilter === 'In Lab' ? 'is-active' : '' }}">
            <span class="so-metric-icon lab"><i class="fas fa-flask"></i></span>
            <span class="so-metric-value">{{ $stats['in_lab'] }}</span>
            <span class="so-metric-label">In lab</span>
        </button>
        <button type="button" wire:click="setStatusFilter('Ready')" class="so-metric {{ $statusFilter === 'Ready' ? 'is-active' : '' }}">
            <span class="so-metric-icon success"><i class="fas fa-check"></i></span>
            <span class="so-metric-value">{{ $stats['ready'] }}</span>
            <span class="so-metric-label">Ready</span>
        </button>
        <button type="button" wire:click="setQuickFilter('overdue')" class="so-metric {{ $quickFilter === 'overdue' ? 'is-active' : '' }}">
            <span class="so-metric-icon danger"><i class="fas fa-clock"></i></span>
            <span class="so-metric-value">{{ $stats['overdue'] }}</span>
            <span class="so-metric-label">Pickup overdue</span>
        </button>
        <button type="button" wire:click="setQuickFilter('renewal_due')" class="so-metric {{ $quickFilter === 'renewal_due' ? 'is-active' : '' }}">
            <span class="so-metric-icon warning"><i class="fas fa-redo"></i></span>
            <span class="so-metric-value">{{ $stats['renewal_due'] }}</span>
            <span class="so-metric-label">Renewal due soon</span>
        </button>
    </section>
    <div class="mb-3 inline-flex overflow-hidden rounded-md border border-teal-700 text-sm font-semibold" role="group" aria-label="Record type">
        <button type="button" wire:click="setRecordType('orders')" aria-pressed="{{ $recordType === 'orders' ? 'true' : 'false' }}" class="px-3 py-1.5 {{ $recordType === 'orders' ? 'bg-teal-700 text-white' : 'bg-white text-teal-800 hover:bg-teal-50' }}">Spectacle Orders</button>
        <button type="button" wire:click="setRecordType('refractions')" aria-pressed="{{ $recordType === 'refractions' ? 'true' : 'false' }}" class="border-l border-teal-700 px-3 py-1.5 {{ $recordType === 'refractions' ? 'bg-teal-700 text-white' : 'bg-white text-teal-800 hover:bg-teal-50' }}">Refractions Needing Order</button>
    </div>

    <section class="so-filters">
        <div class="so-filter-grid">
            <label class="so-search">
                <i class="fas fa-search"></i>
                <input type="search" wire:model.live.debounce.400ms="searchTerm" placeholder="Search patient, folder no., order ID, frame">
                @if($searchTerm)
                    <button type="button" wire:click="$set('searchTerm', '')" aria-label="Clear search"><i class="fas fa-times"></i></button>
                @endif
            </label>

            <div class="so-field"><span>Dates</span><x-date-range from="fromDate" to="toDate" presets="activity" /></div>

            <label class="so-field">
                <span>Status</span>
                <select wire:model.live="statusFilter">
                    <option value="">All statuses</option>
                    <option value="Pending">Awaiting order</option>
                    <option value="Ordered">Pending</option>
                    <option value="In Lab">In Lab</option>
                    <option value="Ready">Ready</option>
                    <option value="Collected">Collected</option>
                    <option value="Cancelled">Cancelled</option>
                </select>
            </label>

            @if(count($doctors) > 0)
                <label class="so-field">
                    <span>Doctor</span>
                    <select wire:model.live="doctorFilter">
                        <option value="">All doctors</option>
                        @foreach($doctors as $doc)
                            <option value="{{ $doc->id }}">{{ $doc->name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            @if(count($labs) > 0)
                <label class="so-field">
                    <span>Lab</span>
                    <select wire:model.live="labFilter">
                        <option value="">All labs</option>
                        @foreach($labs as $lab)
                            <option value="{{ $lab }}">{{ $lab }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
        </div>

        <div class="so-filter-footer">
            <div class="so-chip-row">
                <button type="button" wire:click="setQuickFilter('today')" class="so-chip {{ $quickFilter === 'today' ? 'is-active' : '' }}">Today</button>
                <button type="button" wire:click="setQuickFilter('week')" class="so-chip {{ $quickFilter === 'week' ? 'is-active' : '' }}">This week</button>
                <button type="button" wire:click="setQuickFilter('ready')" class="so-chip {{ $quickFilter === 'ready' ? 'is-active' : '' }}">Ready</button>
                <button type="button" wire:click="setQuickFilter('overdue')" class="so-chip so-chip-warn {{ $quickFilter === 'overdue' ? 'is-active' : '' }}">Overdue pickup</button>
                <button type="button" wire:click="setQuickFilter('renewal_due')" class="so-chip so-chip-warn {{ $quickFilter === 'renewal_due' ? 'is-active' : '' }}">Renewal due soon</button>
            </div>

            <div class="so-sort-row">
                <button type="button" wire:click="sortBy('created_at')" class="so-link-btn">
                    Created
                    @if($sortField === 'created_at')
                        <i class="fas fa-sort-{{ $sortDirection === 'asc' ? 'up' : 'down' }}"></i>
                    @endif
                </button>
                <button type="button" wire:click="sortBy('pickUpDate')" class="so-link-btn">
                    Pickup
                    @if($sortField === 'pickUpDate')
                        <i class="fas fa-sort-{{ $sortDirection === 'asc' ? 'up' : 'down' }}"></i>
                    @endif
                </button>
            </div>
        </div>
    </section>

    @if($spectacles->total() > 0)
        <section class="so-bulk-panel">
            <label class="so-check">
                <input type="checkbox" wire:model.live="selectAllOrders">
                <span>Select matching orders</span>
            </label>
            <span class="so-muted">{{ $spectacles->total() }} {{ $recordType === 'orders' ? 'spectacle order' : 'refraction needing order' }}{{ $spectacles->total() === 1 ? '' : 's' }} found after filters</span>

            @if(count($selectedOrders) > 0)
                <div class="so-bulk-actions">
                    <strong>{{ count($selectedOrders) }} selected</strong>
                    <select wire:model.live="bulkStatus">
                        <option value="">Move to status</option>
                        <option value="Pending">Pending</option>
                        <option value="In Lab">In Lab</option>
                        <option value="Ready">Ready</option>
                        <option value="Collected">Collected</option>
                    </select>
                    <button type="button" wire:click="bulkUpdateStatus" class="so-btn so-btn-primary so-btn-sm">Apply</button>
                    <button type="button" wire:click="clearSelection" class="so-btn so-btn-light so-btn-sm">Clear</button>
                </div>
            @endif
        </section>
    @endif

    @php
        $queueRefractions = $spectacles->getCollection()->filter(fn($row) => $row->consultation && $row->consultation->patient);
    @endphp

    <section class="so-workbench" aria-label="Spectacle work queue">
        <div class="so-queue-panel">
            <div class="so-queue-head">
                <span>Patient</span>
                <span class="so-q-pos">POS</span>
                <span>Status</span>
                <span>Pickup</span>
                <span>Action</span>
            </div>

            @if($queueRefractions->isEmpty())
                <section class="so-empty-state">
                    <i class="fas fa-glasses"></i>
                    <h2>No refractions found</h2>
                    <p>Adjust the filters or wait for doctors to add refraction records.</p>
                    <button type="button" wire:click="resetFilters" class="so-btn so-btn-light">Reset filters</button>
                </section>
            @else
            @foreach($queueRefractions as $refraction)
                @php
                    $patient = $refraction->consultation->patient;
                    $order = $refraction->lensOrder;
                    $posSummary = $this->posOrderSummary($refraction);
                    $status = $order?->status;
                    $statusMeta = $status ? ($statuses[$status] ?? ['label' => $status, 'class' => 'neutral']) : ['label' => 'Awaiting Order', 'class' => 'awaiting'];
                    $isPickupLate = $order && $order->status === 'Ready' && $order->updated_at && $order->updated_at->diffInDays(now()) >= 7;
                    $canCreateOrder = $this->canCreateOrderFromPos($posSummary);
                    $isActiveRow = optional($activeRefraction)->id === $refraction->id;
                    $dispensingIssues = $this->dispensingIssues($refraction);
                @endphp

                <article class="so-queue-row status-{{ $statusMeta['class'] }} {{ $isActiveRow ? 'is-active' : '' }}">
                    <button type="button" wire:click="selectRefraction({{ $refraction->id }})" class="so-queue-main">
                        <span class="so-queue-patient">
                            <span class="so-avatar sm">{{ strtoupper(substr($patient->name, 0, 1)) }}</span>
                            <span>
                                <strong>{{ $patient->name }}</strong>
                                <small>
                                    {{ collect([
                                        $patient->pxnumber,
                                        $patient->gender,
                                        $patient->dob ? \Carbon\Carbon::parse($patient->dob)->age . ' yrs' : null,
                                    ])->filter()->implode(' · ') }}
                                </small>
                            </span>
                        </span>
                        <span class="so-q-pos">
                            <strong>{{ $posSummary['label'] }}</strong>
                            <small>{{ currency() }} {{ number_format($posSummary['amount'], 2) }}</small>
                        </span>
                        <span>
                            <span class="so-status-pill {{ $statusMeta['class'] }}">
                                <span></span>
                                {{ $isPickupLate ? 'Overdue' : $statusMeta['label'] }}
                            </span>
                            @if($order)
                                <small>{{ $order->order_id }}</small>
                            @endif
                            <small>Next: {{ $this->nextRequiredAction($order) }}</small>
                            @if(count($dispensingIssues))<small class="text-red-700">{{ implode(', ', $dispensingIssues) }}</small>@endif
                        </span>
                        <span>
                            <strong>{{ $order ? \Carbon\Carbon::parse($order->pickUpDate)->format('d M Y') : '-' }}</strong>
                            <small>{{ $refraction->created_at?->format('d M Y') }}</small>
                        </span>
                    </button>

                    <div class="so-queue-actions">
                        @if($order)
                            <button type="button" wire:click="directPrint({{ $order->id }})" class="so-icon-btn" title="Print job card">
                                <i class="fas fa-print"></i>
                            </button>
                        @else
                            <button type="button"
                                    wire:click="openOrderModal({{ $refraction->id }})"
                                    class="so-btn so-btn-primary so-btn-sm {{ $canCreateOrder ? '' : 'is-disabled' }}"
                                    {{ !$canCreateOrder ? 'disabled' : '' }}>
                                <i class="fas fa-plus"></i>
                                Create
                            </button>
                        @endif
                    </div>
                </article>
            @endforeach
            @endif
        </div>

        @if($activeRefraction && $activeRefraction->consultation && $activeRefraction->consultation->patient)
            @php
                $activePatient = $activeRefraction->consultation->patient;
                $activeOrder = $activeRefraction->lensOrder;
                $activePosSummary = $this->posOrderSummary($activeRefraction);
                $activeStatus = $activeOrder?->status;
                $activeStatusMeta = $activeStatus ? ($statuses[$activeStatus] ?? ['label' => $activeStatus, 'class' => 'neutral']) : ['label' => 'Awaiting Order', 'class' => 'awaiting'];
                $activeLabName = $activeOrder ? $this->extractNoteValue($activeOrder, 'Lab') : '';
                $activeLabRef = $activeOrder ? $this->extractNoteValue($activeOrder, 'Lab Ref') : '';
                $activeNoteLines = $activeOrder ? $this->orderNoteLines($activeOrder->notes) : [];
                $activePhone = $activePatient->contact ?? '';
                $activeCleanPhone = preg_replace('/\D+/', '', $activePhone);
                $activeMessage = match (true) {
                    $activeOrder?->status === 'Ready' => \App\Models\SmsTemplate::render('spectacles_ready', ['[NAME]' => $activePatient->name, '[ORDER_ID]' => $activeOrder->order_id]),
                    (bool) $activeOrder => "Hello {$activePatient->name}, your spectacle order {$activeOrder->order_id} is {$activeOrder->status}. Pickup date: {$activeOrder->pickUpDate}.",
                    default => "Hello {$activePatient->name}, your spectacle prescription is ready for optical order processing.",
                };
                $activeWhatsAppUrl = \App\Support\Messaging\WhatsAppLink::to($activePhone, $activeMessage) ?? '#';
                $activeSmsUrl = $activeCleanPhone ? 'sms:' . $activeCleanPhone . '?&body=' . rawurlencode($activeMessage) : '#';
                $activeCanCreateOrder = $this->canCreateOrderFromPos($activePosSummary);
            @endphp

            <aside class="so-case-panel" aria-label="Selected spectacle order">
                <header class="so-case-header">
                    <div class="so-patient">
                        <div class="so-avatar">{{ strtoupper(substr($activePatient->name, 0, 1)) }}</div>
                        <div>
                            <h2>{{ $activePatient->name }}</h2>
                            <div class="so-patient-meta">
                                @if($activePatient->pxnumber)
                                    <span>{{ $activePatient->pxnumber }}</span>
                                @endif
                                @if($activePatient->gender)
                                    <span>{{ $activePatient->gender }}</span>
                                @endif
                                @if($activePatient->dob)
                                    <span>{{ \Carbon\Carbon::parse($activePatient->dob)->age }} yrs</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <span class="so-status-pill {{ $activeStatusMeta['class'] }}">
                        <span></span>
                        {{ $activeStatusMeta['label'] }}
                    </span>
                </header>

                <section class="so-panel">
                    <div class="so-panel-title"><i class="fas fa-cash-register"></i> POS & Payment</div>
                    <div class="so-pos-track so-pos-track--{{ $activePosSummary['class'] }}">
                        <div>
                            <span>POS tracking</span>
                            <strong>{{ $activePosSummary['label'] }}</strong>
                            @if($activePosSummary['transaction'])
                                <small>{{ $activePosSummary['transaction'] }}</small>
                            @endif
                        </div>
                        <div>
                            <span>POS amount</span>
                            <strong>{{ currency() }} {{ number_format($activePosSummary['amount'], 2) }}</strong>
                            @if(($activePosSummary['insurer'] ?? 0) > 0)
                                <small>Insurer pays {{ currency() }} {{ number_format($activePosSummary['insurer'], 2) }}</small>
                            @endif
                            @if($activePosSummary['balance'] > 0)
                                <small>Balance {{ currency() }} {{ number_format($activePosSummary['balance'], 2) }}</small>
                            @endif
                            <small>Paid {{ currency() }} {{ number_format($activePosSummary['paid'], 2) }} @if($activePosSummary['discount'] > 0) · Discount {{ currency() }} {{ number_format($activePosSummary['discount'], 2) }} @endif</small>
                        </div>
                        @if($activePosSummary['items']->isNotEmpty())
                            <div class="so-pos-items">
                                @foreach($activePosSummary['items']->take(4) as $posItem)
                                    <span>{{ $posItem->product->name ?? 'Optical item' }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </section>

                <section class="so-panel">
                    <div class="so-panel-title"><i class="fas fa-prescription"></i> Refraction</div>
                    <table class="so-rx-table">
                        <thead>
                            <tr>
                                <th>Eye</th>
                                <th>SPH / CYL / Axis</th>
                                <th>ADD</th>
                                <th>Dist. VA</th>
                                <th>Near VA</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>OD</td>
                                <td>{{ $activeRefraction->refractionOD ?? '-' }}</td>
                                <td>{{ $activeRefraction->refractionOD_ADD ?? '-' }}</td>
                                <td>{{ $activeRefraction->refractionOD_distance_va ?? '-' }}</td>
                                <td>{{ $activeRefraction->refractionOD_near_va ?? '-' }}</td>
                            </tr>
                            <tr>
                                <td>OS</td>
                                <td>{{ $activeRefraction->refractionOS ?? '-' }}</td>
                                <td>{{ $activeRefraction->refractionOS_ADD ?? '-' }}</td>
                                <td>{{ $activeRefraction->refractionOS_distance_va ?? '-' }}</td>
                                <td>{{ $activeRefraction->refractionOS_near_va ?? '-' }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="so-rx-tags">
                        @if($activeRefraction->pd)
                            <span>PD {{ $activeRefraction->pd }} mm</span>
                        @endif
                        @if($activeRefraction->lensType)
                            <span>{{ $activeRefraction->lensType }}</span>
                        @endif
                        <span>{{ $activeRefraction->created_at?->format('d M Y') }}</span>
                    </div>
                </section>

                <section class="so-panel">
                    <div class="so-panel-title"><i class="fas fa-sliders-h"></i> Workflow</div>
                    @if($activeOrder)
                        <div class="so-detail-list">
                            <div>
                                <span>Order ID</span>
                                <strong>{{ $activeOrder->order_id }}</strong>
                            </div>
                            <div>
                                <span>Pickup date</span>
                                <strong>{{ \Carbon\Carbon::parse($activeOrder->pickUpDate)->format('D, d M Y') }}</strong>
                            </div>
                            @if($activeLabName || $activeLabRef)
                                <div class="so-detail-wide">
                                    <span>Lab</span>
                                    <strong>{{ trim($activeLabName . ' ' . $activeLabRef) }}</strong>
                                </div>
                            @endif
                            <div><span>Lab cost</span><strong>{{ currency() }} {{ number_format($activeOrder->lab_cost ?? 0, 2) }}</strong></div>
                            <div><span>Estimated profit</span><strong class="{{ $this->estimatedOrderProfit($activeOrder) < 0 ? 'text-red-700' : 'text-green-700' }}">{{ currency() }} {{ number_format($this->estimatedOrderProfit($activeOrder), 2) }}</strong></div>
                        </div>

                        <div class="so-progress-toggle" aria-label="Spectacle order status">
                            @foreach(['Pending', 'In Lab', 'Ready', 'Collected'] as $workflowStatus)
                                <button type="button"
                                        wire:click="updateStatus({{ $activeOrder->id }}, '{{ $workflowStatus }}')"
                                        class="{{ $activeOrder->status === $workflowStatus ? 'is-active' : '' }}"
                                        title="Set {{ $workflowStatus }}">
                                    {{ $workflowStatus }}
                                </button>
                            @endforeach
                        </div>
                        @if(auth()->user()->hasAnyRole(['Manager','Super Admin']))
                            <label class="so-form-field mt-2"><span>Override reason (required only to skip a stage)</span><input type="text" wire:model="statusOverrideReason" placeholder="Reason for authorized override"></label>
                        @endif

                        <div class="so-contact-row">
                            <a class="so-icon-btn {{ !$activeCleanPhone ? 'is-disabled' : '' }}" href="{{ $activeCleanPhone ? 'tel:' . $activeCleanPhone : '#' }}" title="Call patient"><i class="fas fa-phone"></i></a>
                            <a class="so-icon-btn {{ !$activeCleanPhone ? 'is-disabled' : '' }}" href="{{ $activeSmsUrl }}" title="Send SMS"><i class="fas fa-sms"></i></a>
                            <a class="so-icon-btn whatsapp {{ !$activeCleanPhone ? 'is-disabled' : '' }}" href="{{ $activeWhatsAppUrl }}" target="_blank" rel="noopener" title="Send WhatsApp message"><i class="fab fa-whatsapp"></i></a>
                            <button type="button" wire:click="sendReadyReminder({{ $activeOrder->id }})" class="so-icon-btn" title="Log reminder"><i class="fas fa-bell"></i></button>
                        </div>

                        <div class="so-action-grid">
                            <button type="button" wire:click="directPrint({{ $activeOrder->id }})" class="so-btn so-btn-primary so-btn-sm"><i class="fas fa-print"></i> Job Card</button>
                            <button type="button" wire:click="openCancelConfirm({{ $activeOrder->id }})" class="so-btn so-btn-danger so-btn-sm"><i class="fas fa-ban"></i> Cancel</button>
                        </div>

                        @if(count($activeNoteLines) > 0)
                            <details class="so-notes compact">
                                <summary>Order notes and audit trail</summary>
                                <div>
                                    @foreach($activeNoteLines as $line)
                                        <span>{{ $line }}</span>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    @else
                        <div class="so-empty-order">
                            <i class="fas fa-plus-circle"></i>
                            <p>No spectacle order has been created for this refraction.</p>
                        </div>
                        <button type="button"
                                wire:click="openOrderModal({{ $activeRefraction->id }})"
                                class="so-btn so-btn-primary so-btn-wide {{ $activeCanCreateOrder ? '' : 'is-disabled' }}"
                                {{ !$activeCanCreateOrder ? 'disabled' : '' }}>
                            <i class="fas fa-plus"></i>
                            Create Order
                        </button>
                        @if(!$activeCanCreateOrder)
                            <small class="so-muted mt-2 block">Part or full payment must be recorded at POS first.</small>
                        @endif
                    @endif
                </section>
            </aside>
        @else
            <aside class="so-case-panel so-case-panel-empty">
                <i class="fas fa-glasses"></i>
                <h2>No case selected</h2>
                <p>Select a refraction from the queue to see details and workflow actions.</p>
            </aside>
        @endif
    </section>

    @if($spectacles->hasPages())
        <div class="so-pagination">{{ $spectacles->links() }}</div>
    @endif
</div>

@if($showPrintPreview && $printOrder)
    @php
        $previewRef = $printOrder->refraction;
        $previewPatient = $previewRef?->consultation?->patient;
        $previewLab = $this->extractNoteValue($printOrder, 'Lab');
        $previewLabRef = $this->extractNoteValue($printOrder, 'Lab Ref');
        $previewTotal = (float) $printOrder->frame_price + (float) $printOrder->lens_price;
        $previewPos = $previewRef ? $this->posOrderSummary($previewRef) : ['items' => collect(), 'amount' => 0, 'paid' => 0];
    @endphp
    <div class="so-modal-backdrop" wire:click.self="closePrintPreview" {{ $autoPrint ? 'style=display:none' : '' }}>
        <section class="so-modal so-modal-xl" role="dialog" aria-modal="true" aria-label="Job card preview">
            <header class="so-modal-header">
                <div>
                    <span>Preview before download</span>
                    <h2>Optical Job Card</h2>
                </div>
                <button type="button" wire:click="closePrintPreview" class="so-close-btn" aria-label="Close"><i class="fas fa-times"></i></button>
            </header>

            <div class="so-modal-body">
                <div id="spectacle-job-preview-print" class="th-card">

                    {{-- Header --}}
                    <div class="th-header">
                        <div class="th-clinic">{{ strtoupper($appSettings->clinic_name ?? 'OPTICAL CLINIC') }}</div>
                        <div class="th-job-title">LAB JOB ORDER</div>
                        <div class="th-order-id">{{ $printOrder->order_id }}</div>
                    </div>

                    {{-- Patient info --}}
                    <div class="th-patient">
                        <div class="th-info-row"><span class="th-lbl">Patient:</span> <span class="th-val">{{ strtoupper($previewPatient?->name ?? '—') }}</span></div>
                        <div class="th-info-row"><span class="th-lbl">Phone:</span> <span class="th-val">{{ $previewPatient?->contact ?? '—' }}</span></div>
                        <div class="th-info-row"><span class="th-lbl">Order Date:</span> <span class="th-val">{{ $printOrder->created_at->format('d M, Y') }}</span></div>
                        <div class="th-info-row"><span class="th-lbl">Pickup Date:</span> <span class="th-val">{{ \Carbon\Carbon::parse($printOrder->pickUpDate)->format('d M, Y') }}</span></div>
                    </div>

                    {{-- Prescription --}}
                    <div class="th-section">PRESCRIPTION (Rx)</div>
                    <table class="th-table">
                        <thead>
                            <tr>
                                <th style="width:12%">EYE</th>
                                <th style="width:46%">Prescription</th>
                                <th style="width:22%">VA</th>
                                <th style="width:20%">ADD</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>OD</strong></td>
                                <td>{{ $previewRef->refractionOD ?? '—' }}</td>
                                <td>{{ $previewRef->refractionOD_distance_va ?? '—' }}</td>
                                <td>{{ $previewRef->refractionOD_ADD ?? '--' }}</td>
                            </tr>
                            <tr>
                                <td><strong>OS</strong></td>
                                <td>{{ $previewRef->refractionOS ?? '—' }}</td>
                                <td>{{ $previewRef->refractionOS_distance_va ?? '—' }}</td>
                                <td>{{ $previewRef->refractionOS_ADD ?? '--' }}</td>
                            </tr>
                        </tbody>
                    </table>

                    {{-- Specifications --}}
                    <div class="th-section">SPECIFICATIONS</div>
                    <div class="th-specs">
                        <div class="th-spec-row">
                            <span class="th-lbl">PD (mm):</span>
                            <span class="th-val">{{ $previewRef?->pd ?? 'N/S' }}</span>
                        </div>
                        <div class="th-spec-row">
                            <span class="th-lbl">Lens Type:</span>
                            <span class="th-val">{{ $previewRef?->lensType ?? 'Standard' }}</span>
                        </div>
                        <div class="th-spec-row">
                            <span class="th-lbl">Frame:</span>
                            @php
                                $thFrameName  = null;
                                $thFrameBatch = null;
                                // 0. Patient supplied the frame
                                if ($printOrder->own_frame) {
                                    $thFrameName = strtoupper($printOrder->frame_model_number ?: "Patient's own frame");
                                }
                                // 1. Linked frame product
                                if (!$thFrameName && $printOrder->frameProduct) {
                                    $thFrameName  = $printOrder->frameProduct->name;
                                    $thFrameBatch = $printOrder->frameProduct->batch_number;
                                }
                                // 2. Frame from POS sale items
                                if (!$thFrameName) {
                                    $saleItems = $printOrder->refraction?->consultation?->sale?->items ?? collect();
                                    $frameItem = $saleItems->first(fn($i) =>
                                        str_contains(strtolower((string) optional(optional($i->product)->category)->name), 'frame')
                                    );
                                    if ($frameItem) {
                                        $thFrameName  = $frameItem->product?->name;
                                        $thFrameBatch = $frameItem->product?->batch_number;
                                    }
                                }
                                // 3. Manually entered name (skip placeholder)
                                if (!$thFrameName) {
                                    $raw = trim($printOrder->frame_model_number ?? '');
                                    $thFrameName = ($raw && strtolower($raw) !== 'to be assigned') ? $raw : '—';
                                }
                            @endphp
                            <span class="th-val">{{ $thFrameName }}{{ $thFrameBatch ? ' (Batch: '.$thFrameBatch.')' : '' }}</span>
                        </div>
                        <div class="th-spec-row">
                            <span class="th-lbl">Dispensed By:</span>
                            <span class="th-val">{{ strtoupper($printOrder->user?->name ?? '—') }}</span>
                        </div>
                    </div>

                    {{-- Lab Notes --}}
                    <div class="th-section">LAB NOTES</div>
                    <div class="th-notes">
                        {{ $previewRef?->refractionnotes ?? '' }}
                    </div>

                    {{-- Footer --}}
                    <div class="th-footer">
                        @if($printOrder->own_frame)
                            <strong>PATIENT'S OWN FRAME - GLAZED AT OWNER'S RISK</strong><br>
                        @endif
                        <strong>VERIFY ALL MEASUREMENTS BEFORE SURFACING</strong><br>
                        Printed: {{ now()->format('d/m/Y H:i') }}
                    </div>

                </div>
            </div>

            <footer class="so-modal-footer">
                <button type="button" onclick="printThermalJobCard()" class="so-btn so-btn-light">
                    <i class="fas fa-print"></i>
                    Click to Print
                </button>
            </footer>
        </section>
    </div>
@endif

@if($showOrderModal)
    <div class="so-modal-backdrop" wire:click.self="closeOrderModal">
        <section class="so-modal" role="dialog" aria-modal="true" aria-label="Create spectacle order">
            <header class="so-modal-header">
                <div>
                    <span>Create order</span>
                    <h2>Spectacle Order</h2>
                </div>
                <button type="button" wire:click="closeOrderModal" class="so-close-btn" aria-label="Close"><i class="fas fa-times"></i></button>
            </header>

            <div class="so-modal-body">
                <form wire:submit="createOrder" class="so-order-form">
                    <div class="so-order-grid">
                        <div class="so-order-frame">
                            @if($orderPosProducts['frame'])
                                <label class="so-form-field"><span>Frame</span><input type="text" value="{{ $orderPosProducts['frame']->name }}" readonly><small class="so-field-hint">Sold at POS &mdash; stock already deducted.</small></label>
                            @else
                                <div class="so-order-frame-head">
                                    <span class="so-order-label">Frame @unless($ownFrame)<strong>*</strong>@endunless</span>
                                    <label class="so-own-frame-toggle">
                                        <input type="checkbox" wire:model.live="ownFrame">
                                        <span>Patient brings own frame</span>
                                    </label>
                                </div>
                                @if($ownFrame)
                                    <label class="so-form-field">
                                        <input type="text" wire:model="ownFrameDescription" maxlength="200" placeholder="Describe the frame, e.g. black metal Ray-Ban" aria-label="Own frame description">
                                        <small class="so-field-hint">No frame charge or stock. Glazed at owner's risk.</small>
                                        @error('ownFrameDescription')<small>{{ $message }}</small>@enderror
                                    </label>
                                @else
                                    <label class="so-form-field"><select wire:model.live="selectedFrameId" aria-label="Frame"><option value="">Select frame</option>@foreach($availableFrames as $frame)<option value="{{ $frame->id }}">{{ $frame->name }} ({{ $frame->made_to_order ? 'Made to order' : $frame->quantity }})</option>@endforeach</select>@error('selectedFrameId')<small>{{ $message }}</small>@enderror</label>
                                @endif
                            @endif
                        </div>
                        <div class="so-order-lens">
                            @if($orderPosProducts['lens'])
                                <label class="so-form-field"><span>Lens</span><input type="text" value="{{ $orderPosProducts['lens']->name }}" readonly title="{{ $orderPosProducts['lens']->name }}"><small class="so-field-hint">Sold at POS &mdash; stock already deducted.</small></label>
                            @else
                                <label class="so-form-field"><span>Lens <strong>*</strong></span><select wire:model.live="selectedLensId"><option value="">Select lens</option>@foreach($availableLenses as $lens)<option value="{{ $lens->id }}">{{ $lens->name }} ({{ $lens->made_to_order ? 'Made to order' : $lens->quantity }})</option>@endforeach</select>@error('selectedLensId')<small>{{ $message }}</small>@enderror</label>
                            @endif
                        </div>
                        <label class="so-form-field"><span>Lab</span><input type="text" wire:model="labName"></label>
                        <label class="so-form-field"><span>Lab reference</span><input type="text" wire:model="labReference"></label>
                        <label class="so-form-field"><span>Lab cost</span><input type="number" min="0" step="0.01" wire:model="labCost">@error('labCost')<small>{{ $message }}</small>@enderror</label>
                        <label class="so-form-field">
                            <span>Pickup date <strong>*</strong></span>
                            <input type="date" wire:model.live="pickUpDate" min="{{ date('Y-m-d') }}">
                            @error('pickUpDate')<small>{{ $message }}</small>@enderror
                        </label>
                        <label class="so-form-field so-order-notes">
                            <span>Notes</span>
                            <textarea wire:model="orderNotes" rows="2" placeholder="Optional notes"></textarea>
                            @error('orderNotes')<small>{{ $message }}</small>@enderror
                        </label>
                    </div>

                    <footer class="so-modal-footer no-pad">
                        <button type="button" wire:click="closeOrderModal" class="so-btn so-btn-light">Cancel</button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="createOrder" class="so-btn so-btn-primary">
                            <span wire:loading.remove wire:target="createOrder"><i class="fas fa-check"></i> Create Order</span>
                            <span wire:loading wire:target="createOrder"><i class="fas fa-circle-notch fa-spin"></i> Creating</span>
                        </button>
                    </footer>
                </form>
            </div>
        </section>
    </div>
@endif

@if($cancelConfirmId)
    <div class="so-modal-backdrop" wire:click.self="closeCancelConfirm">
        <section class="so-modal so-modal-sm" role="dialog" aria-modal="true" aria-label="Cancel spectacle order">
            <header class="so-modal-header danger">
                <div>
                    <span>Cancel order</span>
                    <h2>Restore stock and close order?</h2>
                </div>
                <button type="button" wire:click="closeCancelConfirm" class="so-close-btn" aria-label="Close"><i class="fas fa-times"></i></button>
            </header>
            <div class="so-modal-body">
                <label class="so-form-field">
                    <span>Reason</span>
                    <textarea wire:model="cancelReason" rows="3" placeholder="Optional cancellation reason"></textarea>
                </label>
            </div>
            <footer class="so-modal-footer">
                <button type="button" wire:click="closeCancelConfirm" class="so-btn so-btn-light">Keep Order</button>
                <button type="button" wire:click="confirmCancelOrder" class="so-btn so-btn-danger">
                    <i class="fas fa-ban"></i>
                    Cancel Order
                </button>
            </footer>
        </section>
    </div>
@endif

<style>
:root {
    --so-bg: #f4f7fb;
    --so-surface: #ffffff;
    --so-soft: #f8fafc;
    --so-border: #dbe4ee;
    --so-border-strong: #b8c7d9;
    --so-text: #122033;
    --so-muted: #66758a;
    --so-primary: #0f766e;
    --so-primary-dark: #0a5d57;
    --so-primary-soft: #e8fbf7;
    --so-blue: #2563eb;
    --so-lab: #6d5bd0;
    --so-warning: #c86a12;
    --so-success: #16803d;
    --so-danger: #c93434;
    --so-shadow: 0 16px 40px rgba(18, 32, 51, .08);
}

.so-page {
    max-width: 1420px;
    margin: 0 auto;
    padding: 24px;
    color: var(--so-text);
}

.so-topbar,
.so-filters,
.so-order-card,
.so-queue-panel,
.so-case-panel,
.so-bulk-panel,
.so-empty-state {
    background: var(--so-surface);
    border: 1px solid var(--so-border);
    border-radius: 8px;
    box-shadow: 0 1px 2px rgba(18, 32, 51, .04);
}

.so-topbar {
    display: flex;
    justify-content: space-between;
    gap: 18px;
    align-items: center;
    padding: 22px;
}

.so-kicker {
    text-transform: uppercase;
    letter-spacing: .08em;
    font-size: 11px;
    font-weight: 800;
    color: var(--so-primary);
}

.so-topbar h1 {
    margin: 3px 0 4px;
    font-size: 28px;
    line-height: 1.1;
    font-weight: 800;
    letter-spacing: 0;
}

.so-topbar p,
.so-muted {
    margin: 0;
    color: var(--so-muted);
    font-size: 14px;
}

.so-top-actions,
.so-filter-footer,
.so-chip-row,
.so-sort-row,
.so-bulk-panel,
.so-bulk-actions,
.so-contact-row,
.so-action-grid,
.so-modal-footer {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.so-btn,
.so-chip,
.so-link-btn,
.so-icon-btn {
    border: 1px solid transparent;
    border-radius: 6px;
    min-height: 38px;
    padding: 0 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: .16s ease;
    text-decoration: none;
    white-space: nowrap;
}

.so-btn-primary {
    background: var(--so-primary);
    color: #fff;
    border-color: var(--so-primary);
}

.so-btn-primary:hover { background: var(--so-primary-dark); color: #fff; }

.so-btn-light {
    background: #fff;
    color: var(--so-text);
    border-color: var(--so-border);
}

.so-btn-light:hover { background: var(--so-soft); color: var(--so-text); }

.so-btn.is-disabled,
.so-btn:disabled {
    opacity: .55;
    cursor: not-allowed;
}

.so-btn-danger {
    background: #fff5f5;
    color: var(--so-danger);
    border-color: #f3c7c7;
}

.so-btn-sm {
    min-height: 32px;
    padding: 0 10px;
    font-size: 12px;
}

.so-btn-wide { width: 100%; }

.so-metrics {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 16px 0;
    overflow-x: auto;
    padding: 3px;
    background: #eaf0f7;
    border: 1px solid var(--so-border);
    border-radius: 8px;
}

.so-metric {
    border: 1px solid transparent;
    background: transparent;
    border-radius: 6px;
    padding: 8px 12px;
    text-align: left;
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    min-width: max-content;
}

.so-metric.is-active {
    border-color: var(--so-primary);
    background: #fff;
    box-shadow: 0 1px 2px rgba(18, 32, 51, .08);
}

.so-metric-icon {
    width: 28px;
    height: 28px;
    border-radius: 6px;
    display: grid;
    place-items: center;
}

.so-metric-icon.neutral { background: #eef3f8; color: #334155; }
.so-metric-icon.warning { background: #fff4e8; color: var(--so-warning); }
.so-metric-icon.lab { background: #f0efff; color: var(--so-lab); }
.so-metric-icon.success { background: #eaf8ef; color: var(--so-success); }
.so-metric-icon.danger { background: #fff0f0; color: var(--so-danger); }

.so-metric-value {
    font-size: 16px;
    line-height: 1;
    font-weight: 850;
}

.so-metric-label {
    color: var(--so-muted);
    font-size: 12px;
    font-weight: 700;
}

.so-filters {
    padding: 16px;
    margin-bottom: 14px;
}

.so-filter-grid {
    display: grid;
    grid-template-columns: minmax(260px, 1.8fr) repeat(4, minmax(140px, 1fr));
    gap: 12px;
    align-items: end;
}

.so-search,
.so-field,
.so-form-field {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.so-search {
    justify-content: center;
    min-height: 62px;
    padding-top: 20px;
}

.so-search i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--so-muted);
    z-index: 1;
}

.so-search input {
    padding-left: 36px;
}

.so-search button {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    border: 0;
    background: transparent;
    color: var(--so-muted);
}

.so-field span,
.so-form-field span {
    font-size: 11px;
    font-weight: 800;
    color: var(--so-muted);
    text-transform: uppercase;
    letter-spacing: .04em;
    min-height: 14px;
}

.so-search input,
.so-field input,
.so-field select,
.so-bulk-actions select,
.so-form-field input,
.so-form-field select,
.so-form-field textarea {
    width: 100%;
    border: 1px solid var(--so-border);
    background: #fff;
    color: var(--so-text);
    border-radius: 6px;
    min-height: 40px;
    padding: 8px 10px;
    font-size: 14px;
}

.so-search input,
.so-field input,
.so-field select {
    height: 42px;
}

.so-form-field textarea {
    min-height: 90px;
    resize: vertical;
}

.so-form-field small {
    color: var(--so-danger);
    font-weight: 700;
}

/* Create-order modal: 4 columns — frame | lens, then lab details, then notes */
.so-order-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
}

.so-order-frame,
.so-order-lens {
    grid-column: span 2;
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}

.so-order-notes { grid-column: 1 / -1; }
.so-order-notes textarea { min-height: 58px; }

.so-order-frame-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    min-height: 14px;
}

.so-order-label {
    font-size: 11px;
    font-weight: 800;
    color: var(--so-muted);
    text-transform: uppercase;
    letter-spacing: .04em;
}

.so-order-label strong { color: var(--so-danger); }

.so-own-frame-toggle {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 0;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
}

.so-order-lens input[readonly] { text-overflow: ellipsis; }

@media (max-width: 720px) {
    .so-order-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 480px) {
    .so-order-grid { grid-template-columns: minmax(0, 1fr); }
    .so-order-frame,
    .so-order-lens { grid-column: 1 / -1; }
}

.so-form-field small.so-field-hint {
    color: var(--so-muted, #64748b);
    font-weight: 600;
}

.so-filter-footer {
    justify-content: space-between;
    margin-top: 12px;
    border-top: 1px solid var(--so-border);
    padding-top: 12px;
}

.so-chip {
    min-height: 30px;
    padding: 0 11px;
    background: var(--so-soft);
    color: var(--so-text);
    border-color: var(--so-border);
}

.so-chip.is-active {
    background: var(--so-primary-soft);
    color: var(--so-primary-dark);
    border-color: #99d8cf;
}

.so-chip-warn.is-active {
    background: #fff4e8;
    color: var(--so-warning);
    border-color: #f4c58f;
}

.so-link-btn {
    min-height: 30px;
    padding: 0;
    background: transparent;
    color: var(--so-muted);
}

.so-bulk-panel {
    justify-content: space-between;
    padding: 12px 14px;
    margin-bottom: 14px;
}

.so-check {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin: 0;
    font-size: 13px;
    font-weight: 700;
}

.so-check input,
.so-patient input {
    width: 16px;
    height: 16px;
}

.so-workbench {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(340px, 390px);
    gap: 14px;
    align-items: start;
}

.so-queue-panel {
    overflow: hidden;
}

.so-queue-main {
    display: grid;
    grid-template-columns: minmax(170px, 1.25fr) minmax(120px, .8fr) minmax(120px, .75fr) minmax(82px, .45fr);
    gap: 12px;
    align-items: center;
}

.so-queue-head {
    display: grid;
    grid-template-columns: minmax(170px, 1.25fr) minmax(120px, .8fr) minmax(120px, .75fr) minmax(82px, .45fr) 88px;
    gap: 12px;
    align-items: center;
    padding: 11px 14px;
    background: var(--so-soft);
    border-bottom: 1px solid var(--so-border);
    color: var(--so-muted);
    font-size: 11px;
    font-weight: 850;
    text-transform: uppercase;
    letter-spacing: .04em;
}

.so-queue-head span:last-child {
    text-align: right;
}

.so-queue-row {
    display: grid;
    grid-template-columns: 1fr auto;
    border-left: 4px solid var(--so-border-strong);
    border-bottom: 1px solid var(--so-border);
}

.so-queue-row:last-child {
    border-bottom: 0;
}

.so-queue-row.status-awaiting { border-left-color: #94a3b8; }
.so-queue-row.status-pending { border-left-color: var(--so-warning); }
.so-queue-row.status-lab { border-left-color: var(--so-lab); }
.so-queue-row.status-ready { border-left-color: var(--so-success); }
.so-queue-row.status-collected { border-left-color: var(--so-blue); }
.so-queue-row.status-cancelled { border-left-color: #9ca3af; }

.so-queue-row.is-active {
    background: #f3fbfa;
    box-shadow: inset 0 0 0 1px rgba(15, 118, 110, .28);
}

.so-queue-main {
    width: 100%;
    min-width: 0;
    border: 0;
    background: transparent;
    text-align: left;
    padding: 12px 14px;
    color: var(--so-text);
    cursor: pointer;
}

.so-queue-main:hover {
    background: #f9fbfd;
}

.so-queue-main strong,
.so-queue-main small {
    display: block;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.so-queue-main > span {
    min-width: 0;
}

.so-queue-main strong {
    font-size: 13px;
    font-weight: 850;
}

.so-queue-main small {
    margin-top: 3px;
    color: var(--so-muted);
    font-size: 11px;
    font-weight: 700;
}

.so-queue-patient {
    display: flex;
    align-items: center;
    gap: 9px;
    min-width: 0;
}

.so-queue-actions {
    width: 88px;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    padding: 0 12px 0 4px;
}

.so-queue-actions .so-btn-sm {
    width: 76px;
    padding: 0 8px;
}

.so-case-panel {
    position: sticky;
    top: 12px;
    padding: 16px;
    display: grid;
    gap: 16px;
}

.so-case-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--so-border);
}

.so-case-header h2 {
    margin: 0;
    font-size: 18px;
    line-height: 1.2;
    font-weight: 850;
}

.so-case-panel-empty {
    min-height: 260px;
    place-items: center;
    text-align: center;
    color: var(--so-muted);
}

.so-case-panel-empty i {
    font-size: 32px;
    color: var(--so-primary);
}

.so-list {
    display: grid;
    gap: 14px;
}

.so-order-card {
    overflow: hidden;
    border-left: 5px solid var(--so-border-strong);
}

.so-order-card.status-awaiting { border-left-color: #94a3b8; }
.so-order-card.status-pending { border-left-color: var(--so-warning); }
.so-order-card.status-lab { border-left-color: var(--so-lab); }
.so-order-card.status-ready { border-left-color: var(--so-success); }
.so-order-card.status-collected { border-left-color: var(--so-blue); }
.so-order-card.status-cancelled { border-left-color: #9ca3af; }

.so-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 18px;
    border-bottom: 1px solid var(--so-border);
    background: linear-gradient(180deg, #fff, #fbfdff);
}

.so-patient {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.so-no-check {
    width: 16px;
    height: 16px;
}

.so-avatar {
    width: 42px;
    height: 42px;
    border-radius: 8px;
    background: var(--so-primary-soft);
    color: var(--so-primary-dark);
    display: grid;
    place-items: center;
    font-weight: 850;
}

.so-avatar.sm {
    width: 34px;
    height: 34px;
    border-radius: 7px;
    font-size: 13px;
    flex: 0 0 auto;
}

.so-patient h2 {
    margin: 0;
    font-size: 18px;
    line-height: 1.15;
    font-weight: 800;
}

.so-patient-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 5px;
}

.so-patient-meta span,
.so-rx-tags span {
    border: 1px solid var(--so-border);
    background: var(--so-soft);
    color: var(--so-muted);
    border-radius: 999px;
    padding: 2px 8px;
    font-size: 11px;
    font-weight: 750;
}

.so-card-state {
    display: flex;
    align-items: flex-end;
    gap: 7px;
    flex-direction: column;
}

.so-card-state strong {
    font-family: Consolas, Monaco, monospace;
    color: var(--so-primary-dark);
    font-size: 12px;
}

.so-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border-radius: 999px;
    padding: 5px 10px;
    font-size: 12px;
    font-weight: 850;
    border: 1px solid var(--so-border);
    background: #fff;
    max-width: 100%;
}

.so-status-pill span {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: currentColor;
}

.so-status-pill.awaiting { color: #64748b; background: #f8fafc; }
.so-status-pill.pending { color: var(--so-warning); background: #fff8ef; }
.so-status-pill.lab { color: var(--so-lab); background: #f5f3ff; }
.so-status-pill.ready { color: var(--so-success); background: #f0fff4; }
.so-status-pill.collected { color: var(--so-blue); background: #eff6ff; }
.so-status-pill.cancelled { color: #6b7280; background: #f4f4f5; }

.so-card-grid {
    display: grid;
    grid-template-columns: minmax(320px, 1.2fr) minmax(280px, 1fr) minmax(260px, .85fr);
    gap: 14px;
    padding: 16px 18px;
}

.so-panel {
    min-width: 0;
}

.so-panel-title {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--so-text);
    font-size: 13px;
    font-weight: 850;
    margin-bottom: 10px;
}

.so-panel-title i {
    color: var(--so-primary);
}

.so-rx-table,
.so-job-rx {
    width: 100%;
    border-collapse: collapse;
    overflow: hidden;
    border-radius: 6px;
    border: 1px solid var(--so-border);
}

.so-rx-table th,
.so-rx-table td,
.so-job-rx th,
.so-job-rx td {
    padding: 8px 9px;
    border-bottom: 1px solid var(--so-border);
    font-size: 12px;
    text-align: left;
    vertical-align: top;
}

.so-rx-table th,
.so-job-rx th {
    background: var(--so-soft);
    color: var(--so-muted);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .04em;
}

.so-rx-table td:first-child,
.so-job-rx td:first-child {
    font-weight: 850;
    color: var(--so-primary-dark);
}

.so-rx-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 10px;
}

.so-detail-list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 9px;
}

.so-pos-track {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 9px;
    border: 1px solid var(--so-border);
    border-left: 4px solid #94a3b8;
    border-radius: 6px;
    background: var(--so-soft);
    padding: 9px;
    margin-bottom: 10px;
}

.so-pos-track--pending { border-left-color: var(--so-warning); background: #fff8ef; }
.so-pos-track--sold { border-left-color: var(--so-success); background: #f0fff4; }
.so-pos-track--partial { border-left-color: var(--so-blue); background: #eff6ff; }
.so-pos-track--none { border-left-color: #94a3b8; }

.so-pos-track span,
.so-pos-track small {
    display: block;
    color: var(--so-muted);
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .04em;
}

.so-pos-track strong {
    display: block;
    color: var(--so-text);
    font-size: 13px;
    overflow-wrap: anywhere;
}

.so-pos-track small {
    margin-top: 2px;
    text-transform: none;
    letter-spacing: 0;
}

.so-pos-items {
    grid-column: 1 / -1;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.so-pos-items span {
    border: 1px solid var(--so-border);
    background: #fff;
    border-radius: 999px;
    padding: 3px 8px;
    text-transform: none;
    letter-spacing: 0;
}

.so-detail-list div {
    background: var(--so-soft);
    border: 1px solid var(--so-border);
    border-radius: 6px;
    padding: 9px;
    min-width: 0;
}

.so-detail-wide {
    grid-column: 1 / -1;
}

.so-detail-list span,
.so-job-grid span,
.so-job-frame span {
    display: block;
    color: var(--so-muted);
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .04em;
    margin-bottom: 3px;
}

.so-detail-list strong,
.so-job-grid strong,
.so-job-frame strong {
    display: block;
    color: var(--so-text);
    font-size: 13px;
    overflow-wrap: anywhere;
}

.so-text-danger { color: var(--so-danger) !important; }
.so-text-success { color: var(--so-success) !important; }

.so-empty-order {
    border: 1px dashed var(--so-border-strong);
    border-radius: 6px;
    padding: 18px;
    color: var(--so-muted);
    text-align: center;
}

.so-empty-order i {
    color: var(--so-primary);
    margin-bottom: 8px;
}

.so-empty-order p { margin: 0; }

.so-progress-toggle {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 6px;
    padding: 5px;
    border: 1px solid var(--so-border);
    border-radius: 7px;
    background: var(--so-soft);
}

.so-progress-toggle button {
    border: 0;
    border-radius: 5px;
    min-height: 31px;
    background: transparent;
    color: var(--so-muted);
    font-size: 12px;
    font-weight: 800;
}

.so-progress-toggle button.is-active {
    background: #fff;
    color: var(--so-primary-dark);
    box-shadow: 0 1px 3px rgba(18, 32, 51, .1);
}

.so-contact-row {
    margin: 12px 0;
}

.so-icon-btn {
    width: 38px;
    height: 38px;
    padding: 0;
    color: var(--so-text);
    background: #fff;
    border-color: var(--so-border);
}

.so-icon-btn:hover {
    background: var(--so-primary-soft);
    color: var(--so-primary-dark);
    text-decoration: none;
}

.so-icon-btn.whatsapp {
    color: #17843f;
}

.so-icon-btn.is-disabled {
    opacity: .4;
    pointer-events: none;
}

.so-action-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.so-notes,
.so-clinical-note {
    border-top: 1px solid var(--so-border);
    padding: 10px 18px 14px;
}

.so-notes summary {
    cursor: pointer;
    color: var(--so-muted);
    font-size: 12px;
    font-weight: 800;
}

.so-notes div {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 8px;
}

.so-notes span {
    background: #fff;
    border: 1px solid var(--so-border);
    border-radius: 999px;
    color: var(--so-muted);
    padding: 4px 8px;
    font-size: 12px;
}

.so-clinical-note {
    color: var(--so-muted);
    font-size: 13px;
}

.so-empty-state {
    text-align: center;
    padding: 48px 20px;
}

.so-empty-state.compact {
    box-shadow: none;
    border: 1px dashed var(--so-border-strong);
}

.so-empty-state i {
    font-size: 34px;
    color: var(--so-primary);
    margin-bottom: 12px;
}

.so-empty-state h2 {
    margin: 0 0 6px;
    font-size: 20px;
    font-weight: 850;
}

.so-empty-state p {
    margin: 0 0 15px;
    color: var(--so-muted);
}

.so-pagination {
    margin-top: 16px;
}

.so-toast {
    position: fixed;
    top: 18px;
    right: 22px;
    z-index: 20000;
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 280px;
    max-width: 520px;
    padding: 12px 14px;
    border-radius: 8px;
    color: #fff;
    box-shadow: var(--so-shadow);
    font-weight: 750;
}

.so-toast button {
    margin-left: auto;
    border: 0;
    background: transparent;
    color: inherit;
}

.so-toast-ok { background: var(--so-success); }
.so-toast-error { background: var(--so-danger); }

.so-modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 15000;
    background: rgba(15, 23, 42, .58);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 22px;
}

.so-modal {
    width: min(760px, 100%);
    max-height: calc(100vh - 44px);
    overflow: auto;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 26px 80px rgba(0, 0, 0, .26);
}

.so-modal-sm { width: min(520px, 100%); }
.so-modal-lg { width: min(940px, 100%); }
.so-modal-xl { width: min(1040px, 100%); }

.so-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 18px 20px;
    border-bottom: 1px solid var(--so-border);
}

.so-modal-header.danger {
    background: #fff5f5;
}

.so-modal-header span {
    display: block;
    color: var(--so-muted);
    font-size: 11px;
    text-transform: uppercase;
    font-weight: 850;
    letter-spacing: .06em;
}

.so-modal-header h2 {
    margin: 2px 0 0;
    font-size: 20px;
    font-weight: 850;
}

.so-close-btn {
    width: 36px;
    height: 36px;
    border-radius: 6px;
    border: 1px solid var(--so-border);
    background: #fff;
    color: var(--so-muted);
}

.so-modal-body {
    padding: 20px;
}

.so-modal-footer {
    justify-content: flex-end;
    padding: 14px 20px;
    border-top: 1px solid var(--so-border);
}

.so-modal-footer.no-pad {
    padding: 0;
    border-top: 0;
    margin-top: 16px;
}

.so-modal-patient {
    display: flex;
    align-items: center;
    gap: 12px;
    background: var(--so-soft);
    border: 1px solid var(--so-border);
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 16px;
}

.so-modal-patient h3 {
    margin: 0;
    font-size: 17px;
    font-weight: 850;
}

.so-modal-patient p {
    margin: 3px 0 0;
    color: var(--so-muted);
    font-size: 13px;
}

.so-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 14px;
}

.so-form-grid-simple {
    grid-template-columns: 1fr;
}

.span-2 { grid-column: span 2; }
.span-3 { grid-column: span 3; }

.so-form-field strong {
    color: var(--so-danger);
}

.so-results {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    z-index: 5;
    max-height: 260px;
    overflow: auto;
    border: 1px solid var(--so-border);
    border-radius: 7px;
    background: #fff;
    box-shadow: var(--so-shadow);
}

.so-results button {
    width: 100%;
    border: 0;
    border-bottom: 1px solid var(--so-border);
    background: #fff;
    padding: 10px 12px;
    text-align: left;
    display: flex;
    justify-content: space-between;
    gap: 10px;
}

.so-results button:hover {
    background: var(--so-soft);
}

.so-results p {
    margin: 0;
    padding: 12px;
    color: var(--so-muted);
}

.so-cost-box {
    display: flex;
    align-items: center;
    justify-content: space-between;
    border: 1px solid #99d8cf;
    background: var(--so-primary-soft);
    color: var(--so-primary-dark);
    border-radius: 8px;
    padding: 13px 15px;
    margin-top: 16px;
}

.so-cost-box span {
    font-weight: 750;
}

.so-cost-box strong {
    font-size: 20px;
}

/* ---- Thermal job card (screen preview + print) ---- */
.th-card {
    width: 302px; /* ≈ 80mm for screen preview */
    max-width: 100%;
    margin: 0 auto;
    font-family: Arial, sans-serif;
    font-size: 9px;
    line-height: 1.4;
    color: #000;
    background: #fff;
    border: 1px solid #ccc;
    padding: 8px;
}


.th-header {
    background: #000;
    color: #fff;
    text-align: center;
    padding: 8px 6px;
    margin-bottom: 8px;
}

.th-clinic {
    font-size: 13px;
    font-weight: 900;
    letter-spacing: 2px;
    margin-bottom: 2px;
}

.th-job-title {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1px;
    margin-bottom: 3px;
}

.th-order-id {
    font-size: 10px;
    font-weight: 900;
}

.th-patient {
    font-size: 8px;
    padding-bottom: 6px;
    margin-bottom: 6px;
    border-bottom: 1px dashed #000;
}

.th-info-row {
    margin: 3px 0;
}

.th-lbl {
    font-weight: 700;
}

.th-val {
    font-weight: 900;
    margin-left: 2px;
}

.th-section {
    background: #000;
    color: #fff;
    text-align: center;
    padding: 4px 6px;
    font-size: 8px;
    font-weight: 900;
    letter-spacing: 1px;
    margin: 8px 0 5px;
    text-transform: uppercase;
}

.th-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 8px;
    margin-bottom: 4px;
}

.th-table th,
.th-table td {
    border: 1px solid #000;
    padding: 3px 3px;
    text-align: left;
    vertical-align: middle;
}

.th-table th {
    background: #000;
    color: #fff;
    font-weight: 900;
    font-size: 7.5px;
}

.th-specs {
    font-size: 8px;
    margin-bottom: 4px;
}

.th-spec-row {
    padding: 4px 0;
    border-bottom: 1px solid #ccc;
}

.th-spec-row:last-child {
    border-bottom: none;
}

.th-notes {
    border: 1px solid #000;
    padding: 6px;
    min-height: 40px;
    font-size: 8px;
    margin-bottom: 8px;
    background: #fff;
}

.th-footer {
    border-top: 1px dashed #000;
    padding-top: 6px;
    text-align: center;
    font-size: 7px;
    font-style: italic;
}

@media (max-width: 1180px) {
    .so-workbench { grid-template-columns: 1fr; }
    .so-case-panel { position: static; }
    .so-filter-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .so-search { grid-column: span 3; }
    .so-card-grid { grid-template-columns: 1fr; }
    .so-actions-panel { border-top: 1px solid var(--so-border); padding-top: 12px; }
}

@media (max-width: 760px) {
    .so-page { padding: 14px; }
    .so-topbar,
    .so-card-header {
        align-items: flex-start;
        flex-direction: column;
    }
    .so-card-state {
        align-items: flex-start;
    }
    .so-filter-grid,
    .so-detail-list,
    .so-form-grid,
    .so-job-grid,
    .so-job-signatures {
        grid-template-columns: 1fr;
    }
    .so-search,
    .span-2,
    .span-3 {
        grid-column: span 1;
    }
    .so-rx-table {
        min-width: 560px;
    }
    .so-panel:first-child {
        overflow-x: auto;
    }
    .so-action-grid {
        grid-template-columns: 1fr;
    }
    .so-queue-head {
        display: none;
    }
    .so-queue-row {
        grid-template-columns: 1fr;
    }
    .so-queue-main {
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }
    .so-queue-patient {
        grid-column: 1 / -1;
    }
    .so-queue-actions {
        width: 100%;
        justify-content: flex-start;
        padding: 0 14px 12px;
    }
    .so-modal-backdrop {
        padding: 10px;
        align-items: flex-start;
    }
}

/* ── Layout fixes ─────────────────────────────────────────────────────────
   The search text clears its icon, the date picker fits its column, and the
   queue fits beside the case panel at any width (long text ends in "…";
   when the queue is narrow the POS column steps aside: the case panel shows it). */
.so-filter-grid .so-search input { padding-left: 36px; }
.so-search i { top: calc(50% + 10px); }
.so-field .drp,
.so-field .drp-trigger { width: 100%; min-width: 0; }
.so-field .drp-trigger { height: 42px; }

.so-queue-panel { container-type: inline-size; container-name: soqueue; }
.so-queue-main { grid-template-columns: minmax(0, 1.3fr) minmax(0, .85fr) minmax(0, .9fr) minmax(78px, .5fr); }
.so-queue-head { grid-template-columns: minmax(0, 1.3fr) minmax(0, .85fr) minmax(0, .9fr) minmax(78px, .5fr) 88px; }
.so-queue-head span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.so-queue-patient > span:last-child { min-width: 0; flex: 1 1 auto; }
.so-status-pill { overflow: hidden; white-space: nowrap; }

@container soqueue (max-width: 640px) {
    .so-q-pos { display: none !important; }
    .so-queue-main { grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr) minmax(78px, .6fr); }
    .so-queue-head { grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr) minmax(78px, .6fr) 88px; }
}
</style>

<script>
window.addEventListener('auto-print-job-card', function () {
    // Wait one tick for Livewire to paint the hidden DOM, then print and close
    setTimeout(function () {
        printThermalJobCard();

        // Close the Livewire preview (find component via the rendered element's wire:id)
        var el = document.querySelector('[wire\\:id]');
        if (el && window.Livewire) {
            Livewire.find(el.getAttribute('wire:id')).call('closePrintPreview');
        }
    }, 80);
});

function printThermalJobCard() {
    var content = document.getElementById('spectacle-job-preview-print');
    if (!content) return;

    var win = window.open('', '_blank', 'width=400,height=700');
    win.document.write('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Job Card</title><style>');
    win.document.write('@page{size:80mm auto;margin:4mm;}');
    win.document.write('*{margin:0;padding:0;box-sizing:border-box;}');
    win.document.write('body{width:80mm;font-family:Arial,sans-serif;font-size:9px;line-height:1.4;color:#000;background:#fff;}');
    win.document.write('.th-card{width:100%;border:none;padding:0;}');
    win.document.write('.th-header{background:#000;color:#fff;text-align:center;padding:8px 6px;margin-bottom:8px;}');
    win.document.write('.th-clinic{font-size:13px;font-weight:900;letter-spacing:2px;margin-bottom:2px;}');
    win.document.write('.th-job-title{font-size:10px;font-weight:700;letter-spacing:1px;margin-bottom:3px;}');
    win.document.write('.th-order-id{font-size:10px;font-weight:900;}');
    win.document.write('.th-patient{font-size:8px;padding-bottom:6px;margin-bottom:6px;border-bottom:1px dashed #000;}');
    win.document.write('.th-info-row{margin:3px 0;}');
    win.document.write('.th-lbl{font-weight:700;}');
    win.document.write('.th-val{font-weight:900;margin-left:2px;}');
    win.document.write('.th-section{background:#000;color:#fff;text-align:center;padding:4px 6px;font-size:8px;font-weight:900;letter-spacing:1px;margin:8px 0 5px;text-transform:uppercase;-webkit-print-color-adjust:exact;print-color-adjust:exact;}');
    win.document.write('.th-table{width:100%;border-collapse:collapse;font-size:8px;margin-bottom:4px;}');
    win.document.write('.th-table th,.th-table td{border:1px solid #000;padding:3px;text-align:left;vertical-align:middle;}');
    win.document.write('.th-table th{background:#000;color:#fff;font-weight:900;font-size:7.5px;-webkit-print-color-adjust:exact;print-color-adjust:exact;}');
    win.document.write('.th-specs{font-size:8px;margin-bottom:4px;}');
    win.document.write('.th-spec-row{padding:4px 0;border-bottom:1px solid #ccc;}');
    win.document.write('.th-spec-row:last-child{border-bottom:none;}');
    win.document.write('.th-notes{border:1px solid #000;padding:6px;min-height:40px;font-size:8px;margin-bottom:8px;}');
    win.document.write('.th-footer{border-top:1px dashed #000;padding-top:6px;text-align:center;font-size:7px;font-style:italic;}');
    // Split: NativePHP injects a script before every closing head tag, even one inside a string.
    win.document.write('</style></' + 'head><body>');
    win.document.write(content.innerHTML);
    win.document.write('</body></html>');
    win.document.close();

    win.onload = function() {
        setTimeout(function() {
            win.focus();
            win.print();
        }, 300);
    };
    win.onafterprint = function() { win.close(); };
}
</script>
</div>
