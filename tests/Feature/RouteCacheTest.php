<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * docs/RELEASE.md tells operators to run `php artisan route:cache`. It fails if two routes share a name,
 * so guard against duplicate route names coming back.
 */
class RouteCacheTest extends TestCase
{
    public function test_no_two_routes_share_a_name(): void
    {
        $seen = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            if ($name === null) {
                continue;
            }
            $this->assertArrayNotHasKey($name, $seen, "Route name '{$name}' is used by more than one route: route:cache would fail.");
            $seen[$name] = true;
        }
    }

    public function test_login_still_resolves_and_post_is_throttled(): void
    {
        $this->assertSame(url('/login'), route('login'));
        $post = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'login' && in_array('POST', $r->methods(), true));
        $this->assertNotNull($post);
        $this->assertContains('throttle:5,1', $post->gatherMiddleware());
    }
}
