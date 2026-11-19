<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** LOADTEST-20 only: logs time, query count/time and size of every request when LOADTEST_PROFILE=1. */
class LoadTestProfile
{
    public function handle(Request $request, Closure $next)
    {
        if (! env('LOADTEST_PROFILE')) return $next($request);

        $queries = 0; $queryMs = 0.0; $slowest = [0, ''];
        DB::listen(function ($q) use (&$queries, &$queryMs, &$slowest) {
            $queries++; $queryMs += $q->time;
            if ($q->time > $slowest[0]) $slowest = [$q->time, mb_substr($q->sql, 0, 160)];
        });
        $start = microtime(true);
        $response = $next($request);
        $what = $request->path();
        if ($request->is('livewire*/update')) {
            $calls = [];
            foreach ((array) $request->input('components', []) as $c) {
                $snap = json_decode($c['snapshot'] ?? '{}', true);
                $calls[] = ($snap['memo']['name'] ?? '?').':'.implode(',', array_map(fn ($x) => $x['method'] ?? '?', $c['calls'] ?? [])) ;
            }
            $what = 'LW '.implode(' | ', $calls);
        }
        file_put_contents(storage_path('logs/loadtest.jsonl'), json_encode([
            'at' => now()->toDateTimeString(), 'label' => $request->header('X-Loadtest-Label'), 'what' => $what,
            'ms' => round((microtime(true) - $start) * 1000, 1), 'queries' => $queries, 'query_ms' => round($queryMs, 1),
            'kb' => round(strlen((string) $response->getContent()) / 1024, 1), 'status' => $response->getStatusCode(),
            'slowest_ms' => round($slowest[0], 1), 'slowest' => $slowest[1],
        ])."\n", FILE_APPEND);

        return $response;
    }
}
