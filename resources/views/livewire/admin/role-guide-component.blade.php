<div class="clinic-ui ui-page space-y-4" data-livewire-root>
    <x-ui.page-header title="What each role can do" description="Roles are set by the Nexus team and are the same in every clinic. To change one person's access, open them under Users and use the shield button (Access)." />

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3" data-tour="role-guide-cards">
        @foreach($labels as $role => $label)
            <section class="ui-panel p-4 text-sm">
                <h3 class="mb-1 font-semibold text-slate-900">{{ $label }}</h3>
                <p class="text-slate-600">{{ \App\Support\Access\RoleGuide::about($role) }}</p>
            </section>
        @endforeach
    </div>

    <section class="ui-panel overflow-hidden" data-tour="role-guide-table">
        <div class="flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3">
            <div>
                <h3 class="text-sm font-semibold text-slate-900">In detail</h3>
                <p class="text-xs text-slate-500">✓ = the role gives it. Your plan still decides which pages exist (optical rows only matter on optical plans).</p>
            </div>
            <label class="ui-field !w-auto">
                <span class="text-xs">Show</span>
                <select class="ui-input !w-48" wire:model.live="role">
                    <option value="">Every role</option>
                    @foreach($labels as $role => $label)<option value="{{ $role }}">{{ $label }}</option>@endforeach
                </select>
            </label>
        </div>
        <div class="overflow-x-auto">
            <table class="ui-table w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left">What</th>
                        @foreach($shown as $label)<th class="text-center whitespace-nowrap">{{ $label }}</th>@endforeach
                    </tr>
                </thead>
                <tbody>
                    @php
                        // One role picked: list only what it gives, so the answer isn't lost among dashes.
                        $only = count($shown) === 1 ? array_key_first($shown) : null;
                    @endphp
                    @foreach($matrix as $group => $rows)
                        @php if ($only) $rows = array_filter($rows, fn ($row) => $row['roles'][$only]); @endphp
                        @continue($rows === [])
                        <tr><td colspan="{{ count($shown) + 1 }}" class="bg-slate-50 text-xs font-semibold uppercase text-slate-500">{{ $group }}</td></tr>
                        @foreach($rows as $row)
                            <tr>
                                <td>{{ $row['label'] }}</td>
                                @foreach(array_keys($shown) as $role)
                                    <td class="text-center">@if($row['roles'][$role])<i class="fas fa-check text-teal-700" aria-hidden="true"></i><span class="sr-only">Yes</span>@else<span class="text-slate-300" aria-hidden="true">–</span><span class="sr-only">No</span>@endif</td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                    @if($only)
                        <tr><td colspan="2" class="text-xs text-slate-500">Everything else is not part of this role. Pick "Every role" to compare.</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
    </section>
</div>
