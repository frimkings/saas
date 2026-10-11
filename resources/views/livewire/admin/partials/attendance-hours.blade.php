{{-- The seven weekday rows of a working-hours form. $model is the Livewire property holding them, $values its current value. --}}
<div class="space-y-1.5">
    @foreach(\App\Models\AttendanceSetting::DAYS as $weekday => $name)
        <div class="flex flex-wrap items-center gap-2" wire:key="{{ $model }}-{{ $weekday }}">
            <label class="flex w-36 items-center gap-2 text-sm text-slate-800"><input type="checkbox" wire:model.live="{{ $model }}.{{ $weekday }}.on"> {{ $name }}</label>
            @if(! empty($values[$weekday]['on']))
                <input type="time" class="ui-input !w-32 !py-1.5 !text-sm" wire:model="{{ $model }}.{{ $weekday }}.start" aria-label="{{ $name }} starts">
                <span class="text-sm text-slate-500">to</span>
                <input type="time" class="ui-input !w-32 !py-1.5 !text-sm" wire:model="{{ $model }}.{{ $weekday }}.end" aria-label="{{ $name }} ends">
            @else
                <span class="text-sm text-slate-400">Day off</span>
            @endif
            @error("$model.$weekday.start")<p class="ui-error w-full" role="alert">{{ $message }}</p>@enderror
            @error("$model.$weekday.end")<p class="ui-error w-full" role="alert">{{ $message }}</p>@enderror
        </div>
    @endforeach
    @error($model)<p class="ui-error" role="alert">{{ $message }}</p>@enderror
</div>
