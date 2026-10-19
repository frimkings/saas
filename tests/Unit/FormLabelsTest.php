<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every form field on a page that has been moved onto the shared parts has a name a screen reader can read:
 * a <label for> pointing at it, or an aria-label. A label must point at the field right after it (or, for a
 * checkbox or file input, right before it), so a label is never tied to the wrong field. Add each page here
 * as it is converted.
 */
class FormLabelsTest extends TestCase
{
    /** A tag's attributes, stepping over Blade echoes that contain ">". */
    private const ATTRS = '(?:[^>{]|\{\{.*?\}\}|\{(?!\{))*';

    private const PAGES = [
        'livewire/admin/admin-dashboard-component.blade.php',
        'livewire/cashier/cashier-dashboard-component.blade.php',
        'livewire/admin/products-component.blade.php',
        'livewire/admin/insurance-claims-component.blade.php',
        'livewire/admin/insurers-component.blade.php',
        'livewire/admin/insurer-payments-component.blade.php',
        'livewire/admin/insurer-receivables-component.blade.php',
        'livewire/admin/user-role-manager-component.blade.php',
        'livewire/admin/role-permission-manager-component.blade.php',
        'livewire/admin/income-statement-component.blade.php',
        'livewire/admin/day-close-component.blade.php',
        'livewire/admin/cash-book-component.blade.php',
        'livewire/admin/daily-cash-summary-component.blade.php',
        'livewire/doctor/patient-records-component.blade.php',
        'livewire/doctor/partials/structured-refraction-fields.blade.php',
        'livewire/doctor/allrecords-component.blade.php',
        'livewire/doctor/patient-timeline-component.blade.php',
        'livewire/doctor/referral-component.blade.php',
        'livewire/optical/optical-order-create-component.blade.php',
        'livewire/optical/partials/shared-lens-receipt.blade.php',
        'livewire/optical/optical-stock-management-component.blade.php',
        'livewire/secretary/spectacle-component.blade.php',
        'livewire/secretary/patients-component.blade.php',
        'livewire/cashier/cashier-patient-clearance-component.blade.php',
        'livewire/pos-component.blade.php',
    ];

    public function test_every_field_on_converted_pages_has_a_label(): void
    {
        $problems = [];
        foreach (self::PAGES as $page) {
            $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/'.$page);
            foreach ($this->unlabelled($source) as [$line, $field]) {
                $problems[] = "{$page}:{$line} {$field}";
            }
        }

        $this->assertSame([], $problems, "Fields without a label:\n".implode("\n", $problems));
    }

    public function test_labels_point_at_the_field_next_to_them(): void
    {
        $problems = [];
        foreach (self::PAGES as $page) {
            $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/'.$page);
            preg_match_all('/<label\b[^>]*\bfor="([^"{]+)"[^>]*>/', $source, $labels, PREG_OFFSET_CAPTURE);
            foreach ($labels[1] as $i => [$target]) {
                $offset = $labels[0][$i][1];
                $after = substr($source, $offset + strlen($labels[0][$i][0]));
                $before = substr($source, max(0, $offset - 600), min(600, $offset));
                if (preg_match_all('/<input\b[^>]*\bid="([^"]+)"[^>]*>/', $before, $prev) && end($prev[1]) === $target) {
                    continue;
                }
                if (! preg_match('/<(input|select|textarea)\b('.self::ATTRS.')>/s', $after, $next) || str_contains($next[2], 'type="hidden"')) {
                    continue;
                }
                if (! preg_match('/\bid="([^"]+)"/', $next[2], $id) || $id[1] !== $target) {
                    $problems[] = $page.':'.(substr_count(substr($source, 0, $offset), "\n") + 1)." label for=\"{$target}\"";
                }
            }
        }

        $this->assertSame([], $problems, "Labels tied to the wrong field:\n".implode("\n", $problems));
    }

    /** @return list<array{int, string}> */
    private function unlabelled(string $source): array
    {
        $found = [];
        // Attribute text may hold Blade echoes with ">" in them ({{ $claim->id }}); skip over those.
        preg_match_all('/<(input|select|textarea)\b('.self::ATTRS.')>/s', $source, $fields, PREG_OFFSET_CAPTURE);
        foreach ($fields[2] as $i => [$attributes, $offset]) {
            if (preg_match('/type="(hidden|checkbox|radio|submit|button|file)"/', $attributes)
                || str_contains($attributes, 'aria-label') || str_contains($attributes, 'aria-labelledby')) {
                continue;
            }
            if (preg_match('/\bid="([^"]+)"/', $attributes, $id) && str_contains($source, 'for="'.$id[1].'"')) {
                continue;
            }
            // A field inside an open <label> element is named by it.
            $before = substr($source, 0, $offset);
            if (strrpos($before, '<label') !== false && strrpos($before, '<label') > (int) strrpos($before, '</label>')) {
                continue;
            }
            preg_match('/(wire:model[\w.\-]*|x-model)="([^"]+)"/', $attributes, $model);
            $found[] = [substr_count(substr($source, 0, $offset), "\n") + 1, $fields[1][$i][0].' '.($model[2] ?? trim(substr($attributes, 0, 60)))];
        }

        return $found;
    }
}
