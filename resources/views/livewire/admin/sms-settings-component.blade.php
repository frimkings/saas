<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-7">

            {{-- Status banner --}}
            <div class="alert border-0 shadow-sm mb-4 d-flex align-items-center justify-content-between
                        {{ $smsEnabled ? 'alert-success' : 'alert-warning' }}">
                <div>
                    @if($smsEnabled)
                        <i class="fas fa-check-circle mr-2"></i>
                        <strong>SMS Notifications Active</strong>
                        <span class="d-block small mt-1">All SMS triggers (appointments, spectacles, payments) are enabled.</span>
                    @else
                        <i class="fas fa-pause-circle mr-2"></i>
                        <strong>SMS Notifications Paused</strong>
                        <span class="d-block small mt-1">No SMS messages will be sent until notifications are resumed.</span>
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

                        @if($smsCredits['bundles']->isNotEmpty())
                            <label class="small font-weight-bold text-muted">BUY SMS CREDITS</label>
                            <div class="row">
                                @foreach($smsCredits['bundles'] as $bundle)
                                    <div class="col-sm-6 mb-2" wire:key="bundle-{{ $bundle->id }}">
                                        <div class="border rounded p-2 d-flex justify-content-between align-items-center h-100">
                                            <div>
                                                <div class="font-weight-bold">{{ $bundle->name }}</div>
                                                <div class="small text-muted">{{ number_format($bundle->credits) }} credits · {{ $bundle->currency }} {{ number_format($bundle->price, 2) }}</div>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-outline-primary font-weight-bold"
                                                    wire:click="buyBundle({{ $bundle->id }})" wire:loading.attr="disabled"
                                                    wire:confirm="Request {{ number_format($bundle->credits) }} SMS credits for {{ $bundle->currency }} {{ number_format($bundle->price, 2) }}? An invoice will be issued; credits are added once it is paid.">
                                                Buy
                                            </button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <small class="form-text text-muted mb-2">Pay the invoice as you pay your subscription; credits appear here once payment is confirmed.</small>
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
                                   class="form-control bg-light border-0 text-uppercase @error('senderIdRequest') is-invalid @enderror"
                                   placeholder="e.g. EYECLINIC">
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

            {{-- Spectacle Renewal Reminders --}}
            <div class="card border-0 shadow-sm rounded-lg mb-4">
                <div class="card-header bg-info text-white py-3 border-0">
                    <h5 class="mb-0 font-weight-bold"><i class="fas fa-redo mr-2"></i> Spectacle Renewal Reminders</h5>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">
                        Automatically sends an SMS reminder to patients whose spectacles are due for their annual eye review.
                        The reminder fires daily at 09:00 when a patient's renewal date is within the configured number of days.
                    </p>

                    <form wire:submit="saveRenewalSettings">
                        <div class="form-group d-flex align-items-center justify-content-between">
                            <div>
                                <label class="small font-weight-bold text-muted mb-0">ENABLE RENEWAL REMINDERS</label>
                                <div class="small text-muted">Send SMS when renewal date approaches</div>
                            </div>
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" id="renewalEnabled"
                                       wire:model.live="spectacleRenewalEnabled">
                                <label class="custom-control-label" for="renewalEnabled"></label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="small font-weight-bold text-muted">DAYS BEFORE RENEWAL TO SEND REMINDER</label>
                            <div class="input-group" style="max-width:200px">
                                <input type="number" wire:model="spectacleRenewalReminderDays"
                                       class="form-control bg-light border-0 @error('spectacleRenewalReminderDays') is-invalid @enderror"
                                       min="1" max="90" placeholder="30">
                                <div class="input-group-append">
                                    <span class="input-group-text bg-light border-0">days</span>
                                </div>
                            </div>
                            @error('spectacleRenewalReminderDays')
                                <span class="text-danger small">{{ $message }}</span>
                            @enderror
                            <small class="form-text text-muted">Between 1 and 90 days. Default: 30 days.</small>
                        </div>

                        <button type="submit" class="btn btn-info btn-block py-2 font-weight-bold shadow-sm text-white"
                                wire:loading.attr="disabled" wire:target="saveRenewalSettings">
                            <span wire:loading.remove wire:target="saveRenewalSettings"><i class="fas fa-save mr-2"></i> Save Renewal Settings</span>
                            <span wire:loading wire:target="saveRenewalSettings">Saving…</span>
                        </button>
                    </form>
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
