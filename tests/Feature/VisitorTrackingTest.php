<?php

namespace Tests\Feature;

use App\Models\PageVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisitorTrackingTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Safari/605.1.15';

    private function owner(): User
    {
        return User::factory()->admin()->create(['email' => config('admin.hidden_emails')[0]]);
    }

    /** What the script on the page sends once a browser has had the page open for a moment. */
    private function visit(string $uri = '/', array $server = [], array $headers = [], ?string $referrer = null)
    {
        $referrer ??= $headers['Referer'] ?? '';
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return $this->withHeaders($headers + ['User-Agent' => self::BROWSER])->withServerVariables($server)
            ->postJson('/t', ['p' => parse_url($uri, PHP_URL_PATH), 'r' => $referrer, 'c' => $query['visits'] ?? null]);
    }

    public function test_a_public_page_view_is_counted_anonymously(): void
    {
        $this->visit('/', [], ['Referer' => 'https://www.google.com/search?q=x'])->assertNoContent();

        $v = PageVisit::sole();
        $this->assertSame('/', $v->path);
        $this->assertSame('www.google.com', $v->referrer_host);
        $this->assertSame('desktop', $v->device);
        $this->assertSame(32, strlen($v->visitor));
        // nothing that identifies: no IP address, no user agent in any column
        $this->assertStringNotContainsString('127.0.0.1', json_encode($v->getAttributes()));
        $this->assertStringNotContainsString('Mozilla', json_encode($v->getAttributes()));
    }

    public function test_no_cookie_is_set_for_counting(): void
    {
        $names = collect($this->visit('/')->headers->getCookies())->map->getName();

        $this->assertNotContains('visitor', $names);
        $this->assertSame(1, PageVisit::count());
    }

    public function test_the_query_string_is_not_stored(): void
    {
        $this->visit('/vehicles?category=passenger-car&pickup_date=2026-12-01');

        $this->assertSame('/vehicles', PageVisit::sole()->path);
    }

    public function test_the_same_visitor_gets_a_different_identifier_each_day(): void
    {
        $this->travelTo(now()->setTime(10, 0));
        $this->visit('/');
        $this->visit('/locations');
        $this->travelTo(now()->addDay());
        $this->visit('/');

        $ids = PageVisit::orderBy('id')->pluck('visitor');
        $this->assertSame($ids[0], $ids[1]);
        $this->assertNotSame($ids[0], $ids[2]);
    }

    public function test_device_types_are_recognised(): void
    {
        $this->visit('/', [], ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148']);
        $this->visit('/', [], ['User-Agent' => 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) Mobile/15E148']);

        $this->assertEqualsCanonicalizing(['mobile', 'tablet'], PageVisit::pluck('device')->all());
    }

    public function test_the_owners_ip_address_is_not_counted(): void
    {
        config(['visitors.ignored_ips' => ['203.0.113.7']]);

        $this->visit('/', ['REMOTE_ADDR' => '203.0.113.7']);
        $this->assertSame(0, PageVisit::count());

        $this->visit('/', ['REMOTE_ADDR' => '198.51.100.2']);
        $this->assertSame(1, PageVisit::count());
    }

    public function test_robots_other_paths_and_staff_are_not_counted(): void
    {
        $this->visit('/', [], ['User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)']);
        $this->visit('/', [], ['User-Agent' => 'HeadlessChrome/120']);
        $this->visit('/', [], ['User-Agent' => '']);
        $this->visit('/admin');
        $this->visit('/login');
        $this->visit('/does-not-exist');
        $this->postJson('/t', ['p' => ['x']], ['User-Agent' => self::BROWSER]);
        $this->assertSame(0, PageVisit::count());

        $this->actingAs(User::factory()->admin()->create());
        $this->visit('/');
        $this->assertSame(0, PageVisit::count());
    }

    public function test_loading_the_html_alone_is_not_a_visit(): void
    {
        // a robot or mail scanner that downloads the page but never runs its script
        $this->withHeaders(['User-Agent' => self::BROWSER])->get('/')->assertOk()->assertSee("fetch('/t'", false);

        $this->assertSame(0, PageVisit::count());
    }

    public function test_counting_can_be_switched_off(): void
    {
        config(['visitors.enabled' => false]);

        $this->visit('/');

        $this->assertSame(0, PageVisit::count());
    }

    public function test_old_visits_are_pruned(): void
    {
        PageVisit::create(['visited_at' => now()->subDays(100), 'visitor' => str_repeat('a', 32), 'path' => '/', 'device' => 'desktop']);
        PageVisit::create(['visited_at' => now()->subDays(5), 'visitor' => str_repeat('b', 32), 'path' => '/', 'device' => 'desktop']);

        $this->artisan('model:prune', ['--model' => PageVisit::class])->assertSuccessful();

        $this->assertSame(1, PageVisit::count());
    }

    public function test_the_admin_overview_summarises_the_visits(): void
    {
        foreach ([['/', 'a'], ['/', 'a'], ['/vehicles', 'b']] as [$path, $who]) {
            PageVisit::create(['visited_at' => now(), 'visitor' => str_repeat($who, 32), 'path' => $path, 'device' => 'desktop', 'referrer_host' => $path === '/' ? 'example.org' : null]);
        }
        PageVisit::create(['visited_at' => now()->subDays(2), 'visitor' => str_repeat('c', 32), 'path' => '/', 'device' => 'mobile']);
        PageVisit::create(['visited_at' => now()->subDays(60), 'visitor' => str_repeat('d', 32), 'path' => '/', 'device' => 'mobile']);
        $this->actingAs($this->owner());

        $json = $this->getJson('/admin/api/visitors?days=30')->assertOk()->json();

        $this->assertSame(30, count($json['series']));
        $this->assertSame(['visits' => 4, 'visitors' => 3, 'today_visits' => 3, 'today_visitors' => 2], $json['totals']);
        $this->assertSame(['label' => '/', 'total' => 3], $json['pages'][0]);
        $this->assertSame([['label' => 'example.org', 'total' => 2]], $json['referrers']);
        $this->assertSame(5, count($json['recent']));
        $this->assertArrayNotHasKey('ip', $json['recent'][0]);

        $this->assertSame(5, $this->getJson('/admin/api/visitors?days=90')->json('totals.visits'));
    }

    public function test_a_tagged_link_is_stored_as_a_clean_campaign(): void
    {
        $this->visit('/?visits=Acme Rent%20B.V.!');
        $this->visit('/?visits[]=x');
        $this->visit('/');
        $this->visit('/vehicles');

        $this->assertSame(['acme-rent-b-v', null, null, null], PageVisit::orderBy('id')->pluck('campaign')->all());

        $this->actingAs($this->owner());
        $json = $this->getJson('/admin/api/visitors')->assertOk()->json('campaigns');
        $this->assertSame('acme-rent-b-v', $json[0]['label']);
        $this->assertSame(1, $json[0]['visits']);
        $this->assertSame(1, $json[0]['browsed']); // same device went on to other pages
    }

    public function test_the_owners_address_without_the_admin_role_is_no_owner(): void
    {
        $this->actingAs(User::factory()->employee()->create(['email' => config('admin.hidden_emails')[0]]));

        $this->getJson('/admin/api/visitors')->assertForbidden();
    }

    public function test_the_overview_shows_your_ip_and_whether_it_is_ignored(): void
    {
        config(['visitors.ignored_ips' => ['127.0.0.1']]);
        $this->actingAs($this->owner());

        $this->getJson('/admin/api/visitors')->assertOk()->assertJsonPath('your_ip', '127.0.0.1')->assertJsonPath('your_ip_ignored', true);
    }

    public function test_only_the_owner_may_see_the_visitors(): void
    {
        $this->getJson('/admin/api/visitors')->assertUnauthorized();

        $this->actingAs(User::factory()->employee()->create());
        $this->getJson('/admin/api/visitors')->assertForbidden();

        // another admin is not the owner either
        $this->actingAs(User::factory()->admin()->create(['email' => 'anna@example.test']));
        $this->getJson('/admin/api/visitors')->assertForbidden();
        $this->getJson('/admin/api/me')->assertJsonPath('is_owner', false);

        $this->actingAs($this->owner());
        $this->getJson('/admin/api/me')->assertJsonPath('is_owner', true);
        $this->getJson('/admin/api/visitors?days=5')->assertUnprocessable();
    }
}
