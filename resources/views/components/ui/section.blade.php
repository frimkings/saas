{{--
    A panel with a heading row: title, optional one-line description, an optional step number or icon, and actions
    on the right. The body goes in the default slot; leave padding to the content (tables sit flush).

        <x-ui.section title="Payments taken" description="Recorded under the wrong method? …" step="3">
            <x-slot:actions><x-ui.button>Export</x-ui.button></x-slot:actions>
            <div class="ui-table-wrap">…</div>
        </x-ui.section>

    For an empty list, use <p class="ui-section-empty">No payments on this day.</p> rather than an empty table.
--}}
@props(['title', 'description' => null, 'step' => null, 'icon' => null, 'actions' => null])
@php($id = 'section-'.\Illuminate\Support\Str::slug($title).'-'.substr(md5($title.($step ?? '')), 0, 4))
<section {{ $attributes->class(['ui-panel']) }} aria-labelledby="{{ $id }}">
    <div class="ui-section-heading">
        <div class="flex min-w-0 items-start gap-3">
            @if($step)<span class="ui-step" aria-hidden="true">{{ $step }}</span>@elseif($icon)<i class="fas {{ $icon }} mt-1 text-teal-700" aria-hidden="true"></i>@endif
            <div class="min-w-0">
                <h2 id="{{ $id }}">{{ $title }}</h2>
                @if($description)<p class="ui-muted">{{ $description }}</p>@endif
            </div>
        </div>
        @if($actions)<div class="ui-actions">{{ $actions }}</div>@endif
    </div>
    {{ $slot }}
</section>
