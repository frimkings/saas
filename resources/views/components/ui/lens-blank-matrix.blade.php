@props([
    'selectedIndex' => '1.67',
    'selectedCoating' => 'AR',
    'sphStep' => '0.25',
    'cylStep' => '0.25',
])

<div class="clinic-ui space-y-6">
    <!-- Header & Controls Box -->
    <div class="ui-panel p-5 space-y-4 bg-slate-900 text-white border border-slate-800 rounded-xl shadow-lg">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-800 pb-3">
            <div>
                <h3 class="text-base font-extrabold text-white flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full bg-teal-400"></span>
                    Ophthalmic Lens Blank Inventory Power Grid Matrix
                </h3>
                <p class="text-xs text-slate-400">Full clinical range: Sphere (SPH) <strong class="text-teal-300">+10.00 to -10.00 D</strong> in <strong class="text-teal-300">0.25 D</strong> steps, Cylinder (CYL) <strong class="text-purple-300">0.00 to -4.00 D</strong>.</p>
            </div>
            <div class="flex items-center gap-2 text-xs">
                <span class="bg-teal-500/20 text-teal-300 px-3 py-1 rounded font-bold border border-teal-500/30">Sync Active</span>
                <button type="button" class="bg-teal-600 hover:bg-teal-700 text-white font-bold px-3 py-1.5 rounded-lg transition">+ New Item Intake</button>
            </div>
        </div>

        <!-- Matrix Dropdown Filters Bar -->
        <div class="flex flex-wrap gap-2 text-xs">
            <select class="bg-slate-800 text-white border border-slate-700 rounded-lg px-3 py-1.5 font-semibold">
                <option value="1.56">1.56 Mid-Index Standard</option>
                <option value="1.61">1.61 High-Index</option>
                <option value="1.67" selected>1.67 Ultra High-Index</option>
                <option value="1.74">1.74 Super High-Index</option>
            </select>

            <select class="bg-slate-800 text-white border border-slate-700 rounded-lg px-3 py-1.5 font-semibold">
                <option value="AR" selected>Anti-Reflective + Hydrophobic</option>
                <option value="BlueCut">Blue Light Filter (BlueCut)</option>
                <option value="Transitions">Transitions Gen 8 Photochromic</option>
                <option value="HC">Hard Coated Only</option>
            </select>

            <select class="bg-slate-800 text-teal-300 border border-slate-700 rounded-lg px-3 py-1.5 font-bold">
                <option value="0.25" selected>SPH Step: 0.25 D (Clinical Full)</option>
                <option value="0.50">SPH Step: 0.50 D (Standard)</option>
                <option value="1.00">SPH Step: 1.00 D (Compact)</option>
            </select>

            <select class="bg-slate-800 text-indigo-300 border border-slate-700 rounded-lg px-3 py-1.5 font-bold">
                <option value="full_10" selected>SPH Range: +10.00 to -10.00 D (0.25D Steps)</option>
                <option value="standard">SPH Range: +6.00 to -8.00 D</option>
                <option value="extended">SPH Range: +15.00 to -15.00 D</option>
            </select>

            <select class="bg-slate-800 text-purple-300 border border-slate-700 rounded-lg px-3 py-1.5 font-bold">
                <option value="0.25" selected>CYL Step: -0.25 D (Clinical)</option>
                <option value="0.50">CYL Step: -0.50 D (Compact)</option>
            </select>

            <select class="bg-slate-800 text-purple-300 border border-slate-700 rounded-lg px-3 py-1.5 font-bold">
                <option value="full" selected>CYL: 0.00 to -4.00 D (Full)</option>
                <option value="extended">CYL: 0.00 to -6.00 D (Extreme)</option>
            </select>

            <button type="button" class="bg-teal-600 hover:bg-teal-700 text-white font-bold px-3 py-1.5 rounded-lg shadow">+ SPH</button>
            <button type="button" class="bg-purple-600 hover:bg-purple-700 text-white font-bold px-3 py-1.5 rounded-lg shadow">+ CYL</button>
            <button type="button" class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-3.5 py-1.5 rounded-lg shadow">⚡ Auto-Reorder</button>
        </div>

        <!-- Stock Status Color Legend -->
        <div class="flex items-center gap-6 text-xs font-semibold pt-2 border-t border-slate-800">
            <span class="flex items-center gap-1.5 text-emerald-400">
                <span class="w-3 h-3 rounded bg-emerald-500 inline-block"></span> In Stock (5+ pairs)
            </span>
            <span class="flex items-center gap-1.5 text-amber-400">
                <span class="w-3 h-3 rounded bg-amber-500 inline-block"></span> Low Stock (1-4 pairs)
            </span>
            <span class="flex items-center gap-1.5 text-red-400">
                <span class="w-3 h-3 rounded bg-red-500 inline-block"></span> Out of Stock (0 pairs)
            </span>
        </div>
    </div>

    <!-- Batch Selection Bar -->
    <div class="bg-gradient-to-r from-blue-700 via-indigo-700 to-purple-800 text-white p-3 rounded-xl flex flex-wrap justify-between items-center gap-3 shadow-lg text-xs">
        <div class="flex items-center gap-3">
            <span class="bg-white/20 text-white px-3 py-1 rounded-lg font-extrabold text-xs">
                <span id="selected-power-count" class="font-black text-sm">0</span> Powers Selected
            </span>
            <span class="text-blue-100 hidden md:inline">Click diopter cells or row/column headers to select stock items for PO</span>
        </div>

        <div class="flex flex-wrap gap-2">
            <button type="button" onclick="selectMatrixOutofStock()" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded-lg font-bold">
                ⚡ Select Out-of-Stock (0 pairs)
            </button>
            <button type="button" onclick="selectMatrixLowStock()" class="bg-amber-600 hover:bg-amber-700 text-white px-3 py-1.5 rounded-lg font-bold">
                🚨 Select Low Stock (&le;2 pairs)
            </button>
            <button type="button" onclick="orderSelectedPo()" class="bg-white text-blue-900 hover:bg-blue-50 px-4 py-1.5 rounded-lg font-extrabold shadow">
                📦 Order Selected in PO (<span id="po-count">0</span>)
            </button>
            <button type="button" onclick="clearMatrixSelection()" class="bg-slate-900/60 hover:bg-slate-900 text-white px-3 py-1.5 rounded-lg font-semibold">
                Clear
            </button>
        </div>
    </div>

    <!-- 2D Diopter Power Matrix Table (+10.00 to -10.00 at 0.25D steps) -->
    <div class="bg-slate-950 text-white border border-slate-800 rounded-xl overflow-x-auto shadow-inner p-2 max-h-[650px] overflow-y-auto">
        <table class="w-full text-center border-collapse text-xs font-mono">
            <thead>
                <tr class="bg-slate-900 text-slate-300 font-bold border-b border-slate-800">
                    <th class="p-3 text-left bg-slate-900 sticky left-0 z-20 w-24 border-r border-slate-800">SPH \ CYL</th>
                    @foreach(['0.00', '-0.25', '-0.50', '-0.75', '-1.00', '-1.25', '-1.50', '-1.75', '-2.00', '-2.25', '-2.50', '-2.75', '-3.00', '-3.25', '-3.50', '-3.75', '-4.00'] as $cyl)
                        <th class="p-2.5 min-w-[64px] border-r border-slate-800 cursor-pointer hover:bg-slate-800 text-teal-300 font-extrabold">
                            {{ $cyl }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-900">
                @php
                    $sphValues = [];
                    for ($val = 1000; $val >= -1000; $val -= 25) {
                        $floatVal = $val / 100;
                        $sphValues[] = ($floatVal > 0 ? '+' : '') . sprintf('%.2f', $floatVal);
                    }
                    $cylValues = ['0.00', '-0.25', '-0.50', '-0.75', '-1.00', '-1.25', '-1.50', '-1.75', '-2.00', '-2.25', '-2.50', '-2.75', '-3.00', '-3.25', '-3.50', '-3.75', '-4.00'];
                @endphp
                @foreach($sphValues as $sIndex => $sph)
                    <tr class="hover:bg-slate-900/60">
                        <td class="p-2.5 font-extrabold text-blue-300 bg-slate-900 sticky left-0 z-10 border-r border-slate-800 text-left">
                            {{ $sph }}
                        </td>
                        @foreach($cylValues as $cIndex => $cyl)
                            @php
                                // Simulated realistic stock level per diopter cell
                                $val = (($sIndex * 7 + $cIndex * 13 + 3) % 11);
                                if ($val >= 5) {
                                    $bg = 'bg-emerald-950/40 text-emerald-400 border-emerald-900/40 hover:bg-emerald-800/50';
                                    $label = $val . ' pairs';
                                } elseif ($val >= 1) {
                                    $bg = 'bg-amber-950/50 text-amber-400 border-amber-900/50 hover:bg-amber-800/60 font-bold';
                                    $label = $val . ' pairs';
                                } else {
                                    $bg = 'bg-red-950/60 text-red-400 border-red-900/60 hover:bg-red-800/60 font-extrabold';
                                    $label = '0 pairs';
                                }
                            @endphp
                            <td onclick="toggleCellSelection(this, '{{ $sph }}', '{{ $cyl }}', {{ $val }})" 
                                data-pairs="{{ $val }}"
                                class="p-2 text-center border-r border-b border-slate-900 cursor-pointer transition-all {{ $bg }} text-[11px]">
                                <span class="block font-bold text-xs">{{ $val }}</span>
                                <span class="text-[9px] block opacity-80 font-sans">pairs</span>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<script>
    let selectedCells = [];

    function toggleCellSelection(cell, sph, cyl, pairs) {
        if (cell.classList.contains('ring-2')) {
            cell.classList.remove('ring-2', 'ring-blue-500', 'bg-blue-600', 'text-white');
            selectedCells = selectedCells.filter(item => !(item.sph === sph && item.cyl === cyl));
        } else {
            cell.classList.add('ring-2', 'ring-blue-500', 'bg-blue-600', 'text-white');
            selectedCells.push({ sph, cyl, pairs });
        }
        updateSelectionUI();
    }

    function selectMatrixOutofStock() {
        document.querySelectorAll('[data-pairs="0"]').forEach(cell => {
            cell.classList.add('ring-2', 'ring-blue-500', 'bg-blue-600', 'text-white');
        });
        updateSelectionUI();
    }

    function selectMatrixLowStock() {
        document.querySelectorAll('[data-pairs]').forEach(cell => {
            const p = parseInt(cell.getAttribute('data-pairs'));
            if (p > 0 && p <= 2) {
                cell.classList.add('ring-2', 'ring-blue-500', 'bg-blue-600', 'text-white');
            }
        });
        updateSelectionUI();
    }

    function clearMatrixSelection() {
        document.querySelectorAll('.ring-2').forEach(cell => {
            cell.classList.remove('ring-2', 'ring-blue-500', 'bg-blue-600', 'text-white');
        });
        selectedCells = [];
        updateSelectionUI();
    }

    function updateSelectionUI() {
        const count = document.querySelectorAll('.ring-2').length;
        const countElem = document.getElementById('selected-power-count');
        const poElem = document.getElementById('po-count');
        if (countElem) countElem.innerText = count;
        if (poElem) poElem.innerText = count;
    }

    function orderSelectedPo() {
        const count = document.querySelectorAll('.ring-2').length;
        if (count === 0) {
            alert('Please select lens blank diopter powers to add to Purchase Order.');
            return;
        }
        alert(`Successfully added ${count} selected diopter powers to draft Supplier Purchase Order!`);
    }
</script>
