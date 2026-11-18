<?php

namespace App\Http\Middleware;

use App\Models\PageVisit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Counts visits to the public pages, anonymously: path, time, device type, referring host, the optional ?visits= tag and a hash
 * that changes every day. The IP address and the user agent are only used to make that hash and to
 * skip the owner and robots; they are not stored, and no cookie is set.
 *
 * Skipped: staff who are logged in, the IP addresses in config('visitors.ignored_ips'), robots, the
 * admin, the API, assets, and everything that is not a normal page load (only GET, 200, HTML).
 */
class TrackVisit
{
    private const SKIP_PATHS = ['admin', 'admin/*', 'api/*', 'login', 'logout', 'locale/*', 'up', 'build/*', 'storage/*', 'images/*'];

    private const BOT = '/bot|crawl|spider|slurp|preview|monitor|uptime|headless|lighthouse|curl|wget|python|http-?client|scrapy|facebookexternalhit/i';

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if ($this->shouldCount($request, $response)) {
                $this->record($request);
            }
        } catch (Throwable $e) {
            report($e); // counting must never break a page
        }
    }

    private function shouldCount(Request $request, Response $response): bool
    {
        $agent = (string) $request->userAgent();

        return config('visitors.enabled')
            && $request->isMethod('GET')
            && $response->getStatusCode() === 200
            && str_contains((string) $response->headers->get('Content-Type'), 'text/html')
            && ! $request->ajax()
            && ! $request->is(...self::SKIP_PATHS)
            && ! in_array(strtolower((string) $request->header('Purpose', $request->header('Sec-Purpose', ''))), ['prefetch', 'prefetch;prerender'], true)
            && $agent !== '' && ! preg_match(self::BOT, $agent)
            && ! $request->user()
            && ! in_array($request->ip(), config('visitors.ignored_ips'), true);
    }

    private function record(Request $request): void
    {
        $agent = (string) $request->userAgent();
        $host = parse_url((string) $request->headers->get('referer'), PHP_URL_HOST) ?: null;

        PageVisit::create([
            'visited_at' => now(),
            // A new value every day: visitors can be counted per day, but not followed or identified.
            'visitor' => substr(hash_hmac('sha256', $request->ip().'|'.$agent.'|'.now()->toDateString(), (string) config('app.key')), 0, 32),
            'path' => mb_substr('/'.ltrim($request->path(), '/'), 0, 191),
            'referrer_host' => $host && $host !== $request->getHost() ? mb_substr($host, 0, 191) : null,
            'campaign' => $this->campaign($request),
            'device' => match (true) {
                (bool) preg_match('/ipad|tablet/i', $agent) => 'tablet',
                (bool) preg_match('/mobi|android|iphone/i', $agent) => 'mobile',
                default => 'desktop',
            },
            'locale' => app()->getLocale(),
        ]);
    }

    /** ?visits=company-name on a link: only letters, digits, dash and underscore are kept. */
    private function campaign(Request $request): ?string
    {
        $value = $request->query('visits');
        $value = is_string($value) ? strtolower(preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($value))) : '';

        return $value !== '' ? mb_substr(trim($value, '-'), 0, 60) ?: null : null;
    }
}
