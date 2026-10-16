{{--
    A guided tour of the page (App\Support\Guides\TourCatalog, resources/js/guide-tour.js): a "Tour" button
    that walks the person round the page, started by itself the first time they come. Mark what a step
    lights up with data-tour="name".

        <x-guide-tour page="attendance" :context="['manager' => $manager]" />

    :auto="false" never starts it by itself (the page is empty, say); the button still does.
--}}
@props(['page', 'context' => [], 'auto' => true])
@php
    $tour = auth()->check() ? \App\Support\Guides\TourCatalog::tour($page, $context) : null;
@endphp
@if($tour && $tour['steps'])
    @php
        $tourConfig = [
            'steps' => $tour['steps'],
            'auto' => $auto && ! \App\Support\Guides\Guides::seen($tour['key'], $tour['version']),
            'url' => route('guides.record', $tour['key']),
            'csrf' => csrf_token(),
        ];
    @endphp
    @once
        <style>
            .gt-root{position:fixed;inset:0;z-index:1080}
            .gt-root.gt-dim{background:rgba(15,23,42,.55)}
            .gt-hole{position:fixed;border-radius:10px;box-shadow:0 0 0 9999px rgba(15,23,42,.55);outline:2px solid #2dd4bf;pointer-events:none}
            .gt-bubble{position:fixed;background:#fff;border-radius:12px;box-shadow:0 20px 50px rgba(15,23,42,.35);padding:16px 18px;color:#1e293b;font-size:14px;line-height:1.5}
            .gt-bubble.gt-sheet{left:0;right:0;bottom:0;top:auto;border-radius:16px 16px 0 0;padding:18px 16px calc(16px + env(safe-area-inset-bottom))}
            .gt-bubble h2{margin:0 0 6px;font-size:16px;font-weight:700;color:#0f172a}
            .gt-bubble p{margin:0}
            .gt-actions{display:flex;align-items:center;gap:8px;margin-top:14px}
            .gt-actions .gt-gap{flex:1}
            .gt-actions button{border-radius:6px;padding:6px 14px;font-size:13px;font-weight:600;cursor:pointer}
            .gt-skip{border:0;background:none;color:#64748b;padding-left:0!important}
            .gt-back{border:1px solid #cbd5e1;background:#fff;color:#334155}
            .gt-next{border:0;background:#0f766e;color:#fff}
        </style>
    @endonce
    <span wire:ignore x-data="guideTour(@js($tourConfig))" style="display:contents">
        <button type="button" data-tour="tour-replay" x-on:click="start()" title="Take a tour of this page"
                {{ $attributes->merge(['class' => 'ui-button ui-button-secondary']) }}><i class="fas fa-question-circle" aria-hidden="true"></i>Tour</button>
        <template x-teleport="body">
            <div class="gt-root" x-show="open" style="display:none" x-bind:class="hole ? '' : 'gt-dim'" x-on:keydown.escape.window="open && skip()">
                <div class="gt-hole" x-show="hole" x-bind:style="hole"></div>
                <div class="gt-bubble" x-bind:class="mobile ? 'gt-sheet' : ''" x-bind:style="bubbleStyle" role="dialog" aria-modal="true" aria-labelledby="gt-title" aria-describedby="gt-body">
                    <h2 id="gt-title" x-text="step.title"></h2>
                    <p id="gt-body" x-text="step.body"></p>
                    <div class="gt-actions">
                        <button type="button" class="gt-skip" x-show="! last" x-on:click="skip()">Skip tour</button>
                        <span class="gt-gap"></span>
                        <button type="button" class="gt-back" x-show="! first" x-on:click="back()">Back</button>
                        <button type="button" class="gt-next" x-on:click="next()" x-text="last ? 'Done' : 'Next'"></button>
                    </div>
                </div>
            </div>
        </template>
    </span>
@endif
