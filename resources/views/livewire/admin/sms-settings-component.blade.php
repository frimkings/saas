<div class="clinic-ui ui-page">
    <div class="flex flex-wrap -mx-2 justify-center">
        <div class="w-full lg:w-7/12 px-2">

            <div class="mb-4">
                <p class="text-slate-500 text-sm uppercase font-semibold mb-0">Communications</p>
                <h2 class="text-teal-700 font-semibold mb-1">SMS Credits &amp; Sending</h2>
                <p class="text-slate-500 text-sm mb-0">Credits, your sender name and per-branch limits. Choose which messages go out under <a href="{{ route('admin.messages') }}">Messages</a>.</p>
            </div>

            {{-- Status banner --}}
            <div class="rounded-lg border px-3 py-2 text-sm border-0 shadow-sm mb-6 flex items-center justify-between flex-wrap
                        {{ $smsEnabled ? 'border-green-200 bg-green-50 text-green-800' : 'border-amber-200 bg-amber-50 text-amber-900' }}" style="gap:.75rem">
                <div>
                    @if($smsEnabled)
                        <i class="fas fa-check-circle mr-2"></i>
                        <strong>SMS sending is on</strong>
                        <span class="block text-sm mt-1">
                            {{ $messagesOn }} of {{ $messagesTotal }} automatic messages switched on.
                            <a href="{{ route('admin.messages') }}" class="font-semibold">{{ $messagesOn === 0 ? 'Choose messages →' : 'Manage messages →' }}</a>
                        </span>
                    @else
                        <i class="fas fa-pause-circle mr-2"></i>
                        <strong>SMS sending is paused</strong>
                        <span class="block text-sm mt-1">No SMS is sent until you resume, whichever messages are switched on.</span>
                    @endif
                </div>
                <button type="button" wire:click="toggleSms"
                        wire:loading.attr="disabled" wire:target="toggleSms"
                        class="btn ui-button font-semibold shadow-sm ml-4 shrink-0
                               {{ $smsEnabled ? 'ui-button-secondary' : 'ui-button-primary' }}">
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
            <div class="card overflow-hidden border border-slate-200 bg-white border-0 shadow-sm rounded-lg mb-6">
                <div class="card-header border-b border-slate-200 px-4 bg-teal-700 text-white py-4 border-0">
                    <h5 class="mb-0 font-semibold"><i class="fas fa-sms mr-2"></i> SMS Credits &amp; Sender ID</h5>
                </div>
                <div class="card-body p-4">
                    @if($smsCredits)
                        <div class="flex items-baseline justify-between">
                            <span class="text-sm font-semibold text-slate-500 uppercase">SMS credits left</span>
                            <span class="text-xl font-semibold mb-0 font-semibold {{ $smsCredits['balance'] <= 20 ? 'text-red-700' : 'text-green-700' }}">{{ number_format($smsCredits['balance']) }}</span>
                        </div>
                        <small class="mt-1 block text-xs text-slate-500 mb-4">
                            Credits are bought separately from your plan and never expire. Each SMS uses 1 credit per 160 characters
                            (70 when it contains special characters). WhatsApp links and email are free.
                        </small>

                        @if($smsCredits['pending']->isNotEmpty())
                            <div class="rounded-lg border px-3 text-sm border-sky-200 bg-sky-50 text-sky-900 py-2">
                                @foreach($smsCredits['pending'] as $invoice)
                                    <div class="flex justify-between items-center {{ !$loop->last ? 'mb-1' : '' }}">
                                        <span>
                                            <strong>{{ number_format($invoice->sms_credits) }} credits</strong> awaiting payment —
                                            invoice {{ $invoice->number }}, {{ $invoice->currency }} {{ number_format($invoice->balance(), 2) }}
                                        </span>
                                        @if($invoice->status === 'unpaid')
                                            <button type="button" class="btn ui-button ui-button-link ui-button-sm p-0 text-red-700" wire:click="cancelBundleRequest({{ $invoice->id }})"
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
                                <label class="text-sm font-semibold text-slate-500">BUY SMS CREDITS</label>
                                <div class="flex flex-wrap -mx-2">
                                    <template x-for="t in tiers" :key="t.id">
                                        <div class="w-full sm:w-6/12 lg:w-4/12 px-2 mb-2">
                                            <button type="button" class="btn ui-button w-full text-left border border-slate-200 rounded-md p-2 h-full ui-button-secondary" style="white-space: normal"
                                                    :class="valid && tier.id === t.id ? 'border-teal-600 bg-slate-50' : 'bg-white'"
                                                    x-on:click="amount = t.price">
                                                <div class="flex justify-between items-center">
                                                    <span class="text-sm text-slate-500 uppercase font-semibold" x-text="t.name"></span>
                                                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-green-100 text-green-800" x-show="t.price / t.credits < worst() - 0.00001"
                                                          x-text="'save ' + Math.round((1 - (t.price / t.credits) / worst()) * 100) + '%'"></span>
                                                </div>
                                                <div class="text-base font-semibold mb-0" x-text="'GHS ' + count(t.price)"></div>
                                                <div class="text-sm" x-text="count(t.credits) + ' SMS'"></div>
                                                <div class="text-sm text-slate-500" x-text="(t.price / t.credits).toFixed(3) + ' per SMS'"></div>
                                            </button>
                                        </div>
                                    </template>
                                </div>

                                <label class="text-sm font-semibold text-slate-500 mt-2 mb-1" for="sms-top-up-amount">OR ENTER AN AMOUNT</label>
                                <div class="flex items-stretch">
                                    <div class="flex"><span class="flex items-center border border-slate-300 bg-slate-50 px-2 text-sm text-slate-600">GHS</span></div>
                                    <input type="number" id="sms-top-up-amount" class="form-control ui-input @error('topUpAmount') is-invalid @enderror"
                                           x-model="amount" :min="min" :max="max" step="0.01" placeholder="e.g. 250"
                                           x-on:keydown.enter.prevent="submit()">
                                    <div class="flex">
                                        <button type="button" class="btn ui-button ui-button-primary font-semibold" :disabled="!valid" x-ref="requestButton"
                                                data-confirm-button="Request invoice" data-confirm-danger="false"
                                                x-on:click="submit()" wire:loading.attr="disabled" wire:target="requestTopUp">
                                            Request invoice
                                        </button>
                                    </div>
                                </div>
                                @error('topUpAmount')<div class="text-sm text-red-700 mt-1">{{ $message }}</div>@enderror

                                <div class="text-sm text-red-700 mt-2" x-show="amount !== '' && !valid" style="display: none"
                                     x-text="'Enter between GHS ' + count(min) + ' and GHS ' + count(max) + '.'"></div>
                                <div class="border border-slate-200 rounded-md p-2 mt-2 bg-slate-50" x-show="valid" style="display: none">
                                    <div>
                                        You get <strong class="text-green-700" x-text="count(credits) + ' SMS'"></strong>
                                        at GHS <span x-text="(tier.price / tier.credits).toFixed(3)"></span> each
                                        <span class="text-slate-500" x-text="'(' + tier.name + ' rate)'"></span>
                                    </div>
                                    <div class="text-sm text-slate-500" x-show="taxRate > 0"
                                         x-text="'Invoice total with ' + taxRate + '% tax: GHS ' + money(Math.round(value * (1 + taxRate / 100) * 100) / 100)"></div>
                                    <div class="text-sm mt-1" x-show="next">
                                        <i class="fas fa-lightbulb text-amber-600 mr-1"></i>
                                        <a href="#" class="font-semibold" x-on:click.prevent="amount = next.price"
                                           x-text="next ? 'Add GHS ' + money(next.price - value) + ' more' : ''"></a>
                                        <span x-text="next ? 'and get ' + count(next.credits) + ' SMS at ' + (next.price / next.credits).toFixed(3) + ' each (+' + count(next.credits - credits) + ' SMS).' : ''"></span>
                                    </div>
                                </div>
                                <small class="mt-1 block text-xs text-slate-500 mb-2">
                                    Bigger amounts get a lower price per SMS. Pay the invoice as you pay your subscription; credits appear here once payment is confirmed.
                                </small>
                            </div>
                        @else
                            <p class="text-sm text-slate-500">SMS bundles are not available yet. Contact the platform administrator to buy credits.</p>
                        @endif

                        @if($smsCredits['history']->isNotEmpty())
                            <details class="text-sm mt-2">
                                <summary class="text-slate-500">Recent top-ups</summary>
                                <table class="table ui-table ui-table-sm mb-0 mt-1">
                                    @foreach($smsCredits['history'] as $tx)
                                        <tr>
                                            <td>{{ $tx->created_at->format('d M Y') }}</td>
                                            <td>{{ ucfirst($tx->type) }}</td>
                                            <td class="{{ $tx->credits < 0 ? 'text-red-700' : 'text-green-700' }}">{{ $tx->credits > 0 ? '+' : '' }}{{ number_format($tx->credits) }}</td>
                                            <td class="text-slate-500">{{ $tx->note }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            </details>
                        @endif
                    @endif

                    <hr>

                    <label class="text-sm font-semibold text-slate-500">SENDER ID</label>
                    <p class="text-sm mb-2">
                        Messages currently show as
                        <strong>{{ $approvedSenderId ?: (config('services.eazisms.default_sender') ?: 'the platform default sender') }}</strong>.
                        @if($senderIdStatus === 'pending')
                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800 ml-1">Pending approval: {{ $senderIdRequest }}</span>
                        @elseif($senderIdStatus === 'approved')
                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-green-100 text-green-800 ml-1">Approved</span>
                        @elseif($senderIdStatus === 'rejected')
                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-red-100 text-red-800 ml-1">Rejected</span>
                        @endif
                    </p>
                    @if($senderIdStatus === 'rejected' && $senderIdNote)
                        <div class="rounded-lg border px-3 text-sm border-red-200 bg-red-50 text-red-800 py-2">{{ $senderIdNote }}</div>
                    @endif

                    <form wire:submit="requestSenderId">
                        <div class="flex items-stretch">
                            <input type="text" wire:model="senderIdRequest" maxlength="11"
                                   class="form-control ui-input bg-slate-50 border-0 @error('senderIdRequest') is-invalid @enderror"
                                   placeholder="e.g. VisionSpace">
                            <div class="flex">
                                <button type="submit" class="btn ui-button ui-button-primary font-semibold"
                                        wire:loading.attr="disabled" wire:target="requestSenderId">
                                    {{ $senderIdStatus === 'none' ? 'Request' : 'Request change' }}
                                </button>
                            </div>
                        </div>
                        @error('senderIdRequest') <span class="text-red-700 text-sm">{{ $message }}</span> @enderror
                        <small class="mt-1 block text-xs text-slate-500">
                            Up to 11 letters or digits, usually your clinic's short name. The platform registers it with the SMS network;
                            until then messages use the platform sender.
                        </small>
                    </form>
                </div>
            </div>
            @else
            {{-- Credentials card --}}
            <div class="card overflow-hidden border border-slate-200 bg-white border-0 shadow-sm rounded-lg mb-6">
                <div class="card-header border-b border-slate-200 px-4 bg-teal-700 text-white py-4 border-0">
                    <h5 class="mb-0 font-semibold"><i class="fas fa-sms mr-2"></i> SMS API Configuration</h5>
                </div>
                <div class="card-body p-4">
                    <form wire:submit="save">

                        {{-- API Base URL --}}
                        <div class="mb-4">
                            <label class="text-sm font-semibold text-slate-500">API BASE URL</label>
                            <input type="url" wire:model="smsApiUrl"
                                   class="form-control ui-input bg-slate-50 border-0 @error('smsApiUrl') is-invalid @enderror"
                                   placeholder="http://dashboard.eazismspro.com/sms/api">
                            @error('smsApiUrl') <span class="text-red-700 text-sm">{{ $message }}</span> @enderror
                            <small class="mt-1 block text-xs text-slate-500">
                                Paste the base URL only — without any query parameters.
                                e.g. <code>http://dashboard.eazismspro.com/sms/api</code>
                            </small>
                        </div>

                        {{-- API Key --}}
                        <div class="mb-4">
                            <label class="text-sm font-semibold text-slate-500">API KEY</label>
                            <input type="password" wire:model="smsApiKey"
                                   class="form-control ui-input bg-slate-50 border-0 @error('smsApiKey') is-invalid @enderror"
                                   placeholder="Leave blank to keep the existing key"
                                   autocomplete="new-password">
                            @error('smsApiKey') <span class="text-red-700 text-sm">{{ $message }}</span> @enderror
                            <small class="mt-1 block text-xs text-slate-500">Stored encrypted. Leave blank to keep the currently saved key.</small>
                        </div>

                        {{-- Sender ID --}}
                        <div class="mb-4">
                            <label class="text-sm font-semibold text-slate-500">SENDER ID</label>
                            <input type="text" wire:model="smsSenderId"
                                   class="form-control ui-input bg-slate-50 border-0 @error('smsSenderId') is-invalid @enderror"
                                   placeholder="e.g. EYECLINIC"
                                   maxlength="11">
                            @error('smsSenderId') <span class="text-red-700 text-sm">{{ $message }}</span> @enderror
                            <small class="mt-1 block text-xs text-slate-500">
                                Alphanumeric sender name as registered with your provider (max 11 chars).
                            </small>
                        </div>

                        <button type="submit" class="btn ui-button ui-button-primary w-full py-2 font-semibold shadow-sm mt-2"
                                wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading.remove wire:target="save"><i class="fas fa-save mr-2"></i> Save SMS Settings</span>
                            <span wire:loading wire:target="save">Saving…</span>
                        </button>
                    </form>
                </div>
            </div>

            @endif

            {{-- Test & Balance card --}}
            <div class="card overflow-hidden border border-slate-200 bg-white border-0 shadow-sm rounded-lg mb-6">
                <div class="card-header border-b border-slate-200 px-4 bg-slate-500 text-white py-4 border-0">
                    <h5 class="mb-0 font-semibold"><i class="fas fa-paper-plane mr-2"></i> Test & Balance</h5>
                </div>
                <div class="card-body p-4">

                    {{-- Test SMS --}}
                    <div class="mb-4">
                        <label class="text-sm font-semibold text-slate-500">TEST PHONE NUMBER</label>
                        <div class="flex items-stretch">
                            <input type="text" wire:model="testPhone"
                                   class="form-control ui-input bg-slate-50 border-0"
                                   placeholder="e.g. 0241234567">
                            <div class="flex">
                                <button type="button" wire:click="sendTest"
                                        wire:loading.attr="disabled" wire:target="sendTest"
                                        class="btn ui-button ui-button-secondary font-semibold">
                                    <span wire:loading.remove wire:target="sendTest"><i class="fas fa-paper-plane mr-1"></i> Send Test</span>
                                    <span wire:loading wire:target="sendTest">Sending…</span>
                                </button>
                            </div>
                        </div>
                        <small class="mt-1 block text-xs text-slate-500">Sends a test message to confirm SMS delivery is working{{ $platformManaged ? ' (uses 1 SMS credit)' : '' }}.</small>
                    </div>

                    @unless($platformManaged)
                    <hr>

                    {{-- Balance check --}}
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="font-semibold text-sm text-slate-500 uppercase">SMS Balance</div>
                            @if($balanceResult)
                                @if($balanceResult['success'])
                                    <span class="text-green-700 font-semibold">
                                        {{ $balanceResult['response']['balance'] ?? json_encode($balanceResult['response']) }}
                                    </span>
                                @else
                                    <span class="text-red-700 text-sm">{{ $balanceResult['error'] }}</span>
                                @endif
                            @else
                                <span class="text-slate-500 text-sm">Click "Check Balance" to query your account.</span>
                            @endif
                        </div>
                        <button type="button" wire:click="checkBalance"
                                wire:loading.attr="disabled" wire:target="checkBalance"
                                class="btn ui-button ui-button-secondary ui-button-sm font-semibold">
                            <span wire:loading.remove wire:target="checkBalance"><i class="fas fa-wallet mr-1"></i> Check Balance</span>
                            <span wire:loading wire:target="checkBalance">Checking…</span>
                        </button>
                    </div>
                    @endunless

                </div>
            </div>
            @unless($platformManaged)
            {{-- Provider info --}}
            <div class="rounded-lg border px-3 py-2 text-sm border-sky-200 bg-sky-50 text-sky-900 border-0 shadow-sm mb-0">
                <i class="fas fa-info-circle mr-1"></i>
                <strong>EazismsPro API:</strong> SMS are sent via HTTP GET —
                <code>?action=send-sms&api_key=…&to=233xx&from=SENDER&sms=…</code>.
                Phone numbers are automatically normalised to Ghana format (<code>0xx</code> → <code>233xx</code>).
            </div>
            @endunless

        </div>
    </div>
</div>
