<div>
@include('livewire.optical.partials.order-ui')
<style>
/* Thermal job card is printed via a dedicated popup window — no @media print needed here */
#spectacle-job-preview-print { display: block; }
</style>

@php
    $statuses = [
        'Pending' => ['label' => 'Pending', 'class' => 'pending'],
        'In Lab' => ['label' => 'In lab', 'class' => 'lab'],
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
            <x-guide-tour page="clinic.spectacles" :context="['send' => (bool) $opticalTargets]" />
            @if($canOpenOpticalOrders)
            <a href="{{ route('optical.orders.create') }}" class="so-btn text-white" style="background:#087e83;">
                <i class="fas fa-glasses"></i>
                Start Optical Order
            </a>
            @else
            {{-- No optical shop on this plan: orders start from a refraction, so the button opens that list. --}}
            <button type="button" wire:click="setRecordType('refractions')" class="so-btn text-white" style="background:#087e83;" title="Pick the patient's refraction to start the order">
                <i class="fas fa-glasses"></i>
                Start Optical Order
            </button>
            @endif
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

    {{-- One bar for every status filter: the refractions still to order, every order by its status, and the two warnings. --}}
    <section class="so-metrics" aria-label="Filter by status" data-tour="sp-status">
        <button type="button" wire:click="setRecordType('refractions')" aria-pressed="{{ $recordType === 'refractions' ? 'true' : 'false' }}" class="so-metric {{ $recordType === 'refractions' ? 'is-active' : '' }}" title="Refractions marked for glasses with no order yet">
            <span class="so-metric-value">{{ $stats['pending'] }}</span>
            <span class="so-metric-label">To order</span>
        </button>
        <div class="so-metric-group" role="group" aria-label="Orders by status">
            @php
                $segments = ['' => ['Orders', $stats['orders'], 'neutral', 'fa-list-ul'], 'Pending' => ['Pending', $stats['ordered'], 'warning', 'fa-receipt'],
                    'In Lab' => ['In lab', $stats['in_lab'], 'lab', 'fa-flask'], 'Ready' => ['Ready', $stats['ready'], 'success', 'fa-check'],
                    'Collected' => ['Collected', $stats['collected'], 'collected', 'fa-box-open'], 'Cancelled' => ['Cancelled', $stats['cancelled'], 'cancelled', 'fa-ban']];
            @endphp
            @foreach($segments as $value => [$label, $count, $tone, $icon])
                @php $on = $recordType === 'orders' && !$quickFilter && $statusFilter === $value; @endphp
                <button type="button" wire:click="setStatusFilter('{{ $value }}')" aria-pressed="{{ $on ? 'true' : 'false' }}" class="so-metric tone-{{ $tone }} {{ $on ? 'is-active' : '' }}">
                    {{-- Each status in its own colour (the count), the same as the pills in the list; no icons, so the bar is one line. --}}
                    <span class="so-metric-value">{{ $count }}</span>
                    <span class="so-metric-label">{{ $label }}</span>
                </button>
            @endforeach
        </div>
        <div class="so-metric-alerts" data-tour="sp-alerts">
            @foreach(['overdue' => ['Pickup overdue', 'fa-clock', 'Ready for more than 7 days'], 'renewal_due' => ['Renewal due', 'fa-redo', 'Collected glasses due for renewal within 30 days']] as $key => [$label, $icon, $hint])
                <button type="button" wire:click="setQuickFilter('{{ $key }}')" aria-pressed="{{ $quickFilter === $key ? 'true' : 'false' }}" title="{{ $hint }}"
                        class="so-metric so-metric-alert {{ $stats[$key] > 0 ? 'has-items' : '' }} {{ $quickFilter === $key ? 'is-active' : '' }}">
                    <span class="so-metric-value">{{ $stats[$key] }}</span>
                    <span class="so-metric-label">{{ $label }}</span>
                </button>
            @endforeach
        </div>
    </section>
    <section class="so-filters" data-tour="sp-filters">
        <div class="so-filter-grid">
            <label class="so-search">
                <i class="fas fa-search"></i>
                <input aria-label="Search" type="search" wire:model.live.debounce.400ms="searchTerm" placeholder="Search patient, folder no., order ID, frame">
                @if($searchTerm)
                    <button type="button" wire:click="$set('searchTerm', '')" aria-label="Clear search"><i class="fas fa-times"></i></button>
                @endif
            </label>

            <div class="so-field"><span>Dates</span><x-date-range from="fromDate" to="toDate" presets="activity" /></div>


            @if(count($doctors) > 0)
                {{-- Type to find a doctor or lab; picking one filters at once. --}}
                <x-ui.search-select class="so-field" bare live label="Doctor" name="doctorFilter" id="so-doctor-filter" placeholder="All doctors"
                    :options="['' => 'All doctors'] + $doctors->pluck('name', 'id')->all()" />
            @endif

            @if(count($labs) > 0)
                <x-ui.search-select class="so-field" bare live label="Lab" name="labFilter" id="so-lab-filter" placeholder="All labs"
                    :options="['' => 'All labs'] + collect($labs)->mapWithKeys(fn ($lab) => [$lab => $lab])->all()" />
            @endif
            <div class="so-field">
                <span>Sort by</span>
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
        </div>
    </section>

    @if($spectacles->total() > 0)
        {{-- Tick rows in the list; the actions here fit the list shown (To order: create or send; orders: move status). --}}
        @php $pickedCount = count($picked); @endphp
        <section class="so-bulk-panel" aria-label="Bulk actions">
            <span class="so-muted">{{ $spectacles->total() }} {{ $recordType === 'orders' ? \Illuminate\Support\Str::plural('spectacle order', $spectacles->total()) : \Illuminate\Support\Str::plural('refraction', $spectacles->total()).' to order' }} found after filters</span>
            @if($pickedCount > 0)
                <div class="so-bulk-actions">
                    <strong>{{ $pickedCount }} ticked</strong>
                    @if($pickedCount < $spectacles->total())<button type="button" wire:click="pickAllMatching" class="so-link-btn">Tick all {{ min($spectacles->total(), 500) }}</button>@endif
                    @if($recordType === 'refractions')
                        <label class="so-bulk-date">Pickup <input type="date" wire:model="bulkPickUpDate" min="{{ now()->toDateString() }}" aria-label="Pickup date for the new orders"></label>
                        <button type="button" wire:click="bulkCreateOrders" wire:loading.attr="disabled" class="so-btn so-btn-primary so-btn-sm" title="Only patients whose frame and lens are on the POS sale"><i class="fas fa-plus"></i> Create orders</button>
                        @if($opticalTargets)<button type="button" wire:click="openBulkSend" class="so-btn so-btn-sm"><i class="fas fa-paper-plane"></i> Send to optical centre</button>@endif
                    @else
                        <select wire:model="bulkStatus" aria-label="Move the ticked orders to">
                            <option value="">Move to status</option>
                            <option value="Pending">Pending</option>
                            <option value="In Lab">In lab</option>
                            <option value="Ready">Ready</option>
                            <option value="Collected">Collected</option>
                        </select>
                        <button type="button" wire:click="bulkUpdateStatus" wire:loading.attr="disabled" class="so-btn so-btn-primary so-btn-sm">Apply</button>
                    @endif
                    <button type="button" wire:click="clearSelection" class="so-btn so-btn-light so-btn-sm">Clear</button>
                </div>
            @endif
            @error('bulkPickUpDate')<span class="so-bulk-error">{{ $message }}</span>@enderror
        </section>
        @if($bulkSendOpen)
            {{-- One optical centre for every ticked prescription; each still needs its POS payment. --}}
            <dialog wire:ignore.self class="so-print-dialog" aria-label="Send ticked to an optical centre" x-data x-init="$el.showModal()"
                    x-on:cancel.prevent="$wire.set('bulkSendOpen', false)" x-on:click.self="$wire.set('bulkSendOpen', false)">
                <form wire:submit="bulkSend" class="so-modal so-modal-sm">
                    <header class="so-modal-header">
                        <div><span>{{ count($picked) }} ticked</span><h2>Send to Optical Centre</h2></div>
                        <button type="button" wire:click="$set('bulkSendOpen', false)" class="so-close-btn" aria-label="Close"><i class="fas fa-times"></i></button>
                    </header>
                    <div class="so-modal-body space-y-3">
                        <p class="text-xs text-slate-500 !mb-0">Each patient is sent on their own, with only their name, phone and prescription. Any not paid at POS, already ordered or already sent are skipped and listed.</p>
                        <x-ui.search-select label="Optical centre" name="bulkPartnerId" id="so-bulk-partner" placeholder="Type a centre's name…" :required="true"
                            :options="$bulkTargets->mapWithKeys(fn ($p) => [$p->id => $p->clinic?->name])->all()" />
                        <x-ui.field label="Frame" name="bulkFrameChoice" wire:model="bulkFrameChoice" :options="\Illuminate\Support\Arr::except(\App\Models\OpticalOrderRequest::FRAME_CHOICES, ['note'])" />
                        <x-ui.field label="Urgency" name="bulkUrgency" wire:model="bulkUrgency" :options="['normal' => 'Normal', 'urgent' => 'Urgent']" />
                        <x-ui.field label="Notes for every job" name="bulkNotes" type="textarea" rows="2" wire:model="bulkNotes" />
                    </div>
                    <footer class="so-modal-footer">
                        <button type="button" wire:click="$set('bulkSendOpen', false)" class="so-btn so-btn-light">Cancel</button>
                        <button type="submit" wire:loading.attr="disabled" class="so-btn so-btn-primary"><i class="fas fa-paper-plane"></i> Send {{ count($picked) }}</button>
                    </footer>
                </form>
            </dialog>
        @endif
        @if($bulkSkipped)
            <section class="so-bulk-skipped" role="status">
                <strong>Not done ({{ count($bulkSkipped) }}):</strong>
                <ul>@foreach($bulkSkipped as $skip)<li>{{ $skip['name'] }}: {{ $skip['reason'] }}</li>@endforeach</ul>
                <button type="button" wire:click="$set('bulkSkipped', [])" class="so-link-btn">Dismiss</button>
            </section>
        @endif
    @endif

    @php
        $queueRefractions = $spectacles->getCollection()->filter(fn($row) => $row->consultation && $row->consultation->patient);
    @endphp

    <section class="so-workbench" aria-label="Spectacle work queue">
        <div class="so-queue-panel" data-tour="sp-list">
            <div class="so-queue-head">
                @php $pageIds = $queueRefractions->pluck('id')->all(); $pageAllPicked = $pageIds && !array_diff($pageIds, $picked); @endphp
                <label class="so-pick" title="Tick every row on this page" data-tour="sp-tick"><input type="checkbox" wire:click="togglePickPage" @checked($pageAllPicked) aria-label="Tick every row on this page"></label>
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
                    // To order lists patients whose last order was cancelled: that order is history, not this row's order.
                    $lastCancelled = $recordType === 'refractions' && $order?->status === 'Cancelled' ? $order : null;
                    if ($lastCancelled) $order = null;
                    $posSummary = $this->posOrderSummary($refraction);
                    $status = $order?->status;
                    $statusMeta = $status ? ($statuses[$status] ?? ['label' => $status, 'class' => 'neutral']) : ['label' => 'To order', 'class' => 'awaiting'];
                    $isPickupLate = $order && $order->status === 'Ready' && $order->updated_at && $order->updated_at->diffInDays(now()) >= 7;
                    $canCreateOrder = $this->canCreateOrderFromPos($posSummary);
                    $isActiveRow = optional($activeRefraction)->id === $refraction->id;
                    $dispensingIssues = $this->dispensingIssues($refraction);
                @endphp

                <article class="so-queue-row status-{{ $statusMeta['class'] }} {{ $isActiveRow ? 'is-active' : '' }} {{ in_array($refraction->id, $picked, true) ? 'is-picked' : '' }}" wire:key="row-{{ $refraction->id }}">
                    <label class="so-pick"><input type="checkbox" wire:click="togglePick({{ $refraction->id }})" @checked(in_array($refraction->id, $picked, true)) aria-label="Tick {{ $patient->name }}"></label>
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
                        <span class="so-q-pos pos-{{ $posSummary['class'] }}">
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
                            @php $rowSent = $order ? null : ($sentRequests[$refraction->id] ?? null); @endphp
                            @if($rowSent && in_array($rowSent->status, \App\Models\OpticalOrderRequest::ACTIVE, true))
                                <small>Next: with {{ $rowSent->opticalClinic?->name ?? 'the optical centre' }}</small>
                            @else
                                <small>Next: {{ $this->nextRequiredAction($order) }}</small>
                                @if($lastCancelled)<small class="text-red-700">Order {{ $lastCancelled->order_id }} was cancelled</small>@endif
                            @endif
                            @if(count($dispensingIssues))<small class="text-red-700">{{ implode(', ', $dispensingIssues) }}</small>@endif
                        </span>
                        <span>
                            {{-- A pickup date exists only once the order is made; the small line is when the eyes were tested. --}}
                            @if($order)<strong>{{ \Carbon\Carbon::parse($order->pickUpDate)->format('d M Y') }}</strong>@else<strong class="text-slate-400" title="Set when the order is created">Not set</strong>@endif
                            <small title="Date of the refraction">Tested {{ $refraction->created_at?->format('d M Y') }}</small>
                        </span>
                    </button>

                    <div class="so-queue-actions">
                        @if($order)
                            <button type="button" wire:click="openPrintPreview({{ $order->id }})" class="so-icon-btn" title="Preview and print job card" aria-label="Preview and print job card">
                                <i class="fas fa-print"></i>
                            </button>
                        @elseif(($sent = $sentRequests[$refraction->id] ?? null) && in_array($sent->status, \App\Models\OpticalOrderRequest::ACTIVE, true))
                            <a wire:navigate href="{{ route('secretary.optical-sent') }}" class="so-btn so-btn-sm" title="Sent to {{ $sent->opticalClinic?->name }}">
                                <i class="fas fa-paper-plane"></i>
                                {{ match($sent->status) { 'query' => 'Question', 'quoted' => 'Quote', default => 'Sent' } }}
                            </a>
                        @else
                            <button type="button"
                                    wire:click="openOrderModal({{ $refraction->id }})"
                                    class="so-btn so-btn-primary so-btn-sm {{ $canCreateOrder ? '' : 'is-disabled' }}"
                                    {{ !$canCreateOrder ? 'disabled' : '' }}>
                                <i class="fas fa-plus"></i>
                                Create
                            </button>
                            @if($opticalTargets)
                                <button type="button" data-tour="send-to-optical"
                                        x-on:click="$dispatch('send-to-optical', { refractionId: {{ $refraction->id }} })"
                                        class="so-btn so-btn-sm {{ $canCreateOrder ? '' : 'is-disabled' }}" title="Send to an optical centre"
                                        {{ !$canCreateOrder ? 'disabled' : '' }}>
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                            @endif
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
                $activeLastCancelled = $activeOrder?->status === 'Cancelled' && $recordType === 'refractions' ? $activeOrder : null;
                if ($activeLastCancelled) $activeOrder = null;
                $activePosSummary = $this->posOrderSummary($activeRefraction);
                $activeStatus = $activeOrder?->status;
                $activeStatusMeta = $activeStatus ? ($statuses[$activeStatus] ?? ['label' => $activeStatus, 'class' => 'neutral']) : ['label' => 'To order', 'class' => 'awaiting'];
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

            {{-- The case opens as a side drawer, like Partner Clinics: the overlay, the close button and Esc shut it in the browser. --}}
            <div class="oo-overlay" data-sheet-overlay x-data x-on:click="dismissLocal($el.nextElementSibling, $wire, { activeRefractionId: null })" aria-hidden="true"></div>
            <aside class="oo-drawer so-case-drawer" role="dialog" aria-modal="true" aria-labelledby="so-case-title" tabindex="-1" wire:key="so-drawer-{{ $activeRefraction->id }}"
                   x-data x-init="$el.focus()"
                   x-on:keydown.escape.window="! $wire.showOrderModal && ! $wire.showPrintPreview && ! $wire.cancelConfirmId && ! document.querySelector('dialog[open]') && dismissLocal($el, $wire, { activeRefractionId: null })">
            <div class="so-case-panel" aria-label="Selected spectacle order">
                <header class="so-case-header">
                    <div class="so-patient">
                        <div class="so-avatar">{{ strtoupper(substr($activePatient->name, 0, 1)) }}</div>
                        <div>
                            <h2 id="so-case-title">{{ $activePatient->name }}</h2>
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
                    <div class="so-case-header-end">
                        <span class="so-status-pill {{ $activeStatusMeta['class'] }}">
                            <span></span>
                            {{ $activeStatusMeta['label'] }}
                        </span>
                        <button type="button" class="oo-x" x-on:click="dismissLocal($el, $wire, { activeRefractionId: null })" aria-label="Close">&times;</button>
                    </div>
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
                        @if(\App\Support\Access\Access::can('spectacles.override'))
                            <label class="so-form-field mt-2"><span>Override reason (required only to skip a stage)</span><input aria-label="Reason for authorized override" type="text" wire:model="statusOverrideReason" placeholder="Reason for authorized override"></label>
                        @endif

                        <div class="so-contact-row">
                            <a class="so-icon-btn {{ !$activeCleanPhone ? 'is-disabled' : '' }}" href="{{ $activeCleanPhone ? 'tel:' . $activeCleanPhone : '#' }}" title="Call patient"><i class="fas fa-phone"></i></a>
                            <a class="so-icon-btn {{ !$activeCleanPhone ? 'is-disabled' : '' }}" href="{{ $activeSmsUrl }}" title="Send SMS"><i class="fas fa-sms"></i></a>
                            <a class="so-icon-btn whatsapp {{ !$activeCleanPhone ? 'is-disabled' : '' }}" href="{{ $activeWhatsAppUrl }}" target="_blank" rel="noopener" title="Send WhatsApp message"><i class="fab fa-whatsapp"></i></a>
                            <button type="button" wire:click="sendReadyReminder({{ $activeOrder->id }})" class="so-icon-btn" title="Log reminder"><i class="fas fa-bell"></i></button>
                        </div>

                        <div class="so-action-grid">
                            <button type="button" wire:click="openPrintPreview({{ $activeOrder->id }})" class="so-btn so-btn-primary so-btn-sm"><i class="fas fa-print"></i> Job Card</button>
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
                            <p>@if($activeLastCancelled)Order {{ $activeLastCancelled->order_id }} was cancelled. Order again here or send it to an optical centre.@else No spectacle order has been created for this refraction.@endif</p>
                        </div>
                        @php $activeSent = $sentRequests[$activeRefraction->id] ?? null; @endphp
                        @if($activeSent && in_array($activeSent->status, \App\Models\OpticalOrderRequest::ACTIVE, true))
                            <p class="so-muted mb-2"><i class="fas fa-paper-plane mr-1"></i>Sent to <b>{{ $activeSent->opticalClinic?->name }}</b> on {{ $activeSent->created_at->format('d M Y') }} · {{ $activeSent->progressLabel() }}</p>
                            <a wire:navigate href="{{ route('secretary.optical-sent') }}" class="so-btn so-btn-wide">Follow it in Sent to Optical</a>
                        @else
                        <button type="button"
                                wire:click="openOrderModal({{ $activeRefraction->id }})"
                                class="so-btn so-btn-primary so-btn-wide {{ $activeCanCreateOrder ? '' : 'is-disabled' }}"
                                {{ !$activeCanCreateOrder ? 'disabled' : '' }}>
                            <i class="fas fa-plus"></i>
                            Create Order
                        </button>
                        @if($opticalTargets)
                            <button type="button"
                                    x-on:click="$dispatch('send-to-optical', { refractionId: {{ $activeRefraction->id }} })"
                                    class="so-btn so-btn-wide mt-2 {{ $activeCanCreateOrder ? '' : 'is-disabled' }}"
                                    {{ !$activeCanCreateOrder ? 'disabled' : '' }}>
                                <i class="fas fa-paper-plane"></i>
                                Send to Optical Centre
                            </button>
                        @endif
                        @if($activeSent && in_array($activeSent->status, ['rejected', 'quote_declined'], true))
                            <small class="so-muted mt-2 block text-red-700">{{ $activeSent->opticalClinic?->name }} {{ $activeSent->status === 'quote_declined' ? 'quoted for the last request; you declined: '.$activeSent->decline_reason : 'turned down the last request: '.$activeSent->reject_reason }}</small>
                        @endif
                        @endif
                        @if(!$activeCanCreateOrder)
                            <small class="so-muted mt-2 block">Part or full payment must be recorded at POS first.</small>
                        @endif
                    @endif
                </section>
            </div>
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
    {{-- A native dialog (top layer): a fixed box inside the page's main area slid under the sidebar. Receipt-width, like the card. --}}
    <dialog wire:ignore.self class="so-print-dialog" aria-label="Job card preview" x-data x-init="$el.showModal()"
            x-on:cancel.prevent="$wire.closePrintPreview()" x-on:click.self="$wire.closePrintPreview()" {{ $autoPrint ? 'style=display:none' : '' }}>
        <section class="so-modal so-modal-sm">
            <header class="so-modal-header">
                <div>
                    <span>Preview before printing</span>
                    <h2>Optical Job Card</h2>
                </div>
                <button type="button" wire:click="closePrintPreview" class="so-close-btn" aria-label="Close"><i class="fas fa-times"></i></button>
            </header>

            <div class="so-modal-body">
                {{-- Laid out like the POS receipt (cashier/thermal-receipt): the same 80mm thermal styles, black and bold, no banners. --}}
                <div id="spectacle-job-preview-print" class="th-card">
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
                    <div class="center">
                        <div class="clinic-name">{{ $appSettings->clinic_name ?? 'Optical Clinic' }}</div>
                        @if(!empty($appSettings->clinic_address))<div class="small">{{ $appSettings->clinic_address }}</div>@endif
                        @if(!empty($appSettings->clinic_contact))<div class="small">Tel: {{ $appSettings->clinic_contact }}</div>@endif
                        @if(!empty($appSettings->clinic_email))<div class="small">{{ $appSettings->clinic_email }}</div>@endif
                        <div class="divider"></div>
                        <div class="doc-title">Lab Job Order</div>
                        <div class="small"><strong>ORDER: {{ $printOrder->order_id }}</strong></div>
                        <div class="small">{{ $printOrder->created_at->format('M d, Y h:i A') }}</div>
                    </div>
                    <div class="divider"></div>

                    <div class="section">
                        <div class="label">Patient</div>
                        <div>{{ $previewPatient?->name ?? '—' }}</div>
                        <div class="small">Contact: {{ $previewPatient?->contact ?: 'N/A' }}</div>
                        <div class="small">ID: {{ $previewPatient?->pxnumber ?: 'N/A' }}</div>
                    </div>
                    <div class="row-line"><span>PICKUP DATE</span><span>{{ \Carbon\Carbon::parse($printOrder->pickUpDate)->format('M d, Y') }}</span></div>
                    <div class="divider"></div>

                    <div class="label">Prescription (Rx)</div>
                    <table>
                        <thead><tr><th style="width:13%">EYE</th><th style="width:49%">RX</th><th style="width:19%">VA</th><th style="width:19%">ADD</th></tr></thead>
                        <tbody>
                            <tr><td>OD</td><td>{{ $previewRef->refractionOD ?? '—' }}</td><td>{{ $previewRef->refractionOD_distance_va ?? '—' }}</td><td>{{ $previewRef->refractionOD_ADD ?? '—' }}</td></tr>
                            <tr><td>OS</td><td>{{ $previewRef->refractionOS ?? '—' }}</td><td>{{ $previewRef->refractionOS_distance_va ?? '—' }}</td><td>{{ $previewRef->refractionOS_ADD ?? '—' }}</td></tr>
                        </tbody>
                    </table>
                    <div class="strong-divider"></div>

                    <div class="row-line"><span>PD (mm)</span><span>{{ $previewRef?->pd ?? 'N/S' }}</span></div>
                    <div class="row-line"><span>LENS</span><span class="text-right">{{ $previewRef?->lensType ?? 'Standard' }}</span></div>
                    <div class="row-line"><span>FRAME</span><span class="text-right">{{ $thFrameName }}{{ $thFrameBatch ? ' (Batch: '.$thFrameBatch.')' : '' }}</span></div>
                    <div class="divider"></div>

                    <div class="label">Lab notes</div>
                    <div class="notes-box">{{ $previewRef?->refractionnotes ?? '' }}</div>
                    <div class="divider"></div>

                    <div class="footer">
                        @if($printOrder->own_frame)<div><strong>PATIENT'S OWN FRAME - GLAZED AT OWNER'S RISK</strong></div>@endif
                        <div>Verify all measurements before surfacing.</div>
                        <div class="divider"></div>
                        <div>Dispensed by: {{ $printOrder->user?->name ?? 'Staff' }}</div>
                        <div>{{ now()->format('M d, Y h:i A') }}</div>
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
    </dialog>
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
                            <textarea aria-label="Optional notes" wire:model="orderNotes" rows="2" placeholder="Optional notes"></textarea>
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
                    <textarea aria-label="Optional cancellation reason" wire:model="cancelReason" rows="3" placeholder="Optional cancellation reason"></textarea>
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
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    margin: 16px 0;
    min-width: 0;
    padding: 3px;
    background: #eaf0f7;
    border: 1px solid var(--so-border);
    border-radius: 8px;
}

.so-metric {
    border: 1px solid transparent;
    background: transparent;
    border-radius: 6px;
    padding: 8px 10px;
    text-align: left;
    display: flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    min-width: max-content;
}

.so-metric-group {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 2px;
    padding: 2px;
    border: 1px solid var(--so-border);
    border-radius: 7px;
    background: rgba(255, 255, 255, .45);
}

.so-metric-alerts {
    display: flex;
    gap: 6px;
    margin-left: auto;
}

/* Narrow screens: the bar wraps onto more lines instead of scrolling sideways. */
@media (max-width: 760px) {
    .so-metrics, .so-metric-group { flex-wrap: wrap; overflow: visible; }
    .so-metric-alerts { margin-left: 0; flex-wrap: wrap; }
}

.so-metric-alert { color: #64748b; }
.so-metric-alert.has-items { color: var(--so-danger); }
.so-metric-alert.has-items .so-metric-value { color: var(--so-danger); }

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
.so-metric-icon.collected { background: #eff6ff; color: var(--so-blue); }
.so-metric-icon.cancelled { background: #f4f4f5; color: #6b7280; }
.so-metric-icon.awaiting { background: #f1f5f9; color: #64748b; }
/* The count and the active outline take the status colour too. */
.so-metric.tone-warning .so-metric-value { color: var(--so-warning); }
.so-metric.tone-lab .so-metric-value { color: var(--so-lab); }
.so-metric.tone-success .so-metric-value { color: var(--so-success); }
.so-metric.tone-collected .so-metric-value { color: var(--so-blue); }
.so-metric.tone-warning.is-active { border-color: var(--so-warning); }
.so-metric.tone-lab.is-active { border-color: var(--so-lab); }
.so-metric.tone-success.is-active { border-color: var(--so-success); }
.so-metric.tone-collected.is-active { border-color: var(--so-blue); }
.so-metric.tone-cancelled.is-active { border-color: #6b7280; }

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

.so-filter-grid .so-sort-row { min-height: 42px; gap: 4px; }

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
    grid-template-columns: minmax(0, 1fr);
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
    grid-template-columns: 34px minmax(170px, 1.25fr) minmax(120px, .8fr) minmax(120px, .75fr) minmax(82px, .45fr) 88px;
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
    grid-template-columns: 34px minmax(0, 1fr) auto;
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

/* In the drawer the case scrolls on its own and is not sticky. */
.so-case-drawer .so-case-panel { position: static; flex: 1; overflow-y: auto; border: 0; border-radius: 0; box-shadow: none; padding: 18px 20px; }
.so-case-header-end { display: flex; align-items: flex-start; gap: 6px; }

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
/* The list's POS column in the same colours: paid green, part-paid blue, waiting amber, nothing grey. */
.so-q-pos.pos-sold strong { color: var(--so-success); }
.so-q-pos.pos-partial strong { color: var(--so-blue); }
.so-q-pos.pos-pending strong { color: var(--so-warning); }
.so-q-pos.pos-none strong { color: #64748b; }

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
.so-print-dialog { padding: 0; border: 0; background: transparent; width: min(520px, calc(100% - 32px)); max-width: none; max-height: calc(100dvh - 32px); overflow: visible; }
.so-print-dialog::backdrop { background: rgb(15 23 42 / .55); }
.so-print-dialog:focus, .so-print-dialog:focus-visible { outline: none; }
.so-print-dialog > .so-modal { width: 100%; max-height: calc(100dvh - 32px); overflow-y: auto; }
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
    color: var(--so-muted, #64748b);
    font-size: 11px;
    text-transform: uppercase;
    font-weight: 850;
    letter-spacing: .06em;
}

.so-modal-header h2 {
    margin: 2px 0 0;
    font-size: 20px;
    font-weight: 850;
    color: #0f172a;
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

/* ---- Job card preview: the POS receipt's thermal styles (cashier/partials/thermal-styles), kept to the card ---- */
#spectacle-job-preview-print { width: 80mm; max-width: 100%; margin: 0 auto; padding: 4mm; background: #fff; border: 1px solid #d7dee8;
    font-family: "DejaVu Sans", Arial, Helvetica, sans-serif; font-size: 12px; font-weight: 700; line-height: 1.35; color: #000; }
#spectacle-job-preview-print * { color: #000; }
#spectacle-job-preview-print strong, #spectacle-job-preview-print th { font-weight: 900; }
#spectacle-job-preview-print .center { text-align: center; }
#spectacle-job-preview-print .clinic-name { font-size: 18px; font-weight: 900; line-height: 1.2; margin-bottom: 3px; text-transform: uppercase; }
#spectacle-job-preview-print .small { font-size: 11px; }
#spectacle-job-preview-print .doc-title { font-size: 15px; font-weight: 900; letter-spacing: .5px; margin: 6px 0 3px; text-transform: uppercase; }
#spectacle-job-preview-print .divider { border-top: 2px dashed #000; margin: 7px 0; }
#spectacle-job-preview-print .strong-divider { border-top: 3px solid #000; margin: 7px 0; }
#spectacle-job-preview-print .section { margin: 7px 0; }
#spectacle-job-preview-print .label { font-size: 11px; font-weight: 900; text-transform: uppercase; }
#spectacle-job-preview-print .row-line { display: flex; justify-content: space-between; gap: 6px; margin: 3px 0; }
#spectacle-job-preview-print .text-right { text-align: right; }
#spectacle-job-preview-print table { border-collapse: collapse; table-layout: fixed; width: 100%; }
#spectacle-job-preview-print th, #spectacle-job-preview-print td { font-size: 11px; padding: 3px 1px; vertical-align: top; text-align: left; overflow-wrap: anywhere; }
#spectacle-job-preview-print th { border-bottom: 2px solid #000; }
#spectacle-job-preview-print .notes-box { border: 2px solid #000; min-height: 42px; padding: 4px; margin-top: 3px; font-size: 11px; }
#spectacle-job-preview-print .footer { font-size: 11px; margin-top: 10px; text-align: center; }

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
.so-queue-head { grid-template-columns: 34px minmax(0, 1.3fr) minmax(0, .85fr) minmax(0, .9fr) minmax(78px, .5fr) 88px; }
.so-pick { display: flex; align-items: center; justify-content: center; cursor: pointer; }
.so-pick input { width: 17px; height: 17px; accent-color: #0f766e; cursor: pointer; }
.so-queue-row .so-pick { padding-left: 10px; }
.so-queue-head .so-pick { margin-left: -4px; }
.so-queue-row.is-picked { background: #f0fdfa; }
.so-bulk-date { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; }
.so-bulk-date input { height: 32px; border: 1px solid var(--so-border); border-radius: 6px; padding: 0 8px; }
.so-bulk-error { color: #b91c1c; font-size: 12px; font-weight: 700; width: 100%; }
.so-bulk-skipped { margin: -6px 0 12px; padding: 10px 14px; border: 1px solid #fcd34d; background: #fffbeb; border-radius: 8px; font-size: 12px; }
.so-bulk-skipped ul { margin: 4px 0; padding-left: 18px; list-style: disc; }
.so-queue-head span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.so-queue-patient > span:last-child { min-width: 0; flex: 1 1 auto; }
.so-status-pill { overflow: hidden; white-space: nowrap; }

@container soqueue (max-width: 640px) {
    .so-q-pos { display: none !important; }
    .so-queue-main { grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr) minmax(78px, .6fr); }
    .so-queue-head { grid-template-columns: 34px minmax(0, 1.4fr) minmax(0, 1fr) minmax(78px, .6fr) 88px; }
}
</style>

<script>
window.addEventListener('auto-print-job-card', function () {
    // Wait one tick for Livewire to paint the hidden DOM, then print and close
    setTimeout(function () {
        printThermalJobCard();

        // Close the Livewire preview on the spectacles component itself (the
        // first [wire:id] on the page is a layout component, e.g. the header).
        var card = document.getElementById('spectacle-job-preview-print');
        var el = card ? card.closest('[wire\\:id]') : null;
        if (el && window.Livewire) {
            var cmp = Livewire.find(el.getAttribute('wire:id'));
            if (cmp) cmp.call('closePrintPreview');
        }
    }, 80);
});

function printThermalJobCard() {
    var content = document.getElementById('spectacle-job-preview-print');
    if (!content) return;

    var win = window.open('', '_blank', 'width=400,height=700');
    // The POS receipt's thermal stylesheet (one source for both), plus the job card's notes box.
    win.document.write('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Job Card</title>');
    win.document.write(@js(view('cashier.partials.thermal-styles')->render()));
    win.document.write('<style>.notes-box{border:2px solid #000;min-height:42px;padding:4px;margin-top:3px;font-size:11px}.text-right{text-align:right}</style>');
    // Split: NativePHP injects a script before every closing head tag, even one inside a string.
    win.document.write('</' + 'head><body>');
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
    @if($opticalTargets)
        <livewire:secretary.send-to-optical-component wire:key="send-to-optical" />
    @endif
</div>
