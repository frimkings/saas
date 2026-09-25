<?php

namespace App\Enums;

enum EyeLaterality: string
{
    case BOTH = 'Both Eyes';
    case OD = 'OD (Right Eye)';
    case OS = 'OS (Left Eye)';

    /**
     * Get the label for display
     */
    public function label(): string
    {
        return $this->value;
    }

    /**
     * Get short abbreviation
     */
    public function abbreviation(): string
    {
        return match($this) {
            self::BOTH => 'OU',
            self::OD => 'OD',
            self::OS => 'OS',
        };
    }

    /**
     * Get badge color class
     */
    public function badgeClass(): string
    {
        return match($this) {
            self::BOTH => 'badge-primary',
            self::OD => 'badge-info',
            self::OS => 'badge-success',
        };
    }

    /**
     * Get icon
     */
    public function icon(): string
    {
        return match($this) {
            self::BOTH => 'fa-eye',
            self::OD => 'fa-eye',
            self::OS => 'fa-eye',
        };
    }

    /**
     * Get all cases as options array
     */
    public static function options(): array
    {
        return array_column(self::cases(), 'value');
    }
}