@php
    $workspaces = \App\Support\NavigationWorkspace::available(auth()->user());
    $activeWorkspace = \App\Support\NavigationWorkspace::current();
@endphp
@if(count($workspaces) > 1 && ($inline ?? false))
    {{-- Compact single-row version for top bars. --}}
    <form method="POST" action="{{ route('navigation.workspace') }}" class="flex items-center gap-1.5" aria-label="Switch workspace">
        @csrf
        <label for="navigation-workspace" class="text-slate-400">Workspace</label>
        <select id="navigation-workspace" name="workspace" class="h-8 rounded-md border border-slate-600 bg-slate-800 px-2 text-xs text-slate-100">
            @foreach($workspaces as $value => [$label, $destination])
                <option value="{{ $value }}" @selected($activeWorkspace === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <button type="submit" class="h-8 rounded-md bg-teal-700 px-3 text-xs font-semibold text-white hover:bg-teal-600" aria-label="Open selected workspace">Go</button>
    </form>
@elseif(count($workspaces) > 1)
    <form method="POST" action="{{ route('navigation.workspace') }}" class="p-2" aria-label="Switch workspace">
        @csrf
        <label for="navigation-workspace" style="display:block;font-size:12px;color:#cbd5e1;margin-bottom:4px">Workspace</label>
        <div style="display:flex;gap:6px">
            <select id="navigation-workspace" name="workspace" style="min-width:0;flex:1;background:#334155;color:white;border:1px solid #64748b;border-radius:4px;padding:7px">
                @foreach($workspaces as $value => [$label, $destination])
                    <option value="{{ $value }}" @selected($activeWorkspace === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <button type="submit" style="background:#0f766e;color:white;border:0;border-radius:4px;padding:7px 10px" aria-label="Open selected workspace">Go</button>
        </div>
    </form>
@elseif(count($workspaces) === 1)
    <div class="p-2" style="color:#cbd5e1;font-size:12px">{{ reset($workspaces)[0] }} workspace</div>
@endif
