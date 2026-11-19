<div class="clinic-ui ui-page space-y-4" data-livewire-root>
    <a href="{{ route('admin.users') }}" class="inline-block text-sm">← Back to Users</a>
    <x-ui.page-header title="Access · {{ $user->name }}" description="Their role decides what they can open and do. Switch single things on or off for this person only, in this clinic.">
        @unless($locked)
            <x-ui.button variant="secondary" wire:click="resetToRole"><i class="fas fa-undo" aria-hidden="true"></i>Back to role</x-ui.button>
            <x-ui.button variant="primary" wire:click="save" wire:loading.attr="disabled" wire:target="save"><i class="fas fa-save" aria-hidden="true"></i>Save access</x-ui.button>
        @endunless
    </x-ui.page-header>

    <div class="ui-panel p-4 flex flex-wrap gap-x-8 gap-y-2 text-sm" data-tour="access-summary">
        <div><span class="text-slate-500">Role:</span> <b>{{ $roles }}</b></div>
        <div><span class="text-slate-500">Switched for this person:</span> <b>{{ $switched }}</b></div>
        <div class="text-slate-500">Changing their role keeps these switches.</div>
    </div>

    @if($locked)
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700"><i class="fas fa-lock mr-1"></i> {{ $locked }}</div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-4" data-tour="access-switches">
            @foreach($groups as $group => $permissions)
                <section class="ui-panel overflow-hidden">
                    <h3 class="border-b border-slate-200 bg-slate-50 px-4 py-2 text-sm font-semibold text-slate-800">{{ $group }}</h3>
                    <div class="overflow-x-auto">
                        <table class="ui-table w-full text-sm">
                            <thead>
                                <tr>
                                    <th class="text-left">What</th>
                                    <th class="text-center whitespace-nowrap">From role</th>
                                    <th class="text-left whitespace-nowrap">This person</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($permissions as $key => $p)
                                    @php
                                        $field = \App\Livewire\Admin\PersonAccessComponent::field($key);
                                        $choice = $choices[$field] ?? 'role';
                                        $effective = $choice === 'role' ? $p['fromRole'] : $choice === 'on';
                                    @endphp
                                    <tr wire:key="perm-{{ $key }}" class="{{ $p['plan'] ? '' : 'opacity-60' }}">
                                        <td>
                                            {{ $p['label'] }}
                                            @unless($p['plan'])<span class="ml-1 inline-flex rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">Not in your plan</span>@endunless
                                        </td>
                                        <td class="text-center">
                                            @if($p['fromRole'])<i class="fas fa-check text-teal-700" title="Their role gives this"></i><span class="sr-only">Yes</span>@else<span class="text-slate-400" title="Their role doesn't give this">–</span>@endif
                                        </td>
                                        <td>
                                            <div class="flex items-center gap-2">
                                                <select class="ui-input !w-40" aria-label="{{ $p['label'] }}: this person"
                                                        wire:model.live="choices.{{ $field }}"
                                                        @disabled($locked || ! $p['editable'] || ! $p['plan'])>
                                                    <option value="role">As role ({{ $p['fromRole'] ? 'yes' : 'no' }})</option>
                                                    <option value="on">On for them</option>
                                                    <option value="off">Off for them</option>
                                                </select>
                                                @if($choice !== 'role')
                                                    <span class="inline-flex rounded px-1.5 py-0.5 text-xs font-semibold {{ $effective ? 'bg-teal-100 text-teal-800' : 'bg-red-100 text-red-800' }}">{{ $effective ? 'Allowed' : 'Blocked' }}</span>
                                                @endif
                                                @if(! $locked && $p['plan'] && ! $p['editable'])
                                                    <i class="fas fa-lock text-slate-400" title="Only the Owner can change this"></i>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endforeach
        </div>

        <aside class="space-y-4">
            <section class="ui-panel p-4 text-sm" data-tour="access-preview">
                <h3 class="mb-2 font-semibold text-slate-800"><i class="fas fa-eye mr-1"></i> What they can see</h3>
                <p class="mb-3 text-xs text-slate-500">Their menu with the access saved now (save to update it).</p>
                @forelse($preview as $group => $links)
                    <div class="mb-2">
                        <div class="text-xs font-semibold uppercase text-slate-500">{{ $group }}</div>
                        <div class="text-slate-700">{{ implode(' · ', $links) }}</div>
                    </div>
                @empty
                    <p class="text-slate-500">{{ $locked ? 'Everything.' : 'Only their own profile and attendance.' }}</p>
                @endforelse
            </section>
            <section class="ui-panel p-4 text-xs text-slate-600 space-y-2">
                <p><b>On for them</b> gives this person something their role doesn't. <b>Off for them</b> takes something away. <b>As role</b> follows the role.</p>
                <p>Managers can only switch things they have themselves. Only the Owner can switch approvals. The Owner is emailed whenever someone's access goes up, and every change is in the audit trail.</p>
                <p>Your plan still applies: a switch can't open something your plan doesn't include.</p>
            </section>
        </aside>
    </div>
</div>
