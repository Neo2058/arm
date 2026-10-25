<?php

namespace Tests\Unit;

use App\Http\Middleware\AccessBarrierExemptions;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccessBarrierExemptionsTest extends TestCase
{
    #[DataProvider('lifecyclePaths')]
    public function test_livewire_and_filament_paths_are_lifecycle_requests(string $path): void
    {
        $request = Request::create($path, 'POST');

        $this->assertTrue(AccessBarrierExemptions::isLifecycleRequest($request));
        $this->assertTrue(AccessBarrierExemptions::shouldSkip($request));
        $this->assertFalse(AccessBarrierExemptions::isExemptPath($request));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function lifecyclePaths(): array
    {
        return [
            ['/livewire/update'],
            ['/livewire/upload-file'],
            ['/livewire/preview-file'],
            ['/filament/exports/download'],
        ];
    }

    #[DataProvider('exemptPaths')]
    public function test_device_barrier_logout_and_admin_serve_are_exempt_paths(string $path): void
    {
        $request = Request::create($path, str_starts_with($path, '/api/') || $path === '/logout' ? 'POST' : 'GET');

        $this->assertTrue(AccessBarrierExemptions::isExemptPath($request));
        $this->assertTrue(AccessBarrierExemptions::shouldSkip($request));
        $this->assertFalse(AccessBarrierExemptions::isLifecycleRequest($request));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function exemptPaths(): array
    {
        return [
            ['/device-register'],
            ['/api/device/register'],
            ['/barrier'],
            ['/api/barrier/verify'],
            ['/logout'],
            ['/admin/serve-document'],
            ['/admin/serve-training-material'],
            ['/admin/serve-chat-attachment'],
        ];
    }

    #[DataProvider('protectedPaths')]
    public function test_ordinary_pages_are_not_exempt(string $path): void
    {
        $request = Request::create($path);

        $this->assertFalse(AccessBarrierExemptions::isLifecycleRequest($request));
        $this->assertFalse(AccessBarrierExemptions::isExemptPath($request));
        $this->assertFalse(AccessBarrierExemptions::shouldSkip($request));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function protectedPaths(): array
    {
        return [
            ['/mainMenu'],
            ['/documents'],
            ['/rosisi'],
            ['/naryady'],
            ['/admin'],
            ['/admin/documents'],
        ];
    }

    public function test_livewire_route_name_is_lifecycle_even_on_unrelated_path(): void
    {
        $request = Request::create('/unrelated', 'POST');
        $request->setRouteResolver(function () {
            $route = new Route('POST', 'unrelated', fn () => null);
            $route->name('livewire.update');

            return $route;
        });

        $this->assertTrue(AccessBarrierExemptions::isLifecycleRequest($request));
        $this->assertTrue(AccessBarrierExemptions::shouldSkip($request));
    }
}
