@php
    // Compact form controls: a 36px input joined to its button.
    $card = 'rounded-lg border border-slate-200 bg-white p-4 shadow-sm';
    $cardTitle = 'mb-3 flex items-center gap-2 text-sm font-semibold text-slate-800';
    $label = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500';
    $field = 'h-9 min-w-0 border px-3 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500';
    $joinedPrimary = 'h-9 whitespace-nowrap rounded-r-md bg-teal-600 px-4 text-sm font-semibold text-white hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-50';
    $joinedSecondary = 'h-9 whitespace-nowrap rounded-r-md border border-l-0 border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50';
@endphp
<div class="clinic-ui ui-page">
    <div class="mx-auto max-w-6xl">

        <div class="mb-4">
            <p class="text-slate-500 text-sm uppercase font-semibold mb-0">Communications</p>
            <h2 class="text-teal-700 font-semibold mb-1">SMS Credits &amp; Sending</h2>
            <p class="text-slate-500 text-sm mb-0">Credits, your sender name and per-branch limits. Choose which messages go out under <a href="{{ route('admin.messages') }}">Messages</a>.</p>
        </div>

        {{-- Status banner --}}
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border px-4 py-2 text-sm
                    {{ $smsEnabled ? 'border-green-200 bg-green-50 text-green-800' : 'border-amber-200 bg-amber-50 text-amber-900' }}">
            <div>
                @if($smsEnabled)
                    <i class="fas fa-check-circle mr-1"></i>
                    <strong>SMS sending is on.</strong>
                    {{ $messagesOn }} of {{ $messagesTotal }} automatic messages switched on.
                    <a href="{{ route('admin.messages') }}" class="font-semibold">{{ $messagesOn === 0 ? 'Choose messages →' : 'Manage messages →' }}</a>
                @else
                    <i class="fas fa-pause-circle mr-1"></i>
                    <strong>SMS sending is paused.</strong>
                    No SMS is sent until you resume, whichever messages are switched on.
                @endif
            </div>
            <button type="button" wire:click="toggleSms"
                    wire:loading.attr="disabled" wire:target="toggleSms"
                    class="h-8 shrink-0 whitespace-nowrap rounded-md px-3 text-sm font-semibold shadow-sm
                           {{ $smsEnabled ? 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50' : 'bg-teal-600 text-white hover:bg-teal-700' }}">
                <span wire:loading.remove wire:target="toggleSms">
                    <i class="fas {{ $smsEnabled ? 'fa-pause' : 'fa-play' }} mr-1"></i>
                    {{ $smsEnabled ? 'Pause SMS' : 'Resume SMS' }}
                </span>
                <span wire:loading wire:target="toggleSms">Updating…</span>
            </button>
        </div>

        <div class="grid items-start gap-4 lg:grid-cols-5">
            {{-- Left: credits (hosted) or the gateway settings (offline) --}}
            <div class="lg:col-span-3">
            @if($platformManaged)
                <section class="{{ $card }}">
                    <h3 class="{{ $cardTitle }}"><i class="fas fa-sms text-teal-700"></i> SMS Credits</h3>
                    @if($smsCredits)
                        <div class="flex items-baseline justify-between">
                            <span class="text-sm font-semibold text-slate-500 uppercase">SMS credits left</span>
                            <span class="text-2xl font-semibold {{ $smsCredits['balance'] <= 20 ? 'text-red-700' : 'text-green-700' }}">{{ number_format($smsCredits['balance']) }}</span>
                        </div>
                        <small class="mt-1 mb-3 block text-xs text-slate-500">
                            Credits are bought separately from your plan and never expire. Each SMS uses 1 credit per 160 characters
                            (70 when it contains special characters). WhatsApp links and email are free.
                        </small>

                        @if($smsCredits['pending']->isNotEmpty())
                            <div class="mb-3 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900">
                                @foreach($smsCredits['pending'] as $invoice)
                                    <div class="flex justify-between items-center {{ !$loop->last ? 'mb-1' : '' }}">
                                        <span>
                                            <strong>{{ number_format($invoice->sms_credits) }} credits</strong> awaiting payment —
                                            invoice {{ $invoice->number }}, {{ $invoice->currency }} {{ number_format($invoice->balance(), 2) }}
                                        </span>
                                        @if($invoice->status === 'unpaid')
                                            <button type="button" class="text-sm font-semibold text-red-700 hover:underline" wire:click="cancelBundleRequest({{ $invoice->id }})"
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
                                <span class="{{ $label }}">Buy SMS credits</span>
                                <div class="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
                                    <template x-for="t in tiers" :key="t.id">
                                        <button type="button" class="block w-full rounded-md border px-3 py-2 text-left transition hover:border-teal-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                                :class="valid && tier.id === t.id ? 'border-teal-600 bg-teal-50' : 'border-slate-200 bg-white'"
                                                x-on:click="amount = t.price">
                                            <span class="flex items-center justify-between gap-2">
                                                <span class="text-xs font-semibold uppercase tracking-wide text-slate-500" x-text="t.name"></span>
                                                <span class="shrink-0 whitespace-nowrap rounded px-1.5 py-0.5 text-xs font-semibold bg-green-100 text-green-800" x-show="t.price / t.credits < worst() - 0.00001"
                                                      x-text="'save ' + Math.round((1 - (t.price / t.credits) / worst()) * 100) + '%'"></span>
                                            </span>
                                            <span class="block text-base font-semibold text-slate-800" x-text="'GHS ' + count(t.price)"></span>
                                            <span class="block text-xs text-slate-600" x-text="count(t.credits) + ' SMS · ' + (t.price / t.credits).toFixed(3) + ' each'"></span>
                                        </button>
                                    </template>
                                </div>

                                <label class="{{ $label }}" for="sms-top-up-amount">OR ENTER AN AMOUNT</label>
                                <div class="flex w-full max-w-sm">
                                    <span class="flex h-9 items-center rounded-l-md border border-r-0 border-slate-300 bg-slate-50 px-3 text-sm text-slate-600">GHS</span>
                                    <input type="number" id="sms-top-up-amount"
                                           class="{{ $field }} flex-1 @error('topUpAmount') border-red-500 @else border-slate-300 @enderror"
                                           x-model="amount" :min="min" :max="max" step="0.01" placeholder="e.g. 250"
                                           x-on:keydown.enter.prevent="submit()">
                                    <button type="button" class="{{ $joinedPrimary }}"
                                            :disabled="!valid" x-ref="requestButton"
                                            data-confirm-button="Request invoice" data-confirm-danger="false"
                                            x-on:click="submit()" wire:loading.attr="disabled" wire:target="requestTopUp">
                                        Request invoice
                                    </button>
                                </div>
                                @error('topUpAmount')<div class="text-sm text-red-700 mt-1">{{ $message }}</div>@enderror

                                <div class="text-sm text-red-700 mt-2" x-show="amount !== '' && !valid" style="display: none"
                                     x-text="'Enter between GHS ' + count(min) + ' and GHS ' + count(max) + '.'"></div>
                                <div class="border border-slate-200 rounded-md p-2 mt-2 bg-slate-50 text-sm" x-show="valid" style="display: none">
                                    <div>
                                        You get <strong class="text-green-700" x-text="count(credits) + ' SMS'"></strong>
                                        at GHS <span x-text="(tier.price / tier.credits).toFixed(3)"></span> each
                                        <span class="text-slate-500" x-text="'(' + tier.name + ' rate)'"></span>
                                    </div>
                                    <div class="text-slate-500" x-show="taxRate > 0"
                                         x-text="'Invoice total with ' + taxRate + '% tax: GHS ' + money(Math.round(value * (1 + taxRate / 100) * 100) / 100)"></div>
                                    <div class="mt-1" x-show="next">
                                        <i class="fas fa-lightbulb text-amber-600 mr-1"></i>
                                        <a href="#" class="font-semibold" x-on:click.prevent="amount = next.price"
                                           x-text="next ? 'Add GHS ' + money(next.price - value) + ' more' : ''"></a>
                                        <span x-text="next ? 'and get ' + count(next.credits) + ' SMS at ' + (next.price / next.credits).toFixed(3) + ' each (+' + count(next.credits - credits) + ' SMS).' : ''"></span>
                                    </div>
                                </div>
                                <small class="mt-1 block text-xs text-slate-500">
                                    Bigger amounts get a lower price per SMS. Pay the invoice as you pay your subscription; credits appear here once payment is confirmed.
                                </small>
                            </div>
                        @else
                            <p class="text-sm text-slate-500">SMS bundles are not available yet. Contact the platform administrator to buy credits.</p>
                        @endif

                        @if($smsCredits['history']->isNotEmpty())
                            <details class="text-sm mt-3">
                                <summary class="cursor-pointer text-slate-500">Recent top-ups</summary>
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
                </section>
            @else
                {{-- Credentials (offline installs use their own gateway account) --}}
                <section class="{{ $card }}">
                    <h3 class="{{ $cardTitle }}"><i class="fas fa-sms text-teal-700"></i> SMS API Configuration</h3>
                    <form wire:submit="save" class="space-y-3">
                        <div>
                            <label class="{{ $label }}" for="sms-api-url">API base URL</label>
                            <input type="url" id="sms-api-url" wire:model="smsApiUrl"
                                   class="{{ $field }} w-full rounded-md @error('smsApiUrl') border-red-500 @else border-slate-300 @enderror"
                                   placeholder="http://dashboard.eazismspro.com/sms/api">
                            @error('smsApiUrl') <span class="text-red-700 text-sm">{{ $message }}</span> @enderror
                            <small class="mt-1 block text-xs text-slate-500">
                                Paste the base URL only — without any query parameters.
                                e.g. <code>http://dashboard.eazismspro.com/sms/api</code>
                            </small>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="{{ $label }}" for="sms-api-key">API key</label>
                                <input type="password" id="sms-api-key" wire:model="smsApiKey"
                                       class="{{ $field }} w-full rounded-md @error('smsApiKey') border-red-500 @else border-slate-300 @enderror"
                                       placeholder="Leave blank to keep the existing key" autocomplete="new-password">
                                @error('smsApiKey') <span class="text-red-700 text-sm">{{ $message }}</span> @enderror
                                <small class="mt-1 block text-xs text-slate-500">Stored encrypted.</small>
                            </div>
                            <div>
                                <label class="{{ $label }}" for="sms-sender">Sender ID</label>
                                <input type="text" id="sms-sender" wire:model="smsSenderId" maxlength="11"
                                       class="{{ $field }} w-full rounded-md @error('smsSenderId') border-red-500 @else border-slate-300 @enderror"
                                       placeholder="e.g. EYECLINIC">
                                @error('smsSenderId') <span class="text-red-700 text-sm">{{ $message }}</span> @enderror
                                <small class="mt-1 block text-xs text-slate-500">As registered with your provider (max 11 characters).</small>
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="h-9 rounded-md bg-teal-600 px-4 text-sm font-semibold text-white hover:bg-teal-700 disabled:opacity-50"
                                    wire:loading.attr="disabled" wire:target="save">
                                <span wire:loading.remove wire:target="save"><i class="fas fa-save mr-1"></i> Save SMS Settings</span>
                                <span wire:loading wire:target="save">Saving…</span>
                            </button>
                        </div>
                    </form>
                </section>
            @endif
            </div>

            {{-- Right: sender ID and test --}}
            <div class="space-y-4 lg:col-span-2">
                @if($platformManaged)
                    <section class="{{ $card }}">
                        <h3 class="{{ $cardTitle }}"><i class="fas fa-id-badge text-teal-700"></i> Sender ID</h3>
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
                            <div class="mb-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ $senderIdNote }}</div>
                        @endif

                        <form wire:submit="requestSenderId">
                            <div class="flex">
                                <input type="text" wire:model="senderIdRequest" maxlength="11" aria-label="Sender ID"
                                       class="{{ $field }} flex-1 rounded-l-md @error('senderIdRequest') border-red-500 @else border-slate-300 @enderror"
                                       placeholder="e.g. VisionSpace">
                                <button type="submit" class="{{ $joinedPrimary }}"
                                        wire:loading.attr="disabled" wire:target="requestSenderId">
                                    {{ $senderIdStatus === 'none' ? 'Request' : 'Request change' }}
                                </button>
                            </div>
                            @error('senderIdRequest') <span class="text-red-700 text-sm">{{ $message }}</span> @enderror
                            <small class="mt-1 block text-xs text-slate-500">
                                Up to 11 letters or digits, usually your clinic's short name. The platform registers it with the SMS network;
                                until then messages use the platform sender.
                            </small>
                        </form>
                    </section>
                @endif

                <section class="{{ $card }}">
                    <h3 class="{{ $cardTitle }}"><i class="fas fa-paper-plane text-teal-700"></i> Test{{ $platformManaged ? ' SMS' : ' & Balance' }}</h3>
                    <div class="flex">
                        <input type="text" wire:model="testPhone" aria-label="Test phone number"
                               class="{{ $field }} flex-1 rounded-l-md border-slate-300"
                               placeholder="Phone, e.g. 0241234567">
                        <button type="button" wire:click="sendTest"
                                wire:loading.attr="disabled" wire:target="sendTest"
                                class="{{ $joinedSecondary }}">
                            <span wire:loading.remove wire:target="sendTest"><i class="fas fa-paper-plane mr-1"></i> Send test</span>
                            <span wire:loading wire:target="sendTest">Sending…</span>
                        </button>
                    </div>
                    <small class="mt-1 block text-xs text-slate-500">Sends a test message to confirm SMS delivery is working{{ $platformManaged ? ' (uses 1 SMS credit)' : '' }}.</small>

                    @unless($platformManaged)
                        <div class="mt-3 flex items-center justify-between gap-3 border-t border-slate-200 pt-3">
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
                                    class="h-8 shrink-0 whitespace-nowrap rounded-md border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                <span wire:loading.remove wire:target="checkBalance"><i class="fas fa-wallet mr-1"></i> Check Balance</span>
                                <span wire:loading wire:target="checkBalance">Checking…</span>
                            </button>
                        </div>
                    @endunless
                </section>

                @unless($platformManaged)
                    <div class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900">
                        <i class="fas fa-info-circle mr-1"></i>
                        <strong>EazismsPro API:</strong> SMS are sent via HTTP GET —
                        <code>?action=send-sms&api_key=…&to=233xx&from=SENDER&sms=…</code>.
                        Phone numbers are automatically normalised to Ghana format (<code>0xx</code> → <code>233xx</code>).
                    </div>
                @endunless
            </div>
        </div>

        @if(auth()->user()?->hasRole('Super Admin'))
            <div class="mt-4">
                <livewire:admin.branch-sms-limits-component />
            </div>
        @endif

    </div>
</div>
