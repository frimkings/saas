@props([
    'from',                    // Livewire property for the start date (YYYY-MM-DD)
    'to',                      // Livewire property for the end date
    'presets' => 'activity',   // activity | finance | upcoming
    'max' => null,             // 'today' or YYYY-MM-DD; activity filters default to today
    'min' => null,
    'clearable' => false,      // offer "Any date" (both properties set to '')
    'placeholder' => 'Any date',
    'label' => null,           // optional visible label above the button
    'theme' => 'light',        // light | dark (platform pages)
    'align' => 'left',         // which edge of the button the panel lines up with
])
@php
    // "Today" is the clinic's day, not the server's or the browser's.
    $tz = app(\App\Support\Tenancy\TenantContext::class)->clinic()?->default_timezone ?: config('app.timezone');
    $today = now($tz)->toDateString();
    $sets = [
        'activity' => ['today' => 'Today', 'yesterday' => 'Yesterday', 'last3' => 'Last 3 days', 'last7' => 'Last 7 days', 'last15' => 'Last 15 days', 'last30' => 'Last 30 days'],
        'finance' => ['today' => 'Today', 'this_week' => 'This week', 'this_month' => 'This month', 'last_month' => 'Last month', 'this_quarter' => 'This quarter', 'ytd' => 'Year to date', 'last_year' => 'Last year'],
        'upcoming' => ['today' => 'Today', 'tomorrow' => 'Tomorrow', 'next7' => 'Next 7 days', 'next30' => 'Next 30 days', 'this_month_full' => 'This month'],
    ];
    $presetList = collect($sets[$presets] ?? $sets['activity'])->map(fn ($l, $k) => [$k, $l])->values()->all();
    // Activity filters can't look past today; max="none" lifts that (e.g. pickup dates).
    $max = $max === 'none' ? null : ($max ?? ($presets === 'activity' ? 'today' : null));
    $config = [
        'from' => $from, 'to' => $to, 'presets' => $presetList, 'today' => $today,
        'max' => $max === 'today' ? $today : $max, 'min' => $min === 'today' ? $today : $min,
        'clearable' => (bool) $clearable, 'placeholder' => $placeholder, 'align' => $align === 'right' ? 'right' : 'left',
    ];
    $id = 'drp-'.\Illuminate\Support\Str::random(6);
