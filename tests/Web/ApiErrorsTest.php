<?php

namespace Skywave\Tests\Web;

use PHPUnit\Framework\TestCase;
use Skywave\Hdhomerun\Discovery;
use Skywave\Web\Api;
use Skywave\Web\LiveStreams;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the page is told when something goes wrong for a reason worth reading.
 *
 * Thirty-odd places raise a plain RuntimeException carrying a message written for whoever
 * is looking: a drive that has gone away, no room left, a transcoder that would not start.
 * Only two paths ever converted one into a response; everywhere else it escaped uncaught,
 * which is a blank 500 with the reason left in a server log. Playing a channel on a host
 * where the spawn failed showed nothing but "500 Internal Server Error".
 */
class ApiErrorsTest extends TestCase
{
    private string $directory;

    private Api $api;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/skywave-apierrors-' . bin2hex(random_bytes(6));
        mkdir($this->directory);

        // A session directory that cannot be made, because its parent is a file. Live
        // playback then fails the moment it takes its lock, which is a real failure on a
        // real route rather than a stub standing in for one.
        $blocker = $this->directory . '/blocker';
        touch($blocker);

        $this->api = new Api(new Discovery(), [], new LiveStreams($blocker . '/sessions'));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testAReasonWorthReadingReachesThePage(): void
    {
        $response = $this->api->handle(Request::create('/api/streams', 'GET'));
        $body     = json_decode((string) $response->getContent(), true);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('Unable to create', $body['error']);
    }

    public function testAStatusChosenOnPurposeIsNotFlattenedIntoFiveHundred(): void
    {
        // ApiException extends RuntimeException, so nothing but the order of the catch
        // blocks keeps a 404 a 404. Getting that order wrong would turn every deliberate
        // status in the API into a server error.
        $response = $this->api->handle(Request::create('/api/nothing-here', 'GET'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not found', json_decode((string) $response->getContent(), true)['error']);
    }
}
