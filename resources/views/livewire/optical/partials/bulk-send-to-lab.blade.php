{{-- The lab picker for SendsTickedToLab: $count = ticked jobs waiting to start. --}}
@if($showBulkLab)
    <div class="oo-overlay" wire:click="$set('showBulkLab', false)" aria-hidden="true"></div>
    <div role="dialog" aria-modal="true" aria-labelledby="bulk-lab-title" style="position:fixed;inset:0;z-index:1042;display:grid;place-items:center;pointer-events:none">
        <form wire:submit.prevent="bulkSendToLab" class="ui-panel bg-white p-5 space-y-3" style="width:min(460px,92vw);pointer-events:auto">
            <h3 id="bulk-lab-title" class="text-base font-bold text-slate-900">Send {{ $count }} {{ \Illuminate\Support\Str::plural('job', $count) }} to a lab</h3>
            <p class="text-xs text-slate-600">Ticked jobs waiting to start go to this lab, due back on one date. Others are left as they are. Afterwards you can WhatsApp the lab the whole list.</p>
            <div><label for="bulk-lab" class="block text-xs font-semibold mb-1">Lab</label>
                <select id="bulk-lab" wire:model="bulkLabSupplierId" class="ui-input w-full" x-data
                        x-on:change="const lead = parseInt($event.target.selectedOptions[0]?.dataset.lead, 10); const back = new Date(); back.setDate(back.getDate() + (lead || 0)); $wire.$set('bulkExpectedBack', lead ? back.toLocaleDateString('en-CA') : '', false)">
                    <option value="">Not recorded</option>
                    @foreach(\App\Models\Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name', 'lead_time_days']) as $lab)
                        <option value="{{ $lab->id }}" data-lead="{{ $lab->lead_time_days }}">{{ $lab->name }}{{ $lab->lead_time_days ? ' · '.$lab->lead_time_days.' '.\Illuminate\Support\Str::plural('day', $lab->lead_time_days) : '' }}</option>
                    @endforeach
                </select>@error('bulkLabSupplierId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <div><label for="bulk-back" class="block text-xs font-semibold mb-1">Expected back</label>
                <input id="bulk-back" type="date" wire:model="bulkExpectedBack" min="{{ today()->toDateString() }}" class="ui-input w-full">@error('bulkExpectedBack')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <div class="flex justify-end gap-2"><button type="button" class="oo-btn" wire:click="$set('showBulkLab', false)">Cancel</button><button type="submit" class="oo-btn primary" wire:loading.attr="disabled" wire:target="bulkSendToLab">Send to lab</button></div>
        </form>
    </div>
@endif
