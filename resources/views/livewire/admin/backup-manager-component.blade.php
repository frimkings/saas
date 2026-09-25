<div data-livewire-root>
<div class="container-fluid py-4">

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1"><i class="fas fa-database mr-2 text-primary"></i>Database Backup</h2>
            <p class="text-muted mb-0">Create, download, and manage database backup archives.</p>
        </div>
        <div class="d-flex" style="gap:.5rem">
            <button type="button"
                    wire:click="runBackup"
                    wire:loading.attr="disabled"
                    wire:target="runBackup,runFullBackup"
                    class="btn btn-primary font-weight-bold shadow-sm">
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
                    class="btn btn-success font-weight-bold shadow-sm"
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
                    class="btn btn-outline-secondary"
                    title="Remove old backups per retention policy">
                <span wire:loading.remove wire:target="cleanBackups"><i class="fas fa-broom mr-1"></i> Prune Now</span>
                <span wire:loading wire:target="cleanBackups"><i class="fas fa-spinner fa-spin mr-1"></i></span>
            </button>
        </div>
    </div>

    {{-- Stats row --}}
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="small-box bg-primary mb-0">
                <div class="inner">
                    <h3>{{ $backups->count() }}</h3>
                    <p>Total Backups</p>
                </div>
                <div class="icon"><i class="fas fa-archive"></i></div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="small-box bg-info mb-0">
                <div class="inner">
                    <h3>{{ $totalSize }}</h3>
                    <p>Storage Used</p>
                </div>
                <div class="icon"><i class="fas fa-hdd"></i></div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="small-box bg-success mb-0">
                <div class="inner">
                    <h3>{{ $backups->isNotEmpty() ? \Carbon\Carbon::createFromTimestamp($backups->first()['last_modified'])->diffForHumans() : 'Never' }}</h3>
                    <p>Latest Backup</p>
                </div>
                <div class="icon"><i class="fas fa-clock"></i></div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="small-box bg-warning mb-0">
                <div class="inner">
                    <h3>5 min <small style="font-size:.75rem">/ Daily</small></h3>
                    <p>DB / Full Schedule</p>
                </div>
                <div class="icon"><i class="fas fa-calendar-check"></i></div>
            </div>
        </div>
    </div>

    {{-- Backup diagnostics --}}
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex align-items-center justify-content-between py-3">
            <span class="font-weight-bold"><i class="fas fa-stethoscope mr-1 text-info"></i> Backup Diagnostics</span>
            <span class="badge badge-{{ $diagnostics['full_backup_ready'] ? 'success' : ($diagnostics['db_backup_ready'] ? 'warning' : 'danger') }}">
                {{ $diagnostics['full_backup_ready'] ? 'Ready' : ($diagnostics['db_backup_ready'] ? 'DB Ready' : 'Needs Attention') }}
            </span>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-lg-6 mb-3">
                    <div class="p-3 h-100 rounded" style="background:#f8fafc;border:1px solid #e5e7eb">
                        <div class="font-weight-bold mb-2"><i class="fas fa-terminal mr-1 text-primary"></i> MySQL Dump Tool</div>
                        <div class="diag-row"><span>Configured path</span><code>{{ $diagnostics['configured_path'] }}</code></div>
                        <div class="diag-row"><span>Detected path</span><code>{{ $diagnostics['resolved_path'] }}</code></div>
                        <div class="diag-row"><span>Executable</span><code>{{ $diagnostics['executable'] }}</code></div>
                        <div class="diag-row"><span>Version</span><strong>{{ \Illuminate\Support\Str::limit($diagnostics['binary_version'], 120) }}</strong></div>
                    </div>
                </div>
                <div class="col-lg-6 mb-3">
                    <div class="p-3 h-100 rounded" style="background:#f8fafc;border:1px solid #e5e7eb">
                        <div class="font-weight-bold mb-2"><i class="fas fa-server mr-1 text-success"></i> Database & Storage</div>
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
                <div class="alert alert-danger mb-3">
                    <strong><i class="fas fa-exclamation-triangle mr-1"></i> Last backup error:</strong>
                    <pre class="small mt-2 mb-0 p-2 rounded" style="white-space:pre-wrap;background:#fff5f5;border:1px solid #f5c2c7">{{ $diagnostics['last_error'] }}</pre>
                </div>
            @endif

            <div class="collapse" id="backupDiagnosticCandidates">
                <div class="small text-muted mb-2">Auto-detect checks these folders in order for <code>mysqldump.exe</code>:</div>
                <div class="d-flex flex-column" style="gap:.25rem">
                    @foreach($diagnostics['candidates'] as $candidate)
                        <code class="d-block p-2 rounded" style="background:#f1f5f9">{{ $candidate }}</code>
                    @endforeach
                </div>
            </div>

            <button type="button" class="btn btn-sm btn-outline-secondary" data-toggle="collapse" data-target="#backupDiagnosticCandidates">
                <i class="fas fa-list mr-1"></i> Show Detection Paths
            </button>
        </div>
    </div>

    {{-- Backup list --}}
    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex align-items-center justify-content-between py-3">
            <span class="font-weight-bold"><i class="fas fa-list mr-1"></i> Backup Archives</span>
            <small class="text-muted">Stored in <code>storage/app/backups/</code> · Retention: 7 days full, 4 weeks daily</small>
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
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
                                <i class="fas {{ $backup['extension'] === 'sql' ? 'fa-file-code text-info' : 'fa-file-archive text-primary' }} mr-2"></i>
                                <span class="font-weight-semibold small">{{ $backup['name'] }}</span>
                                <span class="badge badge-{{ $backup['extension'] === 'sql' ? 'info' : 'success' }} ml-2">
                                    {{ strtoupper($backup['extension']) }}
                                </span>
                            </td>
                            <td class="align-middle">
                                <span class="badge badge-light border">{{ $backup['size_human'] }}</span>
                            </td>
                            <td class="align-middle small">
                                {{ \Carbon\Carbon::createFromTimestamp($backup['last_modified'])->format('d M Y, H:i') }}
                            </td>
                            <td class="align-middle small text-muted">
                                {{ \Carbon\Carbon::createFromTimestamp($backup['last_modified'])->diffForHumans() }}
                            </td>
                            <td class="align-middle text-right">
                                <a href="{{ route('admin.backup.download', ['filename' => base64_encode($backup['path'])]) }}"
                                   class="btn btn-sm btn-outline-primary"
                                   title="Download">
                                    <i class="fas fa-download"></i>
                                </a>
                                <button type="button"
                                        wire:click="requestRestore({{ $loop->index }})"
                                        class="btn btn-sm btn-outline-warning ml-1"
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
                                        class="btn btn-sm btn-outline-danger ml-1"
                                        title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-5 text-muted">
                                <i class="fas fa-database fa-3x mb-3 d-block opacity-50"></i>
                                <p class="mb-2 font-weight-bold">No backups yet</p>
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
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-white py-3 font-weight-bold">
                <i class="fas fa-copy mr-1 text-primary"></i> External Copy Results
            </div>
            <ul class="list-group list-group-flush">
                @foreach($copyResults as $result)
                    <li class="list-group-item d-flex align-items-center justify-content-between py-2">
                        <span class="small"><i class="fas fa-folder mr-2 text-muted"></i>{{ $result['path'] }}</span>
                        @if($result['ok'])
                            <span class="badge badge-success"><i class="fas fa-check mr-1"></i>Copied</span>
                        @else
                            <span class="badge badge-danger" title="{{ $result['reason'] }}">
                                <i class="fas fa-times mr-1"></i>Failed — {{ $result['reason'] }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Backup Destinations --}}
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
            <span class="font-weight-bold"><i class="fas fa-hdd mr-1 text-success"></i> Backup Destinations</span>
            <small class="text-muted">Backups are always saved locally. Add external drives to also copy there.</small>
        </div>
        <div class="card-body">

            {{-- Always-on local destination --}}
            <div class="d-flex align-items-center p-3 mb-3 rounded"
                 style="background:#f0faf4;border:1px solid #c3e6cb">
                <i class="fas fa-server mr-3 text-success" style="font-size:1.2rem;width:24px"></i>
                <div class="flex-grow-1">
                    <div class="font-weight-bold small">Local Server Storage</div>
                    <div class="text-muted" style="font-size:.78rem">
                        <code>storage/app/backups/</code>
                    </div>
                </div>
                <span class="badge badge-success px-3 py-1">Always On</span>
            </div>

            {{-- Configured extra paths --}}
            @forelse($extraPaths as $index => $path)
                @php $reachable = is_dir($path) && is_writable($path); @endphp
                <div class="d-flex align-items-center p-3 mb-2 rounded"
                     style="background:{{ $reachable ? '#f8f9fa' : '#fff5f5' }};border:1px solid {{ $reachable ? '#dee2e6' : '#f5c6cb' }}">
                    <i class="fas fa-usb mr-3 {{ $reachable ? 'text-primary' : 'text-danger' }}"
                       style="font-size:1.2rem;width:24px"></i>
                    <div class="flex-grow-1">
                        <div class="font-weight-bold small">External Drive</div>
                        <div class="text-muted" style="font-size:.78rem"><code>{{ $path }}</code></div>
                    </div>
                    <span class="badge badge-{{ $reachable ? 'primary' : 'warning' }} mr-3 px-2 py-1">
                        {{ $reachable ? 'Connected' : 'Not Found' }}
                    </span>
                    <button type="button"
                            wire:click="removePath({{ $index }})"
                            wire:confirm="Remove this backup destination?"
                            class="btn btn-sm btn-outline-danger"
                            title="Remove">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            @empty
                <p class="text-muted small mb-3">No external destinations configured yet.</p>
            @endforelse

            {{-- Add new path --}}
            <div class="mt-3 pt-3 border-top">
                <label class="font-weight-bold small text-muted text-uppercase mb-2" style="letter-spacing:.05em">
                    Add External Destination
                </label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fas fa-folder text-muted"></i></span>
                    </div>
                    <input type="text"
                           wire:model="newPath"
                           wire:keydown.enter="addPath"
                           class="form-control @error('newPath') is-invalid @enderror"
                           placeholder="e.g.  E:\backups  or  D:\MyClinicBackups">
                    <div class="input-group-append">
                        <button type="button"
                                wire:click="openBrowser"
                                class="btn btn-outline-secondary"
                                title="Browse folders">
                            <i class="fas fa-folder-open mr-1"></i> Browse
                        </button>
                        <button type="button"
                                wire:click="addPath"
                                wire:loading.attr="disabled"
                                wire:target="addPath"
                                class="btn btn-success font-weight-bold">
                            <i class="fas fa-plus mr-1"></i> Add
                        </button>
                    </div>
                    @error('newPath')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <small class="text-muted mt-1 d-block">
                    <i class="fas fa-info-circle mr-1"></i>
                    Click <strong>Browse</strong> to pick a folder, or type the path manually. The drive must be connected.
                </small>
            </div>
        </div>
    </div>

    {{-- ── Folder Browser Modal ────────────────────────────── --}}
    @if($browserOpen)
    <div class="modal fade show" style="display:block;background:rgba(0,0,0,.55)" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered" style="max-width:560px">
            <div class="modal-content border-0" style="border-radius:10px;overflow:hidden;box-shadow:0 12px 40px rgba(0,0,0,.3)">

                {{-- Title bar --}}
                <div class="d-flex align-items-center px-4 py-3" style="background:#1a3a5c;color:#fff">
                    <i class="fas fa-folder-open mr-2" style="font-size:1.1rem;color:#f6a623"></i>
                    <h5 class="mb-0 font-weight-bold" style="font-size:1rem">Browse for Folder</h5>
                    <button type="button" wire:click="closeBrowser"
                            class="ml-auto btn btn-sm p-0" style="color:rgba(255,255,255,.7);background:none;border:none;font-size:1.2rem;line-height:1">
                        &times;
                    </button>
                </div>

                {{-- Navigation toolbar --}}
                <div class="d-flex align-items-center px-3 py-2" style="background:#2c5282;min-height:44px;gap:.5rem">
                    @if($browserPath)
                        <button type="button" wire:click="browserUp"
                                class="btn btn-sm flex-shrink-0"
                                style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.25);padding:.2rem .55rem"
                                title="Up one level">
                            <i class="fas fa-arrow-up" style="font-size:.75rem"></i>
                        </button>
                        <code class="small flex-grow-1 text-truncate" style="color:#bee3f8;font-size:.72rem">{{ $browserPath }}</code>
                        <button type="button" wire:click="toggleCreateFolder"
                                class="btn btn-sm flex-shrink-0"
                                style="background:{{ $creatingFolder ? 'rgba(246,166,35,.35)' : 'rgba(255,255,255,.12)' }};color:#fff;border:1px solid rgba(255,255,255,.25);font-size:.72rem;white-space:nowrap"
                                title="Create a new sub-folder here">
                            <i class="fas fa-folder-plus mr-1"></i> New Folder
                        </button>
                    @else
                        <i class="fas fa-desktop mr-1" style="color:rgba(255,255,255,.6);font-size:.85rem"></i>
                        <span class="small" style="color:rgba(255,255,255,.75)">This PC — choose a drive</span>
                    @endif
                </div>

                {{-- New folder inline input --}}
                @if($creatingFolder && $browserPath)
                <div class="px-3 pt-2 pb-1 border-bottom" style="background:#fffde7">
                    <div class="input-group input-group-sm">
                        <div class="input-group-prepend">
                            <span class="input-group-text" style="background:#fff8e1;border-color:#ffe082">
                                <i class="fas fa-folder-plus text-warning"></i>
                            </span>
                        </div>
                        <input type="text"
                               wire:model="newFolderName"
                               wire:keydown.enter="createFolder"
                               wire:keydown.escape="toggleCreateFolder"
                               class="form-control @error('newFolderName') is-invalid @enderror"
                               placeholder="New folder name…"
                               style="border-color:#ffe082"
                               autofocus>
                        <div class="input-group-append">
                            <button type="button" wire:click="createFolder"
                                    class="btn btn-warning btn-sm font-weight-bold" title="Create">
                                <i class="fas fa-check"></i>
                            </button>
                            <button type="button" wire:click="toggleCreateFolder"
                                    class="btn btn-outline-secondary btn-sm" title="Cancel">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        @error('newFolderName')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                @endif

                {{-- Content area --}}
                <div style="max-height:340px;overflow-y:auto;background:#fff">

                    {{-- Drive grid (root view) --}}
                    @if(!$browserPath)
                        <div class="p-3">
                            <p class="text-muted small mb-3">
                                <i class="fas fa-info-circle mr-1"></i>
                                Select a drive to browse its folders:
                            </p>
                            <div class="row" style="margin:-4px">
                                @foreach($browserDrives as $drive)
                                <div class="col-6 p-1">
                                    <div wire:click="browserNavigate('{{ addslashes($drive['path']) }}')"
                                         class="fb-drive-card d-flex align-items-center p-3 rounded border"
                                         style="cursor:pointer;transition:all .15s;background:#f8f9fa;border-color:#dee2e6!important">
                                        <div class="mr-3 text-center flex-shrink-0" style="width:36px">
                                            <i class="{{ $drive['icon'] }}" style="font-size:1.9rem;color:{{ $drive['iconColor'] }}"></i>
                                        </div>
                                        <div style="min-width:0">
                                            <div class="font-weight-bold" style="font-size:.95rem;line-height:1.2">
                                                {{ $drive['letter'] }}
                                            </div>
                                            <div class="text-truncate" style="font-size:.72rem;color:#555;max-width:140px">
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
                                 class="fb-folder-row d-flex align-items-center px-3 py-2 border-bottom"
                                 style="cursor:pointer;transition:background .1s">
                                <i class="fas fa-folder mr-3 flex-shrink-0" style="color:#f6a623;font-size:1rem;width:18px"></i>
                                <span class="small flex-grow-1">{{ $dir['name'] }}</span>
                                <i class="fas fa-chevron-right text-muted flex-shrink-0" style="font-size:.6rem"></i>
                            </div>
                        @empty
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-folder-open fa-2x d-block mb-2" style="opacity:.3"></i>
                                <span class="small">This folder has no sub-folders</span>
                                <div class="mt-2">
                                    <button type="button" wire:click="toggleCreateFolder"
                                            class="btn btn-sm btn-outline-warning">
                                        <i class="fas fa-folder-plus mr-1"></i> Create a folder here
                                    </button>
                                </div>
                            </div>
                        @endforelse
                    @endif

                </div>

                {{-- Footer / status bar --}}
                <div class="d-flex align-items-center justify-content-between px-3 py-2"
                     style="background:#f0f4f8;border-top:1px solid #dee2e6">
                    <div class="small text-muted d-flex align-items-center" style="min-width:0;max-width:330px">
                        @if($browserPath)
                            <i class="fas fa-map-marker-alt mr-1 text-primary flex-shrink-0" style="font-size:.7rem"></i>
                            <code class="text-truncate" style="font-size:.7rem">{{ $browserPath }}</code>
                        @else
                            <i class="fas fa-arrow-up mr-1" style="font-size:.7rem"></i>
                            <span style="font-size:.75rem">Navigate into a folder, then click Select</span>
                        @endif
                    </div>
                    <div class="d-flex flex-shrink-0" style="gap:.4rem">
                        <button type="button" class="btn btn-sm btn-secondary" wire:click="closeBrowser">
                            Cancel
                        </button>
                        <button type="button"
                                class="btn btn-sm btn-primary font-weight-bold"
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
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white py-3 font-weight-bold">
            <i class="fas fa-info-circle mr-1 text-info"></i> Retention Policy
        </div>
        <div class="card-body pb-2">

            {{-- Timeline --}}
            <div class="d-flex align-items-stretch" style="gap:0">

                <div class="flex-fill text-center p-3 rounded-left" style="background:#e8f4fd;border:1px solid #b8d8f5">
                    <i class="fas fa-bolt text-primary mb-1 d-block" style="font-size:1.2rem"></i>
                    <div class="font-weight-bold text-primary" style="font-size:1.1rem">Today</div>
                    <div class="small text-muted mt-1">Keep <strong>every</strong> backup<br><span style="font-size:.72rem">(1 per 5 min)</span></div>
                </div>

                <div class="d-flex align-items-center px-1 text-muted" style="font-size:.7rem">&#9658;</div>

                <div class="flex-fill text-center p-3" style="background:#e8f8f0;border:1px solid #b2dfcc">
                    <i class="fas fa-calendar-day text-success mb-1 d-block" style="font-size:1.2rem"></i>
                    <div class="font-weight-bold text-success" style="font-size:1.1rem">This Week</div>
                    <div class="small text-muted mt-1">Keep <strong>1 per day</strong><br><span style="font-size:.72rem">(latest of each day)</span></div>
                </div>

                <div class="d-flex align-items-center px-1 text-muted" style="font-size:.7rem">&#9658;</div>

                <div class="flex-fill text-center p-3" style="background:#fff8e1;border:1px solid #ffe082">
                    <i class="fas fa-calendar-week text-warning mb-1 d-block" style="font-size:1.2rem"></i>
                    <div class="font-weight-bold text-warning" style="font-size:1.1rem">This Month</div>
                    <div class="small text-muted mt-1">Keep <strong>1 per week</strong><br><span style="font-size:.72rem">(latest of each week)</span></div>
                </div>

                <div class="d-flex align-items-center px-1 text-muted" style="font-size:.7rem">&#9658;</div>

                <div class="flex-fill text-center p-3" style="background:#fce8f0;border:1px solid #f5b7cc">
                    <i class="fas fa-calendar-alt text-danger mb-1 d-block" style="font-size:1.2rem"></i>
                    <div class="font-weight-bold text-danger" style="font-size:1.1rem">This Year</div>
                    <div class="small text-muted mt-1">Keep <strong>1 per month</strong><br><span style="font-size:.72rem">(latest of each month)</span></div>
                </div>

                <div class="d-flex align-items-center px-1 text-muted" style="font-size:.7rem">&#9658;</div>

                <div class="flex-fill text-center p-3 rounded-right" style="background:#f0ecfa;border:1px solid #d1c4e9">
                    <i class="fas fa-history mb-1 d-block" style="font-size:1.2rem;color:#6f42c1"></i>
                    <div class="font-weight-bold" style="font-size:1.1rem;color:#6f42c1">Older</div>
                    <div class="small text-muted mt-1">Keep <strong>1 per year</strong><br><span style="font-size:.72rem">(latest of each year)</span></div>
                </div>

            </div>

            <p class="text-muted small mb-0 mt-3 text-center">
                <i class="fas fa-sync-alt mr-1"></i>
                Pruning runs automatically every hour. Click <strong>Prune Now</strong> to apply immediately.
            </p>
        </div>
    </div>

    {{-- Restore Guide --}}
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between"
             data-toggle="collapse" data-target="#restoreGuide" style="cursor:pointer">
            <span class="font-weight-bold">
                <i class="fas fa-undo-alt mr-1 text-danger"></i> How to Restore a Backup
            </span>
            <i class="fas fa-chevron-down text-muted" style="font-size:.75rem"></i>
        </div>
        <div id="restoreGuide" class="collapse">
            <div class="card-body">

                <div class="alert alert-warning py-2 mb-3">
                    <i class="fas fa-exclamation-triangle mr-1"></i>
                    <strong>Run restore from the command line only.</strong>
                    Restoring via the web UI while the app is live risks data corruption.
                    Stop the web server first, run the command, then restart.
                </div>

                {{-- Step list --}}
                <ol class="pl-3" style="line-height:2">
                    <li>
                        <strong>Stop Apache</strong> in the Laragon control panel.
                    </li>
                    <li>
                        Open a terminal and go to the project directory:
                        <div class="my-1">
                            <code class="d-block p-2 rounded" style="background:#f1f3f4;font-size:.8rem">
                                cd C:\laragon\www\eyeclinicproject
                            </code>
                        </div>
                    </li>
                    <li>
                        Run the interactive restore wizard:
                        <div class="my-1">
                            <code class="d-block p-2 rounded" style="background:#1e3a5c;color:#7dd3fc;font-size:.8rem">
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
                            <code class="d-block p-2 rounded" style="background:#1e3a5c;color:#7dd3fc;font-size:.8rem">
                                php artisan backup:restore "EyeClinicProject/2026-05-10-02-00-00.zip"
                            </code>
                        </div>
                        The filename is relative to the <code>storage/app/backups/</code> folder.
                    </li>
                    <li>
                        To restore a <strong>database-only SQL</strong> file, use MySQL directly:
                        <div class="my-1">
                            <code class="d-block p-2 rounded" style="background:#1e3a5c;color:#7dd3fc;font-size:.8rem">
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

                <div class="mt-3 p-3 rounded" style="background:#f0faf4;border:1px solid #c3e6cb;font-size:.82rem">
                    <i class="fas fa-lightbulb text-success mr-1"></i>
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
            Swal.fire({
                title: 'Restore this backup?',
                html: 'This will <strong>overwrite</strong> the current database and all uploaded files with:<br><br><code>' + name + '</code>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#c0392b',
                confirmButtonText: 'Yes, restore it',
                cancelButtonText: 'Cancel',
            }).then(function(result) {
                if (result.isConfirmed) {
                    @this.call('restoreBackup', index);
                }
            });
        });

        window.addEventListener('show-backup-delete-confirmation', function(event) {
            var index = event.detail.index;
            var name  = event.detail.name;
            Swal.fire({
                title: 'Delete this backup?',
                html: '<code>' + name + '</code><br><small class="text-muted">This cannot be undone.</small>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: 'Yes, delete it',
                cancelButtonText: 'Cancel',
            }).then(function(result) {
                if (result.isConfirmed) {
                    @this.call('deleteBackup', index);
                }
            });
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
