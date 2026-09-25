<div>
    {{-- Page header --}}
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2 align-items-center">
                <div class="col-sm-6">
                    <h1 class="m-0">
                        <i class="fas fa-stream mr-2 text-primary"></i>
                        Clinical Timeline
                    </h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ route('doctor.dashboard') }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">Timeline</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">

            {{-- Patient header card --}}
            <div class="card card-primary card-outline mb-3">
                <div class="card-body py-3">
                    <div class="d-flex align-items-center flex-wrap" style="gap:16px;">
                        <div class="rounded-circle d-flex align-items-center justify-content-center text-white font-weight-bold"
                            style="width:56px;height:56px;font-size:22px;background:#003087;flex-shrink:0;">
                            {{ strtoupper(substr($patient->name, 0, 1)) }}
                        </div>
                        <div>
                            <h4 class="mb-0 font-weight-bold">{{ $patient->name }}</h4>
                            <small class="text-muted">
                                PX# {{ $patient->pxnumber }}
                                @if($patient->dob) &nbsp;|&nbsp; Age: {{ \Carbon\Carbon::parse($patient->dob)->age }} @endif
                                @if($patient->gender) &nbsp;|&nbsp; {{ ucfirst($patient->gender) }} @endif
                                @if($patient->contact) &nbsp;|&nbsp; {{ $patient->contact }} @endif
                            </small>
                        </div>
                        <div class="ml-auto d-flex align-items-center" style="gap:8px; flex-wrap:wrap;">
                            <span class="badge badge-pill badge-light border" style="font-size:12px; padding:6px 10px;">
                                {{ $timeline->count() }} event(s)
                            </span>
                            <a href="javascript:history.back()" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-arrow-left mr-1"></i> Back
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Filter tabs --}}
            <div class="card card-outline card-secondary mb-3">
                <div class="card-body py-2">
                    <div class="d-flex flex-wrap" style="gap:6px;">
                        @foreach([
                            'all'          => ['All Events',    'fas fa-list',            'secondary'],
                            'consultation' => ['Consultations', 'fas fa-stethoscope',     'primary'],
                            'refraction'   => ['Refractions',   'fas fa-glasses',         'success'],
                            'lens_order'   => ['Lens Orders',   'fas fa-eye',             'info'],
                            'referral'     => ['Referrals',     'fas fa-share-square',    'warning'],
                            'appointment'  => ['Appointments',  'fas fa-calendar-check',  'danger'],
                        ] as $type => [$label, $icon, $color])
                            <button
                                wire:click="$set('filterType', '{{ $type }}')"
                                class="btn btn-sm {{ $filterType === $type ? "btn-$color" : "btn-outline-$color" }}">
                                <i class="{{ $icon }} mr-1"></i>{{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Timeline --}}
            @if($timeline->isEmpty())
                <div class="card">
                    <div class="card-body text-center py-5 text-muted">
                        <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                        <h5>No events found</h5>
                        <p class="mb-0">No clinical history recorded for this patient yet.</p>
                    </div>
                </div>
            @else
                <div class="timeline">
                    @foreach($timeline as $event)
                        @php
                            $key   = $event['key'];
                            $type  = $event['type'];
                            $date  = $event['date'];
                            $data  = $event['data'];
                            $open  = $expandedId === $key;

                            $config = [
                                'consultation' => ['bg-primary',   'fas fa-stethoscope',    'Consultation'],
                                'refraction'   => ['bg-success',   'fas fa-glasses',        'Refraction'],
                                'lens_order'   => ['bg-info',      'fas fa-eye',            'Lens Order'],
                                'referral'     => ['bg-warning',   'fas fa-share-square',   'Referral / Letter'],
                                'appointment'  => ['bg-danger',    'fas fa-calendar-check', 'Appointment'],
                            ][$type] ?? ['bg-secondary', 'fas fa-circle', ucfirst($type)];

                            [$bgClass, $iconClass, $typeLabel] = $config;
                        @endphp

                        <div>
                            <i class="{{ $iconClass }} {{ $bgClass }}"></i>

                            <div class="timeline-item">
                                <span class="time">
                                    <i class="fas fa-clock mr-1"></i>
                                    {{ $date ? \Carbon\Carbon::parse($date)->format('M d, Y') : '—' }}
                                </span>

                                <h3 class="timeline-header">
                                    <span class="badge {{ $bgClass }} mr-2" style="font-size:11px;">{{ $typeLabel }}</span>

                                    {{-- Per-type title --}}
                                    @if($type === 'consultation')
                                        {{ Str::limit($data->chiefComplaint ?? 'Consultation', 60) }}
                                        @if($data->doctor) <small class="text-muted ml-1">by {{ $data->doctor->name }}</small> @endif
                                    @elseif($type === 'refraction')
                                        Refraction Record
                                        @if($data->user) <small class="text-muted ml-1">by {{ $data->user->name }}</small> @endif
                                    @elseif($type === 'lens_order')
                                        Lens Order — {{ $data->frame_model_number ?? 'No frame' }}
                                        <span class="badge badge-secondary ml-1" style="font-size:10px;">{{ $data->status ?? 'Pending' }}</span>
                                    @elseif($type === 'referral')
                                        {{ $data->letter_type_label ?? ucfirst(str_replace('_', ' ', $data->letter_type)) }}
                                        @if($data->referral_to) <small class="text-muted ml-1">→ {{ $data->referral_to }}</small> @endif
                                    @elseif($type === 'appointment')
                                        {{ $data->title ?? $data->recall_category ?? 'Appointment' }}
                                        <span class="badge badge-{{ $data->status === 'Completed' ? 'success' : ($data->status === 'Missed' ? 'danger' : 'warning') }} ml-1" style="font-size:10px;">
                                            {{ $data->status ?? 'Scheduled' }}
                                        </span>
                                    @endif

                                    <button wire:click="toggleExpand('{{ $key }}')"
                                        class="btn btn-xs btn-outline-secondary ml-2">
                                        <i class="fas fa-{{ $open ? 'chevron-up' : 'chevron-down' }}"></i>
                                    </button>
                                </h3>

                                @if($open)
                                <div class="timeline-body">
                                    {{-- CONSULTATION detail --}}
                                    @if($type === 'consultation')
                                        <div class="row">
                                            <div class="col-md-6">
                                                <table class="table table-sm table-borderless mb-2">
                                                    <tr><th class="text-muted" style="width:130px;">Chief Complaint</th><td>{{ $data->chiefComplaint ?: '—' }}</td></tr>
                                                    <tr><th class="text-muted">IOP OD / OS</th><td>{{ $data->IOPOD ?: '—' }} / {{ $data->IOPOS ?: '—' }} mmHg</td></tr>
                                                    <tr><th class="text-muted">VA OD / OS</th><td>{{ $data->vaOD6m ?: '—' }} / {{ $data->vaOS6m ?: '—' }}</td></tr>
                                                    @if($data->notes)
                                                    <tr><th class="text-muted">Notes</th><td>{{ $data->notes }}</td></tr>
                                                    @endif
                                                </table>
                                                @if($data->diagnoses->isNotEmpty())
                                                    <div class="mb-1"><strong class="text-muted" style="font-size:11px;">DIAGNOSES</strong></div>
                                                    @foreach($data->diagnoses as $dx)
                                                        <span class="badge badge-light border mr-1 mb-1">{{ $dx->name }}</span>
                                                    @endforeach
                                                @endif
                                            </div>
                                            <div class="col-md-6">
                                                @if($data->cartItems->isNotEmpty())
                                                    <div class="mb-1"><strong class="text-muted" style="font-size:11px;">PRESCRIBED ITEMS</strong></div>
                                                    <table class="table table-sm table-bordered">
                                                        <thead class="thead-light"><tr><th>Item</th><th>Eye</th><th>Freq</th><th>Qty</th></tr></thead>
                                                        <tbody>
                                                            @foreach($data->cartItems as $ci)
                                                            <tr>
                                                                <td>{{ $ci->product->name ?? '—' }}</td>
                                                                <td>{{ $ci->eye ? strtoupper($ci->eye) : '—' }}</td>
                                                                <td>{{ $ci->frequency ?: '—' }}</td>
                                                                <td>{{ $ci->quantity }}</td>
                                                            </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                @else
                                                    <p class="text-muted small">No prescribed items.</p>
                                                @endif
                                                <a href="{{ route('doctor.prescription.print', $data) }}" target="_blank"
                                                    class="btn btn-xs btn-outline-warning mt-1">
                                                    <i class="fas fa-prescription mr-1"></i>Print Prescription
                                                </a>
                                            </div>
                                        </div>
                                    @endif

                                    {{-- REFRACTION detail --}}
                                    @if($type === 'refraction')
                                        <div class="row">
                                            <div class="col-md-6">
                                                <strong class="text-muted d-block mb-1" style="font-size:11px;">RIGHT EYE (OD)</strong>
                                                <table class="table table-sm table-bordered mb-2">
                                                    <tr><th>Sphere</th><td>{{ $data->subjective_od_sphere ?? $data->refractionOD_sphere ?? $data->sphereOD ?? '—' }}</td></tr>
                                                    <tr><th>Cylinder</th><td>{{ $data->subjective_od_cylinder ?? $data->refractionOD_cylinder ?? $data->cylinderOD ?? '—' }}</td></tr>
                                                    <tr><th>Axis</th><td>{{ $data->subjective_od_axis ?? $data->refractionOD_axis ?? $data->axisOD ?? '—' }}</td></tr>
                                                    <tr><th>ADD</th><td>{{ $data->subjective_od_add ?? $data->refractionOD_ADD ?? $data->addOD ?? '—' }}</td></tr>
                                                </table>
                                            </div>
                                            <div class="col-md-6">
                                                <strong class="text-muted d-block mb-1" style="font-size:11px;">LEFT EYE (OS)</strong>
                                                <table class="table table-sm table-bordered mb-2">
                                                    <tr><th>Sphere</th><td>{{ $data->subjective_os_sphere ?? $data->refractionOS_sphere ?? $data->sphereOS ?? '—' }}</td></tr>
                                                    <tr><th>Cylinder</th><td>{{ $data->subjective_os_cylinder ?? $data->refractionOS_cylinder ?? $data->cylinderOS ?? '—' }}</td></tr>
                                                    <tr><th>Axis</th><td>{{ $data->subjective_os_axis ?? $data->refractionOS_axis ?? $data->axisOS ?? '—' }}</td></tr>
                                                    <tr><th>ADD</th><td>{{ $data->subjective_os_add ?? $data->refractionOS_ADD ?? $data->addOS ?? '—' }}</td></tr>
                                                </table>
                                            </div>
                                        </div>
                                        @if($data->notes)
                                            <p class="mb-0 text-muted small">{{ $data->notes }}</p>
                                        @endif
                                    @endif

                                    {{-- LENS ORDER detail --}}
                                    @if($type === 'lens_order')
                                        <table class="table table-sm table-borderless">
                                            <tr><th class="text-muted" style="width:140px;">Frame Model</th><td>{{ $data->frame_model_number ?: '—' }}</td></tr>
                                            <tr><th class="text-muted">Frame Price</th><td>{{ currency() }} {{ number_format($data->frame_price, 2) }}</td></tr>
                                            <tr><th class="text-muted">Lens Price</th><td>{{ currency() }} {{ number_format($data->lens_price, 2) }}</td></tr>
                                            <tr><th class="text-muted">Amount Paid</th><td>{{ currency() }} {{ number_format($data->paid_amount, 2) }}</td></tr>
                                            <tr>
                                                <th class="text-muted">Balance</th>
                                                <td>
                                                    @php $balance = ($data->frame_price + $data->lens_price) - $data->paid_amount; @endphp
                                                    @if($balance > 0)
                                                        <span class="text-danger font-weight-bold">{{ currency() }} {{ number_format($balance, 2) }}</span>
                                                    @else
                                                        <span class="text-success">Cleared</span>
                                                    @endif
                                                </td>
                                            </tr>
                                            <tr><th class="text-muted">Pick-up Date</th><td>{{ $data->pickUpDate ? \Carbon\Carbon::parse($data->pickUpDate)->format('M d, Y') : '—' }}</td></tr>
                                            <tr><th class="text-muted">Status</th><td><span class="badge badge-secondary">{{ $data->status ?? 'Pending' }}</span></td></tr>
                                        </table>
                                        @if($data->notes)
                                            <p class="text-muted small">{{ $data->notes }}</p>
                                        @endif
                                    @endif

                                    {{-- REFERRAL detail --}}
                                    @if($type === 'referral')
                                        <table class="table table-sm table-borderless">
                                            <tr><th class="text-muted" style="width:140px;">Type</th><td>{{ $data->letter_type_label ?? ucfirst(str_replace('_', ' ', $data->letter_type)) }}</td></tr>
                                            @if($data->referral_to)<tr><th class="text-muted">Referred To</th><td>{{ $data->referral_to }}</td></tr>@endif
                                            @if($data->diagnosis)<tr><th class="text-muted">Diagnosis</th><td>{{ $data->diagnosis }}</td></tr>@endif
                                            @if($data->referredBy)<tr><th class="text-muted">Issued By</th><td>{{ $data->referredBy->name }}</td></tr>@endif
                                        </table>
                                        <a href="{{ route('doctor.referral.pdf', $data) }}" target="_blank"
                                            class="btn btn-xs btn-outline-secondary">
                                            <i class="fas fa-print mr-1"></i>Print Letter
                                        </a>
                                    @endif

                                    {{-- APPOINTMENT detail --}}
                                    @if($type === 'appointment')
                                        <table class="table table-sm table-borderless">
                                            <tr><th class="text-muted" style="width:140px;">Title</th><td>{{ $data->title ?: '—' }}</td></tr>
                                            <tr><th class="text-muted">Category</th><td>{{ $data->recall_category ?: '—' }}</td></tr>
                                            <tr><th class="text-muted">Scheduled</th><td>{{ $data->scheduled_at ? \Carbon\Carbon::parse($data->scheduled_at)->format('M d, Y h:i A') : '—' }}</td></tr>
                                            <tr><th class="text-muted">Status</th><td><span class="badge badge-{{ $data->status === 'Completed' ? 'success' : ($data->status === 'Missed' ? 'danger' : 'warning') }}">{{ $data->status ?? 'Scheduled' }}</span></td></tr>
                                            @if($data->notes)<tr><th class="text-muted">Notes</th><td>{{ $data->notes }}</td></tr>@endif
                                        </table>
                                    @endif
                                </div>
                                @endif

                            </div>
                        </div>

                    @endforeach

                    {{-- End marker --}}
                    <div>
                        <i class="fas fa-user-circle bg-gray"></i>
                        <div class="timeline-item">
                            <span class="time text-muted"><i class="fas fa-clock mr-1"></i>Patient registered</span>
                            <h3 class="timeline-header no-border">
                                {{ $patient->name }} — registered {{ $patient->created_at ? \Carbon\Carbon::parse($patient->created_at)->format('M d, Y') : '' }}
                            </h3>
                        </div>
                    </div>

                </div>{{-- end .timeline --}}
            @endif

        </div>
    </section>
</div>
