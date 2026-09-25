@props([
    'placeholder' => 'Filter by Date Range',
    'options' => [] // Allows overriding daterangepicker options
])

<div
    x-data="{
        range: @entangle($attributes->wire('model')).live,
        picker: null,
        init() {
            // Merge custom options with default options
            const defaultOptions = {
                autoUpdateInput: false,
                locale: {
                    cancelLabel: 'Clear',
                    format: 'YYYY-MM-DD'
                },
                ...{{ json_encode($options) }}
            };
            
            // Initialize daterangepicker using jQuery
            this.picker = $(this.$refs.input).daterangepicker(defaultOptions);

            // Set input value when component initializes (useful for preserving state on back/forward)
            if (this.range) {
                $(this.$refs.input).val(this.range);
            }
            
            // Listen for 'apply' event
            this.picker.on('apply.daterangepicker', (ev, picker) => {
                const newRange = picker.startDate.format('YYYY-MM-DD') + ' - ' + picker.endDate.format('YYYY-MM-DD');
                $(this.$refs.input).val(newRange);
                // Update Livewire property via entangle
                this.range = newRange; 
            });

            // Listen for 'cancel' event
            this.picker.on('cancel.daterangepicker', (ev, picker) => {
                $(this.$refs.input).val('');
                // Update Livewire property via entangle
                this.range = '';
            });
            
            // Watch for external changes to the Livewire property (e.g., reset)
            this.$watch('range', (newRange) => {
                if (!newRange) {
                     $(this.$refs.input).val('');
                }
            });
        }
    }"
    **wire:ignore**
>
    <input
        x-ref="input"
        type="text"
        class="form-control"
        placeholder="{{ $placeholder }}"
        {{ $attributes->whereDoesntStartWith('wire:model.live') }}
    >
</div>