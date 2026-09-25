<div class="container-fluid py-4">

    {{-- Gmail App Password modal --}}
    @if($showGmailGuide)
    <div class="modal fade show" style="display:block;background:rgba(0,0,0,.5)" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered" style="max-width:520px">
            <div class="modal-content border-0" style="border-radius:10px;overflow:hidden;box-shadow:0 12px 40px rgba(0,0,0,.25)">

                <div class="modal-header py-3 px-4 border-0" style="background:#ea4335">
                    <div class="d-flex align-items-center">
                        <i class="fab fa-google fa-lg mr-2 text-white"></i>
                        <h5 class="modal-title mb-0 font-weight-bold text-white">Gmail — App Password Required</h5>
                    </div>
                    <button type="button" class="close text-white" wire:click="$set('showGmailGuide',false)" style="opacity:1">
                        <span>&times;</span>
                    </button>
                </div>

                <div class="modal-body px-4 py-3">
                    <div class="alert alert-danger py-2 px-3 small mb-3" style="border-radius:6px">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        <strong>Your regular Gmail password will not work.</strong>
                        Error 535 5.7.8 means Google rejected the login.
                        You need a special <strong>App Password</strong> instead.
                    </div>

                    <p class="font-weight-bold mb-2">How to generate a Gmail App Password:</p>
                    <ol class="small mb-3" style="line-height:1.9">
                        <li>
                            Go to
                            <a href="https://myaccount.google.com/security" target="_blank" rel="noopener">
                                myaccount.google.com/security
                            </a>
                        </li>
                        <li>Enable <strong>2-Step Verification</strong> if it is not already on.</li>
                        <li>On the same page, search for <strong>App Passwords</strong> and click it.</li>
                        <li>Choose <em>Mail</em> as the app and <em>Windows Computer</em> as the device.</li>
                        <li>Click <strong>Generate</strong> — Google shows a 16-character code.</li>
                        <li>Copy that code and paste it into the <strong>Password</strong> field on this page.</li>
                    </ol>

                    <div class="p-2 rounded small text-center font-weight-bold" style="background:#f3f4f6;font-family:monospace;letter-spacing:.05em">
                        smtp.gmail.com &nbsp;·&nbsp; Port 587 &nbsp;·&nbsp; TLS &nbsp;·&nbsp; App Password (16 chars)
                    </div>
                </div>

                <div class="modal-footer border-0 pt-0 px-4 pb-3">
                    <button type="button"
                            wire:click="$set('showGmailGuide',false)"
                            class="btn btn-primary btn-block font-weight-bold">
                        <i class="fas fa-check mr-1"></i> Got it — I'll generate an App Password
                    </button>
                </div>

            </div>
        </div>
    </div>
    @endif

    {{-- Header --}}
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h2 class="mb-1"><i class="fas fa-mail-bulk mr-2 text-primary"></i>Mail Settings</h2>
            <p class="text-muted mb-0">Configure the SMTP server used to send automated emails and financial reports.</p>
        </div>
        <button type="button"
                wire:click="save"
                wire:loading.attr="disabled"
                wire:target="save"
                class="btn btn-primary font-weight-bold shadow-sm">
            <span wire:loading.remove wire:target="save"><i class="fas fa-save mr-1"></i> Save Settings</span>
            <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin mr-1"></i> Saving…</span>
        </button>
    </div>

    <div class="row">

        {{-- Left column: SMTP config --}}
        <div class="col-lg-7 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-white py-3 font-weight-bold">
                    <i class="fas fa-server mr-1 text-primary"></i> SMTP Configuration
                </div>
                <div class="card-body">

                    {{-- Provider presets --}}
                    <div class="form-group">
                        <label class="small font-weight-bold text-muted text-uppercase" style="letter-spacing:.05em">
                            Quick Presets
                        </label>
                        <div class="d-flex flex-wrap" style="gap:.5rem">
                            <button type="button" wire:click="applyPreset('gmail')"
                                    class="btn btn-sm btn-outline-secondary">
                                <i class="fab fa-google mr-1" style="color:#ea4335"></i> Gmail
                            </button>
                            <button type="button" wire:click="applyPreset('outlook')"
                                    class="btn btn-sm btn-outline-secondary">
                                <i class="fab fa-microsoft mr-1" style="color:#0078d4"></i> Outlook / Office 365
                            </button>
                            <button type="button" wire:click="applyPreset('yahoo')"
                                    class="btn btn-sm btn-outline-secondary">
                                <i class="fab fa-yahoo mr-1" style="color:#6001d2"></i> Yahoo
                            </button>
                        </div>
                        <small class="text-muted d-block mt-1">
                            Presets fill in the host, port, and encryption. You still need to enter your username and password.
                        </small>
                    </div>

                    <hr class="mt-2 mb-4">

                    {{-- Host + Port --}}
                    <div class="row">
                        <div class="col-sm-8">
                            <div class="form-group">
                                <label class="small font-weight-bold">SMTP Host</label>
                                <input type="text"
                                       wire:model="smtpHost"
                                       class="form-control @error('smtpHost') is-invalid @enderror"
                                       placeholder="e.g. smtp.gmail.com">
                                @error('smtpHost')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="form-group">
                                <label class="small font-weight-bold">Port</label>
                                <input type="number"
                                       wire:model="smtpPort"
                                       class="form-control @error('smtpPort') is-invalid @enderror"
                                       min="1" max="65535">
                                @error('smtpPort')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    {{-- Encryption --}}
                    <div class="form-group">
                        <label class="small font-weight-bold">Encryption</label>
                        <div class="d-flex" style="gap:.5rem">
                            @foreach(['tls' => 'TLS (recommended)', 'ssl' => 'SSL', 'none' => 'None'] as $val => $label)
                                <label class="mb-0 flex-fill" style="cursor:pointer">
                                    <div class="p-2 rounded border text-center small {{ $smtpEncryption === $val ? 'border-primary bg-primary text-white' : 'bg-light' }}"
                                         wire:click="$set('smtpEncryption','{{ $val }}')"
                                         style="cursor:pointer;transition:all .15s">
                                        {{ $label }}
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    {{-- Username --}}
                    <div class="form-group">
                        <label class="small font-weight-bold">Username / Email</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fas fa-at text-muted"></i></span>
                            </div>
                            <input type="email"
                                   wire:model="smtpUsername"
                                   class="form-control @error('smtpUsername') is-invalid @enderror"
                                   placeholder="your@email.com"
                                   autocomplete="off">
                            @error('smtpUsername')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    {{-- Password --}}
                    <div class="form-group">
                        <label class="small font-weight-bold">
                            Password / App Password
                            @if($smtpHost)
                                <span class="text-muted font-weight-normal">(leave blank to keep current)</span>
                            @endif
                        </label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fas fa-lock text-muted"></i></span>
                            </div>
                            <input type="password"
                                   wire:model="smtpPassword"
                                   class="form-control @error('smtpPassword') is-invalid @enderror"
                                   placeholder="••••••••••••"
                                   autocomplete="new-password">
                            @error('smtpPassword')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <small class="text-muted">Stored encrypted. Never visible after saving.</small>
                    </div>

                    <hr class="my-4">

                    {{-- From address & name --}}
                    <div class="row">
                        <div class="col-sm-7">
                            <div class="form-group mb-0">
                                <label class="small font-weight-bold">From Address</label>
                                <input type="email"
                                       wire:model="fromAddress"
                                       class="form-control @error('fromAddress') is-invalid @enderror"
                                       placeholder="noreply@myclinic.com">
                                @error('fromAddress')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-sm-5">
                            <div class="form-group mb-0">
                                <label class="small font-weight-bold">From Name</label>
                                <input type="text"
                                       wire:model="fromName"
                                       class="form-control @error('fromName') is-invalid @enderror"
                                       placeholder="My Eye Clinic">
                                @error('fromName')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        {{-- Right column: Test + Help --}}
        <div class="col-lg-5 mb-4">

            {{-- Test email --}}
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3 font-weight-bold">
                    <i class="fas fa-vial mr-1 text-success"></i> Test Connection
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Sends a real email using the settings on the left (current form values, no save required).
                    </p>
                    <div class="form-group">
                        <label class="small font-weight-bold">Send test to</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fas fa-paper-plane text-muted"></i></span>
                            </div>
                            <input type="email"
                                   wire:model="testRecipient"
                                   class="form-control @error('testRecipient') is-invalid @enderror"
                                   placeholder="your@email.com">
                            @error('testRecipient')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <button type="button"
                            wire:click="sendTest"
                            wire:loading.attr="disabled"
                            wire:target="sendTest"
                            class="btn btn-success btn-block font-weight-bold">
                        <span wire:loading.remove wire:target="sendTest">
                            <i class="fas fa-paper-plane mr-1"></i> Send Test Email
                        </span>
                        <span wire:loading wire:target="sendTest">
                            <i class="fas fa-spinner fa-spin mr-1"></i> Sending…
                        </span>
                    </button>
                </div>
            </div>

            {{-- Quick reference card --}}
            <div class="card shadow-sm border-0">
                <div class="card-body py-3">
                    <p class="font-weight-bold mb-2 small text-muted text-uppercase" style="letter-spacing:.05em">Common SMTP settings</p>
                    <table class="table table-sm table-borderless small mb-0">
                        <thead class="thead-light"><tr><th>Provider</th><th>Host</th><th>Port</th></tr></thead>
                        <tbody>
                            <tr><td><i class="fab fa-google mr-1" style="color:#ea4335"></i>Gmail</td><td><code>smtp.gmail.com</code></td><td>587 TLS</td></tr>
                            <tr><td><i class="fab fa-microsoft mr-1" style="color:#0078d4"></i>Outlook</td><td><code>smtp.office365.com</code></td><td>587 TLS</td></tr>
                            <tr><td><i class="fab fa-yahoo mr-1" style="color:#6001d2"></i>Yahoo</td><td><code>smtp.mail.yahoo.com</code></td><td>587 TLS</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

</div>
