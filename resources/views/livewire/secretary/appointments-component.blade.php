<div data-livewire-root>
<div class="appt-page bg-light min-vh-100">

    @if($confirmationWhatsAppUrl)
    <div class="alert alert-success border-0 shadow-sm d-flex align-items-center justify-content-between flex-wrap mb-0 rounded-0" style="gap:.5rem">
        <span><i class="fab fa-whatsapp mr-1"></i> Booking saved. Send {{ $confirmationPatientName }} the confirmation on WhatsApp.</span>
        <span class="d-flex" style="gap:.5rem">
            <a href="{{ $confirmationWhatsAppUrl }}" target="_blank" rel="noopener" wire:click="dismissConfirmationWhatsApp"
               class="btn btn-success btn-sm font-weight-bold"><i class="fab fa-whatsapp mr-1"></i> Open WhatsApp</a>
            <button type="button" wire:click="dismissConfirmationWhatsApp" class="btn btn-outline-secondary btn-sm">Dismiss</button>
        </span>
    </div>
    @endif

    {{-- ===== PAGE HEADER ===== --}}
    <div class="appt-header">
        <div class="appt-header-inner container-fluid">
            <div>
                <div class="appt-header-kicker">Clinic Registry</div>
                <h1 class="appt-header-title">Appointments</h1>
            </div>
            <div class="appt-header-actions">
                <button wire:click="openWalkInModal" class="btn btn-success btn-sm font-weight-bold shadow-sm">
                    <i class="fas fa-walking mr-1"></i> Walk-in
                </button>
                <button wire:click="openNewAppointmentModal" class="btn btn-primary btn-sm font-weight-bold shadow-sm">
                    <i class="fas fa-plus mr-1"></i> New Appointment
                </button>
                <button wire:click="exportReport" class="btn btn-light btn-sm" title="Export CSV">
                    <i class="fas fa-download"></i>
                </button>
                <button onclick="window.print()" class="btn btn-light btn-sm" title="Print">
                    <i class="fas fa-print"></i>
                </button>
                <button wire:click="$set('activeFilter','settings')" class="btn btn-light btn-sm" title="Settings">
                    <i class="fas fa-cog"></i>
                </button>
            </div>
        </div>
    </div>

    <div class="container-fluid py-3">

        {{-- ===== TODAY SUMMARY CARDS ===== --}}
        <div class="appt-summary-row mb-3">
            @foreach([
                ['label'=>'Booked Today',   'value'=>$statusSummary['Booked']??0,       'icon'=>'fa-calendar-check',  'color'=>'primary'],
                ['label'=>'Arrived',        'value'=>$statusSummary['Arrived']??0,      'icon'=>'fa-user-check',      'color'=>'success'],
                ['label'=>'With Doctor',    'value'=>$statusSummary['With Doctor']??0,  'icon'=>'fa-stethoscope',     'color'=>'info'],
                ['label'=>'Seen Today',     'value'=>$statusSummary['Seen']??0,         'icon'=>'fa-check-double',    'color'=>'secondary'],
                ['label'=>'Missed Today',   'value'=>$statusSummary['Missed']??0,       'icon'=>'fa-user-times',      'color'=>'warning'],
                ['label'=>'Daily Limit',    'value'=>($statusSummary['Booked']??0).'/'.$dailyAppointmentLimit, 'icon'=>'fa-layer-group', 'color'=>'dark'],
            ] as $s)
            <div class="appt-summary-card">
                <div class="appt-summary-icon text-{{ $s['color'] }}"><i class="fas {{ $s['icon'] }}"></i></div>
                <div>
                    <div class="appt-summary-value">{{ $s['value'] }}</div>
                    <div class="appt-summary-label">{{ $s['label'] }}</div>
                </div>
            </div>
            @endforeach
        </div>

        {{-- ===== BULK ACTION BAR ===== --}}
        @if(count($selectedAppointments) > 0)
        <div class="alert alert-dark shadow border-0 d-flex justify-content-between align-items-center mb-3 py-2 px-3">
            <div class="d-flex align-items-center flex-wrap gap-2">
                <span class="font-weight-bold mr-3"><i class="fas fa-check-double mr-1"></i>{{ count($selectedAppointments) }} selected</span>
                <button wire:click="bulkMarkAsSeen" class="btn btn-success btn-sm font-weight-bold mr-1">Mark Seen</button>
                <button wire:click="bulkMarkRemindersSent" class="btn btn-info btn-sm font-weight-bold mr-1">Mark Reminders Sent</button>
                <button wire:click="bulkDelete" onclick="return confirm('Move selected to trash?')" class="btn btn-danger btn-sm font-weight-bold">Trash</button>
            </div>
            <button wire:click="resetSelection" class="btn btn-link text-white font-weight-bold p-0"><i class="fas fa-times"></i></button>
        </div>
        @endif

        {{-- ===== MAIN CARD ===== --}}
        <div class="card shadow-sm border-0">

            {{-- TAB BAR --}}
            <div class="card-header bg-white p-0 border-bottom">
                <ul class="nav appt-tabs">
                    @foreach([
                        'schedule' => ['label'=>'Schedule',     'icon'=>'fa-calendar-alt'],
                        'queue'    => ['label'=>'Waiting Room', 'icon'=>'fa-users'],
                        'history'  => ['label'=>'History',      'icon'=>'fa-history'],
                        'missed'   => ['label'=>'Missed',       'icon'=>'fa-user-times'],
                        'trash'    => ['label'=>'Trash',        'icon'=>'fa-trash-alt'],
                    ] as $key => $tab)
                    <li class="nav-item">
                        <a wire:click.prevent="$set('activeFilter','{{ $key }}')"
                           class="nav-link appt-tab-link {{ $activeFilter===$key ? 'active' : '' }}">
                            <i class="fas {{ $tab['icon'] }} mr-1"></i>{{ $tab['label'] }}
                            <span class="appt-tab-badge {{ $activeFilter===$key ? 'active' : '' }}">{{ $this->counts[$key]??0 }}</span>
                        </a>
                    </li>
                    @endforeach
                </ul>
            </div>

            {{-- ============================= SETTINGS ============================= --}}
            @if($activeFilter === 'settings')
            <div class="card-body p-4">
                <h5 class="font-weight-bold mb-4"><i class="fas fa-cog mr-2 text-muted"></i>Appointment Settings</h5>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="appt-label">Location Link</label>
                        <input type="url" wire:model="clinic_link" class="form-control" placeholder="https://maps.google.com/...">
                        @error('clinic_link') <span class="text-danger small">{{ $message }}</span> @enderror
                    </div>
                    <div class="col-12 mb-3">
                        <small class="text-muted">
                            Reminder, confirmation and follow-up wording (for both SMS and WhatsApp) comes from the
                            @role('Super Admin')
                                <a href="{{ route('admin.settings', ['tab' => 'templates']) }}">SMS Templates</a>.
                            @else
                                SMS Templates in Settings.
                            @endrole
                            The location link fills the <code>[LINK]</code> placeholder.
                        </small>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="appt-label">Daily Appointment Limit</label>
                        <input type="number" min="1" wire:model.live="dailyAppointmentLimit" class="form-control">
                    </div>
                </div>
                <button wire:click="saveSettings" class="btn btn-primary font-weight-bold px-4">Save Settings</button>
            </div>

            {{-- ============================= SCHEDULE ============================= --}}
            @elseif($activeFilter === 'schedule')

                {{-- Toolbar --}}
                <div class="appt-toolbar">
                    <div class="d-flex align-items-center flex-wrap" style="gap:.5rem">
                        <div class="btn-group btn-group-sm" role="group">
                            <button wire:click="$set('scheduleView','list')"
                                    class="btn {{ $scheduleView==='list' ? 'btn-dark' : 'btn-outline-secondary' }}">
                                <i class="fas fa-list mr-1"></i>List
                            </button>
                            <button wire:click="$set('scheduleView','calendar')"
                                    class="btn {{ $scheduleView==='calendar' ? 'btn-dark' : 'btn-outline-secondary' }}">
                                <i class="fas fa-calendar-alt mr-1"></i>Calendar
                            </button>
                            <button wire:click="$set('scheduleView','day')"
                                    class="btn {{ $scheduleView==='day' ? 'btn-dark' : 'btn-outline-secondary' }}">
                                <i class="fas fa-clock mr-1"></i>Day
                            </button>
                            <button wire:click="$set('scheduleView','range')"
                                    class="btn {{ $scheduleView==='range' ? 'btn-dark' : 'btn-outline-secondary' }}">
                                <i class="fas fa-filter mr-1"></i>Range
                            </button>
                        </div>

                        @if($scheduleView === 'calendar' || $scheduleView === 'day')
                        <div class="btn-group btn-group-sm" role="group">
                            <button wire:click="{{ $scheduleView==='calendar' ? 'previousCalendarWeek' : 'previousScheduleDay' }}" class="btn btn-outline-secondary">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <button wire:click="{{ $scheduleView==='calendar' ? 'goToCurrentCalendarWeek' : 'goToTodaySchedule' }}" class="btn btn-outline-secondary">Today</button>
                            <button wire:click="{{ $scheduleView==='calendar' ? 'nextCalendarWeek' : 'nextScheduleDay' }}" class="btn btn-outline-secondary">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                        <span class="font-weight-bold text-dark small">
                            @if($scheduleView==='calendar')
                                {{ \Carbon\Carbon::parse($calendarStartDate)->format('F Y') }}
                            @else
                                {{ \Carbon\Carbon::parse($selectedScheduleDate)->format('l, M d Y') }}
                            @endif
                        </span>
                        @endif
                    </div>

                    @if($scheduleView === 'list')
                    <div class="d-flex align-items-center flex-wrap mt-2" style="gap:.4rem">
                        @foreach(['today'=>'Today','tomorrow'=>'Tomorrow','this_week'=>'This Week','next_30'=>'Next 30 Days'] as $val=>$label)
                        <button wire:click="setQuickFilter('{{ $val }}')"
                                class="btn btn-sm {{ $quickFilter===$val ? 'btn-dark' : 'btn-outline-secondary' }} appt-chip">
                            {{ $label }}
                        </button>
                        @endforeach
                        <div class="ml-2">
                            <input wire:model.live.debounce.300ms="search" type="text" class="form-control form-control-sm"
                                   placeholder="Search patient…" style="min-width:180px">
                        </div>
                    </div>
                    @endif

                    @if($scheduleView === 'range')
                    <div class="d-flex flex-wrap align-items-end mt-2" style="gap:.5rem">
                        <div><label class="appt-label mb-0 d-block">Dates</label><x-date-range from="startDate" to="endDate" presets="upcoming" /></div>
                        <div>
                            <label class="appt-label mb-0">Status</label>
                            <select wire:model.live="statusFilter" class="form-control form-control-sm">
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
                        <div>
                            <label class="appt-label mb-0">Patient</label>
                            <input wire:model.live.debounce.300ms="search" type="text" class="form-control form-control-sm" placeholder="Search…">
                        </div>
                    </div>
                    @endif
                </div>

                {{-- STATUS LEGEND --}}
                @if($scheduleView !== 'range')
                <div class="appt-legend px-3 pb-2 d-flex flex-wrap" style="gap:.35rem">
                    @foreach([
                        ['Pending','secondary'],['Confirmed','primary'],['Arrived','success'],
                        ['With Doctor','info'],['Called','cyan'],['Rescheduled','warning'],
                        ['No Answer','orange'],['Missed','danger'],['Seen','dark'],['Cancelled','light'],
                    ] as $ls)
                    <span class="badge appt-legend-badge badge-{{ $ls[1] }}">{{ $ls[0] }}</span>
                    @endforeach
                </div>
                @endif

                {{-- ---- CALENDAR VIEW ---- --}}
                @if($scheduleView === 'calendar')
                <div class="appointment-calendar"
                     ondragover="event.preventDefault()"
                     ondrop="handleCalendarDrop(event)">
                    <div class="calendar-month-grid">
                        @foreach(['Mon','Tue','Wed','Thu','Fri'] as $wd)
                        <div class="calendar-weekday">{{ $wd }}</div>
                        @endforeach

                        @foreach($calendarWeeks as $week)
                            @foreach($week as $day)
                            @php
                                $dateKey       = $day->format('Y-m-d');
                                $dayAppointments = $calendarAppointments->get($dateKey, collect());
                                $isToday       = $day->isToday();
                                $isCurrentMonth= $day->month === \Carbon\Carbon::parse($calendarStartDate)->month;
                                $isPastDate    = $day->lt(now()->startOfDay());
                                $showMax       = 3;
                                $overflow      = max(0, $dayAppointments->count() - $showMax);
                            @endphp
                            <div class="calendar-day {{ !$isCurrentMonth ? 'is-muted' : '' }} {{ $isToday ? 'is-today' : '' }} {{ $isPastDate ? 'is-past' : '' }}"
                                 data-date="{{ $dateKey }}"
                                 ondragover="event.preventDefault(); this.classList.add('drag-over')"
                                 ondragleave="this.classList.remove('drag-over')"
                                 ondrop="this.classList.remove('drag-over'); handleCalendarDrop(event, '{{ $dateKey }}')">
                                <div class="calendar-day-number {{ $isToday ? 'today-number' : '' }}">{{ $day->format('j') }}</div>
                                @if(!$isPastDate)
                                <button wire:click="bookOnCalendarDate('{{ $dateKey }}')" class="calendar-day-hit" title="Book on {{ $day->format('M d') }}"></button>
                                @endif
                                <div class="calendar-day-stack">
                                    @foreach($dayAppointments->take($showMax) as $app)
                                    @php
                                        $chipColor = [
                                            'Arrived'=>'green','Done'=>'green','Confirmed'=>'blue',
                                            'With Doctor'=>'blue','Called'=>'cyan',
                                            'Couldnt Answer'=>'orange','Rescheduled'=>'orange',
                                        ][$app->status] ?? (['yellow','rose','mint','sky'][$loop->index % 4]);
                                        $patientContact = (string)($app->patient->contact??'');
                                        $waUrl = $this->reminderWhatsAppUrl($app);
                                    @endphp
                                    <div class="calendar-appointment calendar-chip-{{ $chipColor }}"
                                         draggable="true"
                                         ondragstart="event.dataTransfer.setData('appointmentId','{{ $app->id }}'); event.dataTransfer.effectAllowed='move'">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div class="calendar-patient">{{ $app->patient->name }}</div>
                                            <span class="calendar-time-badge">{{ $app->scheduled_at->format('h:i A') }}</span>
                                        </div>
                                        <div class="calendar-reason">{{ Str::limit($app->title, 18) }}</div>
                                        <div class="calendar-contact-actions">
                                            @if($patientContact)
                                            <a href="tel:{{ $patientContact }}" class="calendar-action calendar-action-call" onclick="event.stopPropagation()"><i class="fas fa-phone-alt"></i></a>
                                            @endif
                                            @if($waUrl)
                                            <a href="{{ $waUrl }}" wire:click="markReminderSent({{ $app->id }},'whatsapp')" target="_blank" class="calendar-action calendar-action-whatsapp" onclick="event.stopPropagation()"><i class="fab fa-whatsapp"></i></a>
                                            @endif
                                            <button wire:click="editAppointment({{ $app->id }})" class="calendar-action calendar-action-edit" onclick="event.stopPropagation()"><i class="fas fa-pen"></i></button>
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
                <div class="day-view-grid px-3 pb-3">
                    @foreach($dayTimeSlots as $slot)
                    @php
                        $slotKey  = $slot->format('H:00');
                        $slotApps = $dayAppointments->get($slotKey, collect());
                    @endphp
                    <div class="day-slot {{ $slot->isCurrentHour() ? 'day-slot-now' : '' }}">
                        <div class="day-slot-label">{{ $slot->format('g A') }}</div>
                        <div class="day-slot-body">
                            @forelse($slotApps as $app)
                            @php
                                $statusColor=['Arrived'=>'success','Confirmed'=>'primary','With Doctor'=>'info','Pending'=>'secondary','Rescheduled'=>'warning','Seen'=>'dark','Missed'=>'danger','Cancelled'=>'light'][$app->status]??'secondary';
                            @endphp
                            <div class="day-event border-left border-{{ $statusColor }} pl-2 mb-1">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="font-weight-bold small">{{ $app->patient->name }}</div>
                                        <div class="text-muted" style="font-size:.75rem">{{ $app->title }} · {{ $app->scheduled_at->format('h:i A') }}</div>
                                    </div>
                                    <div class="d-flex" style="gap:.25rem">
                                        <span class="badge badge-{{ $statusColor }}">{{ $app->status }}</span>
                                        <button wire:click="editAppointment({{ $app->id }})" class="btn btn-xs btn-outline-secondary"><i class="fas fa-pen"></i></button>
                                    </div>
                                </div>
                            </div>
                            @empty
                            @endforelse
                        </div>
                    </div>
                    @endforeach
                </div>

                {{-- ---- LIST / RANGE VIEW ---- --}}
                @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0 appt-table">
                        <thead class="thead-light">
                            <tr>
                                <th style="width:36px"><input type="checkbox" wire:model.live="selectAll"></th>
                                <th>Time &amp; Date</th>
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
                                $statusColor=['Arrived'=>'success','Confirmed'=>'primary','With Doctor'=>'info','Pending'=>'secondary','Called'=>'warning','Rescheduled'=>'warning','Seen'=>'dark','Missed'=>'danger','Cancelled'=>'light'][$app->status]??'secondary';
                                $missStats=$noShowStats[$app->patient_id]??['missed'=>0,'total'=>0,'rate'=>0,'class'=>'success'];
                            @endphp
                            <tr>
                                <td class="align-middle"><input type="checkbox" wire:model.live="selectedAppointments" value="{{ $app->id }}"></td>
                                <td class="align-middle">
                                    <div class="font-weight-bold small">{{ $app->scheduled_at->format('h:i A') }}</div>
                                    <div class="text-muted" style="font-size:.75rem">{{ $app->scheduled_at->format('M d, Y') }}</div>
                                    <div class="text-muted" style="font-size:.7rem">{{ $app->scheduled_at->diffForHumans() }}</div>
                                </td>
                                <td class="align-middle">
                                    <div class="font-weight-bold">{{ $app->patient->name }}</div>
                                    <div class="text-muted small">{{ $app->patient->pxnumber }}</div>
                                    @if($missStats['total'] > 0)
                                    <span class="badge badge-{{ $missStats['class'] }}" style="font-size:.65rem">
                                        {{ $missStats['missed'] }}/{{ $missStats['total'] }} missed
                                    </span>
                                    @endif
                                </td>
                                <td class="align-middle">
                                    <div class="small">{{ $app->title }}</div>
                                    <div class="text-muted small"><i class="fas fa-user-md mr-1"></i>{{ $app->doctor->name ?? 'Unassigned' }} &middot; {{ $app->duration_minutes ?? 30 }} min</div>
                                    @if($app->recall_category)
                                    <span class="badge badge-light border" style="font-size:.65rem">{{ $app->recall_category }}</span>
                                    @endif
                                </td>
                                <td class="align-middle">
                                    @php
                                        $patientContact=(string)($app->patient->contact??'');
                                        $waUrl = $this->reminderWhatsAppUrl($app);
                                    @endphp
                                    <div class="d-flex flex-wrap" style="gap:.25rem">
                                        @if($patientContact)
                                        <a href="tel:{{ $patientContact }}" class="btn btn-xs btn-outline-success" title="Call"><i class="fas fa-phone-alt"></i></a>
                                        <a href="{{ $waUrl ?? '#' }}"
                                           wire:click="markReminderSent({{ $app->id }},'whatsapp')"
                                           target="_blank" class="btn btn-xs btn-outline-success" title="WhatsApp"><i class="fab fa-whatsapp"></i></a>
                                        <button wire:click="sendSmsNow({{ $app->id }})" class="btn btn-xs btn-outline-primary" title="Send SMS"
                                                wire:loading.attr="disabled" wire:target="sendSmsNow({{ $app->id }})">
                                            <span wire:loading.remove wire:target="sendSmsNow({{ $app->id }})"><i class="fas fa-sms"></i></span>
                                            <span wire:loading wire:target="sendSmsNow({{ $app->id }})"><i class="fas fa-circle-notch fa-spin"></i></span>
                                        </button>
                                        @else
                                        <span class="text-muted small font-italic">No contact</span>
                                        @endif
                                    </div>
                                    <div class="mt-1">
                                        <span class="badge badge-{{ ($app->reminder_status??'not_sent')==='sent' ? 'success' : 'light border' }}" style="font-size:.65rem">
                                            <i class="fas fa-{{ ($app->reminder_status??'not_sent')==='sent' ? 'check' : 'clock' }} mr-1"></i>
                                            {{ ($app->reminder_status??'not_sent')==='sent' ? 'Reminder sent' : 'Not sent' }}
                                        </span>
                                    </div>
                                </td>
                                <td class="align-middle">
                                    <span class="badge badge-{{ $statusColor }} appt-status-badge">{{ $app->status }}</span>
                                </td>
                                <td class="align-middle text-right" style="white-space:nowrap">
                                    @if($app->status === 'Pending')
                                    <button wire:click="confirmAppointment({{ $app->id }})" class="btn btn-xs btn-outline-primary mr-1" title="Confirm"><i class="fas fa-check"></i></button>
                                    @endif
                                    <button wire:click="editAppointment({{ $app->id }})" class="btn btn-xs btn-outline-secondary mr-1" title="Edit"><i class="fas fa-pen"></i></button>
                                    @if(!in_array($app->status, ['Seen','Cancelled','Missed']))
                                    <button wire:click="openCancelModal({{ $app->id }})" class="btn btn-xs btn-outline-danger" title="Cancel"><i class="fas fa-times"></i></button>
                                    @endif
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="text-center py-5">
                                    <i class="fas fa-calendar-times fa-3x text-muted mb-3 d-block"></i>
                                    <p class="text-muted mb-0">No appointments found
                                    @if($quickFilter) for "{{ ucwords(str_replace('_',' ',$quickFilter)) }}"@endif.
                                    </p>
                                    <button wire:click="openNewAppointmentModal" class="btn btn-sm btn-primary mt-3">
                                        <i class="fas fa-plus mr-1"></i> Book One
                                    </button>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($appointments->hasPages())
                <div class="card-footer bg-white py-2">{{ $appointments->links() }}</div>
                @endif
                @endif

            {{-- ============================= WAITING ROOM ============================= --}}
            @elseif($activeFilter === 'queue')
            <div class="appt-toolbar">
                <div class="d-flex align-items-center justify-content-between w-100 flex-wrap" style="gap:.5rem">
                    <div>
                        <span class="font-weight-bold">Today's Waiting Room</span>
                        <span class="text-muted small ml-2">{{ now()->format('l, M d Y') }}</span>
                    </div>
                    <div class="d-flex" style="gap:.4rem">
                        <button wire:click="exportReport" class="btn btn-sm btn-outline-secondary"><i class="fas fa-download mr-1"></i>Export</button>
                        <button wire:click="closeClinicDay"
                                onclick="return confirm('Move all unfinished appointments to tomorrow?')"
                                class="btn btn-sm btn-outline-warning font-weight-bold">
                            <i class="fas fa-moon mr-1"></i>Close Day
                        </button>
                    </div>
                </div>
                <div class="mt-2">
                    <input wire:model.live.debounce.300ms="search" type="text" class="form-control form-control-sm" placeholder="Search patient…" style="max-width:260px">
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 appt-table">
                    <thead class="thead-light">
                        <tr>
                            <th style="width:36px"><input type="checkbox" wire:model.live="selectAll"></th>
                            <th>Time</th>
                            <th>Patient</th>
                            <th>Reason</th>
                            <th>Queue Actions</th>
                            <th>Status</th>
                            <th class="text-right">Edit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($appointments as $app)
                        @php
                            $statusColor=['Arrived'=>'success','With Doctor'=>'info','Pending'=>'secondary','Called'=>'warning','Done'=>'dark'][$app->status]??'secondary';
                        @endphp
                        <tr class="{{ $app->status==='Arrived' ? 'table-success' : ($app->status==='With Doctor' ? 'table-info' : '') }}" style="opacity:{{ $app->status==='Done' ? '.6' : '1' }}">
                            <td class="align-middle"><input type="checkbox" wire:model.live="selectedAppointments" value="{{ $app->id }}"></td>
                            <td class="align-middle">
                                <div class="font-weight-bold small">{{ $app->scheduled_at->format('h:i A') }}</div>
                            </td>
                            <td class="align-middle">
                                <div class="font-weight-bold">{{ $app->patient->name }}</div>
                                <div class="text-muted small">{{ $app->patient->contact ?? '—' }}</div>
                            </td>
                            <td class="align-middle small">{{ $app->title }}</td>
                            <td class="align-middle">
                                <div class="btn-group btn-group-sm">
                                    <button wire:click="advanceQueueStatus({{ $app->id }},'Arrived')" class="btn btn-outline-success font-weight-bold">Arrived</button>
                                    <button wire:click="advanceQueueStatus({{ $app->id }},'With Doctor')" class="btn btn-outline-info font-weight-bold">Doctor</button>
                                    <button wire:click="advanceQueueStatus({{ $app->id }},'Done')" class="btn btn-outline-dark font-weight-bold">Done</button>
                                </div>
                            </td>
                            <td class="align-middle">
                                <span class="badge badge-{{ $statusColor }} appt-status-badge">{{ $app->status }}</span>
                            </td>
                            <td class="align-middle text-right">
                                <button wire:click="editAppointment({{ $app->id }})" class="btn btn-xs btn-outline-secondary"><i class="fas fa-pen"></i></button>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="text-center py-5">
                                <i class="fas fa-couch fa-3x text-muted mb-3 d-block"></i>
                                <p class="text-muted mb-2">Waiting room is empty.</p>
                                <button wire:click="openWalkInModal" class="btn btn-sm btn-success"><i class="fas fa-walking mr-1"></i>Add Walk-in</button>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($appointments->hasPages())
            <div class="card-footer bg-white py-2">{{ $appointments->links() }}</div>
            @endif

            {{-- ============================= HISTORY ============================= --}}
            @elseif($activeFilter === 'history')
            <div class="appt-toolbar">
                <div class="d-flex align-items-center justify-content-between w-100">
                    <input wire:model.live.debounce.300ms="search" type="text" class="form-control form-control-sm" placeholder="Search patient…" style="max-width:260px">
                    <button wire:click="exportReport" class="btn btn-sm btn-outline-secondary"><i class="fas fa-download mr-1"></i>Export</button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 appt-table">
                    <thead class="thead-light">
                        <tr>
                            <th style="width:36px"><input type="checkbox" wire:model.live="selectAll"></th>
                            <th>Date &amp; Time</th>
                            <th>Patient</th>
                            <th>Reason</th>
                            <th>Outcome</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($appointments as $app)
                        @php $isCancelled = $app->status === 'Cancelled'; @endphp
                        <tr class="{{ $isCancelled ? '' : 'table-success' }}" style="{{ $isCancelled ? 'opacity:.7' : '' }}">
                            <td class="align-middle"><input type="checkbox" wire:model.live="selectedAppointments" value="{{ $app->id }}"></td>
                            <td class="align-middle">
                                <div class="font-weight-bold small">{{ $app->scheduled_at->format('M d, Y') }}</div>
                                <div class="text-muted" style="font-size:.75rem">{{ $app->scheduled_at->format('h:i A') }}</div>
                            </td>
                            <td class="align-middle">
                                <div class="font-weight-bold">{{ $app->patient->name }}</div>
                                <div class="text-muted small">{{ $app->patient->pxnumber }}</div>
                            </td>
                            <td class="align-middle small">{{ $app->title }}</td>
                            <td class="align-middle">
                                <span class="badge badge-{{ $isCancelled ? 'secondary' : 'success' }}">{{ $app->status }}</span>
                            </td>
                            <td class="align-middle text-right">
                                <button wire:click="editAppointment({{ $app->id }})" class="btn btn-xs btn-outline-secondary"><i class="fas fa-pen"></i></button>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="6" class="text-center py-5 text-muted"><i class="fas fa-history fa-3x mb-3 d-block"></i>No history yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($appointments->hasPages())
            <div class="card-footer bg-white py-2">{{ $appointments->links() }}</div>
            @endif

            {{-- ============================= MISSED ============================= --}}
            @elseif($activeFilter === 'missed')
            <div class="appt-toolbar">
                <div class="d-flex align-items-center justify-content-between w-100">
                    <input wire:model.live.debounce.300ms="search" type="text" class="form-control form-control-sm" placeholder="Search patient…" style="max-width:260px">
                    <button wire:click="exportReport" class="btn btn-sm btn-outline-secondary"><i class="fas fa-download mr-1"></i>Export</button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 appt-table">
                    <thead class="thead-light">
                        <tr>
                            <th style="width:36px"><input type="checkbox" wire:model.live="selectAll"></th>
                            <th>Missed On</th>
                            <th>Patient</th>
                            <th>Reason</th>
                            <th>Follow-up</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($appointments as $app)
                        <tr>
                            <td class="align-middle"><input type="checkbox" wire:model.live="selectedAppointments" value="{{ $app->id }}"></td>
                            <td class="align-middle">
                                <div class="font-weight-bold small text-danger">{{ $app->scheduled_at->format('M d, Y') }}</div>
                                <div class="text-muted" style="font-size:.75rem">{{ $app->scheduled_at->diffForHumans() }}</div>
                            </td>
                            <td class="align-middle">
                                <div class="font-weight-bold">{{ $app->patient->name }}</div>
                                <div class="text-muted small">{{ $app->patient->contact ?? '—' }}</div>
                            </td>
                            <td class="align-middle small">{{ $app->title }}</td>
                            <td class="align-middle">
                                {{-- Inline reschedule panel --}}
                                @if($missedActionId === $app->id)
                                <div class="d-flex align-items-end flex-wrap" style="gap:.35rem">
                                    <div>
                                        <label class="appt-label mb-0">Date</label>
                                        <input type="date" wire:model.live="rescheduleDate" class="form-control form-control-sm" style="width:130px">
                                        @error('rescheduleDate') <div class="text-danger" style="font-size:.7rem">{{ $message }}</div> @enderror
                                    </div>
                                    <div>
                                        <label class="appt-label mb-0">Time</label>
                                        <input type="time" wire:model.live="rescheduleTime" class="form-control form-control-sm" style="width:100px">
                                    </div>
                                    <button wire:click="rescheduleMissed" class="btn btn-sm btn-primary font-weight-bold">Confirm</button>
                                    <button wire:click="closeMissedAction" class="btn btn-sm btn-outline-secondary">Cancel</button>
                                </div>
                                @else
                                <div class="d-flex flex-wrap" style="gap:.3rem">
                                    @if($app->patient->contact)
                                    <a href="tel:{{ $app->patient->contact }}" class="btn btn-xs btn-outline-success" title="Call"><i class="fas fa-phone-alt"></i></a>
                                    @if($followUpUrl = $this->missedFollowUpWhatsAppUrl($app))
                                    <a href="{{ $followUpUrl }}" target="_blank" rel="noopener" class="btn btn-xs btn-outline-success" title="Send follow-up on WhatsApp"><i class="fab fa-whatsapp"></i></a>
                                    @endif
                                    <button wire:click="sendMissedFollowUpSms({{ $app->id }})" class="btn btn-xs btn-outline-primary" title="Send follow-up SMS"><i class="fas fa-sms"></i></button>
                                    @endif
                                    <button wire:click="openMissedAction({{ $app->id }})" class="btn btn-xs btn-outline-warning" title="Reschedule"><i class="fas fa-calendar-plus"></i></button>
                                    <button wire:click="resolveMissed({{ $app->id }})" class="btn btn-xs btn-outline-dark" title="Mark Resolved"><i class="fas fa-check"></i></button>
                                </div>
                                @endif
                            </td>
                            <td class="align-middle text-right">
                                <button wire:click="editAppointment({{ $app->id }})" class="btn btn-xs btn-outline-secondary"><i class="fas fa-pen"></i></button>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="text-center py-5">
                                <i class="fas fa-check-circle fa-3x text-success mb-3 d-block"></i>
                                <p class="text-muted mb-0">No missed appointments. Great work!</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($appointments->hasPages())
            <div class="card-footer bg-white py-2">{{ $appointments->links() }}</div>
            @endif

            {{-- ============================= TRASH ============================= --}}
            @elseif($activeFilter === 'trash')
            <div class="appt-toolbar">
                <input wire:model.live.debounce.300ms="search" type="text" class="form-control form-control-sm" placeholder="Search patient…" style="max-width:260px">
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 appt-table">
                    <thead class="thead-light">
                        <tr>
                            <th style="width:36px"><input type="checkbox" wire:model.live="selectAll"></th>
                            <th>Date</th>
                            <th>Patient</th>
                            <th>Reason</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($appointments as $app)
                        <tr style="opacity:.7">
                            <td class="align-middle"><input type="checkbox" wire:model.live="selectedAppointments" value="{{ $app->id }}"></td>
                            <td class="align-middle small">{{ $app->scheduled_at->format('M d, Y') }}</td>
                            <td class="align-middle"><div class="font-weight-bold">{{ $app->patient->name }}</div></td>
                            <td class="align-middle small">{{ $app->title }}</td>
                            <td class="align-middle text-right">
                                <button wire:click="restoreAppointment({{ $app->id }})" class="btn btn-xs btn-success font-weight-bold">
                                    <i class="fas fa-undo mr-1"></i>Restore
                                </button>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="text-center py-5 text-muted"><i class="fas fa-trash-alt fa-3x mb-3 d-block"></i>Trash is empty.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($appointments->hasPages())
            <div class="card-footer bg-white py-2">{{ $appointments->links() }}</div>
            @endif

            @endif {{-- end activeFilter --}}

        </div> {{-- end .card --}}
    </div> {{-- end .container-fluid --}}

    {{-- ===== APPOINTMENT MODAL ===== --}}
    @if($isEditModalOpen)
    <div class="modal d-block appt-modal-backdrop" wire:click.self="closeModal">
        <div class="modal-dialog modal-dialog-centered" style="max-width:540px">
            <div class="modal-content shadow-lg border-0 rounded-lg overflow-hidden">
                <div class="modal-header bg-white border-bottom py-3 px-4">
                    <h5 class="modal-title font-weight-bold mb-0">
                        <i class="fas fa-calendar-plus mr-2 text-primary"></i>
                        {{ $editingAppointmentId ? 'Edit Appointment' : ($newAppointmentStatus==='Arrived' ? 'Walk-in Visit' : 'New Appointment') }}
                    </h5>
                    <button type="button" class="close" wire:click="closeModal"><span>&times;</span></button>
                </div>
                <form wire:submit="saveAppointment">
                    <div class="modal-body px-4 py-3" style="max-height:calc(100vh - 180px); overflow-y:auto">

                        {{-- SECTION: Patient --}}
                        <div class="appt-modal-section-label">Patient</div>
                        <div class="form-group position-relative">
                            @if($selectedPatientName)
                            <div class="d-flex align-items-center justify-content-between bg-light border rounded px-3 py-2">
                                <span class="font-weight-bold"><i class="fas fa-user-circle mr-2 text-primary"></i>{{ $selectedPatientName }}</span>
                                <button type="button" wire:click="clearSelectedPatient" class="btn btn-sm btn-link text-danger p-0"><i class="fas fa-times"></i></button>
                            </div>
                            @else
                            <input type="text" wire:model.live.debounce.300ms="patientSearch"
                                   class="form-control" placeholder="Search by name, phone, or PX number…">
                            @if(!empty($searchablePatients))
                            <div class="list-group position-absolute w-100 shadow-lg mt-1" style="z-index:1100; max-height:200px; overflow-y:auto">
                                @foreach($searchablePatients as $p)
                                <button type="button" wire:click="selectPatient({{ $p->id }},'{{ addslashes($p->name) }}')"
                                        class="list-group-item list-group-item-action py-2">
                                    <div class="font-weight-bold">{{ $p->name }}</div>
                                    <div class="text-muted small">{{ $p->pxnumber }} · {{ $p->contact ?? 'No contact' }}
                                        @if($p->dob) · Age {{ \Carbon\Carbon::parse($p->dob)->age }} @endif
                                    </div>
                                </button>
                                @endforeach
                            </div>
                            @endif
                            @endif
                            @error('patient_id') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>

                        {{-- SECTION: Visit Details --}}
                        <div class="appt-modal-section-label mt-3">Visit Details</div>
                        <div class="form-group">
                            <label class="appt-label">Reason</label>
                            <select wire:model.live="title" class="form-control">
                                <option value="">— Select reason —</option>
                                @foreach($appointmentReasons as $reason)
                                <option value="{{ $reason }}">{{ $reason }}</option>
                                @endforeach
                            </select>
                            @error('title') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="form-group">
                            <label class="appt-label">Recall Category</label>
                            <select wire:model.live="recall_category" class="form-control">
                                <option value="">Use reason</option>
                                @foreach($recallCategories as $cat)
                                <option value="{{ $cat }}">{{ $cat }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="appt-label">Notes</label>
                            <textarea wire:model.live="notes" rows="2" class="form-control"></textarea>
                        </div>

                        {{-- SECTION: Reminder --}}
                        <div class="appt-modal-section-label mt-3">Reminder</div>
                        <div class="form-group">
                            <label class="appt-label">Channel</label>
                            <select wire:model.live="reminder_channel" class="form-control">
                                <option value="whatsapp">WhatsApp</option>
                                <option value="sms">SMS</option>
                                <option value="both">SMS + WhatsApp</option>
                                <option value="none">No reminder</option>
                            </select>
                        </div>

                        {{-- SECTION: Schedule --}}
                        <div class="appt-modal-section-label mt-3">Schedule</div>
                        <div class="form-group">
                            <label class="appt-label">Date &amp; Time</label>
                            <input type="datetime-local" wire:model.live="scheduled_at"
                                   min="{{ now()->format('Y-m-d\TH:i') }}" class="form-control">
                            @error('scheduled_at') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="row">
                            <div class="col-md-7 form-group mb-0">
                                <label class="appt-label">Doctor</label>
                                <select wire:model.live="doctor_id" class="form-control">
                                    <option value="">Unassigned</option>
                                    @foreach($doctors as $doctor)
                                        <option value="{{ $doctor->id }}">{{ $doctor->name }}</option>
                                    @endforeach
                                </select>
                                @error('doctor_id') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-5 form-group mb-0">
                                <label class="appt-label">Duration</label>
                                <select wire:model.live="duration_minutes" class="form-control">
                                    @foreach([15,30,45,60,90,120] as $minutes)
                                        <option value="{{ $minutes }}">{{ $minutes }} minutes</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light px-4 py-3">
                        <button type="button" wire:click="closeModal" class="btn btn-outline-secondary">Discard</button>
                        <button type="submit" class="btn btn-primary font-weight-bold px-4">
                            <i class="fas fa-save mr-1"></i>
                            {{ $editingAppointmentId ? 'Save Changes' : ($newAppointmentStatus==='Arrived' ? 'Add to Waiting Room' : 'Save Appointment') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endif

    {{-- ===== CANCEL MODAL ===== --}}
    @if($isCancelModalOpen)
    <div class="modal d-block appt-modal-backdrop">
        <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
            <div class="modal-content shadow-lg border-0 rounded-lg overflow-hidden">
                <div class="modal-header bg-danger text-white py-3 px-4">
                    <h5 class="modal-title font-weight-bold mb-0"><i class="fas fa-times-circle mr-2"></i>Cancel Appointment</h5>
                    <button type="button" class="close text-white" wire:click="closeCancelModal"><span>&times;</span></button>
                </div>
                <div class="modal-body px-4 py-3">
                    <div class="form-group mb-0">
                        <label class="appt-label">Reason <span class="text-muted font-weight-normal">(optional)</span></label>
                        <textarea wire:model.live="cancelReason" rows="3" class="form-control" placeholder="e.g. Patient requested cancellation…"></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light px-4 py-3">
                    <button type="button" wire:click="closeCancelModal" class="btn btn-outline-secondary">Back</button>
                    <button type="button" wire:click="confirmCancelAppointment" class="btn btn-danger font-weight-bold">Confirm Cancel</button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ===== REMINDER PREVIEW MODAL ===== --}}
    @if($showReminderPreview)
    <div class="modal d-block appt-modal-backdrop">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg border-0 rounded-lg overflow-hidden">
                <div class="modal-header bg-white border-bottom py-3 px-4">
                    <h5 class="modal-title font-weight-bold mb-0"><i class="fab fa-whatsapp mr-2 text-success"></i>Reminder Preview</h5>
                    <button type="button" class="close" wire:click="closeReminderPreview"><span>&times;</span></button>
                </div>
                <div class="modal-body px-4 py-3">
                    <textarea id="reminderPreviewMessage" class="form-control" rows="7" readonly>{{ $previewReminderMessage }}</textarea>
                    <small class="text-muted d-block mt-2">Copy into WhatsApp Web, or open directly.</small>
                </div>
                <div class="modal-footer bg-light px-4 py-3">
                    <button type="button" class="btn btn-outline-secondary"
                            onclick="navigator.clipboard.writeText(document.getElementById('reminderPreviewMessage').value)">
                        <i class="fas fa-copy mr-1"></i>Copy
                    </button>
                    <a href="{{ $this->previewWhatsAppUrl }}" target="_blank" class="btn btn-success">
                        <i class="fab fa-whatsapp mr-1"></i>Open WhatsApp
                    </a>
                    <button type="button" class="btn btn-primary" wire:click="markPreviewReminderSent">Mark Sent</button>
                </div>
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
/* ===== PAGE LAYOUT ===== */
.appt-page { font-family: inherit; }

/* Header */
.appt-header { background: #fff; border-bottom: 1px solid #edf0f4; padding: .9rem 0; position: sticky; top: 0; z-index: 100; }
.appt-header-inner { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .5rem; }
.appt-header-kicker { font-size: .7rem; font-weight: 800; text-transform: uppercase; color: #9ca3af; letter-spacing: .05em; }
.appt-header-title { font-size: 1.35rem; font-weight: 800; color: #1f2937; margin: 0; }
.appt-header-actions { display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; }

/* Summary cards */
.appt-summary-row { display: flex; flex-wrap: wrap; gap: .5rem; }
.appt-summary-card { background: #fff; border: 1px solid #edf0f4; border-radius: 8px; padding: .6rem .9rem; display: flex; align-items: center; gap: .6rem; min-width: 120px; box-shadow: 0 1px 4px rgba(15,23,42,.04); }
.appt-summary-icon { font-size: 1.1rem; width: 28px; text-align: center; }
.appt-summary-value { font-size: 1.1rem; font-weight: 800; color: #1f2937; line-height: 1.1; }
.appt-summary-label { font-size: .68rem; color: #6b7280; font-weight: 600; }

/* Tabs */
.appt-tabs { border-bottom: none; }
.appt-tab-link { display: flex; align-items: center; gap: .3rem; padding: .75rem 1rem; border: none; border-bottom: 3px solid transparent; color: #6b7280; font-size: .82rem; font-weight: 600; transition: all .2s; cursor: pointer; white-space: nowrap; }
.appt-tab-link:hover { color: #374151; border-bottom-color: #d1d5db; }
.appt-tab-link.active { color: #111827; border-bottom-color: #111827; }
.appt-tab-badge { background: #f3f4f6; color: #374151; border-radius: 999px; font-size: .65rem; font-weight: 700; padding: .12em .45em; min-width: 18px; text-align: center; }
.appt-tab-badge.active { background: #111827; color: #fff; }

/* Toolbar */
.appt-toolbar { padding: .75rem 1rem; background: #fafafa; border-bottom: 1px solid #edf0f4; }

/* Quick filter chips */
.appt-chip { border-radius: 999px; font-size: .75rem; padding: .28rem .75rem; }

/* Legend */
.appt-legend { padding-top: .4rem; border-bottom: 1px solid #edf0f4; background: #fff; }
.appt-legend-badge { font-size: .68rem; font-weight: 600; opacity: .85; }

/* Table */
.appt-table th { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; white-space: nowrap; }
.appt-table td { vertical-align: middle; font-size: .85rem; }
.appt-status-badge { font-size: .72rem; padding: .28em .55em; font-weight: 700; }

/* Modal backdrop */
.appt-modal-backdrop { background: rgba(0,0,0,.55); }

/* Modal section label */
.appt-modal-section-label { font-size: .68rem; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; color: #9ca3af; margin-bottom: .4rem; border-bottom: 1px solid #f3f4f6; padding-bottom: .3rem; }

/* Form label utility */
.appt-label { font-size: .72rem; font-weight: 700; color: #374151; text-transform: uppercase; letter-spacing: .04em; margin-bottom: .25rem; display: block; }

/* btn-xs */
.btn-xs { font-size: .7rem; padding: .25rem .5rem; border-radius: 4px; }

/* ===== CALENDAR ===== */
.appointment-calendar { background: #fff; padding: 0 1rem 1.5rem; }
.calendar-month-grid { display: grid; grid-template-columns: repeat(5, minmax(140px, 1fr)); border-top: 1px solid #edf0f4; border-left: 1px solid #edf0f4; overflow-x: auto; }
.calendar-weekday { padding: .6rem .75rem; color: #667085; font-size: .7rem; font-weight: 800; border-right: 1px solid #edf0f4; border-bottom: 1px solid #edf0f4; text-align: center; text-transform: uppercase; }
.calendar-day { position: relative; min-width: 140px; min-height: 128px; padding: 1.5rem .6rem .6rem; border-right: 1px solid #edf0f4; border-bottom: 1px solid #edf0f4; background: #fff; transition: background .15s; }
.calendar-day.is-muted { background: #fafafa; }
.calendar-day.is-muted .calendar-day-number { color: #c4cad4; }
.calendar-day.is-today { background: #fffbeb; }
.calendar-day.is-past { background: #f9fafb; pointer-events: none; opacity: .7; }
.calendar-day.drag-over { background: #eff6ff !important; outline: 2px dashed #3b82f6; }
.calendar-day-number { position: absolute; top: .5rem; left: .65rem; font-size: .72rem; font-weight: 800; color: #667085; z-index: 2; }
.calendar-day-number.today-number { background: #1d4ed8; color: #fff; width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: .65rem; }
.calendar-day-hit { position: absolute; inset: 0; width: 100%; border: 0; background: transparent; cursor: copy; z-index: 1; }
.calendar-day-stack { position: relative; z-index: 2; display: flex; flex-direction: column; gap: .35rem; pointer-events: none; }
.calendar-appointment { border-radius: 5px; padding: .3rem .45rem; border: 1px solid transparent; box-shadow: 0 2px 6px rgba(15,23,42,.06); pointer-events: auto; cursor: grab; }
.calendar-appointment:active { cursor: grabbing; }
.calendar-patient { font-size: .67rem; font-weight: 800; color: #1f2937; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100px; }
.calendar-reason { font-size: .6rem; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.calendar-time-badge { font-size: .58rem; font-weight: 700; color: #475467; white-space: nowrap; }
.calendar-contact-actions { display: flex; flex-wrap: wrap; gap: .2rem; margin-top: .3rem; }
.calendar-action { display: inline-flex; align-items: center; justify-content: center; min-height: 20px; width: 22px; border-radius: 4px; background: #fff; font-size: .65rem; border: 1px solid transparent; cursor: pointer; text-decoration: none !important; }
.calendar-action-call { color: #16a34a; border-color: #16a34a; }
.calendar-action-whatsapp { color: #16a34a; border-color: #16a34a; }
.calendar-action-edit { color: #6b7280; border-color: #d1d5db; }
.calendar-overflow { font-size: .65rem; font-weight: 700; color: #6b7280; text-align: center; margin-top: .2rem; cursor: pointer; }
/* Calendar chip colours */
.calendar-chip-yellow { background: #fef9c3; border-color: #facc15; }
.calendar-chip-rose   { background: #ffe4e6; border-color: #fb7185; }
.calendar-chip-mint,
.calendar-chip-green  { background: #dcfce7; border-color: #4ade80; }
.calendar-chip-sky,
.calendar-chip-blue,
.calendar-chip-cyan   { background: #dbeafe; border-color: #60a5fa; }
.calendar-chip-orange { background: #ffedd5; border-color: #fb923c; }

/* ===== DAY VIEW ===== */
.day-view-grid { padding-top: .75rem; }
.day-slot { display: grid; grid-template-columns: 52px 1fr; border-bottom: 1px solid #f3f4f6; min-height: 52px; align-items: start; padding: .35rem 0; }
.day-slot-now { background: #fefce8; }
.day-slot-label { font-size: .72rem; font-weight: 700; color: #9ca3af; padding-top: .2rem; }
.day-slot-body { padding-left: .5rem; }
.day-event { background: #f9fafb; border-radius: 4px; padding: .3rem .5rem; }

/* Responsive */
@media (max-width: 767px) {
    .appt-summary-row { gap: .35rem; }
    .appt-summary-card { min-width: calc(50% - .35rem); }
    .appt-header-title { font-size: 1.1rem; }
    .calendar-month-grid { grid-template-columns: repeat(5, minmax(120px, 1fr)); }
    .calendar-day, .calendar-weekday { min-width: 120px; }
}
</style>
</div>
