<?php

declare(strict_types=1);

namespace Skywave\Tests\Radio;

use PHPUnit\Framework\TestCase;
use Skywave\Radio\StationLogos;

final class StationLogosTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/skywave-logos-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob("$this->directory/*") ?: [] as $path) {
            @unlink($path);
        }

        @rmdir($this->directory);
    }

    public function testNothingIsKeptUntilAStationSendsOne(): void
    {
        $logos = new StationLogos($this->directory);

        $this->assertNull($logos->pathFor(98.3));
    }

    public function testALogoIsKeptAndFoundAgain(): void
    {
        $logos  = new StationLogos($this->directory);
        $source = $this->picture('first');

        $this->assertTrue($logos->remember(98.3, 0, $source));

        $kept = $logos->pathFor(98.3);
        $this->assertNotNull($kept);
        $this->assertSame('first', file_get_contents($kept));
    }

    public function testTheSameLogoIsNotWrittenTwice(): void
    {
        $logos  = new StationLogos($this->directory);
        $source = $this->picture('same');

        $this->assertTrue($logos->remember(98.3, 0, $source));
        // Asked on every status poll, so the second answer has to be "nothing to do".
        $this->assertFalse($logos->remember(98.3, 0, $source));
    }

    public function testARebrandReplacesIt(): void
    {
        $logos = new StationLogos($this->directory);

        $this->assertTrue($logos->remember(98.3, 0, $this->picture('old')));
        $this->assertTrue($logos->remember(98.3, 0, $this->picture('new logo')));

        $this->assertSame('new logo', file_get_contents((string) $logos->pathFor(98.3)));
    }

    public function testEachProgramKeepsItsOwn(): void
    {
        $logos = new StationLogos($this->directory);

        $logos->remember(98.3, 0, $this->picture('hd1'));
        $logos->remember(98.3, 1, $this->picture('hd2'));

        $this->assertSame('hd1', file_get_contents((string) $logos->pathFor(98.3, 0)));
        $this->assertSame('hd2', file_get_contents((string) $logos->pathFor(98.3, 1)));
    }

    public function testStationsDoNotShareAKey(): void
    {
        $logos = new StationLogos($this->directory);

        $logos->remember(98.3, 0, $this->picture('wrto'));
        $logos->remember(93.1, 0, $this->picture('wfez'));

        $this->assertSame('wrto', file_get_contents((string) $logos->pathFor(98.3)));
        $this->assertSame('wfez', file_get_contents((string) $logos->pathFor(93.1)));
    }

    public function testForgettingAStationTakesEveryProgramWithIt(): void
    {
        $logos = new StationLogos($this->directory);

        $logos->remember(98.3, 0, $this->picture('hd1'));
        $logos->remember(98.3, 1, $this->picture('hd2'));
        $logos->remember(93.1, 0, $this->picture('other'));

        $logos->forget(98.3);

        $this->assertNull($logos->pathFor(98.3, 0));
        $this->assertNull($logos->pathFor(98.3, 1));
        // Only the one asked for.
        $this->assertNotNull($logos->pathFor(93.1));
    }

    public function testAFrequencyOffTheBandIsRefused(): void
    {
        $logos = new StationLogos($this->directory);

        $this->assertFalse($logos->remember(1.0, 0, $this->picture('nope')));
        $this->assertNull($logos->pathFor(1.0));
    }

    public function testAnEmptyOrMissingFileIsRefused(): void
    {
        $logos = new StationLogos($this->directory);

        $this->assertFalse($logos->remember(98.3, 0, $this->directory . '/not-here.png'));
        $this->assertFalse($logos->remember(98.3, 0, $this->picture('')));
        $this->assertNull($logos->pathFor(98.3));
    }

    public function testNoHalfWrittenFileIsLeftBehind(): void
    {
        $logos = new StationLogos($this->directory);
        $logos->remember(98.3, 0, $this->picture('whole'));

        $this->assertSame([], glob("$this->directory/*.part") ?: []);
    }

    private function picture(string $contents): string
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            self::fail('could not make the temporary directory');
        }

        $path = "$this->directory/source-" . bin2hex(random_bytes(4));
        file_put_contents($path, $contents);

        return $path;
    }
}
