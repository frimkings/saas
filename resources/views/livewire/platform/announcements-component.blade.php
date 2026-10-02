<x-platform.page active="announcements" title="Announcements" subtitle="Email clinic owners and Super Admins, and show a banner to Super Admins in the app until they dismiss it.">
    <style>
        .an-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(320px,1fr);gap:16px;align-items:start}
        .an-checks{display:flex;flex-wrap:wrap;gap:6px 14px}.an-checks label{display:flex;align-items:center;gap:6px;color:#dbe6f5;font-size:12px}
        .an-clinics{max-height:190px;overflow-y:auto;border:1px solid var(--line);border-radius:8px;padding:8px 10px;display:grid;gap:5px;background:#0b1526}
        .an-clinics label{display:flex;align-items:center;gap:7px;color:#dbe6f5;font-size:12px}
        .an-count{background:#0b1526;border:1px solid var(--line);border-radius:10px;padding:12px 14px;line-height:1.6}
        .an-count b{color:#fff;font-size:15px}
        .pp-field textarea{box-sizing:border-box;width:100%;background:#050d1c;border:1px solid #273750;color:#edf5ff;border-radius:8px;padding:9px 11px;font:inherit;min-height:170px;resize:vertical}
        .an-preview{width:100%;height:560px;border:1px solid var(--line);border-radius:10px;background:#f4f6f9}
        .an-toggle{display:flex;align-items:center;gap:8px;color:#dbe6f5}
        /* The shared field style stretches inputs to full width; checkboxes keep their own size. */
        .pp-field .an-checks input[type=checkbox],.pp-field .an-clinics input[type=checkbox],.an-toggle input[type=checkbox]{width:auto;padding:0;flex:none}
        @media(max-width:1100px){.an-grid{grid-template-columns:1fr}}
    </style>

    @if($screen === 'list')
        <div class="pp-card">
            <div class="pp-card-head">
                <div><h2>Sent and draft announcements</h2><p>Emails go out in small batches (a few right away, the rest within minutes).</p></div>
                <button type="button" class="pp-btn" wire:click="create">＋ New announcement</button>
            </div>
            <div class="pp-table-wrap">
                <table class="pp-table">
                    <thead><tr><th>Subject</th><th>Status</th><th>Clinics</th><th>Emails</th><th>Banner</th><th>Sent</th><th></th></tr></thead>
                    <tbody>
                        @forelse($announcements as $a)
                            <tr wire:key="an-{{ $a->id }}">
                                <td><b>{{ $a->subject }}</b><small>{{ \Illuminate\Support\Str::limit($a->heading, 70) }}</small></td>
                                <td>
                                    <span class="pp-badge {{ $a->status === 'draft' ? 'grey' : ($a->status === 'sending' ? 'amber' : '') }}">{{ strtoupper($a->status) }}</span>
                                </td>
                                <td>{{ $a->status === 'draft' ? '—' : $a->clinic_count }}</td>
                                <td>
                                    @if(!$a->send_email)<span class="pp-hint">Off</span>
                                    @elseif($a->status === 'draft')—
                                    @else
                                        {{ $a->sent_count }} sent
                                        @if($a->queued_count)<small class="warn">{{ $a->queued_count }} waiting</small>@endif
                                        @if($a->failed_count)<small class="fail">{{ $a->failed_count }} failed</small>@endif
                                    @endif
                                </td>
                                <td>
                                    @if(!$a->show_banner)<span class="pp-hint">Off</span>
                                    @elseif($a->banner_until && $a->banner_until->lt(today()))<span class="pp-hint">Ended</span>
                                    @else On{{ $a->banner_until ? ' until '.$a->banner_until->format('d M') : '' }}@endif
                                </td>
                                <td>{{ $a->sent_at?->format('d M Y H:i') ?? '—' }}</td>
                                <td><button type="button" class="pp-btn alt sm" wire:click="open({{ $a->id }})">{{ $a->status === 'draft' ? 'Edit' : 'View' }}</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="pp-empty">No announcements yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div style="margin-top:10px">{{ $announcements->links() }}</div>
        </div>

    @elseif($screen === 'edit')
        <div style="margin-bottom:12px"><button type="button" class="pp-btn alt sm" wire:click="backToList">← All announcements</button></div>

        <div class="an-grid">
            <div>
                <div class="pp-card pp-form">
                    <div class="pp-card-head" style="margin:0"><div><h2>Message</h2><p>Write **bold**, start lines with "- " for a list, and use [CLINIC] for the clinic's name.</p></div></div>

                    <label class="pp-field"><span>Email subject</span>
                        <input type="text" wire:model.live.debounce.400ms="form.subject" maxlength="150" placeholder="e.g. Action needed: choose which SMS your patients receive">
                        @error('form.subject')<small class="pp-err">{{ $message }}</small>@enderror
                    </label>
                    <label class="pp-field"><span>Heading (also the banner's title)</span>
                        <input type="text" wire:model.live.debounce.400ms="form.heading" maxlength="150">
                        @error('form.heading')<small class="pp-err">{{ $message }}</small>@enderror
                    </label>
                    <label class="pp-field"><span>Message</span>
                        <textarea wire:model.live.debounce.600ms="form.body" maxlength="10000"></textarea>
                        @error('form.body')<small class="pp-err">{{ $message }}</small>@enderror
                    </label>
                    <div class="pp-row">
                        <label class="pp-field"><span>Button text (optional)</span>
                            <input type="text" wire:model.live.debounce.400ms="form.button_label" maxlength="60">
                            @error('form.button_label')<small class="pp-err">{{ $message }}</small>@enderror
                        </label>
                        <label class="pp-field"><span>Button link</span>
                            <input type="url" wire:model.live.debounce.400ms="form.button_url" maxlength="500">
                            @error('form.button_url')<small class="pp-err">{{ $message }}</small>@enderror
                        </label>
                    </div>
                </div>

                <div class="pp-card pp-form">
                    <div class="pp-card-head" style="margin:0"><div><h2>How it goes out</h2></div></div>
                    <label class="an-toggle"><input type="checkbox" wire:model.live="form.send_email"> Email each clinic's owner and active Super Admins</label>
                    <label class="an-toggle"><input type="checkbox" wire:model.live="form.show_banner"> Show a banner to the clinic's Super Admins in the app until they dismiss it</label>
                    @error('form.send_email')<small class="pp-err">{{ $message }}</small>@enderror
                    @if($form['show_banner'])
                        <label class="pp-field" style="max-width:240px"><span>Take the banner down after (optional)</span>
                            <input type="date" wire:model="form.banner_until" min="{{ today()->toDateString() }}">
                            @error('form.banner_until')<small class="pp-err">{{ $message }}</small>@enderror
                        </label>
                    @endif
                </div>

                <div class="pp-card pp-form">
                    <div class="pp-card-head" style="margin:0"><div><h2>Clinics</h2><p>Leave everything empty to reach every active clinic. Picking clinics by name ignores the filters.</p></div></div>
                    @error('audience')<div class="pp-warn">{{ $message }}</div>@enderror

                    <div class="pp-field"><span>Subscription status</span>
                        <div class="an-checks">
                            @foreach(\App\Services\Platform\Announcements::SUBSCRIPTION_STATUSES as $key => $label)
                                <label><input type="checkbox" value="{{ $key }}" wire:model.live="audience.statuses"> {{ $label }}</label>
                            @endforeach
                        </div>
                    </div>
                    <div class="pp-field"><span>Plan</span>
                        <div class="an-checks">
                            @forelse($plans as $id => $name)
                                <label><input type="checkbox" value="{{ $id }}" wire:model.live="audience.plan_ids"> {{ $name }}</label>
                            @empty <span class="pp-hint">No plans yet.</span> @endforelse
                        </div>
                    </div>
                    <div class="pp-field"><span>Installation</span>
                        <div class="an-checks">
                            <label><input type="checkbox" value="hosted" wire:model.live="audience.modes"> Hosted</label>
                            <label><input type="checkbox" value="offline" wire:model.live="audience.modes"> Offline</label>
                        </div>
                    </div>
                    <div class="pp-field"><span>Or pick clinics</span>
                        <input type="search" wire:model.live.debounce.300ms="clinicSearch" placeholder="Search clinics…">
                        <div class="an-clinics">
                            @forelse($clinics as $clinic)
                                <label wire:key="an-c-{{ $clinic->id }}"><input type="checkbox" value="{{ $clinic->id }}" wire:model.live="audience.clinic_ids"> {{ $clinic->name }} <span class="pp-hint">{{ $clinic->deployment_mode }}</span></label>
                            @empty <span class="pp-hint">No clinic found.</span> @endforelse
                        </div>
                        @if(count($audience['clinic_ids']))<small class="pp-hint">{{ count($audience['clinic_ids']) }} picked — filters above are ignored.</small>@endif
                    </div>

                    <div class="an-count">
                        Goes to <b>{{ $preview['clinics'] }}</b> {{ \Illuminate\Support\Str::plural('clinic', $preview['clinics']) }}
                        @if($form['send_email'])
                            · <b>{{ $preview['owners'] }}</b> owner and <b>{{ $preview['admins'] }}</b> Super Admin {{ \Illuminate\Support\Str::plural('email', $preview['admins']) }}
                            @if($preview['noEmail'])<br><span class="warn">{{ $preview['noEmail'] }} {{ \Illuminate\Support\Str::plural('clinic has', $preview['noEmail']) }} no email{{ $form['show_banner'] ? ' and will only see the banner' : '' }}.</span>@endif
                        @endif
                    </div>
                </div>

                <div class="pp-card">
                    <div class="pp-row" style="align-items:end">
                        <label class="pp-field"><span>Send a test to</span>
                            <input type="email" wire:model="testEmail">
                            @error('testEmail')<small class="pp-err">{{ $message }}</small>@enderror
                        </label>
                        <div class="pp-actions">
                            <button type="button" class="pp-btn alt" wire:click="sendTest" wire:loading.attr="disabled">Send test</button>
                        </div>
                    </div>
                    <div class="pp-foot" style="margin-top:14px">
                        @if($announcementId)
                            <button type="button" class="pp-btn danger sm" wire:click="deleteDraft" wire:confirm="Delete this draft?">Delete draft</button>
                        @endif
                        <button type="button" class="pp-btn alt" wire:click="saveDraft">Save draft</button>
                        <button type="button" class="pp-btn" wire:click="send" wire:loading.attr="disabled"
                                wire:confirm="Send this announcement to {{ $preview['clinics'] }} {{ \Illuminate\Support\Str::plural('clinic', $preview['clinics']) }}? It cannot be unsent.">
                            Send to {{ $preview['clinics'] }} {{ \Illuminate\Support\Str::plural('clinic', $preview['clinics']) }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="pp-card" style="position:sticky;top:16px">
                <div class="pp-card-head"><div><h2>Email preview</h2><p>As {{ $preview['names'][0] ?? 'a clinic' }} will see it.</p></div></div>
                @if($emailHtml)
                    <iframe class="an-preview" srcdoc="{{ $emailHtml }}" title="Email preview" sandbox></iframe>
                @else
                    <p class="pp-hint">Write a heading and message to see the email.</p>
                @endif
            </div>
        </div>

    @else
        <div style="margin-bottom:12px"><button type="button" class="pp-btn alt sm" wire:click="backToList">← All announcements</button></div>

        <div class="pp-card" @if($announcement->status === 'sending') wire:poll.10s @endif>
            <div class="pp-card-head">
                <div>
                    <h2>{{ $announcement->subject }}</h2>
                    <p>{{ $announcement->heading }} · sent {{ $announcement->sent_at?->format('d M Y H:i') }} by {{ $announcement->author?->name ?? 'platform' }}</p>
                </div>
                <div class="pp-actions">
                    @if(($counts['failed'] ?? 0) > 0)
                        <button type="button" class="pp-btn alt sm" wire:click="retryFailed">Retry {{ $counts['failed'] }} failed</button>
                    @endif
                    @if($announcement->show_banner && (!$announcement->banner_until || $announcement->banner_until->gte(today())))
                        <button type="button" class="pp-btn danger sm" wire:click="stopBanner" wire:confirm="Take the banner down for every clinic?">Take banner down</button>
                    @endif
                </div>
            </div>
            <div class="pp-stats" style="grid-template-columns:repeat(4,1fr);margin-bottom:14px">
                <div class="pp-stat"><span>Clinics</span><b>{{ $recipients->pluck('clinic_id')->unique()->count() }}</b></div>
                <div class="pp-stat"><span>Emails sent</span><b class="pass">{{ $counts['sent'] ?? 0 }}</b>@if($counts['queued'] ?? 0)<small>{{ $counts['queued'] }} waiting</small>@endif</div>
                <div class="pp-stat"><span>Failed</span><b class="{{ ($counts['failed'] ?? 0) ? 'fail' : '' }}">{{ $counts['failed'] ?? 0 }}</b></div>
                <div class="pp-stat"><span>Banner</span><b style="font-size:16px">{{ !$announcement->show_banner ? 'Off' : (($announcement->banner_until && $announcement->banner_until->lt(today())) ? 'Ended' : 'On') }}</b></div>
            </div>
            <div class="pp-table-wrap">
                <table class="pp-table">
                    <thead><tr><th>Clinic</th><th>Who</th><th>Email</th><th>Status</th><th>When / why</th></tr></thead>
                    <tbody>
                        @foreach($recipients as $r)
                            <tr wire:key="an-r-{{ $r->id }}">
                                <td>{{ $r->clinic?->name ?? 'Deleted clinic' }}</td>
                                <td>{{ ['owner' => 'Owner', 'super_admin' => 'Super Admin', 'none' => '—'][$r->role] ?? $r->role }}</td>
                                <td>{{ $r->email ?? '—' }}</td>
                                <td><span class="pp-badge {{ ['sent' => '', 'queued' => 'amber', 'failed' => 'red', 'skipped' => 'grey'][$r->status] ?? 'grey' }}">{{ strtoupper($r->status) }}</span></td>
                                <td><small style="margin:0">{{ $r->sent_at?->format('d M H:i') ?? $r->error ?? '' }}</small></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-platform.page>
