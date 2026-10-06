<div class="clinic-ui ui-page msg-page">
    <style>
        .msg-page{max-width:980px;margin:0 auto}
        .msg-summary{display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;justify-content:space-between}
        .msg-row{display:flex;align-items:center;gap:.75rem;padding:.7rem 1rem;border-top:1px solid #eef1f5}
        .msg-row:first-child{border-top:0}
        .msg-icon{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#fff;font-size:14px}
        .msg-name{font-weight:600;line-height:1.2}
        .msg-when{font-size:.8rem;color:#6c757d}
        .msg-editor{background:#f8fafc;border-top:1px solid #eef1f5;padding:1rem}
        .msg-preview{background:#fff;border:1px solid #e3e8ef;border-radius:12px 12px 12px 2px;padding:.6rem .8rem;font-size:.9rem;white-space:pre-wrap}
        .msg-chip{cursor:pointer}
        .badge-purple{background:#6f42c1;color:#fff}
        .bg-purple{background:#6f42c1!important}
        @media (max-width:575px){.msg-row{flex-wrap:wrap}.msg-actions{width:100%;justify-content:flex-end}}
    </style>

    {{-- Header --}}
    <div class="mb-4">
        <p class="text-slate-500 text-sm uppercase font-semibold mb-0">Communications</p>
        <h2 class="text-teal-700 font-semibold mb-1">Messages</h2>
        <p class="text-slate-500 text-sm mb-0">Choose which SMS your patients get and how each one reads. Every message starts switched off, since each SMS uses your credits.</p>
    </div>

    {{-- Summary bar --}}
    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white border-0 shadow-sm mb-4">
        <div class="card-body p-4 py-4 msg-summary">
            <div class="flex flex-wrap items-center" style="gap:.5rem 1.25rem">
                @if($availability['available'])
                    <span class="text-sm"><i class="fas fa-check-circle text-green-700 mr-1"></i>
                        SMS sending is on{{ $availability['credits'] !== null ? ' · ' . number_format($availability['credits']) . ' credits left' : '' }}</span>
                @else
                    <span class="text-sm text-red-700"><i class="fas fa-exclamation-circle mr-1"></i>{{ $availability['reason'] }}
                        <a href="{{ route('admin.sms-settings') }}" class="ml-1">Fix it</a></span>
                @endif
                <span class="text-sm"><strong>{{ $onCount }}</strong> of {{ $automaticCount }} automatic messages on</span>
            </div>
            <button type="button" wire:click="turnOnRecommended" wire:loading.attr="disabled" wire:target="turnOnRecommended"
                    class="btn ui-button ui-button-sm {{ $onCount === 0 ? 'ui-button-primary' : 'ui-button-secondary' }} font-semibold"
                    title="{{ implode(', ', array_map(fn ($k) => $templates[$k]['label'] ?? $k, array_filter(\App\Support\Messaging\MessageCatalog::RECOMMENDED, fn ($k) => isset($templates[$k])))) }}">
                <i class="fas fa-magic mr-1"></i> Turn on recommended
            </button>
        </div>
        <div class="border-t border-slate-200 px-4 py-2 bg-white border-0 pt-0 pb-4 flex flex-wrap items-center" style="gap:.5rem">
            <div class="flex items-stretch" style="max-width:280px">
                <div class="flex"><span class="flex items-center border border-slate-300 px-2 text-sm text-slate-600 bg-slate-50 border-0"><i class="fas fa-search"></i></span></div>
                <input type="search" wire:model.live.debounce.300ms="search" class="form-control ui-input bg-slate-50 border-0" placeholder="Search messages" aria-label="Search messages">
            </div>
            <div class="inline-flex flex-wrap gap-1" role="group" aria-label="Show">
                @foreach(['all' => 'All', 'on' => 'On', 'off' => 'Off'] as $value => $label)
                    <button type="button" wire:click="$set('show', '{{ $value }}')" class="btn ui-button {{ $show === $value ? 'ui-button-secondary' : 'ui-button-secondary' }}">{{ $label }}</button>
                @endforeach
            </div>
        </div>
    </div>

    @forelse($groups as $groupKey => $messages)
        @php
            [$groupLabel, $groupIcon] = \App\Support\Messaging\MessageCatalog::GROUPS[$groupKey];
            $groupAutomatic = collect($messages)->reject(fn ($m) => $m['staff']);
        @endphp
        <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white border-0 shadow-sm mb-4" x-data="{ open: true }" wire:key="group-{{ $groupKey }}">
            <button type="button" class="card-header border-b border-slate-200 px-4 py-2 bg-white border-0 flex items-center justify-between w-full text-left" @click="open = !open" :aria-expanded="open">
                <span class="font-semibold"><i class="{{ $groupIcon }} text-teal-700 mr-2"></i>{{ $groupLabel }}</span>
                <span class="text-sm text-slate-500">
                    @if($groupAutomatic->isNotEmpty()) {{ $groupAutomatic->where('is_enabled', true)->count() }} of {{ $groupAutomatic->count() }} on @endif
                    <i class="fas ml-2" :class="open ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                </span>
            </button>
            <div x-show="open" x-collapse>
                @foreach($messages as $key => $msg)
                    <div wire:key="msg-{{ $key }}">
                        <div class="msg-row">
                            <span class="msg-icon bg-{{ $msg['colour'] }}"><i class="{{ $msg['icon'] }}"></i></span>
                            <div class="grow min-w-0">
                                <div class="msg-name">{{ $msg['label'] }} @if($msg['pro'])<span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-50 text-slate-600 border border-slate-200 ml-1">Pro</span>@endif</div>
                                <div class="msg-when">{{ $msg['when'] }}</div>
                            </div>
                            <div class="msg-actions flex items-center" style="gap:.5rem">
                                @if($msg['staff'])
                                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-50 border border-slate-200 text-slate-500">Sent by staff</span>
                                @else
                                    <div class="flex items-center gap-2" title="Send this SMS to patients">
                                        <input type="checkbox" class="rounded border-slate-300 text-teal-700" id="sms-on-{{ $key }}" wire:click="toggleEnabled('{{ $key }}')" @checked($msg['is_enabled'])>
                                        <label class=" text-sm font-semibold {{ $msg['is_enabled'] ? 'text-green-700' : 'text-slate-500' }}" for="sms-on-{{ $key }}">{{ $msg['is_enabled'] ? 'On' : 'Off' }}</label>
                                    </div>
                                @endif
                                <button type="button" wire:click="edit('{{ $key }}')" class="btn ui-button ui-button-sm {{ $editing === $key ? 'ui-button-secondary' : 'ui-button-secondary' }}">
                                    {{ $editing === $key ? 'Close' : 'Edit' }}
                                </button>
                            </div>
                        </div>

                        @if($editing === $key)
                            <div class="msg-editor"
                                 x-data="{
                                    text: $wire.entangle('templates.{{ $key }}.message'),
                                    samples: @js($samples),
                                    get preview() { let t = this.text || ''; for (const [k, v] of Object.entries(this.samples)) t = t.split(k).join(v); return t.replace(/\[[A-Z_]+\]/g, '…'); },
                                    get unicode() { return /[^\n\r A-Za-z0-9@£$¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ!&quot;#¤%&'()*+,\-.\/:;<=>?¡ÄÖÑÜ§¿äöñüà^{}\\\[~\]|€]/.test(this.preview); },
                                    get parts() { const n = this.preview.length, one = this.unicode ? 70 : 160, many = this.unicode ? 67 : 153; return n <= one ? 1 : Math.ceil(n / many); },
                                    insert(ph) { const el = this.$refs.box; const s = el.selectionStart ?? this.text.length; this.text = this.text.slice(0, s) + ph + this.text.slice(el.selectionEnd ?? s); this.$nextTick(() => { el.focus(); el.selectionStart = el.selectionEnd = s + ph.length; }); }
                                 }">
                                <div class="flex flex-wrap -mx-2">
                                    <div class="w-full md:w-7/12 px-2 mb-4 md:mb-0">
                                        <label class="text-sm font-semibold text-slate-500 mb-1" for="tpl-{{ $key }}">Wording</label>
                                        <textarea id="tpl-{{ $key }}" x-ref="box" x-model="text" rows="4" maxlength="1000"
                                                  class="form-control ui-input bg-white @error('templates.'.$key.'.message') is-invalid @enderror"></textarea>
                                        @error('templates.'.$key.'.message') <span class="text-red-700 text-sm block mt-1">{{ $message }}</span> @enderror
                                        <div class="mt-2">
                                            <span class="text-sm font-semibold text-slate-500 mr-1">Insert:</span>
                                            @foreach($msg['placeholders'] as $ph)
                                                <code class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-50 text-slate-600 border border-slate-200 mr-1 mb-1 text-sm msg-chip" role="button" @click="insert('{{ $ph }}')">{{ $ph }}</code>
                                            @endforeach
                                        </div>
                                        @php $unsetLinks = \App\Support\Messaging\ClinicLinks::missingIn($msg['message'] ?? ''); @endphp
                                        @if($unsetLinks)
                                            <small class="text-amber-600 block mt-1">
                                                <i class="fas fa-exclamation-triangle"></i> Not sent until {{ implode(', ', $unsetLinks) }} {{ count($unsetLinks) === 1 ? 'is' : 'are' }} set in
                                                <a href="{{ route('admin.settings', ['tab' => 'links']) }}">Settings &rarr; Clinic Links</a>.
                                            </small>
                                        @endif
                                    </div>
                                    <div class="w-full md:w-5/12 px-2">
                                        <label class="text-sm font-semibold text-slate-500 mb-1">Preview <span class="font-normal">(sample patient)</span></label>
                                        <div class="msg-preview" x-text="preview"></div>
                                        <div class="text-sm mt-1" :class="parts > 1 ? 'text-amber-600' : 'text-slate-500'">
                                            <span x-text="preview.length"></span> characters ·
                                            <strong x-text="parts"></strong> <span x-text="parts === 1 ? 'SMS credit' : 'SMS credits'"></span> per patient
                                            <span x-show="unicode"> · contains special characters (70 per SMS)</span>
                                        </div>
                                    </div>
                                </div>

                                @include('livewire.admin.partials.message-options', ['key' => $key])

                                <div class="flex flex-wrap justify-between items-center mt-4" style="gap:.5rem">
                                    <div class="flex items-stretch" style="max-width:300px">
                                        <input type="tel" wire:model="testPhone" class="form-control ui-input @error('testPhone') is-invalid @enderror" placeholder="Phone for a test SMS" aria-label="Phone for a test SMS">
                                        <div class="flex">
                                            <button type="button" class="btn ui-button ui-button-secondary" wire:click="sendTest('{{ $key }}')" wire:loading.attr="disabled" wire:target="sendTest">Send test</button>
                                        </div>
                                    </div>
                                    <div class="flex" style="gap:.5rem">
                                        <button type="button" wire:click="resetToDefault('{{ $key }}')" class="btn ui-button ui-button-sm ui-button-link text-slate-500">Reset to default wording</button>
                                        <button type="button" wire:click="discardChanges('{{ $key }}')" class="btn ui-button ui-button-sm ui-button-secondary"><i class="fas fa-undo mr-1"></i> Discard</button>
                                        <button type="button" wire:click="save('{{ $key }}')" wire:loading.attr="disabled" wire:target="save" class="btn ui-button ui-button-sm ui-button-primary font-semibold"><i class="fas fa-save mr-1"></i> Save</button>
                                    </div>
                                </div>
                                @error('testPhone') <span class="text-red-700 text-sm block mt-1">{{ $message }}</span> @enderror
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white border-0 shadow-sm"><div class="card-body p-4 text-center text-slate-500 text-sm">No messages match.</div></div>
    @endforelse

    @if(!$campaigns)
        <div class="rounded-lg border px-3 py-2 text-sm bg-white text-slate-700 border-slate-200 mb-0">
            <i class="fas fa-star text-amber-600 mr-1"></i>
            Automatic appointment reminders, missed-appointment follow-ups, aftercare, recalls, birthday wishes, feedback requests and broadcasts come with <strong>SMS reminders &amp; campaigns</strong>. Ask us to add it to your plan.
        </div>
    @endif
</div>
