<?php

namespace App\Http\Controllers;

use App\Models\PageVisit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Visits to the public site. Only the owner (config admin.hidden_emails) may see them: other admins get 403. */
class VisitorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isOwner(), 403);

        $days = (int) ($request->validate(['days' => ['nullable', 'in:7,30,90']])['days'] ?? 30);
        $from = now()->subDays($days - 1)->startOfDay();

        $period = PageVisit::where('visited_at', '>=', $from);
        $perDay = (clone $period)
            ->selectRaw('date(visited_at) as day, count(*) as visits, count(distinct visitor) as visitors')
            ->groupBy(DB::raw('date(visited_at)'))
            ->get()->keyBy('day');

        // Every day of the period, also the quiet ones.
        $series = collect(range(0, $days - 1))->map(function (int $i) use ($from, $perDay) {
            $day = $from->copy()->addDays($i)->toDateString();

            return ['day' => $day, 'visits' => (int) ($perDay[$day]->visits ?? 0), 'visitors' => (int) ($perDay[$day]->visitors ?? 0)];
        })->values();

        $top = fn (string $column, int $limit = 8) => (clone $period)->whereNotNull($column)
            ->selectRaw("$column as label, count(*) as total")->groupBy($column)->orderByDesc('total')->limit($limit)->get()
            ->map(fn ($r) => ['label' => $r->label, 'total' => (int) $r->total])->values();

        // Per tagged link: hits, devices, and how many of those devices looked at more than one page that day.
        $campaigns = (clone $period)->whereNotNull('campaign')->get(['campaign', 'visitor', 'visited_at'])
            ->groupBy('campaign')->map(function ($rows, $name) use ($period) {
                $visitors = $rows->pluck('visitor')->unique();
                $pages = (clone $period)->whereIn('visitor', $visitors)->selectRaw('visitor, count(*) as n')->groupBy('visitor')->pluck('n');

                return [
                    'label' => $name,
                    'visits' => $rows->count(),
                    'visitors' => $visitors->count(),
                    'browsed' => $pages->filter(fn ($n) => $n > 1)->count(),
                    'last' => $rows->max('visited_at')->format('Y-m-d H:i'),
                ];
            })->sortByDesc('last')->values();

        return response()->json([
            'days' => $days,
            'tracking' => (bool) config('visitors.enabled'),
            'retention_days' => (int) config('visitors.retention_days'),
            'your_ip' => $request->ip(),
            'your_ip_ignored' => in_array($request->ip(), config('visitors.ignored_ips'), true),
            'totals' => [
                'visits' => $series->sum('visits'),
                // A visitor is counted once per day (the identifier changes daily).
                'visitors' => $series->sum('visitors'),
                'today_visits' => (int) ($series->last()['visits'] ?? 0),
                'today_visitors' => (int) ($series->last()['visitors'] ?? 0),
            ],
            'series' => $series,
            'pages' => $top('path'),
            'referrers' => $top('referrer_host'),
            'campaigns' => $campaigns,
            'devices' => $top('device', 3),
            'recent' => PageVisit::latest('visited_at')->latest('id')->limit(15)->get()->map(fn (PageVisit $v) => [
                'at' => $v->visited_at->format('Y-m-d H:i'),
                'path' => $v->path,
                'device' => $v->device,
                'referrer' => $v->referrer_host,
                'campaign' => $v->campaign,
                'visitor' => substr($v->visitor, 0, 6),
                'locale' => $v->locale,
            ]),
        ]);
    }
}