@endphp
@once
<style>
[x-cloak]{display:none!important}
.drp{--drp-bg:#fff;--drp-text:#18353d;--drp-muted:#8a9aa0;--drp-line:#dce6e9;--drp-hover:#eef5f6;--drp-band:#dff1ef;--drp-band-text:#0f5f63;--drp-edge:#087e83;--drp-edge-text:#fff;--drp-shadow:0 18px 40px rgb(15 23 42 / .16);position:relative;display:inline-flex;flex-direction:column;gap:4px;font:inherit;min-width:0}
.drp--dark{--drp-bg:#101b2e;--drp-text:#e8eef8;--drp-muted:#6f819c;--drp-line:#273750;--drp-hover:#16304a;--drp-band:#1b2a40;--drp-band-text:#e8eef8;--drp-edge:#f8fafc;--drp-edge-text:#0b1526;--drp-shadow:0 18px 40px rgb(0 0 0 / .45)}
.drp-label{font-size:11px;font-weight:700;color:var(--drp-muted)}
.drp-trigger{display:inline-flex;align-items:center;justify-content:space-between;gap:10px;min-width:210px;box-sizing:border-box;background:var(--drp-bg);color:var(--drp-text);border:1px solid var(--drp-line);border-radius:9px;padding:8px 12px;font:inherit;font-size:13px;cursor:pointer;text-align:left;white-space:nowrap}
.drp--dark .drp-trigger{background:#050d1c}
.drp-trigger:hover{border-color:var(--drp-edge)}.drp-trigger:focus-visible{outline:2px solid var(--drp-edge);outline-offset:1px}
.drp-trigger svg{flex:none;opacity:.7;transition:transform .15s}.drp-trigger[aria-expanded="true"] svg{transform:rotate(180deg)}
.drp-trigger .is-placeholder{color:var(--drp-muted)}
.drp-panel{position:fixed;top:0;left:0;z-index:1100;display:flex;background:var(--drp-bg);color:var(--drp-text);border:1px solid var(--drp-line);border-radius:14px;box-shadow:var(--drp-shadow);padding:12px;gap:8px}
.drp-presets{display:flex;flex-direction:column;gap:2px;min-width:132px;padding-right:8px;border-right:1px solid var(--drp-line)}
.drp-presets button{background:none;border:0;color:var(--drp-text);text-align:left;padding:8px 10px;border-radius:8px;font:inherit;font-size:13px;cursor:pointer;white-space:nowrap}
.drp-presets button:hover,.drp-presets button:focus-visible{background:var(--drp-hover);outline:none}.drp-presets button.is-active{background:var(--drp-band);color:var(--drp-band-text);font-weight:700}
.drp-presets .drp-clear{margin-top:auto;color:var(--drp-muted)}
.drp-cal{width:252px}
.drp-head{display:flex;align-items:center;justify-content:space-between;margin:2px 0 8px}.drp-head b{font-size:13.5px}
.drp-nav{background:none;border:0;color:var(--drp-muted);width:30px;height:30px;border-radius:8px;cursor:pointer;font-size:18px;line-height:1}.drp-nav:hover{background:var(--drp-hover);color:var(--drp-text)}
.drp-grid{display:grid;grid-template-columns:repeat(7,36px);row-gap:4px}
.drp-dow{font-size:11px;font-weight:700;color:var(--drp-muted);text-align:center;padding:4px 0}
.drp-day{position:relative;height:34px;border:0;background:none;color:var(--drp-text);font:inherit;font-size:12.5px;font-weight:600;cursor:pointer;font-variant-numeric:tabular-nums}
.drp-day:focus-visible{outline:2px solid var(--drp-edge);outline-offset:-2px;border-radius:8px;z-index:2}
.drp-day:hover:not(.is-disabled):not(.is-start):not(.is-end){background:var(--drp-hover);border-radius:8px}
.drp-day.is-muted{color:var(--drp-muted);opacity:.55}
.drp-day.is-disabled{color:var(--drp-muted);opacity:.35;cursor:not-allowed}
.drp-day.in-range{background:var(--drp-band);color:var(--drp-band-text)}
.drp-day.row-start{border-top-left-radius:9px;border-bottom-left-radius:9px}.drp-day.row-end{border-top-right-radius:9px;border-bottom-right-radius:9px}
.drp-day.is-start,.drp-day.is-end{background:var(--drp-edge);color:var(--drp-edge-text);z-index:1}
.drp-day.is-start{border-radius:9px 0 0 9px}.drp-day.is-end{border-radius:0 9px 9px 0}.drp-day.is-single,.drp-day.is-start.is-end{border-radius:9px}
.drp-day.is-today::after{content:'';position:absolute;left:50%;bottom:4px;width:4px;height:4px;margin-left:-2px;border-radius:50%;background:currentColor;opacity:.7}
.drp-hint{margin:8px 0 0;font-size:11px;color:var(--drp-muted);min-height:14px}
@media(max-width:600px){
  .drp-panel{position:fixed;left:0!important;right:0!important;top:auto!important;bottom:0;flex-direction:column;border-radius:16px 16px 0 0;padding:14px 16px 18px}
  .drp-presets{flex-direction:row;overflow-x:auto;border-right:0;border-bottom:1px solid var(--drp-line);padding:0 0 8px}
  .drp-presets .drp-clear{margin-top:0}
  .drp-cal{width:auto}.drp-grid{grid-template-columns:repeat(7,1fr)}
}
@media(prefers-reduced-motion:reduce){.drp-trigger svg{transition:none}}
</style>
@endonce
<div wire:ignore {{ $attributes->class(['drp', 'drp--dark' => $theme === 'dark']) }}
     x-data="dateRange(@js($config))" x-on:keydown.escape.window="open && close()" x-on:click.outside="open && close(false)"
     x-on:resize.window="place()" x-on:scroll.window.capture.passive="place()">
    @if($label)<span class="drp-label" id="{{ $id }}-label">{{ $label }}</span>@endif
    <button type="button" class="drp-trigger" x-ref="trigger" x-on:click="toggle()" aria-haspopup="dialog" :aria-expanded="open.toString()"
            @if($label) aria-labelledby="{{ $id }}-label {{ $id }}-value" @else aria-label="Date range" @endif>
        <span id="{{ $id }}-value" :class="{ 'is-placeholder': !from }" x-text="label()">{{ $placeholder }}</span>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
    </button>

    <div class="drp-panel" x-ref="panel" :style="panelStyle" x-show="open" x-cloak x-transition.opacity.duration.100ms role="dialog" aria-label="Choose a date range">
        <div class="drp-presets" role="group" aria-label="Quick ranges">
            <template x-for="[key, text] in presets" :key="key">
                <button type="button" x-on:click="choosePreset(key)" :class="{ 'is-active': activePreset() === key }" x-text="text"></button>
            </template>
            <template x-if="clearable">
                <button type="button" class="drp-clear" x-on:click="clear()">Any date</button>
            </template>
        </div>
        <div class="drp-cal">
            <div class="drp-head">
                <button type="button" class="drp-nav" x-on:click="shiftMonth(-1)" aria-label="Previous month">‹</button>
                <b x-text="monthLabel()" aria-live="polite"></b>
                <button type="button" class="drp-nav" x-on:click="shiftMonth(1)" aria-label="Next month">›</button>
            </div>
            <div class="drp-grid" role="grid" x-ref="grid" x-on:keydown="onKey($event)" x-on:mouseleave="hover = null">
                <template x-for="d in ['S','M','T','W','T','F','S']"><span class="drp-dow" aria-hidden="true" x-text="d"></span></template>
                <template x-for="week in weeks()">
                    <template x-for="day in week" :key="iso(day)">
                        <button type="button" class="drp-day" role="gridcell" :class="dayClasses(day)" :tabindex="isFocus(day) ? 0 : -1"
                                :aria-label="dayLabel(day)" :aria-disabled="disabled(day) ? 'true' : null" :aria-selected="inRange(day) ? 'true' : 'false'"
                                x-on:click="pick(day)" x-on:mouseenter="anchor && (hover = day)" x-on:focus="focus = day" x-text="day.getDate()"></button>
                    </template>
                </template>
            </div>
            <p class="drp-hint" x-text="anchor ? 'Now pick the end date' : 'Pick a start date, or a quick range'"></p>
        </div>
    </div>
</div>
