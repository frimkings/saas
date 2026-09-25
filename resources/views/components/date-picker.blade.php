@props([
    'error' => null
])

<div
    x-data="{ value: @entangle($attributes->wire('model')).live }"
    x-on:change="value = $event.target.value"
    x-init="
        new Pikaday({ 
            field: $refs.input, 
            format: 'YYYY-MM-DD', 
            firstDay: 1, 
            // Crucial: Update Livewire when Pikaday selects a date
            onSelect: function(date) {
                const formattedDate = moment(date).format('YYYY-MM-DD');
                $dispatch('input', formattedDate); // Dispatch input event for Livewire v3 or Alpine x-on:change for v2
                value = formattedDate; // Update the Alpine value
            }
        });"
>
    <input
        {{ $attributes->whereDoesntStartWith('wire:model.live') }}
        x-ref="input"
        x-bind:value="value"
        type="text"
        placeholder="YYYY-MM-DD"
        class="form-control @error($attributes->get('wire:model')) is-invalid @enderror"
    />
</div>