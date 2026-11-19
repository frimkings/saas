<?php

namespace App\Livewire\Platform;

use App\Support\Access\Permissions;
use App\Support\Access\RoleGuide;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Platform → Roles: what each shared role can do (read-only; roles are changed in code by the
 * Nexus team), who holds them in each clinic, and the per-person switches clinics have made.
 */
class RolesComponent extends Component
{
    public string $role = '';

    /** @return array<int, array<string, int>> clinic id => role => active people holding it */
    private function holders(): array
    {
        $rows = DB::table('branch_user_role as bur')
            ->join('roles as r', 'r.id', '=', 'bur.role_id')
            ->join('branches as b', 'b.id', '=', 'bur.branch_id')
            ->join('clinic_user as cu', fn ($join) => $join->on('cu.user_id', '=', 'bur.user_id')->on('cu.clinic_id', '=', 'b.clinic_id'))
            ->where('cu.status', 'active')
            ->groupBy('b.clinic_id', 'r.name')
            ->select('b.clinic_id', 'r.name', DB::raw('count(distinct bur.user_id) as people'))
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->clinic_id][$row->name] = (int) $row->people;
        }

        return $out;
    }

    /** @return array<int, array{on: int, off: int, top: list<string>}> clinic id => switch counts */
    private function switches(): array
    {
        $out = [];
        try {
            $rows = DB::table('clinic_user_permissions')->select('clinic_id', 'permission', 'granted', DB::raw('count(*) as n'))
                ->groupBy('clinic_id', 'permission', 'granted')->get();
        } catch (\Illuminate\Database\QueryException) {
            return [];
        }
        foreach ($rows as $row) {
            $clinic = (int) $row->clinic_id;
            $out[$clinic] ??= ['on' => 0, 'off' => 0, 'by' => []];
            $out[$clinic][$row->granted ? 'on' : 'off'] += (int) $row->n;
            $out[$clinic]['by'][($row->granted ? '+ ' : '− ').Permissions::label($row->permission)] = (int) $row->n;
        }
        foreach ($out as &$clinic) {
            arsort($clinic['by']);
            $clinic['top'] = array_slice(array_keys($clinic['by']), 0, 3);
            unset($clinic['by']);
        }

        return $out;
    }

    public function render()
    {
        $labels = RoleGuide::labels();
        $holders = $this->holders();
        $switches = $this->switches();
        $clinics = DB::table('clinics')->whereIn('id', array_unique([...array_keys($holders), ...array_keys($switches)]))
            ->orderBy('name')->pluck('name', 'id');
        $totals = [];
        foreach ($holders as $byRole) {
            foreach ($byRole as $role => $people) $totals[$role] = ($totals[$role] ?? 0) + $people;
        }

        return view('livewire.platform.roles-component', [
            'labels' => $labels,
            'shown' => array_key_exists($this->role, $labels) ? [$this->role => $labels[$this->role]] : $labels,
            'matrix' => RoleGuide::matrix(),
            'holders' => $holders,
            'switches' => $switches,
            'clinics' => $clinics,
            'totals' => $totals,
        ])->layout('layouts.platform');
    }
}
