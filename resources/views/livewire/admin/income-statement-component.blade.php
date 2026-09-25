<div class="income-shell">
    <style>
        .income-shell { background: #f5f7fb; min-height: 100vh; color: #1f2933; }
        .income-toolbar { background: #ffffff; border-bottom: 1px solid #dde3ea; }
        .income-title { font-size: 1.35rem; font-weight: 800; margin: 0; }
        .income-subtitle { color: #697586; font-size: .85rem; margin: 0; }
        .income-panel { background: #ffffff; border: 1px solid #dde3ea; border-radius: 8px; box-shadow: 0 8px 22px rgba(31, 41, 51, .04); }
        .statement-table th { background: #f8fafc; border-top: 0; font-size: .72rem; letter-spacing: .03em; text-transform: uppercase; color: #52606d; }
        .statement-heading td { background: #eef2f7; font-weight: 800; text-transform: uppercase; }
        .statement-total td { font-weight: 800; border-top: 2px solid #cbd5e1; }
        .statement-final td { background: #ecfdf3; font-weight: 900; border-top: 2px solid #16a34a; }
        .muted-label { color: #697586; font-size: .72rem; font-weight: 800; text-transform: uppercase; }
        .amount-cell { width: 190px; text-align: right; font-variant-numeric: tabular-nums; }
        .line-actions { width: 55px; text-align: right; }
        .summary-card { background: #ffffff; border: 1px solid #dde3ea; border-radius: 8px; min-height: 92px; }
        .summary-label { color: #697586; font-size: .72rem; font-weight: 800; text-transform: uppercase; }
        .summary-value { font-size: 1.25rem; font-weight: 900; }
        .export-only-header { display: none; }
        @media print {
            @page { size: A4 portrait; margin: 14mm 12mm; }
            body { background: #ffffff !important; }
            .main-sidebar, .main-header, .income-toolbar, .income-panel .btn, .col-xl-4, .no-print { display: none !important; }
            .content-wrapper, .income-shell { margin: 0 !important; background: #ffffff !important; }
            .container-fluid { width: 100% !important; max-width: 100% !important; padding: 0 !important; }
            .income-panel { box-shadow: none !important; border: 0 !important; }
            .col-xl-8 { flex: 0 0 100% !important; max-width: 100% !important; }
            .export-only-header { display: block !important; text-align: center; margin-bottom: 16px; border-bottom: 1px solid #cbd5e1; padding-bottom: 10px; }
            .export-clinic-logo { max-height: 58px; max-width: 130px; object-fit: contain; margin-bottom: 6px; }
            .export-clinic-name { font-size: 18px; font-weight: 800; text-transform: uppercase; }
            .export-clinic-details, .export-statement-period { font-size: 11px; color: #374151; }
            .statement-table { font-size: 11px; }
            .statement-table th, .statement-table td { padding: 5px 7px; }
            .income-panel .border-bottom { display: none !important; }
        }
    </style>

    <div class="income-toolbar">
        <div class="container-fluid py-3">
            <div class="d-flex flex-wrap align-items-center justify-content-between">
                <div class="mb-2 mb-md-0">
                    <h1 class="income-title">Income Statement</h1>
                    <p class="income-subtitle">
                        {{ \Carbon\Carbon::parse($fromDate)->format('M d, Y') }} to {{ \Carbon\Carbon::parse($toDate)->format('M d, Y') }}
                    </p>
                </div>
                <div class="d-flex flex-wrap">
                    <a href="{{ route('admin.income-statement.export.csv', ['from' => $fromDate, 'to' => $toDate]) }}" class="btn btn-sm btn-outline-success mr-2 mb-2">
                        <i class="fas fa-file-csv mr-1"></i>CSV
                    </a>
                    <a href="{{ route('admin.income-statement.export.pdf', ['from' => $fromDate, 'to' => $toDate]) }}" class="btn btn-sm btn-outline-danger mr-2 mb-2">
                        <i class="fas fa-file-pdf mr-1"></i>PDF
                    </a>
                    <a href="{{ route('admin.income-statement.preview', ['from' => $fromDate, 'to' => $toDate]) }}" target="_blank" class="btn btn-sm btn-outline-info mr-2 mb-2">
                        <i class="fas fa-search mr-1"></i>Preview
                    </a>
                    <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-dark mr-2 mb-2">
                        <i class="fas fa-print mr-1"></i>Print
                    </button>
                    <button type="button" wire:click="setThisMonth" class="btn btn-sm btn-primary mr-2 mb-2">
                        This Month
                    </button>
                    <button type="button" wire:click="setLastMonth" class="btn btn-sm btn-outline-primary mr-2 mb-2">
                        Last Month
                    </button>
                </div>
            </div>

            <x-finance.statement-switcher :links="$switcher" current="clinic" class="mt-2" />

            <div class="row mt-3">
                <div class="col-md-5 mb-2"><label class="muted-label mb-1 d-block">Period</label><x-date-range from="fromDate" to="toDate" presets="finance" /></div>
            </div>
        </div>
    </div>

    <div class="container-fluid py-4">
        <div class="export-only-header">
            @if($clinicSettings->logoDataUri())
                <img src="{{ $clinicSettings->logoDataUri() }}" class="export-clinic-logo" alt="Clinic Logo">
            @endif
            <div class="export-clinic-name">{{ $clinicSettings->clinic_name }}</div>
            <div class="export-clinic-details">
                {{ $clinicSettings->clinic_address }}
                @if($clinicSettings->clinic_contact)
                    | Tel: {{ $clinicSettings->clinic_contact }}
                @endif
                @if($clinicSettings->clinic_email)
                    | Email: {{ $clinicSettings->clinic_email }}
                @endif
            </div>
            <div class="export-statement-period">
                Income Statement for {{ \Carbon\Carbon::parse($fromDate)->format('M d, Y') }} to {{ \Carbon\Carbon::parse($toDate)->format('M d, Y') }}
            </div>
            <div class="export-statement-period">
                Generated by {{ auth()->user()->name ?? 'System' }}
            </div>
        </div>

        @if($isLocked)
            <div class="alert alert-warning d-flex justify-content-between align-items-center">
                <div>
                    <strong>Period locked.</strong>
                    This statement was locked by {{ $periodLock->lockedBy->name ?? 'System' }}
                    on {{ optional($periodLock->locked_at)->format('M d, Y h:i A') }}.
                </div>
                <button type="button" wire:click="unlockPeriod" class="btn btn-sm btn-outline-dark">Unlock</button>
            </div>
        @endif

        @if($uncategorizedWarnings['revenue'] > 0 || $uncategorizedWarnings['cost'] > 0)
            <div class="alert alert-info">
                Some sales are categorized as <strong>Uncategorized</strong>.
                Revenue: {{ currency() }} {{ number_format($uncategorizedWarnings['revenue'], 2) }},
                Cost: {{ currency() }} {{ number_format($uncategorizedWarnings['cost'], 2) }}.
                Assign categories to those products for a cleaner statement.
            </div>
        @endif

        <div class="row no-print">
            <div class="col-xl-3 col-md-6 mb-3">
                <div class="summary-card p-3">
                    <div class="summary-label">Revenue</div>
                    <div class="summary-value text-primary">{{ currency() }} {{ number_format($statement['revenue'], 2) }}</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-3">
                <div class="summary-card p-3">
                    <div class="summary-label">Gross Profit</div>
                    <div class="summary-value {{ $statement['gross_profit'] >= 0 ? 'text-success' : 'text-danger' }}">{{ currency() }} {{ number_format($statement['gross_profit'], 2) }}</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-3">
                <div class="summary-card p-3">
                    <div class="summary-label">Operating Profit</div>
                    <div class="summary-value {{ $statement['operating_profit'] >= 0 ? 'text-success' : 'text-danger' }}">{{ currency() }} {{ number_format($statement['operating_profit'], 2) }}</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-3">
                <div class="summary-card p-3">
                    <div class="summary-label">Net Profit</div>
                    <div class="summary-value {{ $statement['net_profit'] >= 0 ? 'text-success' : 'text-danger' }}">{{ currency() }} {{ number_format($statement['net_profit'], 2) }}</div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-8 mb-3">
                <div class="income-panel">
                    <div class="p-3 border-bottom">
                        <h5 class="font-weight-bold mb-0">Statement</h5>
                        <div class="income-subtitle">Revenue and cost of sales come from paid sales. Expenses and tax are entered below.</div>
                    </div>
                    <div class="table-responsive">
                        <table class="table statement-table mb-0">
                            <thead>
                                <tr>
                                    <th>Description</th>
                                    <th class="amount-cell">Current</th>
                                    <th class="amount-cell">Previous</th>
                                    <th class="amount-cell">Change</th>
                                    <th class="line-actions"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="statement-heading">
                                    <td>Revenue</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($statement['revenue'], 2) }}</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($comparison['statement']['revenue'], 2) }}</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($statement['revenue'] - $comparison['statement']['revenue'], 2) }}</td>
                                    <td></td>
                                </tr>
                                @forelse($revenueLines as $line)
                                    <tr>
                                        <td>{{ $line->name }}</td>
                                        <td class="amount-cell">{{ currency() }} {{ number_format($line->amount, 2) }}</td>
                                        <td class="amount-cell text-muted">-</td>
                                        <td class="amount-cell text-muted">-</td>
                                        <td></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-muted">No sales revenue in this period.</td></tr>
                                @endforelse

                                <tr class="statement-heading">
                                    <td>Cost Of Sales</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($statement['cost_of_sales'], 2) }}</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($comparison['statement']['cost_of_sales'], 2) }}</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($statement['cost_of_sales'] - $comparison['statement']['cost_of_sales'], 2) }}</td>
                                    <td></td>
                                </tr>
                                @forelse($costOfSalesLines as $line)
                                    <tr>
                                        <td>{{ $line->name }}</td>
                                        <td class="amount-cell">{{ currency() }} {{ number_format($line->amount, 2) }}</td>
                                        <td class="amount-cell text-muted">-</td>
                                        <td class="amount-cell text-muted">-</td>
                                        <td></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-muted">No cost of sales in this period.</td></tr>
                                @endforelse

                                <tr class="statement-total">
                                    <td>Gross Profit</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($statement['gross_profit'], 2) }}</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($comparison['statement']['gross_profit'], 2) }}</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($statement['gross_profit'] - $comparison['statement']['gross_profit'], 2) }}</td>
                                    <td></td>
                                </tr>

                                <tr class="statement-heading">
                                    <td>Operating Expenses</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($statement['operating_expenses'], 2) }}</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($comparison['statement']['operating_expenses'], 2) }}</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($statement['operating_expenses'] - $comparison['statement']['operating_expenses'], 2) }}</td>
                                    <td></td>
                                </tr>
                                @foreach($statement['tracked_operating_lines'] as $tracked)
                                    <tr>
                                        <td>{{ $tracked['name'] }} <span class="text-muted small">(<a href="{{ route('admin.expenses', ['fromDate' => $fromDate, 'toDate' => $toDate]) }}">Expense Tracker</a>)</span></td>
                                        <td class="amount-cell">{{ currency() }} {{ number_format($tracked['amount'], 2) }}</td>
                                        <td class="amount-cell text-muted">-</td>
                                        <td class="amount-cell text-muted">-</td>
                                        <td class="line-actions"></td>
                                    </tr>
                                @endforeach
                                @forelse($statement['operating_lines'] as $line)
                                    <tr>
                                        <td>
                                            {{ $line->name }} <span class="text-muted small">({{ $line->entry_date->format('M d') }})</span>
                                            @if($line->notes && trim($line->notes) !== 'Default recurring value from income statement setup.')<div class="text-muted small">{{ $line->notes }}</div>@endif
                                        </td>
                                        <td class="amount-cell">{{ currency() }} {{ number_format($line->amount, 2) }}</td>
                                        <td class="amount-cell text-muted">-</td>
                                        <td class="amount-cell text-muted">-</td>
                                        <td class="line-actions">
                                            @if(!$isLocked)
                                            <button type="button" wire:click="deleteEntry({{ $line->id }})" class="btn btn-sm btn-outline-danger" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    @if($statement['tracked_operating_lines']->isEmpty())<tr><td colspan="5" class="text-muted">No operating expenses entered.</td></tr>@endif
                                @endforelse

                                <tr class="statement-total">
                                    <td>Operating Profit</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($statement['operating_profit'], 2) }}</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($comparison['statement']['operating_profit'], 2) }}</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($statement['operating_profit'] - $comparison['statement']['operating_profit'], 2) }}</td>
                                    <td></td>
                                </tr>

                                <tr class="statement-heading">
                                    <td>Non-operating Expenses</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($statement['non_operating_expenses'], 2) }}</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($comparison['statement']['non_operating_expenses'], 2) }}</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($statement['non_operating_expenses'] - $comparison['statement']['non_operating_expenses'], 2) }}</td>
                                    <td></td>
                                </tr>
                                @foreach($statement['tracked_non_operating_lines'] as $tracked)
                                    <tr>
                                        <td>{{ $tracked['name'] }} <span class="text-muted small">(<a href="{{ route('admin.expenses', ['fromDate' => $fromDate, 'toDate' => $toDate]) }}">Expense Tracker</a>)</span></td>
                                        <td class="amount-cell">{{ currency() }} {{ number_format($tracked['amount'], 2) }}</td>
                                        <td class="amount-cell text-muted">-</td>
                                        <td class="amount-cell text-muted">-</td>
                                        <td class="line-actions"></td>
                                    </tr>
                                @endforeach
                                @forelse($statement['non_operating_lines'] as $line)
                                    <tr>
                                        <td>
                                            {{ $line->name }} <span class="text-muted small">({{ $line->entry_date->format('M d') }})</span>
                                            @if($line->notes && trim($line->notes) !== 'Default recurring value from income statement setup.')<div class="text-muted small">{{ $line->notes }}</div>@endif
                                        </td>
                                        <td class="amount-cell">{{ currency() }} {{ number_format($line->amount, 2) }}</td>
                                        <td class="amount-cell text-muted">-</td>
                                        <td class="amount-cell text-muted">-</td>
                                        <td class="line-actions">
                                            @if(!$isLocked)
                                            <button type="button" wire:click="deleteEntry({{ $line->id }})" class="btn btn-sm btn-outline-danger" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    @if($statement['tracked_non_operating_lines']->isEmpty())<tr><td colspan="5" class="text-muted">No non-operating expenses entered.</td></tr>@endif
                                @endforelse

                                <tr class="statement-total">
                                    <td>Profit For The Period</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($statement['profit_for_period'], 2) }}</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($comparison['statement']['profit_for_period'], 2) }}</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($statement['profit_for_period'] - $comparison['statement']['profit_for_period'], 2) }}</td>
                                    <td></td>
                                </tr>
                                <tr>
                                    <td>
                                        Tax
                                        @if($statement['tax_entry'])
                                            <span class="text-muted small">({{ number_format($statement['tax_rate'], 2) }}%)</span>
                                        @else
                                            <span class="text-muted small">(0.00%)</span>
                                        @endif
                                    </td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($statement['tax_amount'], 2) }}</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($comparison['statement']['tax_amount'], 2) }}</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($statement['tax_amount'] - $comparison['statement']['tax_amount'], 2) }}</td>
                                    <td class="line-actions">
                                        @if($statement['tax_entry'] && !$isLocked)
                                            <button type="button" wire:click="deleteEntry({{ $statement['tax_entry']->id }})" class="btn btn-sm btn-outline-danger" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                                <tr class="statement-final">
                                    <td>Net Profit</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($statement['net_profit'], 2) }}</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($comparison['statement']['net_profit'], 2) }}</td>
                                    <td class="amount-cell">{{ currency() }} {{ number_format($statement['net_profit'] - $comparison['statement']['net_profit'], 2) }}</td>
                                    <td></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-xl-4 mb-3">
                <div class="income-panel p-3 mb-3">
                    <h5 class="font-weight-bold mb-3">Add Statement Line</h5>
                    @if($isLocked)
                        <div class="alert alert-warning">This period is locked. Manual entries are disabled.</div>
                    @endif
                    <div class="form-group">
                        <label class="muted-label">Type</label>
                        <select wire:model.live="section" class="form-control" {{ $isLocked ? 'disabled' : '' }}>
                            @foreach($sections as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group mb-2">
                        <label class="muted-label">Entry</label>
                        <select wire:model.live="selectedPreset" class="form-control @error('name') is-invalid @enderror" {{ $isLocked ? 'disabled' : '' }}>
                            <option value="">Select entry</option>
                            @foreach($entryPresets[$section] ?? [] as $preset)
                                <option value="{{ $preset }}">{{ $preset }}</option>
                            @endforeach
                            <option value="custom">Custom entry</option>
                        </select>
                        @error('name')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-group">
                        <label class="muted-label">Type Custom Entry</label>
                        <input type="text" wire:model="customName" class="form-control @error('name') is-invalid @enderror" placeholder="Optional, overrides selected entry" {{ $isLocked ? 'disabled' : '' }}>
                        <small class="form-text text-muted">Use this when the line is not in the dropdown.</small>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="muted-label">Amount</label>
                                <input type="number" step="0.01" wire:model="amount" class="form-control @error('amount') is-invalid @enderror" {{ $section === \App\Models\IncomeStatementEntry::TAX || $isLocked ? 'disabled' : '' }}>
                                @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="muted-label">Tax %</label>
                                <input type="number" step="0.01" wire:model="percentage" class="form-control @error('percentage') is-invalid @enderror" {{ $section === \App\Models\IncomeStatementEntry::TAX && !$isLocked ? '' : 'disabled' }}>
                                @error('percentage')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="muted-label">Date</label>
                        <input type="date" wire:model="entryDate" class="form-control @error('entryDate') is-invalid @enderror" {{ $isLocked ? 'disabled' : '' }}>
                        @error('entryDate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-group">
                        <label class="muted-label">Notes</label>
                        <textarea wire:model="notes" class="form-control" rows="2" {{ $isLocked ? 'disabled' : '' }}></textarea>
                    </div>
                    <button type="button" wire:click="saveEntry" class="btn btn-primary btn-block" {{ $isLocked ? 'disabled' : '' }}>
                        Save Line
                    </button>
                </div>

                <div class="income-panel p-3 mb-3">
                    <h5 class="font-weight-bold mb-3">Recurring Templates</h5>
                    <button type="button" wire:click="applyTemplates" class="btn btn-outline-primary btn-block mb-3" {{ $isLocked ? 'disabled' : '' }}>
                        Apply Templates To Period
                    </button>
                    <div class="form-group">
                        <label class="muted-label">Type</label>
                        <select wire:model.live="templateSection" class="form-control">
                            @foreach($sections as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="muted-label">Template Name</label>
                        <input type="text" wire:model="templateName" class="form-control @error('templateName') is-invalid @enderror" placeholder="Rent, Nurse Salary, Loan Interest">
                        @error('templateName')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="muted-label">Amount</label>
                                <input type="number" step="0.01" wire:model="templateAmount" class="form-control @error('templateAmount') is-invalid @enderror" {{ $templateSection === \App\Models\IncomeStatementEntry::TAX ? 'disabled' : '' }}>
                                @error('templateAmount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="muted-label">Tax %</label>
                                <input type="number" step="0.01" wire:model="templatePercentage" class="form-control @error('templatePercentage') is-invalid @enderror" {{ $templateSection === \App\Models\IncomeStatementEntry::TAX ? '' : 'disabled' }}>
                                @error('templatePercentage')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="muted-label">Notes</label>
                        <textarea wire:model="templateNotes" class="form-control" rows="2"></textarea>
                    </div>
                    <button type="button" wire:click="saveTemplate" class="btn btn-secondary btn-block">Save Template</button>

                    <hr>
                    @forelse($templates as $template)
                        <div class="d-flex justify-content-between border-bottom py-2">
                            <div>
                                <div class="font-weight-bold">{{ $template->name }}</div>
                                <div class="text-muted small">{{ $sections[$template->section] ?? $template->section }}</div>
                            </div>
                            <div class="text-right">
                                @if($template->section === \App\Models\IncomeStatementEntry::TAX)
                                    <div>{{ number_format($template->percentage, 2) }}%</div>
                                @else
                                    <div>{{ currency() }} {{ number_format($template->amount, 2) }}</div>
                                @endif
                                <button type="button" wire:click="deleteTemplate({{ $template->id }})" class="btn btn-sm btn-link text-danger p-0">Remove</button>
                            </div>
                        </div>
                    @empty
                        <div class="text-muted">No recurring templates saved.</div>
                    @endforelse
                </div>

                <div class="income-panel p-3 mb-3">
                    <h5 class="font-weight-bold mb-2">
                        <i class="fas fa-receipt text-danger mr-1"></i>Expense Tracker
                    </h5>
                    <p class="text-muted small mb-0">
                        Expenses recorded in the <a href="{{ route('admin.expenses', ['fromDate' => $fromDate, 'toDate' => $toDate]) }}">Expense Tracker</a>
                        are included automatically, by category. Add lines here only for costs not recorded there, such as salaries or tax.
                    </p>
                </div>

                <div class="income-panel p-3 mb-3">
                    <h5 class="font-weight-bold mb-3">Period Lock</h5>
                    @if($isLocked)
                        <p class="text-muted small">Unlock this period before changing entries or clinic expenses dated in it.</p>
                        <button type="button" wire:click="unlockPeriod" class="btn btn-outline-dark btn-block">Unlock Period</button>
                    @else
                        <div class="form-group">
                            <label class="muted-label">Lock Notes</label>
                            <textarea wire:model="lockNotes" class="form-control" rows="2"></textarea>
                        </div>
                        <button type="button" wire:click="lockPeriod" class="btn btn-warning btn-block">Lock Period</button>
                    @endif
                </div>

                <div class="income-panel p-3">
                    <h5 class="font-weight-bold mb-3">Saved Lines In Period</h5>
                    @forelse($entries as $entry)
                        <div class="d-flex justify-content-between border-bottom py-2">
                            @if($editingEntryId === $entry->id)
                                <div class="w-100">
                                    <div class="form-group mb-2">
                                        <label class="muted-label">Name</label>
                                        <input type="text" wire:model="editingName" class="form-control form-control-sm @error('editingName') is-invalid @enderror">
                                        @error('editingName')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="form-group mb-2">
                                                <label class="muted-label">Amount</label>
                                                <input type="number" step="0.01" wire:model="editingAmount" class="form-control form-control-sm @error('editingAmount') is-invalid @enderror" {{ $entry->section === \App\Models\IncomeStatementEntry::TAX ? 'disabled' : '' }}>
                                                @error('editingAmount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group mb-2">
                                                <label class="muted-label">Tax %</label>
                                                <input type="number" step="0.01" wire:model="editingPercentage" class="form-control form-control-sm @error('editingPercentage') is-invalid @enderror" {{ $entry->section === \App\Models\IncomeStatementEntry::TAX ? '' : 'disabled' }}>
                                                @error('editingPercentage')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group mb-2">
                                                <label class="muted-label">Date</label>
                                                <input type="date" wire:model="editingDate" class="form-control form-control-sm @error('editingDate') is-invalid @enderror">
                                                @error('editingDate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-group mb-2">
                                        <label class="muted-label">Notes</label>
                                        <textarea wire:model="editingNotes" class="form-control form-control-sm" rows="2"></textarea>
                                    </div>
                                    <div class="text-right">
                                        <button type="button" wire:click="updateEntry" class="btn btn-sm btn-primary">Save</button>
                                        <button type="button" wire:click="cancelEdit" class="btn btn-sm btn-outline-secondary">Cancel</button>
                                    </div>
                                </div>
                            @else
                                <div>
                                    <div class="font-weight-bold">{{ $entry->name }}</div>
                                    <div class="text-muted small">{{ $sections[$entry->section] ?? $entry->section }} - {{ $entry->entry_date->format('M d, Y') }}</div>
                                    @if($entry->notes && trim($entry->notes) !== 'Default recurring value from income statement setup.')<div class="text-muted small">{{ $entry->notes }}</div>@endif
                                </div>
                                <div class="text-right">
                                    @if($entry->section === \App\Models\IncomeStatementEntry::TAX)
                                        <div>{{ number_format($entry->percentage, 2) }}%</div>
                                    @else
                                        <div>{{ currency() }} {{ number_format($entry->amount, 2) }}</div>
                                    @endif
                                    @if(!$isLocked)
                                        <button type="button" wire:click="editEntry({{ $entry->id }})" class="btn btn-sm btn-link text-primary p-0 mr-2">Edit</button>
                                        <button type="button" wire:click="deleteEntry({{ $entry->id }})" class="btn btn-sm btn-link text-danger p-0">Delete</button>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="text-muted">No manual lines saved for this period.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

</div>
