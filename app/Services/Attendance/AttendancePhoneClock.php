<?php

namespace App\Services\Attendance;

use App\Models\AttendancePunch;
use App\Models\AttendanceSetting;
use App\Models\AttendanceStaff;
use Carbon\CarbonImmutable;

/**
 * A punch from someone's own phone. It is taken only when the branch allows it and the phone
 * says it is within the branch's distance; where the phone was is kept on the punch. A phone's
 * location can be faked by someone determined, so the place is there for a manager to look at.
 */
class AttendancePhoneClock
{
    /** A phone unsure of where it is by more than this (or the branch's distance, if larger) is not taken. */
    public const WORST_ACCURACY = 100;

    /** Two punches from a phone must be at least this far apart. */
    public const MINUTES_BETWEEN = 2;

    /**
     * @return array{ok: bool, message: string, punch?: AttendancePunch}
     */
    public function punch(AttendanceStaff $staff, float $latitude, float $longitude, ?float $accuracy, CarbonImmutable $now): array
    {
        $settings = AttendanceSetting::current();
        if (! $settings->phone_clock_in || $settings->clock_in_latitude === null || $settings->clock_in_longitude === null) {
            return ['ok' => false, 'message' => 'Clocking in from a phone is not switched on at this branch.'];
        }
        if (abs($latitude) > 90 || abs($longitude) > 180 || ($latitude == 0.0 && $longitude == 0.0)) {
            return ['ok' => false, 'message' => 'Your phone did not give a location. Turn on location and try again.'];
        }
        $radius = (int) $settings->clock_in_radius ?: 150;
        if ($accuracy !== null && $accuracy > max(self::WORST_ACCURACY, $radius)) {
            return ['ok' => false, 'message' => 'Your phone is unsure where it is (to within '.round($accuracy).' m). Turn on GPS, step outside or wait a moment, then try again.'];
        }
        $distance = self::distance($latitude, $longitude, (float) $settings->clock_in_latitude, (float) $settings->clock_in_longitude);
        if ($distance > $radius) {
            return ['ok' => false, 'message' => 'You are about '.self::metres($distance).' from the branch. You can clock in within '.self::metres($radius).' of it.'];
        }
        $last = AttendancePunch::query()->where('attendance_staff_id', $staff->id)->where('punched_at', '>', $now->subMinutes(self::MINUTES_BETWEEN)->format('Y-m-d H:i:s'))
            ->orderByDesc('punched_at')->first();
        if ($last) {
            return ['ok' => false, 'message' => 'You punched at '.$last->punched_at->format('g:i A').'. Wait a couple of minutes before punching again.'];
        }

        $punch = AttendancePunch::create(['attendance_staff_id' => $staff->id, 'punched_at' => $now->format('Y-m-d H:i:00'), 'source' => 'phone',
            'latitude' => round($latitude, 7), 'longitude' => round($longitude, 7), 'accuracy' => $accuracy === null ? null : (int) round($accuracy), 'distance' => $distance,
            'created_by' => auth()->id()]);

        return ['ok' => true, 'message' => 'Punched at '.$now->format('g:i A').', '.self::metres($distance).' from the branch.', 'punch' => $punch];
    }

    /** Metres between two places on the ground (haversine). */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return (int) round(6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /** 420 -> '420 m', 2350 -> '2.4 km'. */
    public static function metres(int $metres): string
    {
        return $metres >= 1000 ? number_format($metres / 1000, 1).' km' : $metres.' m';
    }
}
