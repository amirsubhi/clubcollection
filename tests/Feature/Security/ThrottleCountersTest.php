<?php

namespace Tests\Feature\Security;

use App\Models\Club;
use Illuminate\Routing\Route as RouteObject;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A plain throttle:N,M keys its counter by the signed-in user alone, so every such route shared one counter: viewing
 * 20 slip thumbnails used up the 20 admin writes a minute, and 10 slip views blocked a member's payment. Each
 * throttle now names its own counter (throttle:N,M,<name>|).
 */
class ThrottleCountersTest extends TestCase
{
    public function test_every_numeric_throttle_names_its_own_counter(): void
    {
        $shared = collect(Route::getRoutes()->getRoutes())
            ->flatMap(fn (RouteObject $route) => collect($route->gatherMiddleware())
                ->filter(fn ($m) => is_string($m) && preg_match('/^throttle:\d+,\d+$/', $m))
                ->map(fn ($m) => $route->getName().' '.$m))
            ->values()->all();

        $this->assertSame([], $shared, 'A throttle:N,M without a counter name shares one counter with every other one.');
    }

    public function test_viewing_thumbnails_does_not_use_up_the_admin_write_limit(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        // Missing ids still count against the thumbnail counter (they answer 404).
        for ($i = 0; $i < 25; $i++) {
            $this->get(route('admin.attachments.thumb', 999999 + $i));
        }

        $this->assertNotSame(429, $this->put(route('admin.clubs.profile.update', $club), [])->status());

        // And the thumbnail counter itself still applies (300 a minute is unchanged).
        $this->assertNotSame(429, $this->get(route('admin.attachments.thumb', 1))->status());
    }
}
