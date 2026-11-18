<?php

namespace App\Http\Controllers;

use App\Models\PageVisit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Counts a visit to a public page, anonymously: path, time, device type, referring host, the optional
 * ?visits= tag and a hash that changes every day. The IP address and the user agent are only used to make
 * that hash and to skip the owner and robots; they are not stored, and no cookie is set.
 *
 * The page reports itself with a small script after a moment (see layouts/public). Robots and mail
 * scanners that only download the HTML never run it, so they are not counted. Also skipped: staff who
 * are logged in, the IP addresses in config('visitors.ignored_ips') and user agents that call themselves bots.
 */
class VisitController extends Controller
{
    private const PAGES = ['/', '/vehicles', '/locations', '/rent'];

    private const BOT = '/bot|crawl|spider|slurp|preview|monitor|uptime|headless|lighthouse|curl|wget|python|http-?client|scrapy|facebookexternalhit|phantom|selenium|puppeteer|playwright/i';

    public function __invoke(Request $request): Response
    {
        try {
            $path = $this->path($request->input('p'));
            $agent = (string) $request->userAgent();

            if (config('visitors.enabled') && $path
                && $agent !== '' && ! preg_match(self::BOT, $agent)
                && ! $request->user()
                && ! in_array($request->ip(), config('visitors.ignored_ips'), true)) {
                $this->record($request, $path, $agent);
            }
        } catch (\Throwable $e) {
            report($e); // counting must never break a page
        }

        return response()->noContent();
    }

    /** Only the public pages count; no query string. */
    private function path(mixed $value): ?string
    {
        $path = is_string($value) ? '/'.trim(strtok($value, '?#') ?: '', '/') : '';

        return in_array($path, self::PAGES, true) ? $path : null;
    }

    private function record(Request $request, string $path, string $agent): void
    {
        $referrer = parse_url((string) $request->input('r'), PHP_URL_HOST) ?: null;

        PageVisit::create([
            'visited_at' => now(),
            // A new value every day: visitors can be counted per day, but not followed or identified.
            'visitor' => substr(hash_hmac('sha256', $request->ip().'|'.$agent.'|'.now()->toDateString(), (string) config('app.key')), 0, 32),
            'path' => $path,
            'referrer_host' => $referrer && $referrer !== $request->getHost() ? mb_substr($referrer, 0, 191) : null,
            'campaign' => $this->campaign($request->input('c')),
            'device' => match (true) {
                (bool) preg_match('/ipad|tablet/i', $agent) => 'tablet',
                (bool) preg_match('/mobi|android|iphone/i', $agent) => 'mobile',
                default => 'desktop',
            },
            'locale' => app()->getLocale(),
        ]);
    }

    /** ?visits=company-name on a link: only letters, digits, dash and underscore are kept. */
    private function campaign(mixed $value): ?string
    {
        $value = is_string($value) ? strtolower(preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($value))) : '';

        return mb_substr(trim($value, '-'), 0, 60) ?: null;
    }
}
