<?php

namespace Tests;

use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\ControllerTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use Dgvirtual\Components\Controllers\FamousQuotes;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the async fetch endpoint of the famous-quotes component.
 *
 * The controller reads the quote from a URL stored in a property. The
 * `FakeFamousQuotesController` below points that property at a local file so
 * the tests never touch the network; the endpoint is otherwise the real one.
 *
 * @internal
 */
final class FamousQuotesControllerTest extends CIUnitTestCase
{
    use ControllerTestTrait;

    private const CACHE_KEY = 'famous_quote';

    /**
     * Dummy URL scheme used to simulate an API that fails with an exception.
     */
    private const FAILING_PROTOCOL = 'xcompquoteboom';

    /**
     * Cache double that records the TTL of every save() call, so the
     * `seconds` query parameter handling can be asserted precisely.
     */
    private RecordingMockCache $cache;

    /**
     * Path of the local file used in place of the external API endpoint.
     */
    private string $apiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        // CIUnitTestCase injects a plain MockCache; replace it with the
        // recording one so TTLs are observable.
        $this->cache = new RecordingMockCache();
        Services::injectMock('cache', $this->cache);

        $path = tempnam(sys_get_temp_dir(), 'zenquotes');
        if ($path === false) {
            $this->fail('Unable to create a temporary API fixture file.');
        }

