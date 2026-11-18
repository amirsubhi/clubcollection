<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The CSRF middleware skips itself while running tests, so a feature POST
 * cannot catch a webhook route that still carries it. Check the resolved
 * middleware list instead: gateways cannot send a CSRF token.
 */
class WebhookCsrfExemptionTest extends TestCase
{
    private function hasCsrf(\Illuminate\Routing\Route $route): bool
    {
        // Resolving the HTTP kernel registers the middleware groups on the router.
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class);

        foreach (app('router')->gatherRouteMiddleware($route) as $middleware) {
            $class = is_string($middleware) ? explode(':', $middleware)[0] : null;
            if ($class && class_exists($class) && is_a($class, PreventRequestForgery::class, true)) {
                return true;
            }
        }

        return false;
    }

    private const EXEMPT = ['webhook.toyyibpay', 'webhook.billplz'];

    /** Unknown-URL catch-alls: they change nothing (only ever 404/405), so they skip CSRF (FallbackController). */
    private const NOT_FOUND = ['fallback.admin', 'fallback'];

    public function test_the_unknown_url_catch_alls_only_reach_the_fallback_controller(): void
    {
        foreach (self::NOT_FOUND as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Route {$name} missing");
            $this->assertSame(\App\Http\Controllers\FallbackController::class, $route->getActionName());
        }
    }

    public function test_gateway_webhooks_are_exempt_from_csrf(): void
    {
        foreach (self::EXEMPT as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Route {$name} missing");
            $this->assertFalse($this->hasCsrf($route), "{$name} must not require CSRF");
        }
    }

    public function test_every_other_state_changing_route_requires_csrf(): void
    {
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            if (! array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) || in_array($route->getName(), [...self::EXEMPT, ...self::NOT_FOUND], true)) {
                continue;
            }
            $label = implode('|', $route->methods()).' '.$route->uri();
            $this->assertTrue($this->hasCsrf($route), "{$label} is missing CSRF protection");
            $checked++;
        }
        $this->assertGreaterThan(20, $checked);
    }

    public function test_private_disk_is_not_served_over_http(): void
    {
        $names = collect(Route::getRoutes())->map(fn ($r) => (string) $r->getName());

        $this->assertFalse($names->contains(fn ($n) => str_starts_with($n, 'storage.local')), 'private local disk must not be served (filesystems.local.serve)');
    }
}
