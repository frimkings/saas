<div class="clinic-ui ui-page space-y-5">
    {{-- Page header --}}
    <div class="ui-heading">
        <div>
            <h1><i class="fas fa-stream mr-2 text-teal-700" aria-hidden="true"></i>Clinical timeline</h1>
            <p class="ui-muted"><a href="{{ route('doctor.dashboard') }}" class="text-teal-700 no-underline hover:underline">Dashboard</a> / Timeline</p>
        </div>
    </div>

    <section class="content">
        <div class="w-full">

            {{-- Patient header card --}}
            <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white card-primary mb-4">
                <div class="card-body p-4 py-4">
                    <div class="flex items-center flex-wrap" style="gap:16px;">
                        <div class="rounded-full flex items-center justify-center text-white font-semibold"
                            style="width:56px;height:56px;font-size:22px;background:#003087;flex-shrink:0;">
                            {{ strtoupper(substr($patient->name, 0, 1)) }}
                        </div>
                        <div>
                            <h4 class="mb-0 font-semibold">{{ $patient->name }}</h4>
                            <small class="text-slate-500">
                                PX# {{ $patient->pxnumber }}
                                @if($patient->dob) &nbsp;|&nbsp; Age: {{ \Carbon\Carbon::parse($patient->dob)->age }} @endif
                                @if($patient->gender) &nbsp;|&nbsp; {{ ucfirst($patient->gender) }} @endif
                                @if($patient->contact) &nbsp;|&nbsp; {{ $patient->contact }} @endif
                            </small>
                        </div>
                        <div class="ml-auto flex items-center" style="gap:8px; flex-wrap:wrap;">
                            <span class="inline-flex items-center px-1.5 py-0.5 text-xs font-semibold rounded-full bg-slate-50 text-slate-600 border border-slate-200" style="font-size:12px; padding:6px 10px;">
                                {{ $timeline->count() }} event(s)
                            </span>
                            <a href="javascript:history.back()" class="btn ui-button ui-button-sm ui-button-secondary">
                                <i class="fas fa-arrow-left mr-1"></i> Back
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Filter tabs --}}
            <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white card-secondary mb-4">
                <div class="card-body p-4 py-2">
                    <div class="flex flex-wrap" style="gap:6px;">
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
                                class="btn ui-button ui-button-sm {{ $filterType === $type ? "btn-$color" : "btn-outline-$color" }}">
                                <i class="{{ $icon }} mr-1"></i>{{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Timeline --}}
            @if($timeline->isEmpty())
                <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white">
                    <div class="card-body p-4 text-center py-12 text-slate-500">
                        <i class="fas fa-inbox fa-3x mb-4 block"></i>
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
                                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold {{ $bgClass }} mr-2" style="font-size:11px;">{{ $typeLabel }}</span>

                                    {{-- Per-type title --}}
                                    @if($type === 'consultation')
                                        {{ Str::limit($data->chiefComplaint ?? 'Consultation', 60) }}
                                        @if($data->doctor) <small class="text-slate-500 ml-1">by {{ $data->doctor->name }}</small> @endif
                                    @elseif($type === 'refraction')
                                        Refraction Record
                                        @if($data->user) <small class="text-slate-500 ml-1">by {{ $data->user->name }}</small> @endif
                                    @elseif($type === 'lens_order')
                                        Lens Order — {{ $data->frame_model_number ?? 'No frame' }}
                                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-100 text-slate-700 ml-1" style="font-size:10px;">{{ $data->status ?? 'Pending' }}</span>
                                    @elseif($type === 'referral')
                                        {{ $data->letter_type_label ?? ucfirst(str_replace('_', ' ', $data->letter_type)) }}
                                        @if($data->referral_to) <small class="text-slate-500 ml-1">→ {{ $data->referral_to }}</small> @endif
                                    @elseif($type === 'appointment')
                                        {{ $data->title ?? $data->recall_category ?? 'Appointment' }}
                                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold badge-{{ $data->status === 'Completed' ? 'success' : ($data->status === 'Missed' ? 'danger' : 'warning') }} ml-1" style="font-size:10px;">
                                            {{ $data->status ?? 'Scheduled' }}
                                        </span>
                                    @endif

                                    <button wire:click="toggleExpand('{{ $key }}')"
                                        class="btn ui-button ui-button-sm ui-button-secondary ml-2">
                                        <i class="fas fa-{{ $open ? 'chevron-up' : 'chevron-down' }}"></i>
                                    </button>
                                </h3>

                                @if($open)
                                <div class="timeline-body">
                                    {{-- CONSULTATION detail --}}
                                    @if($type === 'consultation')
                                        <div class="flex flex-wrap -mx-2">
                                            <div class="w-full md:w-6/12 px-2">
                                                <table class="table ui-table ui-table-sm mb-2">
                                                    <tr><th class="text-slate-500" style="width:130px;">Chief Complaint</th><td>{{ $data->chiefComplaint ?: '—' }}</td></tr>
                                                    <tr><th class="text-slate-500">IOP OD / OS</th><td>{{ $data->IOPOD ?: '—' }} / {{ $data->IOPOS ?: '—' }} mmHg</td></tr>
                                                    <tr><th class="text-slate-500">VA OD / OS</th><td>{{ $data->vaOD6m ?: '—' }} / {{ $data->vaOS6m ?: '—' }}</td></tr>
                                                    @if($data->notes)
                                                    <tr><th class="text-slate-500">Notes</th><td>{{ $data->notes }}</td></tr>
                                                    @endif
                                                </table>
                                                @if($data->diagnoses->isNotEmpty())
                                                    <div class="mb-1"><strong class="text-slate-500" style="font-size:11px;">DIAGNOSES</strong></div>
                                                    @foreach($data->diagnoses as $dx)
                                                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-50 text-slate-600 border border-slate-200 mr-1 mb-1">{{ $dx->name }}</span>
                                                    @endforeach
                                                @endif
                                            </div>
                                            <div class="w-full md:w-6/12 px-2">
                                                @if($data->cartItems->isNotEmpty())
                                                    <div class="mb-1"><strong class="text-slate-500" style="font-size:11px;">PRESCRIBED ITEMS</strong></div>
                                                    <table class="table ui-table ui-table-sm">
                                                        <thead class=""><tr><th>Item</th><th>Eye</th><th>Freq</th><th>Qty</th></tr></thead>
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
                                                    <p class="text-slate-500 text-sm">No prescribed items.</p>
                                                @endif
                                                <a href="{{ route('doctor.prescription.print', $data) }}" target="_blank"
                                                    class="btn ui-button ui-button-sm ui-button-secondary mt-1">
                                                    <i class="fas fa-prescription mr-1"></i>Print Prescription
                                                </a>
                                            </div>
                                        </div>
                                    @endif

                                    {{-- REFRACTION detail --}}
                                    @if($type === 'refraction')
                                        <div class="flex flex-wrap -mx-2">
                                            <div class="w-full md:w-6/12 px-2">
                                                <strong class="text-slate-500 block mb-1" style="font-size:11px;">RIGHT EYE (OD)</strong>
                                                <table class="table ui-table ui-table-sm mb-2">
                                                    <tr><th>Sphere</th><td>{{ $data->subjective_od_sphere ?? $data->refractionOD_sphere ?? $data->sphereOD ?? '—' }}</td></tr>
                                                    <tr><th>Cylinder</th><td>{{ $data->subjective_od_cylinder ?? $data->refractionOD_cylinder ?? $data->cylinderOD ?? '—' }}</td></tr>
                                                    <tr><th>Axis</th><td>{{ $data->subjective_od_axis ?? $data->refractionOD_axis ?? $data->axisOD ?? '—' }}</td></tr>
                                                    <tr><th>ADD</th><td>{{ $data->subjective_od_add ?? $data->refractionOD_ADD ?? $data->addOD ?? '—' }}</td></tr>
                                                </table>
                                            </div>
                                            <div class="w-full md:w-6/12 px-2">
                                                <strong class="text-slate-500 block mb-1" style="font-size:11px;">LEFT EYE (OS)</strong>
                                                <table class="table ui-table ui-table-sm mb-2">
                                                    <tr><th>Sphere</th><td>{{ $data->subjective_os_sphere ?? $data->refractionOS_sphere ?? $data->sphereOS ?? '—' }}</td></tr>
                                                    <tr><th>Cylinder</th><td>{{ $data->subjective_os_cylinder ?? $data->refractionOS_cylinder ?? $data->cylinderOS ?? '—' }}</td></tr>
                                                    <tr><th>Axis</th><td>{{ $data->subjective_os_axis ?? $data->refractionOS_axis ?? $data->axisOS ?? '—' }}</td></tr>
                                                    <tr><th>ADD</th><td>{{ $data->subjective_os_add ?? $data->refractionOS_ADD ?? $data->addOS ?? '—' }}</td></tr>
                                                </table>
                                            </div>
                                        </div>
                                        @if($data->notes)
                                            <p class="mb-0 text-slate-500 text-sm">{{ $data->notes }}</p>
                                        @endif
                                    @endif

                                    {{-- LENS ORDER detail --}}
                                    @if($type === 'lens_order')
                                        <table class="table ui-table ui-table-sm">
                                            <tr><th class="text-slate-500" style="width:140px;">Frame Model</th><td>{{ $data->frame_model_number ?: '—' }}</td></tr>
                                            <tr><th class="text-slate-500">Frame Price</th><td>{{ currency() }} {{ number_format($data->frame_price, 2) }}</td></tr>
                                            <tr><th class="text-slate-500">Lens Price</th><td>{{ currency() }} {{ number_format($data->lens_price, 2) }}</td></tr>
                                            <tr><th class="text-slate-500">Amount Paid</th><td>{{ currency() }} {{ number_format($data->paid_amount, 2) }}</td></tr>
                                            <tr>
                                                <th class="text-slate-500">Balance</th>
                                                <td>
                                                    @php $balance = ($data->frame_price + $data->lens_price) - $data->paid_amount; @endphp
                                                    @if($balance > 0)
                                                        <span class="text-red-700 font-semibold">{{ currency() }} {{ number_format($balance, 2) }}</span>
                                                    @else
                                                        <span class="text-green-700">Cleared</span>
                                                    @endif
                                                </td>
                                            </tr>
                                            <tr><th class="text-slate-500">Pick-up Date</th><td>{{ $data->pickUpDate ? \Carbon\Carbon::parse($data->pickUpDate)->format('M d, Y') : '—' }}</td></tr>
                                            <tr><th class="text-slate-500">Status</th><td><span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-100 text-slate-700">{{ $data->status ?? 'Pending' }}</span></td></tr>
                                        </table>
                                        @if($data->notes)
                                            <p class="text-slate-500 text-sm">{{ $data->notes }}</p>
                                        @endif
                                    @endif

                                    {{-- REFERRAL detail --}}
                                    @if($type === 'referral')
                                        <table class="table ui-table ui-table-sm">
                                            <tr><th class="text-slate-500" style="width:140px;">Type</th><td>{{ $data->letter_type_label ?? ucfirst(str_replace('_', ' ', $data->letter_type)) }}</td></tr>
                                            @if($data->referral_to)<tr><th class="text-slate-500">Referred To</th><td>{{ $data->referral_to }}</td></tr>@endif
                                            @if($data->diagnosis)<tr><th class="text-slate-500">Diagnosis</th><td>{{ $data->diagnosis }}</td></tr>@endif
                                            @if($data->referredBy)<tr><th class="text-slate-500">Issued By</th><td>{{ $data->referredBy->name }}</td></tr>@endif
                                        </table>
                                        <a href="{{ route('doctor.referral.pdf', $data) }}" target="_blank"
                                            class="btn ui-button ui-button-sm ui-button-secondary">
                                            <i class="fas fa-print mr-1"></i>Print Letter
                                        </a>
                                    @endif

                                    {{-- APPOINTMENT detail --}}
                                    @if($type === 'appointment')
                                        <table class="table ui-table ui-table-sm">
                                            <tr><th class="text-slate-500" style="width:140px;">Title</th><td>{{ $data->title ?: '—' }}</td></tr>
                                            <tr><th class="text-slate-500">Category</th><td>{{ $data->recall_category ?: '—' }}</td></tr>
                                            <tr><th class="text-slate-500">Scheduled</th><td>{{ $data->scheduled_at ? \Carbon\Carbon::parse($data->scheduled_at)->format('M d, Y h:i A') : '—' }}</td></tr>
                                            <tr><th class="text-slate-500">Status</th><td><span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold badge-{{ $data->status === 'Completed' ? 'success' : ($data->status === 'Missed' ? 'danger' : 'warning') }}">{{ $data->status ?? 'Scheduled' }}</span></td></tr>
                                            @if($data->notes)<tr><th class="text-slate-500">Notes</th><td>{{ $data->notes }}</td></tr>@endif
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
                            <span class="time text-slate-500"><i class="fas fa-clock mr-1"></i>Patient registered</span>
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
