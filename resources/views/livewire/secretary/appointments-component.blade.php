@php
    $statusTone = [
        'Pending' => 'bg-slate-100 text-slate-700', 'Confirmed' => 'bg-blue-100 text-blue-800', 'Arrived' => 'bg-green-100 text-green-800',
        'With Doctor' => 'bg-sky-100 text-sky-800', 'Called' => 'bg-cyan-100 text-cyan-800', 'Rescheduled' => 'bg-amber-100 text-amber-800',
        'Couldnt Answer' => 'bg-orange-100 text-orange-800', 'Missed' => 'bg-red-100 text-red-800', 'Seen' => 'bg-slate-700 text-white',
        'Done' => 'bg-slate-700 text-white', 'Cancelled' => 'border border-slate-200 bg-white text-slate-500',
    ];
    $missTone = ['success' => 'bg-green-100 text-green-800', 'warning' => 'bg-amber-100 text-amber-800', 'danger' => 'bg-red-100 text-red-800'];
    $badge = 'inline-flex items-center whitespace-nowrap rounded-md px-2 py-0.5 text-xs font-semibold';
    $label = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500';
    $input = 'ui-input';
    $small = 'ui-input !py-1.5 !text-sm';
    $iconButton = 'inline-flex h-8 min-w-[2rem] items-center justify-center rounded-md border bg-white px-2 text-xs no-underline hover:bg-slate-50 disabled:opacity-50';
    $segment = fn (bool $on) => $on ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-300 hover:bg-slate-50';
    $search = fn ($width = 'max-w-xs') => '<input wire:model.live.debounce.300ms="search" type="search" class="'.$small.' '.$width.'" placeholder="Search patient…" aria-label="Search patient">';
