<?php

namespace App\Support;

final class OpticalServices
{
    public const TYPES = [
        'lens_transfer' => ['name' => 'Lens transfer', 'requires_rx' => false, 'requires_frame' => true],
        'frame_adjustment' => ['name' => 'Frame adjustment', 'requires_rx' => false, 'requires_frame' => true],
        'frame_repair' => ['name' => 'Frame repair', 'requires_rx' => false, 'requires_frame' => true],
        'glazing' => ['name' => 'Glazing / fitting', 'requires_rx' => true, 'requires_frame' => true],
        'lens_fitting' => ['name' => 'Lens fitting', 'requires_rx' => true, 'requires_frame' => true],
        'custom' => ['name' => 'Other optical service', 'requires_rx' => false, 'requires_frame' => false],
        'custom_frame' => ['name' => 'Other frame service', 'requires_rx' => false, 'requires_frame' => true],
        'custom_rx' => ['name' => 'Other prescription service', 'requires_rx' => true, 'requires_frame' => false],
    ];
}
