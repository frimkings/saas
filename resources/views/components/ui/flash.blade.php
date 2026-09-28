{{-- Turns session flash messages into toasts through the app-wide `notify` event (the optical
     layout's toast stack, or toastr in the clinic and platform layouts). Layouts include it for
     messages carried across a redirect; a Livewire view includes it for messages flashed during
     an update. Each message fires once per request even when both render it.
     `map` adds component-specific session keys: ['panel_message' => 'success']. --}}
@props(['link' => null, 'linkLabel' => null, 'linkNewTab' => false, 'map' => []])
@php
    $keys = ['success' => 'success', 'status' => 'success', 'error' => 'error', 'warning' => 'warning', 'info' => 'info'] + $map;
    $shown = request()->attributes->get('flash.shown', []);
@endphp
@foreach($keys as $key => $type)
    @php $message = session($key); @endphp
    @if(is_string($message) && trim($message) !== '' && ! in_array($key.'|'.$message, $shown, true))
        @php $shown[] = $key.'|'.$message; request()->attributes->set('flash.shown', $shown); @endphp
        <div hidden wire:key="flash-{{ $key }}-{{ \Illuminate\Support\Str::random(10) }}"
             data-type="{{ $type }}" @if($link && $type === 'success') data-link="{{ $link }}" data-link-label="{{ $linkLabel ?? 'Open' }}" @if($linkNewTab) data-new-tab @endif @endif
             x-data x-init="setTimeout(() => $dispatch('notify', { type: $el.dataset.type, message: $el.textContent.trim(), link: $el.dataset.link || null, linkLabel: $el.dataset.linkLabel || null, newTab: 'newTab' in $el.dataset }))">{{ $message }}</div>
    @endif
@endforeach
