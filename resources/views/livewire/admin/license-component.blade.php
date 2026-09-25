<div class="p-4">
    <h3 class="text-primary font-weight-bold">License &amp; Subscription</h3>
    <p><strong>{{ $clinicName }}</strong> &middot; {{ $hosted ? 'Hosted clinic' : 'Local / offline installation' }}</p>
    <p class="text-muted">Your developer manages your clinic plan and renewal.</p>
    @if($resultMsg)
        <div class="alert {{ $resultType === 'success' ? 'alert-success' : 'alert-danger' }}" role="status">{{ $resultMsg }}</div>
    @endif
    @if($access['read_only'])
        <div class="alert alert-warning">New entries and changes are restricted. Renew your license or subscription to restore write access.</div>
    @elseif(!$hosted && $access['expires'] && now()->addDays(30)->toDateString() >= $access['expires'])
        <div class="alert alert-info">Your {{ $access['status'] === 'trial' ? 'trial' : 'license' }} expires on {{ $access['expires'] }}. Contact the developer for a renewal key.</div>
    @endif
    <div class="row">
        <div class="col-lg-6 mb-3">
            <div class="card shadow-sm h-100"><div class="card-body">
                <h5>Current access</h5>
                <dl>
                    <dt>Plan</dt><dd>{{ $hosted ? ($subscription?->plan?->name ?? 'Not assigned') : $access['plan'] }}</dd>
                    <dt>Product</dt><dd>{{ \App\Support\PlanProduct::label(\App\Support\PlanProduct::productOf($hosted ? ($subscription?->feature_snapshot ?? []) : $access['features'])) }}</dd>
                    <dt>Status</dt><dd>{{ ucfirst($access['status']) }}</dd>
                    <dt>Expiry / period end</dt><dd>{{ $hosted ? ($subscription?->current_period_ends_at?->toDateString() ?? 'Not assigned') : ($access['expires'] ?? 'Not assigned') }}</dd>
                </dl>
                <h6>Included features</h6>
                @php($allFeatures = $hosted ? ($subscription?->feature_snapshot ?? []) : $access['features'])
                @php($features = array_values(array_diff($allFeatures, \App\Support\PlanProduct::PRODUCT_KEYS)))
                @if(in_array('*', $features, true) || ($hosted && $subscription && empty($allFeatures)))
                    <p>All features included.</p>
                @elseif(empty($features))
                    <p>No additional features assigned.</p>
                @else
                    <ul>@foreach($features as $feature)<li>{{ \App\Support\PlanProduct::EXTRAS[$feature][0] ?? ucwords(str_replace('_', ' ', $feature)) }}</li>@endforeach</ul>
                @endif
            </div></div>
        </div>
        <div class="col-lg-6 mb-3">
            <div class="card shadow-sm h-100"><div class="card-body">
                @if($hosted)
                    <h5>Billing and renewal</h5>
                    <p>Your subscription is managed by the developer. View invoices or request a plan change through billing.</p>
                    <a href="{{ route('admin.subscription') }}" class="btn btn-primary">Subscription &amp; Billing</a>
                @else
                    <h5>Activate or renew offline</h5>
                    <p>Send your installation ID to the developer. Paste the signed key supplied for this installation. Internet access is not required.</p>
                    <label for="installation-id">Installation ID</label>
                    <input id="installation-id" class="form-control mb-3" readonly value="{{ $installationId }}">
                    <form wire:submit="activate">
                        <label for="license-key">Developer-issued license key</label>
                        <textarea id="license-key" wire:model="licenseKey" class="form-control mb-3" rows="4" placeholder="EYECLINIC-PRO-..."></textarea>
                        <button class="btn btn-primary" type="submit" wire:loading.attr="disabled">Activate license</button>
                    </form>
                @endif
            </div></div>
        </div>
    </div>
    @if(!$hosted && $activationHistory->isNotEmpty())
        <h5>Activation history</h5>
        <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Activated</th><th>Administrator</th><th>Plan</th><th>Expiry</th><th>Features</th></tr></thead><tbody>
        @foreach($activationHistory as $activation)<tr><td>{{ $activation->created_at->format('d M Y H:i T') }}</td><td>{{ $activation->user?->name }}</td><td>{{ data_get($activation->new_values, 'plan', 'Legacy Pro') }}</td><td>{{ data_get($activation->new_values, 'expires', 'Not recorded') }}</td><td>{{ implode(', ', data_get($activation->new_values, 'features', [])) }}</td></tr>@endforeach
        </tbody></table></div>
    @endif
</div>
