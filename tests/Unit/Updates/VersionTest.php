<?php

namespace Tests\Unit\Updates;

use App\Support\Updates\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VersionTest extends TestCase
{
    /** @return iterable<string, array{string, string, int}> */
    public static function pairs(): iterable
    {
        yield 'minor' => ['2.1.0', '2.2.0', -1];
        yield 'rc before release' => ['2.2.0-rc.1', '2.2.0', -1];
        yield 'rc numeric' => ['2.2.0-rc.2', '2.2.0-rc.10', -1];
        yield 'dev lowest' => ['dev', '0.0.1', -1];
        yield 'dev vs dev' => ['dev', 'dev', 0];
        yield 'equal' => ['2.2.0', '2.2.0', 0];
        yield 'higher' => ['2.10.0', '2.9.9', 1];
        yield 'release above rc' => ['2.2.0', '2.2.0-rc.1', 1];
        yield 'leading v' => ['v2.2.0', '2.2.0', 0];
        yield 'rc vs next patch' => ['2.2.0-rc.1', '2.1.9', 1];
    }

    #[DataProvider('pairs')]
    public function test_compare(string $a, string $b, int $expected): void
    {
        $this->assertSame($expected, Version::compare($a, $b));
        $this->assertSame(-$expected, Version::compare($b, $a));
    }

    public function test_is_release(): void
    {
        $this->assertFalse(Version::isRelease('dev'));
        $this->assertTrue(Version::isRelease('2.2.0'));
        $this->assertTrue(Version::isRelease('2.2.0-rc.1'));
    }
}