@endphp
<div data-livewire-root>
<div class="clinic-ui ui-page space-y-5">

    @if($confirmationWhatsAppUrl)
        <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900" role="status">
            <span><i class="fab fa-whatsapp mr-1" aria-hidden="true"></i>
                @switch($confirmationKind)
                    @case('rescheduled') Appointment moved. Send {{ $confirmationPatientName }} the new time on WhatsApp. @break
                    @case('cancelled') Appointment cancelled. Let {{ $confirmationPatientName }} know on WhatsApp. @break
                    @default Booking saved. Send {{ $confirmationPatientName }} the confirmation on WhatsApp.
                @endswitch
            </span>
            <span class="flex gap-2">
                <a href="{{ $confirmationWhatsAppUrl }}" target="_blank" rel="noopener" wire:click="dismissConfirmationWhatsApp" class="ui-button ui-button-primary"><i class="fab fa-whatsapp" aria-hidden="true"></i>Open WhatsApp</a>
                <button type="button" wire:click="dismissConfirmationWhatsApp" class="ui-button ui-button-secondary">Dismiss</button>
            </span>
        </div>
    @endif

    <div class="ui-heading">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Clinic registry</p>
            <h1>Appointments</h1>
        </div>
        <div class="ui-actions">
            <button type="button" wire:click="openWalkInModal" class="ui-button ui-button-secondary"><i class="fas fa-walking" aria-hidden="true"></i>Walk-in</button>
            <button type="button" wire:click="openNewAppointmentModal" class="ui-button ui-button-primary"><i class="fas fa-plus" aria-hidden="true"></i>New appointment</button>
            <button type="button" wire:click="exportReport" class="ui-button ui-button-secondary" title="Export CSV" aria-label="Export CSV"><i class="fas fa-download" aria-hidden="true"></i></button>
            <button type="button" onclick="window.print()" class="ui-button ui-button-secondary" title="Print" aria-label="Print"><i class="fas fa-print" aria-hidden="true"></i></button>
            <button type="button" wire:click="$set('activeFilter','settings')" class="ui-button ui-button-secondary" title="Settings" aria-label="Appointment settings"><i class="fas fa-cog" aria-hidden="true"></i></button>
        </div>
    </div>

    {{-- Today at a glance --}}
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-6">
        @foreach([
            ['Booked today', $statusSummary['Booked'] ?? 0, 'fa-calendar-check', 'text-blue-600'],
            ['Arrived', $statusSummary['Arrived'] ?? 0, 'fa-user-check', 'text-green-600'],
            ['With doctor', $statusSummary['With Doctor'] ?? 0, 'fa-stethoscope', 'text-sky-600'],
            ['Seen today', $statusSummary['Seen'] ?? 0, 'fa-check-double', 'text-slate-500'],
            ['Missed today', $statusSummary['Missed'] ?? 0, 'fa-user-times', 'text-amber-500'],
            ['Daily limit', ($statusSummary['Booked'] ?? 0).'/'.$dailyAppointmentLimit, 'fa-layer-group', 'text-slate-800'],
        ] as [$summaryLabel, $value, $icon, $colour])
            <div class="ui-panel flex items-center gap-3 px-3 py-2">
                <i class="fas {{ $icon }} {{ $colour }} w-6 text-center text-lg" aria-hidden="true"></i>
                <div>
                    <p class="text-lg font-semibold leading-tight text-slate-900">{{ $value }}</p>
                    <p class="text-xs font-semibold text-slate-500">{{ $summaryLabel }}</p>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Bulk actions --}}
    @if(count($selectedAppointments) > 0)
        <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-slate-800 px-4 py-2 text-white shadow" role="region" aria-label="Selected appointments">
            <div class="flex flex-wrap items-center gap-2">
                <span class="mr-2 font-semibold"><i class="fas fa-check-double mr-1 text-teal-300" aria-hidden="true"></i>{{ count($selectedAppointments) }} selected</span>
                @if($activeFilter === 'missed')
                    <button type="button" wire:click="prepareBulkFollowUp" wire:loading.attr="disabled" wire:target="prepareBulkFollowUp" class="ui-button ui-button-primary"><i class="fas fa-sms" aria-hidden="true"></i>Send follow-up SMS</button>
                    <button type="button" wire:click="bulkMarkAsSeen" class="ui-button ui-button-secondary">Mark resolved</button>
                @else
                    <button type="button" wire:click="bulkMarkAsSeen" class="ui-button ui-button-secondary">Mark seen</button>
                    <button type="button" wire:click="bulkMarkRemindersSent" class="ui-button ui-button-secondary">Mark reminders sent</button>
                @endif
                <button type="button" wire:click="bulkDelete" wire:confirm="Move selected to trash?" class="ui-button ui-button-danger">Trash</button>
            </div>
            <button type="button" wire:click="resetSelection" class="px-2 text-white/80 hover:text-white" aria-label="Clear selection"><i class="fas fa-times" aria-hidden="true"></i></button>
        </div>
    @endif

    {{-- Bulk follow-up confirmation (missed tab) --}}
    @if($bulkFollowUpPlan)
        <section class="ui-panel border-teal-300 p-4" role="dialog" aria-labelledby="bulk-follow-up-title">
            <h2 id="bulk-follow-up-title" class="mb-2 font-semibold"><i class="fas fa-sms mr-1 text-teal-700" aria-hidden="true"></i>
                Send the missed-appointment follow-up to {{ count($bulkFollowUpPlan['send']) }} {{ Str::plural('patient', count($bulkFollowUpPlan['send'])) }}?</h2>
            @if($bulkFollowUpPlan['preview'])
                <p class="mb-1 text-xs text-slate-500">Preview ({{ $bulkFollowUpPlan['send'][0]['name'] }}):</p>
                <div class="mb-2 whitespace-pre-wrap rounded-lg border border-slate-200 bg-slate-50 p-2 text-sm">{{ $bulkFollowUpPlan['preview'] }}</div>
            @endif
            <ul class="mb-3 space-y-1 text-sm">
                @if(count($bulkFollowUpPlan['send']))
                    <li class="text-green-700"><i class="fas fa-check mr-1" aria-hidden="true"></i>{{ count($bulkFollowUpPlan['send']) }} will be texted · about {{ $bulkFollowUpPlan['credits'] }} SMS {{ Str::plural('credit', $bulkFollowUpPlan['credits']) }}@if($bulkFollowUpPlan['creditsLeft'] !== null) ({{ number_format($bulkFollowUpPlan['creditsLeft']) }} left)@endif</li>
                @endif
                @foreach($bulkFollowUpPlan['skipped'] as $skip)
                    <li class="text-amber-700"><i class="fas fa-minus-circle mr-1" aria-hidden="true"></i>Skipped: {{ $skip['name'] }} — {{ $skip['reason'] }}</li>
                @endforeach
                @if($bulkFollowUpPlan['leftOver'])
                    <li class="text-amber-700"><i class="fas fa-exclamation-triangle mr-1" aria-hidden="true"></i>{{ $bulkFollowUpPlan['leftOver'] }} more not sent this time (at most {{ \App\Livewire\Secretary\AppointmentsComponent::BULK_FOLLOW_UP_LIMIT }} per batch, and no more than your credits cover). Send them in another batch.</li>
                @endif
                @unless($bulkFollowUpPlan['available'])
                    <li class="text-red-700"><i class="fas fa-exclamation-circle mr-1" aria-hidden="true"></i>{{ $bulkFollowUpPlan['reason'] }}</li>
                @endunless
            </ul>
            <div class="flex justify-end gap-2">
                <button type="button" wire:click="cancelBulkFollowUp" class="ui-button ui-button-secondary">Cancel</button>
                @if($bulkFollowUpPlan['available'] && count($bulkFollowUpPlan['send']))
                    <button type="button" wire:click="sendBulkFollowUp" wire:loading.attr="disabled" wire:target="sendBulkFollowUp" class="ui-button ui-button-primary">
                        <span wire:loading.remove wire:target="sendBulkFollowUp">Send {{ count($bulkFollowUpPlan['send']) }} SMS</span>
                        <span wire:loading wire:target="sendBulkFollowUp">Sending…</span>
                    </button>
                @endif
            </div>
        </section>
    @endif

    <section class="ui-panel">
        {{-- Tabs --}}
        <div class="flex overflow-x-auto border-b border-slate-200" role="tablist" aria-label="Appointment lists">
            @foreach([
                'schedule' => ['Schedule', 'fa-calendar-alt'],
                'queue' => ['Waiting room', 'fa-users'],
                'history' => ['History', 'fa-history'],
                'missed' => ['Missed', 'fa-user-times'],
                'trash' => ['Trash', 'fa-trash-alt'],
            ] as $key => [$tabLabel, $icon])
                <button type="button" role="tab" aria-selected="{{ $activeFilter === $key ? 'true' : 'false' }}" wire:click="$set('activeFilter','{{ $key }}')"
                        @class(['flex items-center gap-2 whitespace-nowrap border-b-2 px-4 py-3 text-sm font-semibold',
                                'border-slate-900 text-slate-900' => $activeFilter === $key,
                                'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => $activeFilter !== $key])>
                    <i class="fas {{ $icon }}" aria-hidden="true"></i>{{ $tabLabel }}
                    <span @class(['min-w-[1.25rem] rounded-full px-1.5 text-center text-xs',
                                  'bg-slate-900 text-white' => $activeFilter === $key,
                                  'bg-slate-100 text-slate-700' => $activeFilter !== $key])>{{ $this->counts[$key] ?? 0 }}</span>
                </button>
            @endforeach
        </div>

        {{-- ============================= SETTINGS ============================= --}}
        @if($activeFilter === 'settings')
            <div class="space-y-4 p-5">
                <h2 class="text-base font-semibold"><i class="fas fa-cog mr-2 text-slate-400" aria-hidden="true"></i>Appointment settings</h2>
                <div>
                    <p class="{{ $label }}">Location link</p>
                    <p class="text-sm text-slate-600">
                        Set with the clinic's other links in
                        @if(auth()->user()?->hasRole('Super Admin'))
                            <a href="{{ route('admin.settings', ['tab' => 'links']) }}" class="text-teal-700 underline">Settings &rarr; Clinic Links</a>;
                        @else
                            Settings &rarr; Clinic Links;
                        @endif
                        messages insert it with <code>[MAP_LINK]</code>.
                    </p>
                </div>
                <p class="text-sm text-slate-600">
                    Reminder, confirmation and follow-up wording (for both SMS and WhatsApp) comes from the
                    @role('Super Admin')
                        <a href="{{ route('admin.messages') }}" class="text-teal-700 underline">SMS Templates</a>.
                    @else
                        SMS Templates in Settings.
                    @endrole
                    Links (location, WhatsApp, review, social) come from Settings &rarr; Clinic Links.
                </p>
                <div class="max-w-xs">
                    <label for="appt-daily-limit" class="{{ $label }}">Daily appointment limit</label>
                    <input id="appt-daily-limit" type="number" min="1" wire:model.live.debounce.400ms="dailyAppointmentLimit" class="{{ $input }}">
                </div>
            </div>

        {{-- ============================= SCHEDULE ============================= --}}
        @elseif($activeFilter === 'schedule')
            <div class="space-y-2 border-b border-slate-200 bg-slate-50 px-4 py-3">
                <div class="flex flex-wrap items-center gap-2">
                    <div class="inline-flex overflow-hidden rounded-md border border-slate-300 text-sm" role="group" aria-label="Schedule view">
                        @foreach(['list' => ['List', 'fa-list'], 'calendar' => ['Calendar', 'fa-calendar-alt'], 'day' => ['Day', 'fa-clock'], 'range' => ['Range', 'fa-filter']] as $view => [$viewLabel, $icon])
                            <button type="button" wire:click="$set('scheduleView','{{ $view }}')" aria-pressed="{{ $scheduleView === $view ? 'true' : 'false' }}"
                                    class="border-l px-3 py-1.5 first:border-l-0 {{ $segment($scheduleView === $view) }}"><i class="fas {{ $icon }} mr-1" aria-hidden="true"></i>{{ $viewLabel }}</button>
                        @endforeach
                    </div>

                    @if($scheduleView === 'calendar' || $scheduleView === 'day')
                        <div class="inline-flex overflow-hidden rounded-md border border-slate-300 text-sm" role="group" aria-label="Move through dates">
                            <button type="button" wire:click="{{ $scheduleView === 'calendar' ? 'previousCalendarWeek' : 'previousScheduleDay' }}" class="bg-white px-3 py-1.5 hover:bg-slate-50" aria-label="Previous"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>
                            <button type="button" wire:click="{{ $scheduleView === 'calendar' ? 'goToCurrentCalendarWeek' : 'goToTodaySchedule' }}" class="border-x border-slate-300 bg-white px-3 py-1.5 hover:bg-slate-50">Today</button>
                            <button type="button" wire:click="{{ $scheduleView === 'calendar' ? 'nextCalendarWeek' : 'nextScheduleDay' }}" class="bg-white px-3 py-1.5 hover:bg-slate-50" aria-label="Next"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>
                        </div>
                        <span class="text-sm font-semibold text-slate-800">
                            @if($scheduleView === 'calendar')
                                {{ \Carbon\Carbon::parse($calendarStartDate)->format('F Y') }}
                            @else
                                {{ \Carbon\Carbon::parse($selectedScheduleDate)->format('l, M d Y') }}
                            @endif
                        </span>
                    @endif
                </div>

                @if($scheduleView === 'list')
                    <div class="flex flex-wrap items-center gap-2">
                        @foreach(['today' => 'Today', 'tomorrow' => 'Tomorrow', 'this_week' => 'This week', 'next_30' => 'Next 30 days'] as $val => $chip)
                            <button type="button" wire:click="setQuickFilter('{{ $val }}')" aria-pressed="{{ $quickFilter === $val ? 'true' : 'false' }}"
                                    class="rounded-full border px-3 py-1 text-xs font-semibold {{ $segment($quickFilter === $val) }}">{{ $chip }}</button>
                        @endforeach
                        {!! $search('min-w-[180px] max-w-xs') !!}
                    </div>
                @endif

                @if($scheduleView === 'range')
                    <div class="flex flex-wrap items-end gap-3">
                        <div><span class="{{ $label }}">Dates</span><x-date-range from="startDate" to="endDate" presets="upcoming" /></div>
                        <div>
                            <label for="appt-status-filter" class="{{ $label }}">Status</label>
                            <select id="appt-status-filter" wire:model.live="statusFilter" class="{{ $small }}">
                                <option value="All">All</option>
                                <option>Pending</option>
                                <option>Confirmed</option>
                                <option>Arrived</option>
                                <option>With Doctor</option>
                                <option>Called</option>
                                <option>Rescheduled</option>
                                <option value="Couldnt Answer">No Answer</option>
                                <option>Missed</option>
                                <option>Seen</option>
                                <option>Cancelled</option>
                            </select>
                        </div>
                        <div><span class="{{ $label }}">Patient</span>{!! $search() !!}</div>
                    </div>
                @endif
            </div>

            @if($scheduleView !== 'range')
                <div class="flex flex-wrap gap-1.5 border-b border-slate-200 px-4 py-2" aria-label="Status colours">
                    @foreach(['Pending', 'Confirmed', 'Arrived', 'With Doctor', 'Called', 'Rescheduled', 'Couldnt Answer' => 'No Answer', 'Missed', 'Seen', 'Cancelled'] as $status => $legend)
                        @php $status = is_int($status) ? $legend : $status; @endphp
                        <span class="{{ $badge }} {{ $statusTone[$status] }}">{{ $legend }}</span>
                    @endforeach
                </div>
            @endif

            {{-- ---- CALENDAR VIEW ---- --}}
            @if($scheduleView === 'calendar')
                <div class="appointment-calendar" ondragover="event.preventDefault()" ondrop="handleCalendarDrop(event)">
                    <div class="calendar-month-grid">
                        @foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri'] as $wd)
                            <div class="calendar-weekday">{{ $wd }}</div>
                        @endforeach

                        @foreach($calendarWeeks as $week)
                            @foreach($week as $day)
                                @php
                                    $dateKey = $day->format('Y-m-d');
                                    $dayAppointments = $calendarAppointments->get($dateKey, collect());
                                    $isToday = $day->isToday();
                                    $isCurrentMonth = $day->month === \Carbon\Carbon::parse($calendarStartDate)->month;
                                    $isPastDate = $day->lt(now()->startOfDay());
                                    $showMax = 3;
                                    $overflow = max(0, $dayAppointments->count() - $showMax);
                                @endphp
                                <div class="calendar-day {{ !$isCurrentMonth ? 'is-muted' : '' }} {{ $isToday ? 'is-today' : '' }} {{ $isPastDate ? 'is-past' : '' }}"
                                     data-date="{{ $dateKey }}"
                                     ondragover="event.preventDefault(); this.classList.add('drag-over')"
                                     ondragleave="this.classList.remove('drag-over')"
                                     ondrop="this.classList.remove('drag-over'); handleCalendarDrop(event, '{{ $dateKey }}')">
                                    <div class="calendar-day-number {{ $isToday ? 'today-number' : '' }}">{{ $day->format('j') }}</div>
                                    @if(!$isPastDate)
                                        <button type="button" wire:click="bookOnCalendarDate('{{ $dateKey }}')" class="calendar-day-hit" title="Book on {{ $day->format('M d') }}" aria-label="Book on {{ $day->format('M d') }}"></button>
                                    @endif
                                    <div class="calendar-day-stack">
                                        @foreach($dayAppointments->take($showMax) as $app)
                                            @php
                                                $chipColor = [
                                                    'Arrived' => 'green', 'Done' => 'green', 'Confirmed' => 'blue',
                                                    'With Doctor' => 'blue', 'Called' => 'cyan',
                                                    'Couldnt Answer' => 'orange', 'Rescheduled' => 'orange',
                                                ][$app->status] ?? (['yellow', 'rose', 'mint', 'sky'][$loop->index % 4]);
                                                $patientContact = (string) ($app->patient->contact ?? '');
                                                $waUrl = $this->reminderWhatsAppUrl($app);
                                            @endphp
                                            <div class="calendar-appointment calendar-chip-{{ $chipColor }}" draggable="true"
                                                 ondragstart="event.dataTransfer.setData('appointmentId','{{ $app->id }}'); event.dataTransfer.effectAllowed='move'">
                                                <div class="flex items-start justify-between gap-1">
                                                    <div class="calendar-patient">{{ $app->patient->name }}</div>
                                                    <span class="calendar-time-badge">{{ $app->scheduled_at->format('h:i A') }}</span>
                                                </div>
                                                <div class="calendar-reason">{{ Str::limit($app->title, 18) }}</div>
                                                <div class="calendar-contact-actions">
                                                    @if($patientContact)
                                                        <a href="tel:{{ $patientContact }}" class="calendar-action calendar-action-call" onclick="event.stopPropagation()" aria-label="Call {{ $app->patient->name }}"><i class="fas fa-phone-alt" aria-hidden="true"></i></a>
                                                    @endif
                                                    @if($waUrl)
                                                        <a href="{{ $waUrl }}" wire:click="markReminderSent({{ $app->id }},'whatsapp')" target="_blank" class="calendar-action calendar-action-whatsapp" onclick="event.stopPropagation()" aria-label="WhatsApp reminder to {{ $app->patient->name }}"><i class="fab fa-whatsapp" aria-hidden="true"></i></a>
                                                    @endif
                                                    <button type="button" wire:click="editAppointment({{ $app->id }})" class="calendar-action calendar-action-edit" onclick="event.stopPropagation()" aria-label="Edit appointment"><i class="fas fa-pen" aria-hidden="true"></i></button>
                                                </div>
                                            </div>
                                        @endforeach
                                        @if($overflow > 0)
                                            <div class="calendar-overflow">+{{ $overflow }} more</div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        @endforeach
                    </div>
                </div>

            {{-- ---- DAY VIEW ---- --}}
            @elseif($scheduleView === 'day')
                <div class="px-4 pb-4 pt-2">
                    @foreach($dayTimeSlots as $slot)
                        @php $slotApps = $dayAppointments->get($slot->format('H:00'), collect()); @endphp
                        <div @class(['grid min-h-[52px] grid-cols-[52px_1fr] items-start border-b border-slate-100 py-1.5', 'bg-amber-50' => $slot->isCurrentHour()])>
                            <div class="pt-1 text-xs font-semibold text-slate-400">{{ $slot->format('g A') }}</div>
                            <div class="space-y-1 pl-2">
                                @foreach($slotApps as $app)
                                    <div class="flex items-start justify-between gap-2 rounded border-l-4 border-slate-300 bg-slate-50 px-2 py-1">
                                        <div>
                                            <p class="text-sm font-semibold">{{ $app->patient->name }}</p>
                                            <p class="text-xs text-slate-500">{{ $app->title }} · {{ $app->scheduled_at->format('h:i A') }}</p>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <span class="{{ $badge }} {{ $statusTone[$app->status] ?? $statusTone['Pending'] }}">{{ $app->status }}</span>
                                            <button type="button" wire:click="editAppointment({{ $app->id }})" class="{{ $iconButton }} border-slate-300 text-slate-600" aria-label="Edit appointment"><i class="fas fa-pen" aria-hidden="true"></i></button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

            {{-- ---- LIST / RANGE VIEW ---- --}}
            @else
                <div class="ui-table-wrap">
                    <table class="ui-table">
                        <thead>
                            <tr>
                                <th class="w-9"><input type="checkbox" wire:model.live="selectAll" class="rounded border-slate-300 text-teal-700" aria-label="Select all"></th>
                                <th>Time &amp; date</th>
                                <th>Patient</th>
                                <th>Reason</th>
                                <th>Reminder</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($appointments as $app)
                                @php
                                    $missStats = $noShowStats[$app->patient_id] ?? ['missed' => 0, 'total' => 0, 'rate' => 0, 'class' => 'success'];
                                    $patientContact = (string) ($app->patient->contact ?? '');
                                    $waUrl = $this->reminderWhatsAppUrl($app);
                                    $reminderSent = ($app->reminder_status ?? 'not_sent') === 'sent';
                                @endphp
                                <tr>
                                    <td><input type="checkbox" wire:model.live="selectedAppointments" value="{{ $app->id }}" class="rounded border-slate-300 text-teal-700" aria-label="Select {{ $app->patient->name }}"></td>
                                    <td class="whitespace-nowrap">
                                        <p class="font-semibold">{{ $app->scheduled_at->format('h:i A') }}</p>
                                        <p class="text-xs text-slate-500">{{ $app->scheduled_at->format('M d, Y') }}</p>
                                        <p class="text-xs text-slate-400">{{ $app->scheduled_at->diffForHumans() }}</p>
                                    </td>
                                    <td>
                                        <p class="font-semibold">{{ $app->patient->name }}</p>
                                        <p class="text-xs text-slate-500">{{ $app->patient->pxnumber }}</p>
                                        @if($missStats['total'] > 0)
                                            <span class="{{ $badge }} {{ $missTone[$missStats['class']] ?? $missTone['success'] }} mt-1">{{ $missStats['missed'] }}/{{ $missStats['total'] }} missed</span>
                                        @endif
                                    </td>
                                    <td>
                                        <p>{{ $app->title }}</p>
                                        <p class="text-xs text-slate-500"><i class="fas fa-user-md mr-1" aria-hidden="true"></i>{{ $app->doctor->name ?? 'Unassigned' }} &middot; {{ $app->duration_minutes ?? 30 }} min</p>
                                        @if($app->recall_category)
                                            <span class="{{ $badge }} mt-1 border border-slate-200 bg-white text-slate-600">{{ $app->recall_category }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="flex flex-wrap gap-1">
                                            @if($patientContact)
                                                <a href="tel:{{ $patientContact }}" class="{{ $iconButton }} border-green-300 text-green-700" title="Call" aria-label="Call {{ $app->patient->name }}"><i class="fas fa-phone-alt" aria-hidden="true"></i></a>
                                                <a href="{{ $waUrl ?? '#' }}" wire:click="markReminderSent({{ $app->id }},'whatsapp')" target="_blank" class="{{ $iconButton }} border-green-300 text-green-700" title="WhatsApp" aria-label="WhatsApp {{ $app->patient->name }}"><i class="fab fa-whatsapp" aria-hidden="true"></i></a>
                                                <button type="button" wire:click="sendSmsNow({{ $app->id }})" class="{{ $iconButton }} border-blue-300 text-blue-700" title="Send SMS" aria-label="Send SMS to {{ $app->patient->name }}"
                                                        wire:loading.attr="disabled" wire:target="sendSmsNow({{ $app->id }})">
                                                    <span wire:loading.remove wire:target="sendSmsNow({{ $app->id }})"><i class="fas fa-sms" aria-hidden="true"></i></span>
                                                    <span wire:loading wire:target="sendSmsNow({{ $app->id }})"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i></span>
                                                </button>
                                            @else
                                                <span class="text-xs italic text-slate-400">No contact</span>
                                            @endif
                                        </div>
                                        <span class="{{ $badge }} mt-1 {{ $reminderSent ? 'bg-green-100 text-green-800' : 'border border-slate-200 bg-white text-slate-500' }}">
                                            <i class="fas fa-{{ $reminderSent ? 'check' : 'clock' }} mr-1" aria-hidden="true"></i>{{ $reminderSent ? 'Reminder sent' : 'Not sent' }}
                                        </span>
                                    </td>
                                    <td><span class="{{ $badge }} {{ $statusTone[$app->status] ?? $statusTone['Pending'] }}">{{ $app->status }}</span></td>
                                    <td>
                                        <div class="flex justify-end gap-1">
                                            @if($app->status === 'Pending')
                                                <button type="button" wire:click="confirmAppointment({{ $app->id }})" class="{{ $iconButton }} border-blue-300 text-blue-700" title="Confirm" aria-label="Confirm appointment"><i class="fas fa-check" aria-hidden="true"></i></button>
                                            @endif
                                            <button type="button" wire:click="editAppointment({{ $app->id }})" class="{{ $iconButton }} border-slate-300 text-slate-600" title="Edit" aria-label="Edit appointment"><i class="fas fa-pen" aria-hidden="true"></i></button>
                                            @if(!in_array($app->status, ['Seen', 'Cancelled', 'Missed']))
                                                <button type="button" wire:click="openCancelModal({{ $app->id }})" class="{{ $iconButton }} border-red-300 text-red-700" title="Cancel" aria-label="Cancel appointment"><i class="fas fa-times" aria-hidden="true"></i></button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="ui-empty">
                                        <i class="fas fa-calendar-times mb-3 block text-4xl text-slate-300" aria-hidden="true"></i>
                                        <p class="text-slate-500">No appointments found{{ $quickFilter ? ' for "'.ucwords(str_replace('_', ' ', $quickFilter)).'"' : '' }}.</p>
                                        <button type="button" wire:click="openNewAppointmentModal" class="ui-button ui-button-primary mt-3"><i class="fas fa-plus" aria-hidden="true"></i>Book one</button>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($appointments->hasPages())
                    <div class="border-t border-slate-200 px-4 py-2">{{ $appointments->links() }}</div>
                @endif
            @endif

        {{-- ============================= WAITING ROOM ============================= --}}
        @elseif($activeFilter === 'queue')
            <div class="space-y-2 border-b border-slate-200 bg-slate-50 px-4 py-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p><span class="font-semibold">Today's waiting room</span> <span class="ml-2 text-sm text-slate-500">{{ now()->format('l, M d Y') }}</span></p>
                    <div class="flex gap-2">
                        <button type="button" wire:click="exportReport" class="ui-button ui-button-secondary"><i class="fas fa-download" aria-hidden="true"></i>Export</button>
                        <button type="button" wire:click="closeClinicDay" wire:confirm="Move all unfinished appointments to tomorrow?" class="ui-button ui-button-secondary"><i class="fas fa-moon" aria-hidden="true"></i>Close day</button>
                    </div>
                </div>
                {!! $search() !!}
            </div>
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th class="w-9"><input type="checkbox" wire:model.live="selectAll" class="rounded border-slate-300 text-teal-700" aria-label="Select all"></th>
                            <th>Time</th>
                            <th>Patient</th>
                            <th>Reason</th>
                            <th>Queue actions</th>
                            <th>Status</th>
                            <th class="text-right">Edit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($appointments as $app)
                            <tr @class(['bg-green-50' => $app->status === 'Arrived', 'bg-sky-50' => $app->status === 'With Doctor', 'opacity-60' => $app->status === 'Done'])>
                                <td><input type="checkbox" wire:model.live="selectedAppointments" value="{{ $app->id }}" class="rounded border-slate-300 text-teal-700" aria-label="Select {{ $app->patient->name }}"></td>
                                <td class="whitespace-nowrap font-semibold">{{ $app->scheduled_at->format('h:i A') }}</td>
                                <td>
                                    <p class="font-semibold">{{ $app->patient->name }}</p>
                                    <p class="text-xs text-slate-500">{{ $app->patient->contact ?? '—' }}</p>
                                </td>
                                <td>{{ $app->title }}</td>
                                <td>
                                    <div class="inline-flex overflow-hidden rounded-md border border-slate-300 text-xs font-semibold" role="group" aria-label="Move {{ $app->patient->name }} through the queue">
                                        <button type="button" wire:click="advanceQueueStatus({{ $app->id }},'Arrived')" class="bg-white px-2.5 py-1.5 text-green-700 hover:bg-green-50">Arrived</button>
                                        <button type="button" wire:click="advanceQueueStatus({{ $app->id }},'With Doctor')" class="border-x border-slate-300 bg-white px-2.5 py-1.5 text-sky-700 hover:bg-sky-50">Doctor</button>
                                        <button type="button" wire:click="advanceQueueStatus({{ $app->id }},'Done')" class="bg-white px-2.5 py-1.5 text-slate-700 hover:bg-slate-100">Done</button>
                                    </div>
                                </td>
                                <td><span class="{{ $badge }} {{ $statusTone[$app->status] ?? $statusTone['Pending'] }}">{{ $app->status }}</span></td>
                                <td class="text-right"><button type="button" wire:click="editAppointment({{ $app->id }})" class="{{ $iconButton }} border-slate-300 text-slate-600" aria-label="Edit appointment"><i class="fas fa-pen" aria-hidden="true"></i></button></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="ui-empty">
                                    <i class="fas fa-couch mb-3 block text-4xl text-slate-300" aria-hidden="true"></i>
                                    <p class="mb-2 text-slate-500">Waiting room is empty.</p>
                                    <button type="button" wire:click="openWalkInModal" class="ui-button ui-button-primary"><i class="fas fa-walking" aria-hidden="true"></i>Add walk-in</button>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($appointments->hasPages())
                <div class="border-t border-slate-200 px-4 py-2">{{ $appointments->links() }}</div>
            @endif

        {{-- ============================= HISTORY ============================= --}}
        @elseif($activeFilter === 'history')
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50 px-4 py-3">
                {!! $search() !!}
                <button type="button" wire:click="exportReport" class="ui-button ui-button-secondary"><i class="fas fa-download" aria-hidden="true"></i>Export</button>
            </div>
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th class="w-9"><input type="checkbox" wire:model.live="selectAll" class="rounded border-slate-300 text-teal-700" aria-label="Select all"></th>
                            <th>Date &amp; time</th>
                            <th>Patient</th>
                            <th>Reason</th>
                            <th>Outcome</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($appointments as $app)
                            @php $isCancelled = $app->status === 'Cancelled'; @endphp
                            <tr @class(['opacity-70' => $isCancelled])>
                                <td><input type="checkbox" wire:model.live="selectedAppointments" value="{{ $app->id }}" class="rounded border-slate-300 text-teal-700" aria-label="Select {{ $app->patient->name }}"></td>
                                <td class="whitespace-nowrap">
                                    <p class="font-semibold">{{ $app->scheduled_at->format('M d, Y') }}</p>
                                    <p class="text-xs text-slate-500">{{ $app->scheduled_at->format('h:i A') }}</p>
                                </td>
                                <td>
                                    <p class="font-semibold">{{ $app->patient->name }}</p>
                                    <p class="text-xs text-slate-500">{{ $app->patient->pxnumber }}</p>
                                </td>
                                <td>{{ $app->title }}</td>
                                <td><span class="{{ $badge }} {{ $isCancelled ? 'bg-slate-100 text-slate-600' : 'bg-green-100 text-green-800' }}">{{ $app->status }}</span></td>
                                <td class="text-right"><button type="button" wire:click="editAppointment({{ $app->id }})" class="{{ $iconButton }} border-slate-300 text-slate-600" aria-label="Edit appointment"><i class="fas fa-pen" aria-hidden="true"></i></button></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="ui-empty text-slate-500"><i class="fas fa-history mb-3 block text-4xl text-slate-300" aria-hidden="true"></i>No history yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($appointments->hasPages())
                <div class="border-t border-slate-200 px-4 py-2">{{ $appointments->links() }}</div>
            @endif

        {{-- ============================= MISSED ============================= --}}
        @elseif($activeFilter === 'missed')
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50 px-4 py-3">
                <div class="flex flex-wrap items-center gap-2">
                    {!! $search() !!}
                    <div class="inline-flex overflow-hidden rounded-md border border-slate-300 text-sm" role="group" aria-label="Show missed appointments">
                        @foreach(['all' => 'All', 'not_followed' => 'Not followed up', 'recent' => 'Last 7 days'] as $view => $viewLabel)
                            <button type="button" wire:click="$set('missedView', '{{ $view }}')" aria-pressed="{{ $missedView === $view ? 'true' : 'false' }}"
                                    class="border-l px-3 py-1.5 first:border-l-0 {{ $segment($missedView === $view) }}">{{ $viewLabel }}</button>
                        @endforeach
                    </div>
                </div>
                <button type="button" wire:click="exportReport" class="ui-button ui-button-secondary"><i class="fas fa-download" aria-hidden="true"></i>Export</button>
            </div>
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th class="w-9"><input type="checkbox" wire:model.live="selectAll" class="rounded border-slate-300 text-teal-700" aria-label="Select all"></th>
                            <th>Missed on</th>
                            <th>Patient</th>
                            <th>Reason</th>
                            <th>Follow-up</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($appointments as $app)
                            <tr>
                                <td><input type="checkbox" wire:model.live="selectedAppointments" value="{{ $app->id }}" class="rounded border-slate-300 text-teal-700" aria-label="Select {{ $app->patient->name }}"></td>
                                <td class="whitespace-nowrap">
                                    <p class="font-semibold text-red-700">{{ $app->scheduled_at->format('M d, Y') }}</p>
                                    <p class="text-xs text-slate-500">{{ $app->scheduled_at->diffForHumans() }}</p>
                                </td>
                                <td>
                                    <p class="font-semibold">{{ $app->patient->name }}</p>
                                    <p class="text-xs text-slate-500">{{ $app->patient->contact ?? '—' }}</p>
                                </td>
                                <td>{{ $app->title }}</td>
                                <td>
                                    @if($missedActionId === $app->id)
                                        {{-- Inline reschedule --}}
                                        <div class="flex flex-wrap items-end gap-2">
                                            <div>
                                                <label for="reschedule-date-{{ $app->id }}" class="{{ $label }}">Date</label>
                                                <input id="reschedule-date-{{ $app->id }}" type="date" wire:model.live="rescheduleDate" class="{{ $small }} !w-36">
                                                @error('rescheduleDate')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                                            </div>
                                            <div>
                                                <label for="reschedule-time-{{ $app->id }}" class="{{ $label }}">Time</label>
                                                <input id="reschedule-time-{{ $app->id }}" type="time" wire:model.live="rescheduleTime" class="{{ $small }} !w-28">
                                            </div>
                                            <button type="button" wire:click="rescheduleMissed" class="ui-button ui-button-primary">Confirm</button>
                                            <button type="button" wire:click="closeMissedAction" class="ui-button ui-button-secondary">Cancel</button>
                                        </div>
                                    @else
                                        <div class="flex flex-wrap items-center gap-1">
                                            @if($app->missed_followup_sent_at)
                                                <span class="{{ $badge }} mr-1 border border-green-200 bg-white text-green-700" title="Follow-up SMS sent"><i class="fas fa-check mr-1" aria-hidden="true"></i>SMS sent {{ $app->missed_followup_sent_at->format('M d') }}</span>
                                            @endif
                                            @if($app->patient->contact)
                                                <a href="tel:{{ $app->patient->contact }}" class="{{ $iconButton }} border-green-300 text-green-700" title="Call" aria-label="Call {{ $app->patient->name }}"><i class="fas fa-phone-alt" aria-hidden="true"></i></a>
                                                @if($followUpUrl = $this->missedFollowUpWhatsAppUrl($app))
                                                    <a href="{{ $followUpUrl }}" target="_blank" rel="noopener" class="{{ $iconButton }} border-green-300 text-green-700" title="Send follow-up on WhatsApp" aria-label="Send {{ $app->patient->name }} a follow-up on WhatsApp"><i class="fab fa-whatsapp" aria-hidden="true"></i></a>
                                                @endif
                                                <button type="button" wire:click="sendMissedFollowUpSms({{ $app->id }})" class="{{ $iconButton }} border-blue-300 text-blue-700" title="Send follow-up SMS" aria-label="Send {{ $app->patient->name }} a follow-up SMS"><i class="fas fa-sms" aria-hidden="true"></i></button>
                                            @endif
                                            <button type="button" wire:click="openMissedAction({{ $app->id }})" class="{{ $iconButton }} border-amber-300 text-amber-700" title="Reschedule" aria-label="Reschedule"><i class="fas fa-calendar-plus" aria-hidden="true"></i></button>
                                            <button type="button" wire:click="resolveMissed({{ $app->id }})" class="{{ $iconButton }} border-slate-300 text-slate-700" title="Mark resolved" aria-label="Mark resolved"><i class="fas fa-check" aria-hidden="true"></i></button>
                                        </div>
                                    @endif
                                </td>
                                <td class="text-right"><button type="button" wire:click="editAppointment({{ $app->id }})" class="{{ $iconButton }} border-slate-300 text-slate-600" aria-label="Edit appointment"><i class="fas fa-pen" aria-hidden="true"></i></button></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="ui-empty">
                                    <i class="fas fa-check-circle mb-3 block text-4xl text-green-500" aria-hidden="true"></i>
                                    <p class="text-slate-500">No missed appointments. Great work!</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($appointments->hasPages())
                <div class="border-t border-slate-200 px-4 py-2">{{ $appointments->links() }}</div>
            @endif

        {{-- ============================= TRASH ============================= --}}
        @elseif($activeFilter === 'trash')
            <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">{!! $search() !!}</div>
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th class="w-9"><input type="checkbox" wire:model.live="selectAll" class="rounded border-slate-300 text-teal-700" aria-label="Select all"></th>
                            <th>Date</th>
                            <th>Patient</th>
                            <th>Reason</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($appointments as $app)
                            <tr class="opacity-70">
                                <td><input type="checkbox" wire:model.live="selectedAppointments" value="{{ $app->id }}" class="rounded border-slate-300 text-teal-700" aria-label="Select {{ $app->patient->name }}"></td>
                                <td class="whitespace-nowrap">{{ $app->scheduled_at->format('M d, Y') }}</td>
                                <td class="font-semibold">{{ $app->patient->name }}</td>
                                <td>{{ $app->title }}</td>
                                <td class="text-right"><button type="button" wire:click="restoreAppointment({{ $app->id }})" class="ui-button ui-button-secondary"><i class="fas fa-undo" aria-hidden="true"></i>Restore</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="ui-empty text-slate-500"><i class="fas fa-trash-alt mb-3 block text-4xl text-slate-300" aria-hidden="true"></i>Trash is empty.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($appointments->hasPages())
                <div class="border-t border-slate-200 px-4 py-2">{{ $appointments->links() }}</div>
            @endif
        @endif
    </section>

    {{-- ===== APPOINTMENT DIALOG ===== --}}
    @if($isEditModalOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" wire:click.self="closeModal" x-data x-on:keydown.escape.window="$wire.closeModal()">
            <div class="flex max-h-[calc(100vh-2rem)] w-full max-w-xl flex-col overflow-hidden rounded-xl bg-white shadow-xl" role="dialog" aria-modal="true" aria-labelledby="appt-dialog-title">
                <div class="ui-panel-heading">
                    <h2 id="appt-dialog-title"><i class="fas fa-calendar-plus mr-2 text-teal-700" aria-hidden="true"></i>{{ $editingAppointmentId ? 'Edit appointment' : ($newAppointmentStatus === 'Arrived' ? 'Walk-in visit' : 'New appointment') }}</h2>
                    <button type="button" class="ui-button ui-button-secondary" wire:click="closeModal" aria-label="Close dialog">Close</button>
                </div>
                <form wire:submit="saveAppointment" class="flex min-h-0 flex-col">
                    <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4">
                        <fieldset class="space-y-2">
                            <legend class="mb-2 w-full border-b border-slate-100 pb-1 text-xs font-bold uppercase tracking-widest text-slate-400">Patient</legend>
                            <div class="relative">
                                @if($selectedPatientName)
                                    <div class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                                        <span class="font-semibold"><i class="fas fa-user-circle mr-2 text-teal-700" aria-hidden="true"></i>{{ $selectedPatientName }}</span>
                                        <button type="button" wire:click="clearSelectedPatient" class="text-red-700" aria-label="Choose a different patient"><i class="fas fa-times" aria-hidden="true"></i></button>
                                    </div>
                                @else
                                    <input type="search" wire:model.live.debounce.300ms="patientSearch" class="{{ $input }}" placeholder="Search by name, phone, or PX number…" aria-label="Search patient" autocomplete="off">
                                    @if(!empty($searchablePatients))
                                        <div class="absolute z-10 mt-1 max-h-52 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg">
                                            @foreach($searchablePatients as $p)
                                                <button type="button" wire:click="selectPatient({{ $p->id }},'{{ addslashes($p->name) }}')" class="block w-full border-b border-slate-100 px-3 py-2 text-left hover:bg-slate-50">
                                                    <span class="block font-semibold">{{ $p->name }}</span>
                                                    <span class="block text-xs text-slate-500">{{ $p->pxnumber }} · {{ $p->contact ?? 'No contact' }}@if($p->dob) · Age {{ \Carbon\Carbon::parse($p->dob)->age }}@endif</span>
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif
                                @endif
                                @error('patient_id')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                            </div>
                        </fieldset>

                        <fieldset class="space-y-3">
                            <legend class="mb-2 w-full border-b border-slate-100 pb-1 text-xs font-bold uppercase tracking-widest text-slate-400">Visit details</legend>
                            <div>
                                <label for="appt-title" class="{{ $label }}">Reason</label>
                                <select id="appt-title" wire:model.live="title" class="{{ $input }}" aria-invalid="{{ $errors->has('title') ? 'true' : 'false' }}">
                                    <option value="">— Select reason —</option>
                                    @foreach($appointmentReasons as $reason)<option value="{{ $reason }}">{{ $reason }}</option>@endforeach
                                </select>
                                @error('title')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="appt-recall" class="{{ $label }}">Recall category</label>
                                <select id="appt-recall" wire:model.live="recall_category" class="{{ $input }}">
                                    <option value="">Use reason</option>
                                    @foreach($recallCategories as $cat)<option value="{{ $cat }}">{{ $cat }}</option>@endforeach
                                </select>
                            </div>
                            <div>
                                <label for="appt-notes" class="{{ $label }}">Notes</label>
                                <textarea id="appt-notes" wire:model.live.debounce.400ms="notes" rows="2" class="{{ $input }}"></textarea>
                            </div>
                        </fieldset>

                        <fieldset>
                            <legend class="mb-2 w-full border-b border-slate-100 pb-1 text-xs font-bold uppercase tracking-widest text-slate-400">Reminder</legend>
                            <label for="appt-channel" class="{{ $label }}">Channel</label>
                            <select id="appt-channel" wire:model.live="reminder_channel" class="{{ $input }}">
                                <option value="whatsapp">WhatsApp</option>
                                <option value="sms">SMS</option>
                                <option value="both">SMS + WhatsApp</option>
                                <option value="none">No reminder</option>
                            </select>
                        </fieldset>

                        <fieldset class="space-y-3">
                            <legend class="mb-2 w-full border-b border-slate-100 pb-1 text-xs font-bold uppercase tracking-widest text-slate-400">Schedule</legend>
                            <div>
                                <label for="appt-when" class="{{ $label }}">Date &amp; time</label>
                                <input id="appt-when" type="datetime-local" wire:model.live="scheduled_at" min="{{ now()->format('Y-m-d\TH:i') }}" class="{{ $input }}" aria-invalid="{{ $errors->has('scheduled_at') ? 'true' : 'false' }}">
                                @error('scheduled_at')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                            </div>
                            <div class="grid gap-3 sm:grid-cols-[7fr_5fr]">
                                <div>
                                    <label for="appt-doctor" class="{{ $label }}">Doctor</label>
                                    <select id="appt-doctor" wire:model.live="doctor_id" class="{{ $input }}" aria-invalid="{{ $errors->has('doctor_id') ? 'true' : 'false' }}">
                                        <option value="">Unassigned</option>
                                        @foreach($doctors as $doctor)<option value="{{ $doctor->id }}">{{ $doctor->name }}</option>@endforeach
                                    </select>
                                    @error('doctor_id')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label for="appt-duration" class="{{ $label }}">Duration</label>
                                    <select id="appt-duration" wire:model.live="duration_minutes" class="{{ $input }}">
                                        @foreach([15, 30, 45, 60, 90, 120] as $minutes)<option value="{{ $minutes }}">{{ $minutes }} minutes</option>@endforeach
                                    </select>
                                </div>
                            </div>
                        </fieldset>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
                        <button type="button" wire:click="closeModal" class="ui-button ui-button-secondary">Discard</button>
                        <button type="submit" class="ui-button ui-button-primary"><i class="fas fa-save" aria-hidden="true"></i>{{ $editingAppointmentId ? 'Save changes' : ($newAppointmentStatus === 'Arrived' ? 'Add to waiting room' : 'Save appointment') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- ===== CANCEL DIALOG ===== --}}
    @if($isCancelModalOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" x-data x-on:keydown.escape.window="$wire.closeCancelModal()">
            <div class="w-full max-w-md overflow-hidden rounded-xl bg-white shadow-xl" role="dialog" aria-modal="true" aria-labelledby="cancel-dialog-title">
                <div class="ui-panel-heading bg-red-50">
                    <h2 id="cancel-dialog-title" class="text-red-800"><i class="fas fa-times-circle mr-2" aria-hidden="true"></i>Cancel appointment</h2>
                    <button type="button" class="ui-button ui-button-secondary" wire:click="closeCancelModal" aria-label="Close dialog">Close</button>
                </div>
                <div class="px-5 py-4">
                    <label for="cancel-reason" class="{{ $label }}">Reason <span class="font-normal normal-case">(optional)</span></label>
                    <textarea id="cancel-reason" wire:model.live.debounce.400ms="cancelReason" rows="3" class="{{ $input }}" placeholder="e.g. Patient requested cancellation…"></textarea>
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
                    <button type="button" wire:click="closeCancelModal" class="ui-button ui-button-secondary">Back</button>
                    <button type="button" wire:click="confirmCancelAppointment" class="ui-button ui-button-danger">Confirm cancel</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ===== REMINDER PREVIEW DIALOG ===== --}}
    @if($showReminderPreview)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" x-data x-on:keydown.escape.window="$wire.closeReminderPreview()">
            <div class="w-full max-w-lg overflow-hidden rounded-xl bg-white shadow-xl" role="dialog" aria-modal="true" aria-labelledby="preview-dialog-title">
                <div class="ui-panel-heading">
                    <h2 id="preview-dialog-title"><i class="fab fa-whatsapp mr-2 text-green-600" aria-hidden="true"></i>Reminder preview</h2>
                    <button type="button" class="ui-button ui-button-secondary" wire:click="closeReminderPreview" aria-label="Close dialog">Close</button>
                </div>
                <div class="px-5 py-4">
                    <textarea id="reminderPreviewMessage" class="{{ $input }}" rows="7" readonly aria-label="Reminder message">{{ $previewReminderMessage }}</textarea>
                    <p class="mt-2 text-xs text-slate-500">Copy into WhatsApp Web, or open directly.</p>
                </div>
                <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
                    <button type="button" class="ui-button ui-button-secondary" onclick="navigator.clipboard.writeText(document.getElementById('reminderPreviewMessage').value)"><i class="fas fa-copy" aria-hidden="true"></i>Copy</button>
                    <a href="{{ $this->previewWhatsAppUrl }}" target="_blank" class="ui-button ui-button-secondary"><i class="fab fa-whatsapp" aria-hidden="true"></i>Open WhatsApp</a>
                    <button type="button" class="ui-button ui-button-primary" wire:click="markPreviewReminderSent">Mark sent</button>
                </div>
            </div>
        </div>
    @endif
</div>

<script>
function handleCalendarDrop(event, newDate) {
    event.preventDefault();
    const appointmentId = event.dataTransfer.getData('appointmentId');
    if (appointmentId && newDate) {
        @this.dragDropReschedule(parseInt(appointmentId), newDate);
    }
}
</script>

<style>
/* Week calendar: drag an appointment chip onto another day to move it. */
.appointment-calendar { background: #fff; padding: 0 1rem 1.5rem; overflow-x: auto; }
.calendar-month-grid { display: grid; grid-template-columns: repeat(5, minmax(140px, 1fr)); border-top: 1px solid #edf0f4; border-left: 1px solid #edf0f4; }
.calendar-weekday { padding: .6rem .75rem; color: #667085; font-size: .7rem; font-weight: 800; border-right: 1px solid #edf0f4; border-bottom: 1px solid #edf0f4; text-align: center; text-transform: uppercase; }
.calendar-day { position: relative; min-width: 140px; min-height: 128px; padding: 1.5rem .6rem .6rem; border-right: 1px solid #edf0f4; border-bottom: 1px solid #edf0f4; background: #fff; transition: background .15s; }
.calendar-day.is-muted { background: #fafafa; }
.calendar-day.is-muted .calendar-day-number { color: #c4cad4; }
.calendar-day.is-today { background: #fffbeb; }
.calendar-day.is-past { background: #f9fafb; pointer-events: none; opacity: .7; }
.calendar-day.drag-over { background: #eff6ff !important; outline: 2px dashed #3b82f6; }
.calendar-day-number { position: absolute; top: .5rem; left: .65rem; font-size: .72rem; font-weight: 800; color: #667085; z-index: 2; }
.calendar-day-number.today-number { background: #087e83; color: #fff; width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: .65rem; }
.calendar-day-hit { position: absolute; inset: 0; width: 100%; border: 0; background: transparent; cursor: copy; z-index: 1; }
.calendar-day-stack { position: relative; z-index: 2; display: flex; flex-direction: column; gap: .35rem; pointer-events: none; }
.calendar-appointment { border-radius: 5px; padding: .3rem .45rem; border: 1px solid transparent; box-shadow: 0 2px 6px rgba(15,23,42,.06); pointer-events: auto; cursor: grab; }
.calendar-appointment:active { cursor: grabbing; }
.calendar-patient { font-size: .67rem; font-weight: 800; color: #1f2937; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100px; }
.calendar-reason { font-size: .6rem; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.calendar-time-badge { font-size: .58rem; font-weight: 700; color: #475467; white-space: nowrap; }
.calendar-contact-actions { display: flex; flex-wrap: wrap; gap: .2rem; margin-top: .3rem; }
.calendar-action { display: inline-flex; align-items: center; justify-content: center; min-height: 20px; width: 22px; border-radius: 4px; background: #fff; font-size: .65rem; border: 1px solid transparent; cursor: pointer; text-decoration: none !important; }
.calendar-action-call, .calendar-action-whatsapp { color: #16a34a; border-color: #16a34a; }
.calendar-action-edit { color: #6b7280; border-color: #d1d5db; }
.calendar-overflow { font-size: .65rem; font-weight: 700; color: #6b7280; text-align: center; margin-top: .2rem; cursor: pointer; }
.calendar-chip-yellow { background: #fef9c3; border-color: #facc15; }
.calendar-chip-rose { background: #ffe4e6; border-color: #fb7185; }
.calendar-chip-mint, .calendar-chip-green { background: #dcfce7; border-color: #4ade80; }
.calendar-chip-sky, .calendar-chip-blue, .calendar-chip-cyan { background: #dbeafe; border-color: #60a5fa; }
.calendar-chip-orange { background: #ffedd5; border-color: #fb923c; }
@media (max-width: 767px) {
    .calendar-month-grid { grid-template-columns: repeat(5, minmax(120px, 1fr)); }
    .calendar-day, .calendar-weekday { min-width: 120px; }
}
</style>
</div>
