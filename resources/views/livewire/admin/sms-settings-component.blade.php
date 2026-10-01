<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-7">

            <div class="mb-3">
                <p class="text-muted small text-uppercase font-weight-bold mb-0">Communications</p>
                <h2 class="text-primary font-weight-bold mb-1">SMS Credits &amp; Sending</h2>
                <p class="text-muted small mb-0">Credits, your sender name and per-branch limits. Choose which messages go out under <a href="{{ route('admin.messages') }}">Messages</a>.</p>
            </div>

            {{-- Status banner --}}
            <div class="alert border-0 shadow-sm mb-4 d-flex align-items-center justify-content-between flex-wrap
                        {{ $smsEnabled ? 'alert-success' : 'alert-warning' }}" style="gap:.75rem">
                <div>
                    @if($smsEnabled)
                        <i class="fas fa-check-circle mr-2"></i>
                        <strong>SMS sending is on</strong>
                        <span class="d-block small mt-1">
                            {{ $messagesOn }} of {{ $messagesTotal }} automatic messages switched on.
                            <a href="{{ route('admin.messages') }}" class="font-weight-bold">{{ $messagesOn === 0 ? 'Choose messages →' : 'Manage messages →' }}</a>
                        </span>
                    @else
                        <i class="fas fa-pause-circle mr-2"></i>
                        <strong>SMS sending is paused</strong>
                        <span class="d-block small mt-1">No SMS is sent until you resume, whichever messages are switched on.</span>
                    @endif
                </div>
                <button type="button" wire:click="toggleSms"
                        wire:loading.attr="disabled" wire:target="toggleSms"
                        class="btn font-weight-bold shadow-sm ml-3 flex-shrink-0
                               {{ $smsEnabled ? 'btn-warning' : 'btn-success' }}">
                    <span wire:loading.remove wire:target="toggleSms">
                        <i class="fas {{ $smsEnabled ? 'fa-pause' : 'fa-play' }} mr-1"></i>
                        {{ $smsEnabled ? 'Pause SMS' : 'Resume SMS' }}
                    </span>
                    <span wire:loading wire:target="toggleSms">Updating…</span>
                </button>
            </div>

            @if(auth()->user()?->hasRole('Super Admin'))
                <livewire:admin.branch-sms-limits-component />
            @endif

            @if($platformManaged)
            {{-- Platform-managed gateway (hosted clinics) --}}
            <div class="card border-0 shadow-sm rounded-lg mb-4">
                <div class="card-header bg-primary text-white py-3 border-0">
                    <h5 class="mb-0 font-weight-bold"><i class="fas fa-sms mr-2"></i> SMS Credits &amp; Sender ID</h5>
                </div>
                <div class="card-body">
                    @if($smsCredits)
                        <div class="d-flex align-items-baseline justify-content-between">
                            <span class="small font-weight-bold text-muted text-uppercase">SMS credits left</span>
                            <span class="h3 mb-0 font-weight-bold {{ $smsCredits['balance'] <= 20 ? 'text-danger' : 'text-success' }}">{{ number_format($smsCredits['balance']) }}</span>
                        </div>
                        <small class="form-text text-muted mb-3">
                            Credits are bought separately from your plan and never expire. Each SMS uses 1 credit per 160 characters
                            (70 when it contains special characters). WhatsApp links and email are free.
                        </small>

                        @if($smsCredits['pending']->isNotEmpty())
                            <div class="alert alert-info small py-2">
                                @foreach($smsCredits['pending'] as $invoice)
                                    <div class="d-flex justify-content-between align-items-center {{ !$loop->last ? 'mb-1' : '' }}">
                                        <span>
                                            <strong>{{ number_format($invoice->sms_credits) }} credits</strong> awaiting payment —
                                            invoice {{ $invoice->number }}, {{ $invoice->currency }} {{ number_format($invoice->balance(), 2) }}
                                        </span>
                                        @if($invoice->status === 'unpaid')
                                            <button type="button" class="btn btn-link btn-sm p-0 text-danger" wire:click="cancelBundleRequest({{ $invoice->id }})"
                                                    wire:confirm="Cancel this SMS credit request?">Cancel</button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if($smsCredits['tiers'])
                            {{-- Bundles are quick picks and rate tiers; the clinic can also type any amount in the range. --}}
                            <div x-data="{
                                    tiers: @js($smsCredits['tiers']),
                                    min: {{ \App\Models\SmsBundle::MIN_TOP_UP }},
                                    max: {{ \App\Models\SmsBundle::MAX_TOP_UP }},
                                    taxRate: {{ $smsCredits['taxRate'] }},
                                    amount: '',
                                    rate(t) { return t.credits / t.price },
                                    get value() { return parseFloat(this.amount) || 0 },
                                    get valid() { return this.value >= this.min && this.value <= this.max },
                                    get tier() {
                                        let best = this.tiers[0];
                                        for (const t of this.tiers) if (t.price <= this.value + 0.00001 && this.rate(t) > this.rate(best)) best = t;
                                        return best;
                                    },
                                    get credits() { return Math.floor(this.value * this.rate(this.tier) + 0.000001) },
                                    get next() {
                                        return this.tiers.find(t => t.price > this.value && t.price <= this.max && this.rate(t) > this.rate(this.tier)) || null;
                                    },
                                    worst() { return Math.max(...this.tiers.map(t => t.price / t.credits)) },
                                    money(n) { return n.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) },
                                    count(n) { return n.toLocaleString('en-US') },
                                    submit() {
                                        if (!this.valid) return;
                                        const value = this.value;
                                        window.appConfirm('Request ' + this.count(this.credits) + ' SMS credits for GHS ' + this.money(value) + '? An invoice will be issued; credits are added once it is paid.', this.$refs.requestButton)
                                            .then(ok => ok && $wire.requestTopUp(value));
                                    },
                                 }"
                                 x-on:sms-top-up-requested.window="amount = ''">
                                {{-- The preview above follows the same rule as SmsBundle::quoteFor(); the server recalculates on submit. --}}
                                <label class="small font-weight-bold text-muted">BUY SMS CREDITS</label>
                                <div class="row">
                                    <template x-for="t in tiers" :key="t.id">
                                        <div class="col-sm-6 col-lg-4 mb-2">
                                            <button type="button" class="btn btn-block text-left border rounded p-2 h-100" style="white-space: normal"
                                                    :class="valid && tier.id === t.id ? 'border-primary bg-light' : 'bg-white'"
                                                    x-on:click="amount = t.price">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <span class="small text-muted text-uppercase font-weight-bold" x-text="t.name"></span>
                                                    <span class="badge badge-success" x-show="t.price / t.credits < worst() - 0.00001"
                                                          x-text="'save ' + Math.round((1 - (t.price / t.credits) / worst()) * 100) + '%'"></span>
                                                </div>
                                                <div class="h5 font-weight-bold mb-0" x-text="'GHS ' + count(t.price)"></div>
                                                <div class="small" x-text="count(t.credits) + ' SMS'"></div>
                                                <div class="small text-muted" x-text="(t.price / t.credits).toFixed(3) + ' per SMS'"></div>
                                            </button>
                                        </div>
                                    </template>
                                </div>

                                <label class="small font-weight-bold text-muted mt-2 mb-1" for="sms-top-up-amount">OR ENTER AN AMOUNT</label>
                                <div class="input-group">
                                    <div class="input-group-prepend"><span class="input-group-text">GHS</span></div>
                                    <input type="number" id="sms-top-up-amount" class="form-control @error('topUpAmount') is-invalid @enderror"
                                           x-model="amount" :min="min" :max="max" step="0.01" placeholder="e.g. 250"
                                           x-on:keydown.enter.prevent="submit()">
                                    <div class="input-group-append">
                                        <button type="button" class="btn btn-primary font-weight-bold" :disabled="!valid" x-ref="requestButton"
                                                data-confirm-button="Request invoice" data-confirm-danger="false"
                                                x-on:click="submit()" wire:loading.attr="disabled" wire:target="requestTopUp">
                                            Request invoice
                                        </button>
                                    </div>
                                </div>
                                @error('topUpAmount')<div class="small text-danger mt-1">{{ $message }}</div>@enderror

                                <div class="small text-danger mt-2" x-show="amount !== '' && !valid" style="display: none"
                                     x-text="'Enter between GHS ' + count(min) + ' and GHS ' + count(max) + '.'"></div>
                                <div class="border rounded p-2 mt-2 bg-light" x-show="valid" style="display: none">
                                    <div>
                                        You get <strong class="text-success" x-text="count(credits) + ' SMS'"></strong>
                                        at GHS <span x-text="(tier.price / tier.credits).toFixed(3)"></span> each
                                        <span class="text-muted" x-text="'(' + tier.name + ' rate)'"></span>
                                    </div>
                                    <div class="small text-muted" x-show="taxRate > 0"
                                         x-text="'Invoice total with ' + taxRate + '% tax: GHS ' + money(Math.round(value * (1 + taxRate / 100) * 100) / 100)"></div>
                                    <div class="small mt-1" x-show="next">
                                        <i class="fas fa-lightbulb text-warning mr-1"></i>
                                        <a href="#" class="font-weight-bold" x-on:click.prevent="amount = next.price"
                                           x-text="next ? 'Add GHS ' + money(next.price - value) + ' more' : ''"></a>
                                        <span x-text="next ? 'and get ' + count(next.credits) + ' SMS at ' + (next.price / next.credits).toFixed(3) + ' each (+' + count(next.credits - credits) + ' SMS).' : ''"></span>
                                    </div>
                                </div>
                                <small class="form-text text-muted mb-2">
                                    Bigger amounts get a lower price per SMS. Pay the invoice as you pay your subscription; credits appear here once payment is confirmed.
                                </small>
                            </div>
                        @else
                            <p class="small text-muted">SMS bundles are not available yet. Contact the platform administrator to buy credits.</p>
                        @endif

                        @if($smsCredits['history']->isNotEmpty())
                            <details class="small mt-2">
                                <summary class="text-muted">Recent top-ups</summary>
                                <table class="table table-sm mb-0 mt-1">
                                    @foreach($smsCredits['history'] as $tx)
                                        <tr>
                                            <td>{{ $tx->created_at->format('d M Y') }}</td>
                                            <td>{{ ucfirst($tx->type) }}</td>
                                            <td class="{{ $tx->credits < 0 ? 'text-danger' : 'text-success' }}">{{ $tx->credits > 0 ? '+' : '' }}{{ number_format($tx->credits) }}</td>
                                            <td class="text-muted">{{ $tx->note }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            </details>
                        @endif
                    @endif

                    <hr>

                    <label class="small font-weight-bold text-muted">SENDER ID</label>
                    <p class="small mb-2">
                        Messages currently show as
                        <strong>{{ $approvedSenderId ?: (config('services.eazisms.default_sender') ?: 'the platform default sender') }}</strong>.
                        @if($senderIdStatus === 'pending')
                            <span class="badge badge-warning ml-1">Pending approval: {{ $senderIdRequest }}</span>
                        @elseif($senderIdStatus === 'approved')
                            <span class="badge badge-success ml-1">Approved</span>
                        @elseif($senderIdStatus === 'rejected')
                            <span class="badge badge-danger ml-1">Rejected</span>
                        @endif
                    </p>
                    @if($senderIdStatus === 'rejected' && $senderIdNote)
                        <div class="alert alert-danger small py-2">{{ $senderIdNote }}</div>
                    @endif

                    <form wire:submit="requestSenderId">
                        <div class="input-group">
                            <input type="text" wire:model="senderIdRequest" maxlength="11"
                                   class="form-control bg-light border-0 @error('senderIdRequest') is-invalid @enderror"
                                   placeholder="e.g. VisionSpace">
                            <div class="input-group-append">
                                <button type="submit" class="btn btn-primary font-weight-bold"
                                        wire:loading.attr="disabled" wire:target="requestSenderId">
                                    {{ $senderIdStatus === 'none' ? 'Request' : 'Request change' }}
                                </button>
                            </div>
                        </div>
                        @error('senderIdRequest') <span class="text-danger small">{{ $message }}</span> @enderror
                        <small class="form-text text-muted">
                            Up to 11 letters or digits, usually your clinic's short name. The platform registers it with the SMS network;
                            until then messages use the platform sender.
                        </small>
                    </form>
                </div>
            </div>
            @else
            {{-- Credentials card --}}
            <div class="card border-0 shadow-sm rounded-lg mb-4">
                <div class="card-header bg-primary text-white py-3 border-0">
                    <h5 class="mb-0 font-weight-bold"><i class="fas fa-sms mr-2"></i> SMS API Configuration</h5>
                </div>
                <div class="card-body">
                    <form wire:submit="save">

                        {{-- API Base URL --}}
                        <div class="form-group">
                            <label class="small font-weight-bold text-muted">API BASE URL</label>
                            <input type="url" wire:model="smsApiUrl"
                                   class="form-control bg-light border-0 @error('smsApiUrl') is-invalid @enderror"
                                   placeholder="http://dashboard.eazismspro.com/sms/api">
                            @error('smsApiUrl') <span class="text-danger small">{{ $message }}</span> @enderror
                            <small class="form-text text-muted">
                                Paste the base URL only — without any query parameters.
                                e.g. <code>http://dashboard.eazismspro.com/sms/api</code>
                            </small>
                        </div>

                        {{-- API Key --}}
                        <div class="form-group">
                            <label class="small font-weight-bold text-muted">API KEY</label>
                            <input type="password" wire:model="smsApiKey"
                                   class="form-control bg-light border-0 @error('smsApiKey') is-invalid @enderror"
                                   placeholder="Leave blank to keep the existing key"
                                   autocomplete="new-password">
                            @error('smsApiKey') <span class="text-danger small">{{ $message }}</span> @enderror
                            <small class="form-text text-muted">Stored encrypted. Leave blank to keep the currently saved key.</small>
                        </div>

                        {{-- Sender ID --}}
                        <div class="form-group">
                            <label class="small font-weight-bold text-muted">SENDER ID</label>
                            <input type="text" wire:model="smsSenderId"
                                   class="form-control bg-light border-0 @error('smsSenderId') is-invalid @enderror"
                                   placeholder="e.g. EYECLINIC"
                                   maxlength="11">
                            @error('smsSenderId') <span class="text-danger small">{{ $message }}</span> @enderror
                            <small class="form-text text-muted">
                                Alphanumeric sender name as registered with your provider (max 11 chars).
                            </small>
                        </div>

                        <button type="submit" class="btn btn-primary btn-block py-2 font-weight-bold shadow-sm mt-2"
                                wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading.remove wire:target="save"><i class="fas fa-save mr-2"></i> Save SMS Settings</span>
                            <span wire:loading wire:target="save">Saving…</span>
                        </button>
                    </form>
                </div>
            </div>

            @endif

            {{-- Test & Balance card --}}
            <div class="card border-0 shadow-sm rounded-lg mb-4">
                <div class="card-header bg-secondary text-white py-3 border-0">
                    <h5 class="mb-0 font-weight-bold"><i class="fas fa-paper-plane mr-2"></i> Test & Balance</h5>
                </div>
                <div class="card-body">

                    {{-- Test SMS --}}
                    <div class="form-group">
                        <label class="small font-weight-bold text-muted">TEST PHONE NUMBER</label>
                        <div class="input-group">
                            <input type="text" wire:model="testPhone"
                                   class="form-control bg-light border-0"
                                   placeholder="e.g. 0241234567">
                            <div class="input-group-append">
                                <button type="button" wire:click="sendTest"
                                        wire:loading.attr="disabled" wire:target="sendTest"
                                        class="btn btn-outline-primary font-weight-bold">
                                    <span wire:loading.remove wire:target="sendTest"><i class="fas fa-paper-plane mr-1"></i> Send Test</span>
                                    <span wire:loading wire:target="sendTest">Sending…</span>
                                </button>
                            </div>
                        </div>
                        <small class="form-text text-muted">Sends a test message to confirm SMS delivery is working{{ $platformManaged ? ' (uses 1 SMS credit)' : '' }}.</small>
                    </div>

                    @unless($platformManaged)
                    <hr>

                    {{-- Balance check --}}
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="font-weight-bold small text-muted text-uppercase">SMS Balance</div>
                            @if($balanceResult)
                                @if($balanceResult['success'])
                                    <span class="text-success font-weight-bold">
                                        {{ $balanceResult['response']['balance'] ?? json_encode($balanceResult['response']) }}
                                    </span>
                                @else
                                    <span class="text-danger small">{{ $balanceResult['error'] }}</span>
                                @endif
                            @else
                                <span class="text-muted small">Click "Check Balance" to query your account.</span>
                            @endif
                        </div>
                        <button type="button" wire:click="checkBalance"
                                wire:loading.attr="disabled" wire:target="checkBalance"
                                class="btn btn-outline-secondary btn-sm font-weight-bold">
                            <span wire:loading.remove wire:target="checkBalance"><i class="fas fa-wallet mr-1"></i> Check Balance</span>
                            <span wire:loading wire:target="checkBalance">Checking…</span>
                        </button>
                    </div>
                    @endunless

                </div>
            </div>
            @unless($platformManaged)
            {{-- Provider info --}}
            <div class="alert alert-info border-0 shadow-sm small mb-0">
                <i class="fas fa-info-circle mr-1"></i>
                <strong>EazismsPro API:</strong> SMS are sent via HTTP GET —
                <code>?action=send-sms&api_key=…&to=233xx&from=SENDER&sms=…</code>.
                Phone numbers are automatically normalised to Ghana format (<code>0xx</code> → <code>233xx</code>).
            </div>
            @endunless

        </div>
    </div>
</div>
