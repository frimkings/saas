<div class="clinic-ui ui-page" data-livewire-root>
<div class="w-full py-6">

    {{-- Header --}}
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="mb-1"><i class="fas fa-database mr-2 text-teal-700"></i>Database Backup</h2>
            <p class="text-slate-500 mb-0">Create, download, and manage database backup archives.</p>
        </div>
        <div class="flex" style="gap:.5rem">
            <button type="button"
                    wire:click="runBackup"
                    wire:loading.attr="disabled"
                    wire:target="runBackup,runFullBackup"
                    class="btn ui-button ui-button-primary font-semibold shadow-sm">
                <span wire:loading.remove wire:target="runBackup,runFullBackup">
                    <i class="fas fa-database mr-1"></i> DB Backup
                </span>
                <span wire:loading wire:target="runBackup">
                    <i class="fas fa-spinner fa-spin mr-1"></i> Running… please wait
                </span>
            </button>
            <button type="button"
                    wire:click="runFullBackup"
                    wire:loading.attr="disabled"
                    wire:target="runBackup,runFullBackup"
                    class="btn ui-button ui-button-primary font-semibold shadow-sm"
                    title="Back up the database plus uploaded pictures, documents, PDFs, and configured files">
                <span wire:loading.remove wire:target="runBackup,runFullBackup">
                    <i class="fas fa-archive mr-1"></i> Full Backup
                </span>
                <span wire:loading wire:target="runFullBackup">
                    <i class="fas fa-spinner fa-spin mr-1"></i> Runningâ€¦ please wait
                </span>
            </button>
            <button type="button"
                    wire:click="cleanBackups"
                    wire:loading.attr="disabled"
                    wire:target="cleanBackups,runBackup,runFullBackup"
                    class="btn ui-button ui-button-secondary"
                    title="Remove old backups per retention policy">
                <span wire:loading.remove wire:target="cleanBackups"><i class="fas fa-broom mr-1"></i> Prune Now</span>
                <span wire:loading wire:target="cleanBackups"><i class="fas fa-spinner fa-spin mr-1"></i></span>
            </button>
        </div>
    </div>

    {{-- Stats row --}}
    <div class="flex flex-wrap -mx-2 mb-6">
        <div class="w-full md:w-3/12 px-2 mb-4">
            <div class="small-box bg-teal-700 text-white mb-0">
                <div class="inner">
                    <h3>{{ $backups->count() }}</h3>
                    <p>Total Backups</p>
                </div>
                <div class="icon"><i class="fas fa-archive"></i></div>
            </div>
        </div>
        <div class="w-full md:w-3/12 px-2 mb-4">
            <div class="small-box bg-sky-600 text-white mb-0">
                <div class="inner">
                    <h3>{{ $totalSize }}</h3>
                    <p>Storage Used</p>
                </div>
                <div class="icon"><i class="fas fa-hdd"></i></div>
            </div>
        </div>
        <div class="w-full md:w-3/12 px-2 mb-4">
            <div class="small-box bg-green-600 text-white mb-0">
                <div class="inner">
                    <h3>{{ $backups->isNotEmpty() ? \Carbon\Carbon::createFromTimestamp($backups->first()['last_modified'])->diffForHumans() : 'Never' }}</h3>
                    <p>Latest Backup</p>
                </div>
                <div class="icon"><i class="fas fa-clock"></i></div>
            </div>
        </div>
        <div class="w-full md:w-3/12 px-2 mb-4">
            <div class="small-box bg-amber-400 mb-0">
                <div class="inner">
                    <h3>5 min <small style="font-size:.75rem">/ Daily</small></h3>
                    <p>DB / Full Schedule</p>
                </div>
                <div class="icon"><i class="fas fa-calendar-check"></i></div>
            </div>
        </div>
    </div>

    {{-- Backup diagnostics --}}
    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm mb-6">
        <div class="card-header border-b border-slate-200 px-4 bg-white flex items-center justify-between py-4">
            <span class="font-semibold"><i class="fas fa-stethoscope mr-1 text-sky-700"></i> Backup Diagnostics</span>
            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold badge-{{ $diagnostics['full_backup_ready'] ? 'success' : ($diagnostics['db_backup_ready'] ? 'warning' : 'danger') }}">
                {{ $diagnostics['full_backup_ready'] ? 'Ready' : ($diagnostics['db_backup_ready'] ? 'DB Ready' : 'Needs Attention') }}
            </span>
        </div>
        <div class="card-body p-4">
            <div class="flex flex-wrap -mx-2">
                <div class="w-full lg:w-6/12 px-2 mb-4">
                    <div class="p-4 h-full rounded-md" style="background:#f8fafc;border:1px solid #e5e7eb">
                        <div class="font-semibold mb-2"><i class="fas fa-terminal mr-1 text-teal-700"></i> MySQL Dump Tool</div>
                        <div class="diag-row"><span>Configured path</span><code>{{ $diagnostics['configured_path'] }}</code></div>
                        <div class="diag-row"><span>Detected path</span><code>{{ $diagnostics['resolved_path'] }}</code></div>
                        <div class="diag-row"><span>Executable</span><code>{{ $diagnostics['executable'] }}</code></div>
                        <div class="diag-row"><span>Version</span><strong>{{ \Illuminate\Support\Str::limit($diagnostics['binary_version'], 120) }}</strong></div>
                    </div>
                </div>
                <div class="w-full lg:w-6/12 px-2 mb-4">
                    <div class="p-4 h-full rounded-md" style="background:#f8fafc;border:1px solid #e5e7eb">
                        <div class="font-semibold mb-2"><i class="fas fa-server mr-1 text-green-700"></i> Database & Storage</div>
                        <div class="diag-row"><span>MySQL server</span><strong>{{ $diagnostics['server_version'] }}</strong></div>
                        <div class="diag-row"><span>Database</span><strong>{{ $diagnostics['database_name'] }}</strong></div>
                        <div class="diag-row"><span>DB user</span><strong>{{ $diagnostics['database_user'] }}</strong></div>
                        <div class="diag-row"><span>Backup folder</span><code>{{ $diagnostics['backup_root'] }}</code></div>
                        <div class="diag-row">
                            <span>Folder writable</span>
                            <strong class="text-{{ $diagnostics['backup_root_writable'] ? 'success' : 'danger' }}">{{ $diagnostics['backup_root_writable'] ? 'Yes' : 'No' }}</strong>
                        </div>
                        <div class="diag-row"><span>Temp folder</span><code>{{ $diagnostics['temporary_directory'] }}</code></div>
                        <div class="diag-row">
                            <span>Temp writable</span>
                            <strong class="text-{{ $diagnostics['temporary_directory_ready'] ? 'success' : 'danger' }}">{{ $diagnostics['temporary_directory_ready'] ? 'Yes' : 'No' }}</strong>
                        </div>
                        <div class="diag-row">
                            <span>Backup disk readable</span>
                            <strong class="text-{{ $diagnostics['backup_disk_readable'] ? 'success' : 'danger' }}">{{ $diagnostics['backup_disk_readable'] ? 'Yes' : 'No' }}</strong>
                        </div>
                        <div class="diag-row">
                            <span>PHP zip</span>
                            <strong class="text-{{ $diagnostics['zip_extension_loaded'] ? 'success' : 'danger' }}">{{ $diagnostics['zip_extension_loaded'] ? 'Enabled' : 'Missing' }}</strong>
                        </div>
                        <div class="diag-row">
                            <span>Process runner</span>
                            <strong class="text-{{ $diagnostics['proc_open_enabled'] ? 'success' : 'danger' }}">{{ $diagnostics['proc_open_enabled'] ? 'Enabled' : 'Disabled' }}</strong>
                        </div>
                    </div>
                </div>
            </div>

            @if($diagnostics['last_error'])
                <div class="rounded-lg border px-3 py-2 text-sm border-red-200 bg-red-50 text-red-800 mb-4">
                    <strong><i class="fas fa-exclamation-triangle mr-1"></i> Last backup error:</strong>
                    <pre class="text-sm mt-2 mb-0 p-2 rounded-md" style="white-space:pre-wrap;background:#fff5f5;border:1px solid #f5c2c7">{{ $diagnostics['last_error'] }}</pre>
                </div>
            @endif

            <div class="collapse" id="backupDiagnosticCandidates">
                <div class="text-sm text-slate-500 mb-2">Auto-detect checks these folders in order for <code>mysqldump.exe</code>:</div>
                <div class="flex flex-col" style="gap:.25rem">
                    @foreach($diagnostics['candidates'] as $candidate)
                        <code class="block p-2 rounded-md" style="background:#f1f5f9">{{ $candidate }}</code>
                    @endforeach
                </div>
            </div>

            <button type="button" class="btn ui-button ui-button-sm ui-button-secondary" data-toggle="collapse" data-target="#backupDiagnosticCandidates">
                <i class="fas fa-list mr-1"></i> Show Detection Paths
            </button>
        </div>
    </div>

    {{-- Backup list --}}
    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="card-header border-b border-slate-200 px-4 bg-white flex items-center justify-between py-4">
            <span class="font-semibold"><i class="fas fa-list mr-1"></i> Backup Archives</span>
            <small class="text-slate-500">Stored in <code>storage/app/backups/</code> · Retention: 7 days full, 4 weeks daily</small>
        </div>

        <div class="ui-table-wrap">
            <table class="table ui-table mb-0">
                <thead class="">
                    <tr>
                        <th style="width:45%">File</th>
                        <th>Size</th>
                        <th>Created</th>
                        <th>Age</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($backups as $backup)
                        <tr wire:key="backup-{{ $loop->index }}">
                            <td class="align-middle">
                                <i class="fas {{ $backup['extension'] === 'sql' ? 'fa-file-code text-sky-700' : 'fa-file-archive text-teal-700' }} mr-2"></i>
                                <span class="font-semibold text-sm">{{ $backup['name'] }}</span>
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold badge-{{ $backup['extension'] === 'sql' ? 'info' : 'success' }} ml-2">
                                    {{ strtoupper($backup['extension']) }}
                                </span>
                            </td>
                            <td class="align-middle">
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-50 text-slate-600 border border-slate-200">{{ $backup['size_human'] }}</span>
                            </td>
                            <td class="align-middle text-sm">
                                {{ \Carbon\Carbon::createFromTimestamp($backup['last_modified'])->format('d M Y, H:i') }}
                            </td>
                            <td class="align-middle text-sm text-slate-500">
                                {{ \Carbon\Carbon::createFromTimestamp($backup['last_modified'])->diffForHumans() }}
                            </td>
                            <td class="align-middle text-right">
                                <a href="{{ route('admin.backup.download', ['filename' => base64_encode($backup['path'])]) }}"
                                   class="btn ui-button ui-button-sm ui-button-secondary"
                                   title="Download">
                                    <i class="fas fa-download"></i>
                                </a>
                                <button type="button"
                                        wire:click="requestRestore({{ $loop->index }})"
                                        class="btn ui-button ui-button-sm ui-button-secondary ml-1"
                                        title="{{ $backup['extension'] === 'zip' ? 'Restore' : 'SQL backups are database-only downloads' }}"
                                        {{ $isRestoring || $backup['extension'] !== 'zip' ? 'disabled' : '' }}>
                                    @if($isRestoring)
                                        <i class="fas fa-spinner fa-spin"></i>
                                    @else
                                        <i class="fas fa-undo-alt"></i>
                                    @endif
                                </button>
                                <button type="button"
                                        wire:click="requestDeleteBackup({{ $loop->index }})"
                                        class="btn ui-button ui-button-sm ui-button-danger ml-1"
                                        title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-12 text-slate-500">
                                <i class="fas fa-database fa-3x mb-4 block opacity-50"></i>
                                <p class="mb-2 font-semibold">No backups yet</p>
                                <small>Click <strong>Run Backup Now</strong> to create your first backup.</small>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Copy results after backup run --}}
    @if(!empty($copyResults))
        <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white border-0 shadow-sm mt-6">
            <div class="card-header border-b border-slate-200 px-4 bg-white py-4 font-semibold">
                <i class="fas fa-copy mr-1 text-teal-700"></i> External Copy Results
            </div>
            <ul class="overflow-hidden rounded-md border border-slate-200 bg-white">
                @foreach($copyResults as $result)
                    <li class="list-group-item block w-full border-b border-slate-100 px-3 text-left flex items-center justify-between py-2">
                        <span class="text-sm"><i class="fas fa-folder mr-2 text-slate-500"></i>{{ $result['path'] }}</span>
                        @if($result['ok'])
                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-green-100 text-green-800"><i class="fas fa-check mr-1"></i>Copied</span>
                        @else
                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-red-100 text-red-800" title="{{ $result['reason'] }}">
                                <i class="fas fa-times mr-1"></i>Failed — {{ $result['reason'] }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Backup Destinations --}}
    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white border-0 shadow-sm mt-6">
        <div class="card-header border-b border-slate-200 px-4 bg-white py-4 flex items-center justify-between">
            <span class="font-semibold"><i class="fas fa-hdd mr-1 text-green-700"></i> Backup Destinations</span>
            <small class="text-slate-500">Backups are always saved locally. Add external drives to also copy there.</small>
        </div>
        <div class="card-body p-4">

            {{-- Always-on local destination --}}
            <div class="flex items-center p-4 mb-4 rounded-md"
                 style="background:#f0faf4;border:1px solid #c3e6cb">
                <i class="fas fa-server mr-4 text-green-700" style="font-size:1.2rem;width:24px"></i>
                <div class="grow">
                    <div class="font-semibold text-sm">Local Server Storage</div>
                    <div class="text-slate-500" style="font-size:.78rem">
                        <code>storage/app/backups/</code>
                    </div>
                </div>
                <span class="inline-flex items-center rounded text-xs font-semibold bg-green-100 text-green-800 px-4 py-1">Always On</span>
            </div>

            {{-- Configured extra paths --}}
            @forelse($extraPaths as $index => $path)
                @php $reachable = is_dir($path) && is_writable($path); @endphp
                <div class="flex items-center p-4 mb-2 rounded-md"
                     style="background:{{ $reachable ? '#f8f9fa' : '#fff5f5' }};border:1px solid {{ $reachable ? '#dee2e6' : '#f5c6cb' }}">
                    <i class="fas fa-usb mr-4 {{ $reachable ? 'text-teal-700' : 'text-red-700' }}"
                       style="font-size:1.2rem;width:24px"></i>
                    <div class="grow">
                        <div class="font-semibold text-sm">External Drive</div>
                        <div class="text-slate-500" style="font-size:.78rem"><code>{{ $path }}</code></div>
                    </div>
                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold badge-{{ $reachable ? 'primary' : 'warning' }} mr-4 px-2 py-1">
                        {{ $reachable ? 'Connected' : 'Not Found' }}
                    </span>
                    <button type="button"
                            wire:click="removePath({{ $index }})"
                            wire:confirm="Remove this backup destination?"
                            class="btn ui-button ui-button-sm ui-button-danger"
                            title="Remove">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            @empty
                <p class="text-slate-500 text-sm mb-4">No external destinations configured yet.</p>
            @endforelse

            {{-- Add new path --}}
            <div class="mt-4 pt-4 border-t border-slate-200">
                <label class="font-semibold text-sm text-slate-500 uppercase mb-2" style="letter-spacing:.05em">
                    Add External Destination
                </label>
                <div class="flex items-stretch">
                    <div class="flex">
                        <span class="flex items-center border border-slate-300 bg-slate-50 px-2 text-sm text-slate-600"><i class="fas fa-folder text-slate-500"></i></span>
                    </div>
                    <input type="text"
                           wire:model="newPath"
                           wire:keydown.enter="addPath"
                           class="form-control ui-input @error('newPath') is-invalid @enderror"
                           placeholder="e.g.  E:\backups  or  D:\MyClinicBackups">
                    <div class="flex">
                        <button type="button"
                                wire:click="openBrowser"
                                class="btn ui-button ui-button-secondary"
                                title="Browse folders">
                            <i class="fas fa-folder-open mr-1"></i> Browse
                        </button>
                        <button type="button"
                                wire:click="addPath"
                                wire:loading.attr="disabled"
                                wire:target="addPath"
                                class="btn ui-button ui-button-primary font-semibold">
                            <i class="fas fa-plus mr-1"></i> Add
                        </button>
                    </div>
                    @error('newPath')
                        <div class="ui-error">{{ $message }}</div>
                    @enderror
                </div>
                <small class="text-slate-500 mt-1 block">
                    <i class="fas fa-info-circle mr-1"></i>
                    Click <strong>Browse</strong> to pick a folder, or type the path manually. The drive must be connected.
                </small>
            </div>
        </div>
    </div>

    {{-- ── Folder Browser Modal ────────────────────────────── --}}
    @if($browserOpen)
    <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 show" style="display:block;background:rgba(0,0,0,.55)" tabindex="-1">
        <div class="mx-auto my-8 w-full max-w-lg" style="max-width:560px">
            <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl border-0" style="border-radius:10px;overflow:hidden;box-shadow:0 12px 40px rgba(0,0,0,.3)">

                {{-- Title bar --}}
                <div class="flex items-center px-6 py-4" style="background:#1a3a5c;color:#fff">
                    <i class="fas fa-folder-open mr-2" style="font-size:1.1rem;color:#f6a623"></i>
                    <h5 class="mb-0 font-semibold" style="font-size:1rem">Browse for Folder</h5>
                    <button type="button" wire:click="closeBrowser"
                            class="ml-auto btn ui-button ui-button-sm p-0 ui-button-secondary" style="color:rgba(255,255,255,.7);background:none;border:none;font-size:1.2rem;line-height:1">
                        &times;
                    </button>
                </div>

                {{-- Navigation toolbar --}}
                <div class="flex items-center px-4 py-2" style="background:#2c5282;min-height:44px;gap:.5rem">
                    @if($browserPath)
                        <button type="button" wire:click="browserUp"
                                class="btn ui-button ui-button-sm shrink-0 ui-button-secondary"
                                style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.25);padding:.2rem .55rem"
                                title="Up one level">
                            <i class="fas fa-arrow-up" style="font-size:.75rem"></i>
                        </button>
                        <code class="text-sm grow truncate" style="color:#bee3f8;font-size:.72rem">{{ $browserPath }}</code>
                        <button type="button" wire:click="toggleCreateFolder"
                                class="btn ui-button ui-button-sm shrink-0 ui-button-secondary"
                                style="background:{{ $creatingFolder ? 'rgba(246,166,35,.35)' : 'rgba(255,255,255,.12)' }};color:#fff;border:1px solid rgba(255,255,255,.25);font-size:.72rem;white-space:nowrap"
                                title="Create a new sub-folder here">
                            <i class="fas fa-folder-plus mr-1"></i> New Folder
                        </button>
                    @else
                        <i class="fas fa-desktop mr-1" style="color:rgba(255,255,255,.6);font-size:.85rem"></i>
                        <span class="text-sm" style="color:rgba(255,255,255,.75)">This PC — choose a drive</span>
                    @endif
                </div>

                {{-- New folder inline input --}}
                @if($creatingFolder && $browserPath)
                <div class="px-4 pt-2 pb-1 border-b border-slate-200" style="background:#fffde7">
                    <div class="flex items-stretch">
                        <div class="flex">
                            <span class="flex items-center border border-slate-300 bg-slate-50 px-2 text-sm text-slate-600" style="background:#fff8e1;border-color:#ffe082">
                                <i class="fas fa-folder-plus text-amber-600"></i>
                            </span>
                        </div>
                        <input type="text"
                               wire:model="newFolderName"
                               wire:keydown.enter="createFolder"
                               wire:keydown.escape="toggleCreateFolder"
                               class="form-control ui-input @error('newFolderName') is-invalid @enderror"
                               placeholder="New folder name…"
                               style="border-color:#ffe082"
                               autofocus>
                        <div class="flex">
                            <button type="button" wire:click="createFolder"
                                    class="btn ui-button ui-button-secondary ui-button-sm font-semibold" title="Create">
                                <i class="fas fa-check"></i>
                            </button>
                            <button type="button" wire:click="toggleCreateFolder"
                                    class="btn ui-button ui-button-secondary ui-button-sm" title="Cancel">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        @error('newFolderName')
                            <div class="ui-error">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                @endif

                {{-- Content area --}}
                <div style="max-height:340px;overflow-y:auto;background:#fff">

                    {{-- Drive grid (root view) --}}
                    @if(!$browserPath)
                        <div class="p-4">
                            <p class="text-slate-500 text-sm mb-4">
                                <i class="fas fa-info-circle mr-1"></i>
                                Select a drive to browse its folders:
                            </p>
                            <div class="flex flex-wrap -mx-2" style="margin:-4px">
                                @foreach($browserDrives as $drive)
                                <div class="w-6/12 px-2 p-1">
                                    <div wire:click="browserNavigate('{{ addslashes($drive['path']) }}')"
                                         class="fb-drive-card flex items-center p-4 rounded-md border border-slate-200"
                                         style="cursor:pointer;transition:all .15s;background:#f8f9fa;border-color:#dee2e6!important">
                                        <div class="mr-4 text-center shrink-0" style="width:36px">
                                            <i class="{{ $drive['icon'] }}" style="font-size:1.9rem;color:{{ $drive['iconColor'] }}"></i>
                                        </div>
                                        <div style="min-width:0">
                                            <div class="font-semibold" style="font-size:.95rem;line-height:1.2">
                                                {{ $drive['letter'] }}
                                            </div>
                                            <div class="truncate" style="font-size:.72rem;color:#555;max-width:140px">
                                                {{ $drive['label'] }}
                                            </div>
                                            @if($drive['freeHuman'])
                                                <div style="font-size:.68rem;color:#888">
                                                    {{ $drive['freeHuman'] }} free
                                                    @if($drive['sizeHuman']) of {{ $drive['sizeHuman'] }} @endif
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                @endforeach
                            </div>
                        </div>

                    {{-- Folder list --}}
                    @else
                        @forelse($browserDirs as $dir)
                            <div wire:click="browserNavigate('{{ addslashes($dir['path']) }}')"
                                 class="fb-folder-row flex items-center px-4 py-2 border-b border-slate-200"
                                 style="cursor:pointer;transition:background .1s">
                                <i class="fas fa-folder mr-4 shrink-0" style="color:#f6a623;font-size:1rem;width:18px"></i>
                                <span class="text-sm grow">{{ $dir['name'] }}</span>
                                <i class="fas fa-chevron-right text-slate-500 shrink-0" style="font-size:.6rem"></i>
                            </div>
                        @empty
                            <div class="text-center py-12 text-slate-500">
                                <i class="fas fa-folder-open fa-2x block mb-2" style="opacity:.3"></i>
                                <span class="text-sm">This folder has no sub-folders</span>
                                <div class="mt-2">
                                    <button type="button" wire:click="toggleCreateFolder"
                                            class="btn ui-button ui-button-sm ui-button-secondary">
                                        <i class="fas fa-folder-plus mr-1"></i> Create a folder here
                                    </button>
                                </div>
                            </div>
                        @endforelse
                    @endif

                </div>

                {{-- Footer / status bar --}}
                <div class="flex items-center justify-between px-4 py-2"
                     style="background:#f0f4f8;border-top:1px solid #dee2e6">
                    <div class="text-sm text-slate-500 flex items-center" style="min-width:0;max-width:330px">
                        @if($browserPath)
                            <i class="fas fa-map-marker-alt mr-1 text-teal-700 shrink-0" style="font-size:.7rem"></i>
                            <code class="truncate" style="font-size:.7rem">{{ $browserPath }}</code>
                        @else
                            <i class="fas fa-arrow-up mr-1" style="font-size:.7rem"></i>
                            <span style="font-size:.75rem">Navigate into a folder, then click Select</span>
                        @endif
                    </div>
                    <div class="flex shrink-0" style="gap:.4rem">
                        <button type="button" class="btn ui-button ui-button-sm ui-button-secondary" wire:click="closeBrowser">
                            Cancel
                        </button>
                        <button type="button"
                                class="btn ui-button ui-button-sm ui-button-primary font-semibold"
                                wire:click="selectCurrentFolder"
                                @if(!$browserPath) disabled @endif>
                            <i class="fas fa-check mr-1"></i> Select This Folder
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </div>
    @endif

    {{-- Retention policy info --}}
    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white border-0 shadow-sm mt-6">
        <div class="card-header border-b border-slate-200 px-4 bg-white py-4 font-semibold">
            <i class="fas fa-info-circle mr-1 text-sky-700"></i> Retention Policy
        </div>
        <div class="card-body p-4 pb-2">

            {{-- Timeline --}}
            <div class="flex items-stretch" style="gap:0">

                <div class="flex-1 text-center p-4 rounded-left" style="background:#e8f4fd;border:1px solid #b8d8f5">
                    <i class="fas fa-bolt text-teal-700 mb-1 block" style="font-size:1.2rem"></i>
                    <div class="font-semibold text-teal-700" style="font-size:1.1rem">Today</div>
                    <div class="text-sm text-slate-500 mt-1">Keep <strong>every</strong> backup<br><span style="font-size:.72rem">(1 per 5 min)</span></div>
                </div>

                <div class="flex items-center px-1 text-slate-500" style="font-size:.7rem">&#9658;</div>

                <div class="flex-1 text-center p-4" style="background:#e8f8f0;border:1px solid #b2dfcc">
                    <i class="fas fa-calendar-day text-green-700 mb-1 block" style="font-size:1.2rem"></i>
                    <div class="font-semibold text-green-700" style="font-size:1.1rem">This Week</div>
                    <div class="text-sm text-slate-500 mt-1">Keep <strong>1 per day</strong><br><span style="font-size:.72rem">(latest of each day)</span></div>
                </div>

                <div class="flex items-center px-1 text-slate-500" style="font-size:.7rem">&#9658;</div>

                <div class="flex-1 text-center p-4" style="background:#fff8e1;border:1px solid #ffe082">
                    <i class="fas fa-calendar-week text-amber-600 mb-1 block" style="font-size:1.2rem"></i>
                    <div class="font-semibold text-amber-600" style="font-size:1.1rem">This Month</div>
                    <div class="text-sm text-slate-500 mt-1">Keep <strong>1 per week</strong><br><span style="font-size:.72rem">(latest of each week)</span></div>
                </div>

                <div class="flex items-center px-1 text-slate-500" style="font-size:.7rem">&#9658;</div>

                <div class="flex-1 text-center p-4" style="background:#fce8f0;border:1px solid #f5b7cc">
                    <i class="fas fa-calendar-alt text-red-700 mb-1 block" style="font-size:1.2rem"></i>
                    <div class="font-semibold text-red-700" style="font-size:1.1rem">This Year</div>
                    <div class="text-sm text-slate-500 mt-1">Keep <strong>1 per month</strong><br><span style="font-size:.72rem">(latest of each month)</span></div>
                </div>

                <div class="flex items-center px-1 text-slate-500" style="font-size:.7rem">&#9658;</div>

                <div class="flex-1 text-center p-4 rounded-right" style="background:#f0ecfa;border:1px solid #d1c4e9">
                    <i class="fas fa-history mb-1 block" style="font-size:1.2rem;color:#6f42c1"></i>
                    <div class="font-semibold" style="font-size:1.1rem;color:#6f42c1">Older</div>
                    <div class="text-sm text-slate-500 mt-1">Keep <strong>1 per year</strong><br><span style="font-size:.72rem">(latest of each year)</span></div>
                </div>

            </div>

            <p class="text-slate-500 text-sm mb-0 mt-4 text-center">
                <i class="fas fa-sync-alt mr-1"></i>
                Pruning runs automatically every hour. Click <strong>Prune Now</strong> to apply immediately.
            </p>
        </div>
    </div>

    {{-- Restore Guide --}}
    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white border-0 shadow-sm mt-6">
        <div class="card-header border-b border-slate-200 px-4 bg-white py-4 flex items-center justify-between"
             data-toggle="collapse" data-target="#restoreGuide" style="cursor:pointer">
            <span class="font-semibold">
                <i class="fas fa-undo-alt mr-1 text-red-700"></i> How to Restore a Backup
            </span>
            <i class="fas fa-chevron-down text-slate-500" style="font-size:.75rem"></i>
        </div>
        <div id="restoreGuide" class="collapse">
            <div class="card-body p-4">

                <div class="rounded-lg border px-3 text-sm border-amber-200 bg-amber-50 text-amber-900 py-2 mb-4">
                    <i class="fas fa-exclamation-triangle mr-1"></i>
                    <strong>Run restore from the command line only.</strong>
                    Restoring via the web UI while the app is live risks data corruption.
                    Stop the web server first, run the command, then restart.
                </div>

                {{-- Step list --}}
                <ol class="pl-4" style="line-height:2">
                    <li>
                        <strong>Stop Apache</strong> in the Laragon control panel.
                    </li>
                    <li>
                        Open a terminal and go to the project directory:
                        <div class="my-1">
                            <code class="block p-2 rounded-md" style="background:#f1f3f4;font-size:.8rem">
                                cd C:\laragon\www\eyeclinicproject
                            </code>
                        </div>
                    </li>
                    <li>
                        Run the interactive restore wizard:
                        <div class="my-1">
                            <code class="block p-2 rounded-md" style="background:#1e3a5c;color:#7dd3fc;font-size:.8rem">
                                php artisan backup:restore
                            </code>
                        </div>
                        The command lists available backups. Select one, confirm, and it will:
                        <ul class="mt-1 mb-0" style="font-size:.875rem">
                            <li>Import the database dump automatically</li>
                            <li>Restore all uploaded files to <code>storage/app/public/</code></li>
                            <li>Save the backed-up <code>.env</code> as <code>.env.restored</code> for review</li>
                        </ul>
                    </li>
                    <li>
                        To restore from a <strong>specific file</strong> (e.g. from a pen drive):
                        <div class="my-1">
                            <code class="block p-2 rounded-md" style="background:#1e3a5c;color:#7dd3fc;font-size:.8rem">
                                php artisan backup:restore "EyeClinicProject/2026-05-10-02-00-00.zip"
                            </code>
                        </div>
                        The filename is relative to the <code>storage/app/backups/</code> folder.
                    </li>
                    <li>
                        To restore a <strong>database-only SQL</strong> file, use MySQL directly:
                        <div class="my-1">
                            <code class="block p-2 rounded-md" style="background:#1e3a5c;color:#7dd3fc;font-size:.8rem">
                                mysql -u root eyeclinicproject &lt; storage\app\backups\Eye Clinic\database-eyeclinicproject-2026-07-07-10-00-00.sql
                            </code>
                        </div>
                    </li>
                    <li>
                        If credentials changed, compare <code>.env.restored</code> against your current
                        <code>.env</code> and apply any differences manually.
                    </li>
                    <li>
                        <strong>Start Apache</strong> again and verify the application works.
                    </li>
                </ol>

                <div class="mt-4 p-4 rounded-md" style="background:#f0faf4;border:1px solid #c3e6cb;font-size:.82rem">
                    <i class="fas fa-lightbulb text-green-700 mr-1"></i>
                    <strong>Tip:</strong> Do a test restore to a separate XAMPP installation before you need it in an emergency.
                    Backups are only useful if you know they work.
                </div>

            </div>
        </div>
    </div>

    <script>
        window.addEventListener('show-backup-restore-confirmation', function(event) {
            var index = event.detail.index;
            var name  = event.detail.name;
            window.appConfirm('This will overwrite the current database and all uploaded files with:\n' + name,
                { title: 'Restore this backup?', confirmText: 'Yes, restore it', danger: true })
                .then(function(ok) { if (ok) @this.call('restoreBackup', index); });
        });

        window.addEventListener('show-backup-delete-confirmation', function(event) {
            var index = event.detail.index;
            var name  = event.detail.name;
            window.appConfirm(name + '\nThis cannot be undone.', { title: 'Delete this backup?', confirmText: 'Yes, delete it', danger: true })
                .then(function(ok) { if (ok) @this.call('deleteBackup', index); });
        });
    </script>

</div>

<style>
.font-weight-semibold { font-weight: 600; }
.opacity-50 { opacity: .4; }

/* Folder browser hover states */
.fb-drive-card:hover {
    background: #e8f0fe !important;
    border-color: #4285f4 !important;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(66,133,244,.2);
}
.fb-folder-row:hover {
    background: #f0f4ff;
}
.fb-folder-row:hover .fa-folder {
    color: #e6911a;
}
.diag-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    border-bottom: 1px solid #edf2f7;
    padding: 7px 0;
    font-size: .84rem;
}
.diag-row:last-child {
    border-bottom: 0;
}
.diag-row span {
    color: #64748b;
    flex: 0 0 130px;
}
.diag-row code,
.diag-row strong {
    text-align: right;
    word-break: break-all;
}
</style>
</div>