        $this->apiFixture                    = $path;
        FakeFamousQuotesController::$apiNode = $this->apiFixture;
    }

    protected function tearDown(): void
    {
        FakeFamousQuotesController::$apiNode = '';

        if (is_file($this->apiFixture)) {
            unlink($this->apiFixture);
        }

        parent::tearDown();
    }

    public function testFetchReturnsCachedQuoteWithoutCallingTheApi(): void
    {
        $cached = ['text' => 'Cached quote', 'author' => 'Cached author'];
        $this->cache->save(self::CACHE_KEY, $cached, 60);

        // Ignore the seeding save above when checking for later writes.
        $this->cache->ttls = [];

        // If the API were consulted this is what would be returned instead.
        $this->writeApiResponse('[{"q":"API quote","a":"API author"}]');

        $result = $this->controller(FakeFamousQuotesController::class)->execute('fetch');

        $result->assertStatus(200);
        $result->assertJSONExact(['quote' => $cached, 'cached' => true]);

        // Nothing was fetched, so nothing was written to the cache.
        $this->assertSame([], $this->cache->ttls);
    }

    public function testFetchCallsApiAndCachesQuoteWithRequestedTtl(): void
    {
        $this->writeApiResponse('[{"q":"Fresh quote","a":"Fresh author"}]');
        $this->request->setGlobal('get', ['seconds' => '120']);

        $result = $this->controller(FakeFamousQuotesController::class)->execute('fetch');

        $result->assertStatus(200);
        $result->assertJSONExact([
            'quote'  => ['text' => 'Fresh quote', 'author' => 'Fresh author'],
            'cached' => false,
        ]);

        $this->assertSame(120, $this->cache->ttls[self::CACHE_KEY]);

        // A second request is served from the cache, proving the quote was stored.
        $second = $this->controller(FakeFamousQuotesController::class)->execute('fetch');

        $second->assertJSONExact([
            'quote'  => ['text' => 'Fresh quote', 'author' => 'Fresh author'],
            'cached' => true,
        ]);
    }

    public function testFetchUsesDefaultTtlWhenSecondsIsNotNumeric(): void
    {
        $this->writeApiResponse('[{"q":"Fresh quote","a":"Fresh author"}]');
        $this->request->setGlobal('get', ['seconds' => 'not-a-number']);

        $this->controller(FakeFamousQuotesController::class)->execute('fetch');

        $this->assertSame(5, $this->cache->ttls[self::CACHE_KEY]);
    }

    public function testFetchRoundsTheRequestedSeconds(): void
    {
        $this->writeApiResponse('[{"q":"Fresh quote","a":"Fresh author"}]');
        $this->request->setGlobal('get', ['seconds' => '10.6']);

        $this->controller(FakeFamousQuotesController::class)->execute('fetch');

        $this->assertSame(11, $this->cache->ttls[self::CACHE_KEY]);
    }

    public function testFetchFallsBackWhenApiReturnsInvalidJson(): void
    {
        $this->writeApiResponse('this is not json');

        $result = $this->controller(FakeFamousQuotesController::class)->execute('fetch');

        $result->assertStatus(200);
        $result->assertJSONExact([
            'quote' => [
                'text'   => 'The only way to do great work is to love what you do',
                'author' => 'Steve Jobs',
            ],
            'cached' => false,
        ]);

        // The fallback must not be cached.
        $this->assertNull($this->cache->get(self::CACHE_KEY));
    }

    public function testFetchFallsBackWhenApiReturnsNoQuote(): void
    {
        $this->writeApiResponse('[]');

        $result = $this->controller(FakeFamousQuotesController::class)->execute('fetch');

        $result->assertStatus(200);
        $result->assertJSONFragment(['cached' => false]);
        $result->assertJSONFragment(['quote' => ['author' => 'Steve Jobs']]);

        $this->assertNull($this->cache->get(self::CACHE_KEY));
    }

    public function testFetchFallsBackWhenApiCallThrows(): void
    {
        // A stream wrapper that always fails covers the `catch (Exception)`
        // branch, which a plain network failure does not reach.
        $this->assertTrue(
            stream_wrapper_register(self::FAILING_PROTOCOL, ThrowingStreamWrapper::class),
            'Unable to register the fake stream wrapper.',
        );
        FakeFamousQuotesController::$apiNode = self::FAILING_PROTOCOL . '://quote';

        try {
            $result = $this->controller(FakeFamousQuotesController::class)->execute('fetch');
        } finally {
            stream_wrapper_unregister(self::FAILING_PROTOCOL);
        }

        $result->assertStatus(200);
        $result->assertJSONFragment(['cached' => false]);
        $result->assertJSONFragment(['quote' => ['author' => 'Steve Jobs']]);

        $this->assertNull($this->cache->get(self::CACHE_KEY));
    }

    public function testFetchFallsBackWhenApiIsUnreachable(): void
    {
        // Point at a path that does not exist: file_get_contents() returns
        // false (after emitting a warning) rather than throwing.
        FakeFamousQuotesController::$apiNode = $this->apiFixture . '.missing';

        // Silence the expected "failed to open stream" warning so it is not
        // reported as a PHPUnit issue.
        set_error_handler(static fn (): bool => true);

        try {
            $result = $this->controller(FakeFamousQuotesController::class)->execute('fetch');
        } finally {
            restore_error_handler();
        }

        $result->assertStatus(200);
        $result->assertJSONFragment(['cached' => false]);
        $result->assertJSONFragment(['quote' => ['author' => 'Steve Jobs']]);

        $this->assertNull($this->cache->get(self::CACHE_KEY));
    }

    private function writeApiResponse(string $json): void
    {
        file_put_contents($this->apiFixture, $json);
    }
}

/**
 * Controller double that reads from a local file instead of the real
 * ZenQuotes endpoint, avoiding any network access during tests.
 */
final class FakeFamousQuotesController extends FamousQuotes
{
    /**
     * Path the controller should read instead of the real API URL.
     * Set it before the controller is instantiated.
     */
    public static string $apiNode = '';

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);

        if (self::$apiNode !== '') {
            $this->famousQuotesAPINode = self::$apiNode;
        }
    }
}

/**
 * MockCache that keeps a record of the TTL passed to every save() call.
 */
final class RecordingMockCache extends MockCache
{
    /**
     * TTL (in seconds) recorded for each cache key, keyed by that key.
     *
     * @var array<string, int>
     */
    public array $ttls = [];

    public function save(string $key, $value, int $ttl = 60): bool
    {
        $this->ttls[$key] = $ttl;

        return parent::save($key, $value, $ttl);
    }
}

/**
 * Stream wrapper whose stream_open() always throws, used to simulate the
 * external API raising an exception.
 */
final class ThrowingStreamWrapper
{
    /**
     * @var resource|null
     */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        throw new RuntimeException('Simulated API failure.');
    }
}
