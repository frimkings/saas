{{-- Latest vitals taken at check-in (BP, blood sugar, IOP). Red chip = above the usual threshold. --}}
@php($vitals = $vitals ?? null)
<div class="mb-4 rounded-xl border border-teal-200 bg-teal-50/60 px-4 py-3">
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <span class="text-xs font-bold uppercase tracking-wide text-teal-800"><i class="fas fa-heart-pulse mr-1" aria-hidden="true"></i>Vitals</span>
        @if($vitals)
            @if($vitals->bp())
                <span class="inline-flex items-center gap-1 text-sm {{ $vitals->bpHigh() ? 'font-bold text-red-700' : 'text-slate-700' }}">
                    BP <strong>{{ $vitals->bp() }}</strong><span class="text-xs text-slate-500">mmHg</span>@if($vitals->bpHigh())<span class="rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-bold text-red-700">HIGH</span>@endif
                </span>
            @endif
            @if($vitals->sugarLabel())
                <span class="inline-flex items-center gap-1 text-sm {{ $vitals->sugarHigh() ? 'font-bold text-red-700' : 'text-slate-700' }}">
                    Sugar <strong>{{ $vitals->sugarLabel() }}</strong>@if($vitals->sugarHigh())<span class="rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-bold text-red-700">HIGH</span>@endif
                </span>
            @endif
            @if($vitals->iop_od !== null || $vitals->iop_os !== null)
                <span class="text-sm text-slate-700">IOP <strong>R {{ $vitals->iop_od !== null ? rtrim(rtrim(number_format((float) $vitals->iop_od, 1), '0'), '.') : '—' }} / L {{ $vitals->iop_os !== null ? rtrim(rtrim(number_format((float) $vitals->iop_os, 1), '0'), '.') : '—' }}</strong><span class="text-xs text-slate-500"> mmHg</span></span>
            @endif
            <span class="ml-auto text-xs text-slate-500">{{ $vitals->recorded_at?->format('d M Y H:i') }}@if($vitals->recorder) · {{ $vitals->recorder->name }}@endif</span>
        @else
            <span class="text-sm text-slate-500">None recorded yet. Reception or a nurse can add them from the Patient Clearance page.</span>
        @endif
    </div>
</div>
