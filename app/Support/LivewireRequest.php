<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Livewire's own requests (component actions and polling). Livewire names its update route
 * "default.livewire.update" (a custom one ends in "livewire.update"), so checking for exactly
 * "livewire.update" never matches; this is the one place that knows the name.
 */
class LivewireRequest
{
    public static function isUpdate(Request $request): bool
    {
        return $request->routeIs('livewire.update', '*.livewire.update');
    }

    /**
     * The components a Livewire update is for: name => the component's public data, read from
     * each snapshot. Livewire checks each snapshot's signature before running anything.
     *
     * @return list<array{name: string, data: array, calls: array, updates: array}>
     */
    public static function components(Request $request): array
    {
        $out = [];
        foreach ((array) $request->input('components', []) as $component) {
            $snapshot = json_decode($component['snapshot'] ?? '', true);
            $out[] = [
                'name' => (string) ($snapshot['memo']['name'] ?? ''),
                'data' => (array) ($snapshot['data'] ?? []),
                'calls' => (array) ($component['calls'] ?? []),
                'updates' => (array) ($component['updates'] ?? []),
            ];
        }

        return $out;
    }
}
