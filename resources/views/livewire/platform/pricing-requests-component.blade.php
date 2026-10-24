<x-platform.page active="pricing" title="Pricing Page" subtitle="Requests from the public pricing page, which clinic plans it shows, and the attendance prices. Clinic prices come from Subscription Plans and the add-on catalogue.">
    <x-slot:actions><a class="ui-button ui-button-secondary" href="{{ route('pricing') }}" target="_blank" rel="noopener">Open the pricing page ↗</a></x-slot:actions>
    <style>.pr-sel{font-size:12px;color:#aebbd0;line-height:1.6}.pr-sel b{color:#e8eef8}.pr-actions{display:flex;flex-wrap:wrap;gap:6px}.pr-detail td{background:#0b1526}.pr-notes{width:100%;min-height:70px;box-sizing:border-box;background:#050d1c;border:1px solid #273750;color:#edf5ff;border-radius:8px;padding:9px 11px;font:inherit}</style>

    <div class="ui-tabs" role="tablist" data-tour="pr-tabs">
        <button type="button" role="tab" aria-selected="{{ $tab === 'requests' ? 'true' : 'false' }}" class="{{ $tab === 'requests' ? 'active' : '' }}" wire:click="setTab('requests')">Requests @if($counts['new'] ?? 0)<span class="ui-badge ui-badge--danger">{{ $counts['new'] }} new</span>@endif</button>
        <button type="button" role="tab" aria-selected="{{ $tab === 'page' ? 'true' : 'false' }}" class="{{ $tab === 'page' ? 'active' : '' }}" wire:click="setTab('page')">Plans on the page</button>
        <button type="button" role="tab" aria-selected="{{ $tab === 'attendance' ? 'true' : 'false' }}" class="{{ $tab === 'attendance' ? 'active' : '' }}" wire:click="setTab('attendance')">Attendance prices</button>
    </div>

    @if($tab === 'requests')
        <section class="ui-card">
            <div class="ui-card-head">
                <div><h2>Requests</h2><p>Each one is emailed to the requests inbox (Platform → Support) as it arrives, with a reminder at 8:00 while it's still new after a day. "Make a quote" turns one into a quotation or pro-forma invoice to send them. Set the clinic up under Clinic Onboarding, then mark it "Became a clinic".</p></div>
                <label class="ui-field" style="width:240px" data-tour="pr-status"><span>Show</span>
                    <select class="ui-input" wire:model.live="status">
                        <option value="open">Open (new and contacted)</option>
                        @foreach(\App\Models\PricingRequest::STATUSES as $key => $label)<option value="{{ $key }}">{{ $label }} ({{ $counts[$key] ?? 0 }})</option>@endforeach
                        <option value="all">All</option>
                    </select>
                </label>
            </div>
            <div class="ui-table-wrap ui-table-wrap--framed" data-tour="pr-list">
                <table class="ui-table">
                    <thead><tr><th>Clinic</th><th>Contact</th><th>Plan</th><th>Status</th><th>Received</th><th></th></tr></thead>
                    <tbody>
                    @forelse($requests as $r)
                        <tr wire:key="pr-{{ $r->id }}">
                            <td><b>{{ $r->clinic_name }}</b>@if($r->town)<small>{{ $r->town }}</small>@endif</td>
                            <td>{{ $r->contact_name }}<small><a href="tel:{{ $r->phone }}" style="color:#38bdf8">{{ $r->phone }}</a>@if($r->email) · <a href="mailto:{{ $r->email }}" style="color:#38bdf8">{{ $r->email }}</a>@endif</small></td>
                            <td>{{ $r->plan_name }}<small>@if(!empty($r->selection['special']))Special pricing: quote to agree @else{{ $r->currency }} {{ number_format((float) $r->quoted_total, 2) }} {{ $r->billing_interval === 'yearly' ? 'a year' : 'a month' }}@endif</small></td>
                            <td><span class="ui-badge {{ ['new' => 'ui-badge--warning', 'contacted' => 'ui-badge--neutral', 'won' => '', 'lost' => 'ui-badge--danger'][$r->status] ?? 'ui-badge--neutral' }}">{{ \App\Models\PricingRequest::STATUSES[$r->status] ?? $r->status }}</span>@if($r->handler)<small>by {{ $r->handler->name }}</small>@endif</td>
                            <td>{{ $r->created_at->format('j M Y, H:i') }}<small>{{ $r->created_at->diffForHumans() }}</small></td>
                            <td><button type="button" class="ui-button ui-button-secondary ui-button-sm" wire:click="open({{ $r->id }})" aria-expanded="{{ $openId === $r->id ? 'true' : 'false' }}">{{ $openId === $r->id ? 'Close' : 'Details' }}</button></td>
                        </tr>
                        @if($openId === $r->id)
                            @php($sel = $r->selection ?? [])
                            <tr class="pr-detail" wire:key="prd-{{ $r->id }}"><td colspan="6">
                                <div class="ui-row">
                                    <div class="pr-sel">
                                        <div><b>Plan:</b> {{ $r->plan_name }} — @if(!empty($sel['special']))special pricing, no list price{{ $r->billing_interval === 'yearly' ? ', wants yearly billing' : '' }}@else{{ $r->currency }} {{ number_format((float) $r->monthly_total, 2) }} a month{{ $r->billing_interval === 'yearly' ? ', billed yearly' : '' }}@endif</div>
                                        @if(!empty($sel['lines']))<div><b>Add-ons:</b> {{ collect($sel['lines'])->map(fn ($l) => $l[0] . ' (' . $r->currency . ' ' . number_format((float) $l[1], 2) . ')')->implode(', ') }}</div>@endif
                                        @if(!empty($sel['features']))<div><b>Ticked:</b> {{ collect($sel['features'])->map(fn ($f) => \App\Services\PricingCatalogue::label($f))->implode(', ') }}</div>@endif
                                        @if(($sel['product'] ?? null) === 'attendance')
                                        <div><b>Size:</b> {{ number_format($sel['staff'] ?? 0) }} staff on attendance{{ !empty($sel['size']) ? ' (priced up to ' . $sel['size'] . ')' : '' }}</div>
                                        @else
                                        <div><b>Size:</b> {{ $sel['users'] ?? '—' }} users · {{ $sel['visits'] ?? '—' }} patients a day · {{ isset($sel['storage_mb']) ? \App\Services\PricingCatalogue::storageLabel((int) $sel['storage_mb']) : ($sel['storage_gb'] ?? 0).' GB' }} scans · {{ $sel['branches'] ?? 1 }} {{ ($sel['branches'] ?? 1) == 1 ? 'branch' : 'branches' }}</div>
                                        @endif
                                        @if($r->message)<div style="margin-top:6px"><b>Message:</b> {{ $r->message }}</div>@endif
                                    </div>
                                    <label class="ui-field"><span>Notes (only the platform sees these)</span>
                                        <textarea class="ui-input" wire:model="notes" maxlength="2000" placeholder="e.g. Called Tue, wants a demo on Friday"></textarea>
                                        @error('notes')<small class="ui-error">{{ $message }}</small>@enderror
                                    </label>
                                </div>
                                <div class="pr-actions" style="margin-top:10px">
                                    <button type="button" class="ui-button ui-button-secondary ui-button-sm" wire:click="saveNotes({{ $r->id }})">Save notes</button>
                                    @foreach(\App\Models\PricingRequest::STATUSES as $key => $label)
                                        @if($key !== $r->status)<button type="button" class="ui-button ui-button-sm {{ $key === 'lost' ? 'ui-button-danger' : ($key === 'won' ? 'ui-button-primary' : 'ui-button-secondary') }}" wire:click="mark({{ $r->id }}, '{{ $key }}')">Mark {{ strtolower($label) }}</button>@endif
                                    @endforeach
                                    <a class="ui-button ui-button-primary ui-button-sm" href="{{ route('platform.quotes', ['request' => $r->id]) }}">Make a quote</a>
                                    <a class="ui-button ui-button-secondary ui-button-sm" href="{{ route('platform.dashboard', ['tab' => 'onboarding']) }}">Clinic Onboarding</a>
                                </div>
                            </td></tr>
                        @endif
                    @empty
                        <tr><td colspan="6" class="ui-empty">No requests here yet. They arrive when someone presses "Request this plan" on the pricing page.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div style="margin-top:12px">{{ $requests->links() }}</div>
        </section>
    @elseif($tab === 'attendance')
        @php($attPackages = \App\Support\Pricing\AttendanceSuitePrices::PACKAGES)
        <form class="ui-card" wire:submit="saveAttendance">
            <div class="ui-card-head"><div><h2>Attendance prices</h2><p>The Staff attendance view of the pricing page. Basic's price follows the number of staff; each package adds a fixed amount on top at any size; modules bought on their own cost the same at any size. Yearly is twelve months. These figures are for the page and its requests; invoices still come from the clinic's plan and add-ons.</p></div></div>

            <div class="ui-row" style="align-items:flex-start;gap:20px;flex-wrap:wrap">
                <div style="flex:1;min-width:280px">
                    <h3 style="margin:0 0 6px;font-size:14px">Basic, by number of staff</h3>
                    <div class="ui-table-wrap ui-table-wrap--framed">
                        <table class="ui-table">
                            <thead><tr><th>Staff, up to</th><th>Basic a month (GHS)</th><th></th></tr></thead>
                            <tbody>
                            @foreach($att['sizes'] as $i => $row)
                                <tr wire:key="as-{{ $i }}">
                                    <td><input class="ui-input" type="number" min="1" wire:model="att.sizes.{{ $i }}.up_to" aria-label="Staff up to, row {{ $i + 1 }}">@error("att.sizes.$i.up_to")<small class="ui-error">{{ $message }}</small>@enderror</td>
                                    <td><input class="ui-input" type="number" min="0" step="0.01" wire:model="att.sizes.{{ $i }}.price" aria-label="Basic price, row {{ $i + 1 }}">@error("att.sizes.$i.price")<small class="ui-error">{{ $message }}</small>@enderror</td>
                                    <td><button type="button" class="ui-button ui-button-secondary ui-button-sm" wire:click="removeSize({{ $i }})" @disabled(count($att['sizes']) <= 1) aria-label="Remove row {{ $i + 1 }}">Remove</button></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @error('att.sizes')<p class="ui-error" style="margin:6px 0 0">{{ $message }}</p>@enderror
                    <div style="display:flex;gap:10px;align-items:flex-end;margin-top:10px;flex-wrap:wrap">
                        <button type="button" class="ui-button ui-button-secondary ui-button-sm" wire:click="addSize">Add a size</button>
                        <label class="ui-field" style="width:220px"><span>Special pricing from (staff)</span><input class="ui-input" type="number" min="2" wire:model="att.special_from">@error('att.special_from')<small class="ui-error">{{ $message }}</small>@enderror</label>
                    </div>
                    <p class="ui-hint" style="margin:6px 0 0">The largest size must be one below special pricing (e.g. up to 599 with special pricing from 600).</p>
                </div>

                <div style="flex:1.4;min-width:340px">
                    <h3 style="margin:0 0 6px;font-size:14px">Packages</h3>
                    <div class="ui-table-wrap ui-table-wrap--framed">
                        <table class="ui-table">
                            <thead><tr><th>Package</th><th style="width:130px">On top of Basic (GHS)</th><th>One-line pitch</th><th>Most popular</th></tr></thead>
                            <tbody>
                            @foreach($attPackages as $key => $package)
                                <tr wire:key="ap-{{ $key }}">
                                    <td><b>{{ $package['name'] }}</b></td>
                                    <td>@if($key === 'basic')<span class="ui-hint">—</span>@else<input class="ui-input" type="number" min="0" step="0.01" wire:model="att.packages.{{ $key }}.uplift" aria-label="{{ $package['name'] }} on top of Basic">@error("att.packages.$key.uplift")<small class="ui-error">{{ $message }}</small>@enderror @endif</td>
                                    <td><input class="ui-input" type="text" maxlength="120" wire:model="att.taglines.{{ $key }}" aria-label="Pitch for {{ $package['name'] }}">@error("att.taglines.$key")<small class="ui-error">{{ $message }}</small>@enderror</td>
                                    <td><input type="radio" name="att-featured" value="{{ $key }}" wire:model="att.featured" aria-label="Mark {{ $package['name'] }} most popular" style="width:auto"></td>
                                </tr>
                            @endforeach
                                <tr><td colspan="3" class="ui-hint">No package marked</td><td><input type="radio" name="att-featured" value="" wire:model="att.featured" aria-label="No package marked most popular" style="width:auto"></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <h3 style="margin:14px 0 6px;font-size:14px">Modules on their own</h3>
                    <div class="ui-table-wrap ui-table-wrap--framed">
                        <table class="ui-table">
                            <thead><tr><th>Module</th><th>In package</th><th style="width:130px">A month (GHS)</th></tr></thead>
                            <tbody>
                            @foreach(\App\Support\Pricing\AttendanceSuitePrices::DEFAULT_MODULES as $key => $default)
                                <tr wire:key="am-{{ $key }}">
                                    <td>{{ \App\Support\Pricing\AttendanceSuitePrices::label($key) }}</td>
                                    <td class="ui-hint">{{ collect($attPackages)->first(fn ($p) => in_array($key, $p['adds'], true))['name'] ?? '' }} and up</td>
                                    <td><input class="ui-input" type="number" min="0" step="0.01" wire:model="att.modules.{{ $key }}" aria-label="{{ \App\Support\Pricing\AttendanceSuitePrices::label($key) }} price">@error("att.modules.$key")<small class="ui-error">{{ $message }}</small>@enderror</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2" style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
                <button type="button" class="ui-button ui-button-secondary" wire:click="resetAttendance" wire:confirm="Put every attendance price back to the standard price list?">Back to the standard list</button>
                <button type="submit" class="ui-button ui-button-primary" wire:loading.attr="disabled" wire:target="saveAttendance">Save</button>
            </div>
        </form>
    @else
        <form class="ui-card" wire:submit="savePage">
            <div class="ui-card-head"><div><h2>Plans on the page</h2><p>Tick the plans to show. They're listed cheapest first, so the order only breaks ties. "Patients a day" is the busiest clinic each plan is meant for; the calculator won't suggest a plan to a clinic busier than that (leave it empty on the top plan). A new version of a plan replaces the old one on the page automatically.</p></div></div>
            <div class="ui-table-wrap ui-table-wrap--framed">
                <table class="ui-table">
                    <thead><tr><th>Show</th><th>Plan</th><th>Price</th><th style="width:90px">Order</th><th style="width:120px">Patients a day, up to</th><th>One-line pitch</th><th>Most popular</th></tr></thead>
                    <tbody>
                    @forelse($families as $family => $plan)
                        <tr wire:key="pf-{{ $family }}">
                            <td><input type="checkbox" wire:model="page.{{ $family }}.show" aria-label="Show {{ $plan->name }}" style="width:auto"></td>
                            <td><b>{{ $plan->name }}</b><small>{{ \App\Support\PlanProduct::label($plan->product ?: \App\Support\PlanProduct::productOf($plan->features)) }} · {{ $family }}</small></td>
                            <td>{{ $plan->currency }} {{ number_format((float) $plan->base_price, 2) }}<small>{{ $plan->included_users ?? '∞' }} users · {{ $plan->storage_limit_mb === null ? '∞' : ($plan->storage_limit_mb ? number_format($plan->storage_limit_mb) . ' MB' : 'no') }} scans</small></td>
                            <td><input class="ui-input" type="number" min="1" max="99" wire:model="page.{{ $family }}.order" aria-label="Order of {{ $plan->name }}">@error("page.$family.order")<small class="ui-error">{{ $message }}</small>@enderror</td>
                            <td><input class="ui-input" type="number" min="1" wire:model="page.{{ $family }}.max_visits" placeholder="No limit" aria-label="Patients a day for {{ $plan->name }}">@error("page.$family.max_visits")<small class="ui-error">{{ $message }}</small>@enderror</td>
                            <td><input class="ui-input" type="text" maxlength="120" wire:model="page.{{ $family }}.tagline" aria-label="Pitch for {{ $plan->name }}">@error("page.$family.tagline")<small class="ui-error">{{ $message }}</small>@enderror</td>
                            <td><input type="checkbox" wire:model="page.{{ $family }}.featured" aria-label="Mark {{ $plan->name }} most popular" style="width:auto"></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="ui-empty">No active plans. Create them under Subscription Plans, or run <code>php artisan pricing:clinical-suite</code>.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <p class="ui-hint" style="margin:10px 0">Prices for buying an extra on its own come from the add-on catalogue (Subscription Plans → Add-ons). An extra that isn't offered there is shown as "comes with" the first plan that has it.</p>
            <div class="flex flex-wrap items-center justify-end gap-2"><button type="submit" class="ui-button ui-button-primary" wire:loading.attr="disabled" wire:target="savePage">Save</button></div>
        </form>
    @endif
</x-platform.page>
