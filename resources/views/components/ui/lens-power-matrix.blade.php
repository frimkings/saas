@props([
    'odSphere' => '0.00',
    'odCyl' => '0.00',
    'odAxis' => '0',
    'odAdd' => '0.00',
    'odVa' => '6/6',
    'osSphere' => '0.00',
    'osCyl' => '0.00',
    'osAxis' => '0',
    'osAdd' => '0.00',
    'osVa' => '6/6',
    'pdRight' => '31.5',
    'pdLeft' => '31.0',
    'readOnly' => false,
    'wirePrefix' => '',
])

<div class="clinic-ui ui-panel p-4 space-y-4 bg-white border border-slate-200 rounded-xl shadow-sm">
    <div class="flex items-center justify-between border-b border-slate-200 pb-3">
        <div class="flex items-center gap-2">
            <span class="w-3 h-3 rounded-full bg-teal-600"></span>
            <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wider">Lens Power Matrix (Optical Rx Grid)</h3>
        </div>
        <span class="ui-badge" style="background:#e5f5f3; color:#087e83;">Standard ISO 13666</span>
    </div>

    <!-- Lens Power Matrix Table -->
    <div class="ui-table-wrap">
        <table class="ui-table text-center font-mono">
            <thead>
                <tr class="bg-slate-50 text-slate-700 font-semibold text-xs border-b border-slate-200">
                    <th class="text-left w-24">Eye</th>
                    <th>Sphere (SPH)</th>
                    <th>Cylinder (CYL)</th>
                    <th>Axis (°)</th>
                    <th>Addition (ADD)</th>
                    <th>Visual Acuity (VA)</th>
                    <th class="text-right">Monocular PD</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-xs">
                <!-- OD / Right Eye Row -->
                <tr class="hover:bg-teal-50/40">
                    <td class="text-left font-bold text-teal-800 bg-teal-50/60 p-3">
                        <span class="inline-block px-2 py-0.5 rounded bg-teal-700 text-white font-sans text-[11px]">OD</span>
                        <span class="text-[10px] text-teal-700 font-sans block">Right Eye</span>
                    </td>
                    <td class="p-2">
                        @if($readOnly)
                            <span class="font-bold text-slate-900">{{ sprintf('%+.2f', (float)$odSphere) }} DS</span>
                        @else
                            <input type="number" step="0.25" wire:model="{{ $wirePrefix }}od_sphere" class="ui-input text-center font-mono text-xs p-1.5" placeholder="0.00">
                        @endif
                    </td>
                    <td class="p-2">
                        @if($readOnly)
                            <span>{{ sprintf('%+.2f', (float)$odCyl) }} DC</span>
                        @else
                            <input type="number" step="0.25" wire:model="{{ $wirePrefix }}od_cylinder" class="ui-input text-center font-mono text-xs p-1.5" placeholder="0.00">
                        @endif
                    </td>
                    <td class="p-2">
                        @if($readOnly)
                            <span>{{ str_pad((string)$odAxis, 3, '0', STR_PAD_LEFT) }}°</span>
                        @else
                            <input type="number" min="0" max="180" wire:model="{{ $wirePrefix }}od_axis" class="ui-input text-center font-mono text-xs p-1.5" placeholder="180">
                        @endif
                    </td>
                    <td class="p-2">
                        @if($readOnly)
                            <span class="text-teal-700 font-bold">+{{ sprintf('%.2f', (float)$odAdd) }}</span>
                        @else
                            <input type="number" step="0.25" min="0" wire:model="{{ $wirePrefix }}od_add" class="ui-input text-center font-mono text-xs p-1.5" placeholder="0.00">
                        @endif
                    </td>
                    <td class="p-2">
                        @if($readOnly)
                            <span>{{ $odVa }}</span>
                        @else
                            <input type="text" wire:model="{{ $wirePrefix }}od_va" class="ui-input text-center font-sans text-xs p-1.5" placeholder="6/6">
                        @endif
                    </td>
                    <td class="p-2 text-right">
                        @if($readOnly)
                            <span class="font-semibold text-slate-800">{{ $pdRight }} mm</span>
                        @else
                            <input type="number" step="0.5" wire:model="{{ $wirePrefix }}pd_right" class="ui-input text-right font-mono text-xs p-1.5" placeholder="31.5">
                        @endif
                    </td>
                </tr>

                <!-- OS / Left Eye Row -->
                <tr class="hover:bg-blue-50/40">
                    <td class="text-left font-bold text-blue-800 bg-blue-50/60 p-3">
                        <span class="inline-block px-2 py-0.5 rounded bg-blue-700 text-white font-sans text-[11px]">OS</span>
                        <span class="text-[10px] text-blue-700 font-sans block">Left Eye</span>
                    </td>
                    <td class="p-2">
                        @if($readOnly)
                            <span class="font-bold text-slate-900">{{ sprintf('%+.2f', (float)$osSphere) }} DS</span>
                        @else
                            <input type="number" step="0.25" wire:model="{{ $wirePrefix }}os_sphere" class="ui-input text-center font-mono text-xs p-1.5" placeholder="0.00">
                        @endif
                    </td>
                    <td class="p-2">
                        @if($readOnly)
                            <span>{{ sprintf('%+.2f', (float)$osCyl) }} DC</span>
                        @else
                            <input type="number" step="0.25" wire:model="{{ $wirePrefix }}os_cylinder" class="ui-input text-center font-mono text-xs p-1.5" placeholder="0.00">
                        @endif
                    </td>
                    <td class="p-2">
                        @if($readOnly)
                            <span>{{ str_pad((string)$osAxis, 3, '0', STR_PAD_LEFT) }}°</span>
                        @else
                            <input type="number" min="0" max="180" wire:model="{{ $wirePrefix }}os_axis" class="ui-input text-center font-mono text-xs p-1.5" placeholder="175">
                        @endif
                    </td>
                    <td class="p-2">
                        @if($readOnly)
                            <span class="text-teal-700 font-bold">+{{ sprintf('%.2f', (float)$osAdd) }}</span>
                        @else
                            <input type="number" step="0.25" min="0" wire:model="{{ $wirePrefix }}os_add" class="ui-input text-center font-mono text-xs p-1.5" placeholder="0.00">
                        @endif
                    </td>
                    <td class="p-2">
                        @if($readOnly)
                            <span>{{ $osVa }}</span>
                        @else
                            <input type="text" wire:model="{{ $wirePrefix }}os_va" class="ui-input text-center font-sans text-xs p-1.5" placeholder="6/6">
                        @endif
                    </td>
                    <td class="p-2 text-right">
                        @if($readOnly)
                            <span class="font-semibold text-slate-800">{{ $pdLeft }} mm</span>
                        @else
                            <input type="number" step="0.5" wire:model="{{ $wirePrefix }}pd_left" class="ui-input text-right font-mono text-xs p-1.5" placeholder="31.0">
                        @endif
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Binocular Total PD Summary Bar -->
    <div class="flex items-center justify-between text-xs bg-slate-50 p-2.5 rounded-lg border border-slate-200">
        <div class="flex items-center gap-2">
            <span class="ui-muted font-medium">Binocular Total PD:</span>
            <span class="font-mono font-bold text-teal-800 text-sm">
                {{ ((float)($pdRight ?: 31.5) + (float)($pdLeft ?: 31.0)) }} mm
            </span>
        </div>
        <div class="ui-muted text-[11px]">
            Format: Minus Cylinder Format (ANSI Z80.1 Optical Standard)
        </div>
    </div>
</div>
