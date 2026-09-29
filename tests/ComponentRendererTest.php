<?php

namespace Tests;

use Dgvirtual\Components\Config\Components;
use Dgvirtual\Components\Libraries\Component;
use Dgvirtual\Components\Libraries\ComponentRenderer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * @internal
 */
final class ComponentRendererTest extends TestCase
{
    private ComponentRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new ComponentRenderer();
        helper('cache');
    }

    protected function setCache($slotValue = null)
    {
        // Set the cache with key 'famous_quote'
        // The new format stores just the quote array (text + author).
        $quote = [
            'text'   => 'The only way to do great work is to love what you do',
            'author' => 'Steve Jobs',
        ];
        cache()->save('famous_quote', $quote, 60);
    }

    protected function emptyCache(): void
    {
        // Delete the cache key 'famous_quote'
        cache()->delete('famous_quote');
        parent::tearDown();
    }

    public function testRender()
    {
        $html   = '<div><x-button-green>Click me!</x-button-green></div>';
        $result = $this->renderer->render($html);
        $this->assertIsString($result);
    }

    public function testRenderEmpty()
    {
        $html   = '';
        $result = $this->renderer->render($html);
        $this->assertSame($result, '');
    }

    public function testRenderSelfClosingTags()
    {
        $html   = '<x-avatar src="https://example.com/myavatar" />';
        $result = $this->invokeMethod($this->renderer, 'renderSelfClosingTags', [$html]);
        $this->assertIsString($result);
        $this->assertStringContainsString('rounded-circle shadow-4', $result);
    }

    public function testRenderSelfClosingTagsControlledComponent()
    {
        $this->setCache();
        $html   = '<x-famous-quotes />'; // used as self-closing here
        $result = $this->invokeMethod($this->renderer, 'renderSelfClosingTags', [$html]);
        $this->assertIsString($result);
        $this->assertStringContainsString('blockquote-footer text-center', $result);
        $this->emptyCache();
    }

    public function testRenderPairedTags()
    {
        $html   = '<x-button-green>Click me!</x-button-green>';
        $result = $this->invokeMethod($this->renderer, 'renderPairedTags', [$html]);
        $this->assertIsString($result);
        $this->assertStringContainsString('<button', $result);
    }

    // public function testRenderPairedTagsRecursive()
    // {
    //     $html = '<div class="anything"><x-button-green><x-bootstrap-icon /> Click me!</x-button-green></div>';
    //     $result = $this->invokeMethod($this->renderer, 'renderPairedTags', [$html]);

    //     $this->assertIsString($result);
    //     $this->assertStringContainsString('<i class="bi ', $result);
    // }

    public function testRenderPairedTagsControlledComponent()
    {
        $this->setCache('Really Famous');
        $html   = '<x-famous-quotes seconds="5">Really Famous</x-famous-quotes>';
        $result = $this->invokeMethod($this->renderer, 'renderPairedTags', [$html]);
        $this->assertIsString($result);
        $this->assertStringContainsString('Really Famous', $result);
        cache()->delete('famous_quote');
        $this->emptyCache();
    }

    public function testParseAttributes()
    {
        $attributes = 'class="btn" type="button"';
        $result     = $this->invokeMethod($this->renderer, 'parseAttributes', [$attributes]);
        $this->assertIsArray($result);
        $this->assertContains('button', $result);
    }

    public function testParseAttributesNormalizesHyphenatedNames()
    {
        $attributes = 'hx-post="/delete/1" hx-target="#row" hx-confirm="Sure?"';
        $result     = $this->invokeMethod($this->renderer, 'parseAttributes', [$attributes]);

        $this->assertSame('/delete/1', $result['hx_post']);
        $this->assertSame('#row', $result['hx_target']);
        $this->assertSame('Sure?', $result['hx_confirm']);
        // The invalid original keys must not linger (extract() would drop them).
        $this->assertArrayNotHasKey('hx-post', $result);
    }

    public function testParseAttributesNormalizesOtherInvalidCharacters()
    {
        $attributes = 'data-bs-toggle="dropdown" x-on:click="open" :class="active" aria-label="Menu"';
        $result     = $this->invokeMethod($this->renderer, 'parseAttributes', [$attributes]);

        $this->assertSame('dropdown', $result['data_bs_toggle']);
        $this->assertSame('open', $result['x_on_click']);
        $this->assertSame('active', $result['_class']);
        $this->assertSame('Menu', $result['aria_label']);
    }

    public function testParseAttributesHandlesValuelessAttribute()
    {
        $attributes = 'disabled';
        $result     = $this->invokeMethod($this->renderer, 'parseAttributes', [$attributes]);

        $this->assertSame('', $result['disabled']);
    }

    public function testHyphenatedAttributeReachesView()
    {
        $view = __DIR__ . '/testHtmxView.php';
        file_put_contents($view, '<button hx-post="<?= $hx_post ?>" hx-target="<?= $hx_target ?>">x</button>');

        $attributes = $this->invokeMethod($this->renderer, 'parseAttributes', ['hx-post="/save" hx-target="#out"']);

        try {
            $result = $this->invokeMethod($this->renderer, 'renderView', [$view, $attributes]);
        } finally {
            unlink($view);
        }

        $this->assertStringContainsString('hx-post="/save"', $result);
        $this->assertStringContainsString('hx-target="#out"', $result);
    }

    public function testRenderView()
    {
        $view = __DIR__ . '/testView.php';
        $data = ['buttonText' => 'Click me!'];

        // Create a temporary view file
        $phpCode = <<<'PHP'
            <button><?php echo $buttonText; ?></button>
            PHP;
        file_put_contents($view, $phpCode);

        $result = $this->invokeMethod($this->renderer, 'renderView', [$view, $data]);

        // Clean up the temporary view file
        unlink($view);

        $this->assertIsString($result);
    }

    public function testRenderViewWithMisplacedVariable()
    {
        $view = __DIR__ . '/testViewWithMisplacedVariable.php';
        $data = ['cardTextMisnamed' => 'What a nice card!'];

        // Create a temporary view file with a missing variable
        $phpCode = <<<'PHP'
            <div class="card"><?php echo $cardText; ?></div>
            PHP;
        file_put_contents($view, $phpCode);

        $this->expectException(Throwable::class);

        try {
            $this->invokeMethod($this->renderer, 'renderView', [$view, $data]);
        } finally {
            // Clean up the temporary view file
            unlink($view);
        }
    }

    public function testFactoryIsNotInstance()
    {
        $name   = 'button-green';
        $view   = __DIR__ . '/../src/Components/button-green.php';
        $result = $this->invokeMethod($this->renderer, 'factory', [$name, $view]);
        $this->assertNull($result);
    }

    public function testFactoryIsInstance()
    {
        $name   = 'famous-quotes';
        $view   = __DIR__ . '/../src/Components/famous-quotes.php';
        $result = $this->invokeMethod($this->renderer, 'factory', [$name, $view]);
        $this->assertInstanceOf(Component::class, $result);
    }

    public function testFactoryClassNotFound()
    {
        // do mismatch: component class is valid, view file exists, but
        // does not have a corresponding class
        $name   = 'button-green';
        $view   = __DIR__ . '/../src/Components/famous-quotes.php';
        $result = $this->invokeMethod($this->renderer, 'factory', [$name, $view]);
        $this->assertNull($result);
    }

    public function testLocateView()
    {
        $name   = 'button-green';
        $result = $this->invokeMethod($this->renderer, 'locateView', [$name]);
        $this->assertIsString($result);
        $this->assertFileExists($result);
    }

    public function testLocateViewNotFound()
    {
        $name = 'yellow-button';
        // Set the expectation for the exception BEFORE invoking the method
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('View not found for component: yellow-button');
        $this->invokeMethod($this->renderer, 'locateView', [$name]);
    }

    public function testGetComponentsLookupPaths()
    {
        $result = $this->invokeMethod($this->renderer, 'getComponentsLookupPaths', []);
        $this->assertIsArray($result);
        if (is_array($result)) {
            foreach ($result as $path) {
                $this->assertStringContainsString('Components', $path);
            }
        }
    }

    public function testStripQuotes()
    {
        $string = '"quoted string"';
        $result = $this->invokeMethod($this->renderer, 'stripQuotes', [$string]);
        $this->assertSame($result, 'quoted string');
    }

    public function testBuildCacheKeyDiffersOnAttributes(): void
    {
        $view = __DIR__ . '/testCacheKeyView.php';
        file_put_contents($view, '<img src="<?= $src ?>"/>');

        $key1 = $this->invokeMethod($this->renderer, 'buildCacheKey', ['avatar', $view, ['src' => 'a.jpg'], null]);
        $key2 = $this->invokeMethod($this->renderer, 'buildCacheKey', ['avatar', $view, ['src' => 'b.jpg'], null]);

        unlink($view);

        $this->assertIsString($key1);
        $this->assertStringStartsWith('xcomp_', $key1);
        $this->assertNotSame($key1, $key2);
    }

    public function testBuildCacheKeyDiffersOnComponentCacheKey(): void
    {
        $view = __DIR__ . '/testCacheKeyView2.php';
        file_put_contents($view, '<div></div>');

        $componentA = new class () extends Component {
            public function cacheKey(): string
            {
                return 'user_1';
            }
        };
        $componentB = new class () extends Component {
            public function cacheKey(): string
            {
                return 'user_2';
            }
        };

        $key1 = $this->invokeMethod($this->renderer, 'buildCacheKey', ['widget', $view, [], $componentA]);
        $key2 = $this->invokeMethod($this->renderer, 'buildCacheKey', ['widget', $view, [], $componentB]);

        unlink($view);

        $this->assertNotSame($key1, $key2);
    }

    public function testRenderCachedViewOnlyStoresInCache(): void
    {
        $view = __DIR__ . '/testCacheStoreView.php';
        file_put_contents($view, '<span><?= $label ?? "ok" ?></span>');

        $attrs    = ['label' => 'cached!'];
        $cacheKey = $this->invokeMethod($this->renderer, 'buildCacheKey', ['test-widget', $view, $attrs, null]);
        cache()->delete($cacheKey);

        $config               = config(Components::class);
        $originalTtl          = $config->viewCacheTtl;
        $config->viewCacheTtl = 60;

        try {
            $result = $this->invokeMethod($this->renderer, 'renderCached', ['test-widget', $view, $attrs, null]);
        } finally {
            $config->viewCacheTtl = $originalTtl;
            unlink($view);
        }

        $this->assertIsString($result);
        $this->assertStringContainsString('cached!', $result);

        $stored = cache($cacheKey);
        $this->assertSame($result, $stored);

        cache()->delete($cacheKey);
    }

    public function testRenderCachedReturnsCachedValueOnHit(): void
    {
        $view = __DIR__ . '/testCacheHitView.php';
        file_put_contents($view, '<span>fresh</span>');

        $attrs    = [];
        $cacheKey = $this->invokeMethod($this->renderer, 'buildCacheKey', ['hit-widget', $view, $attrs, null]);
        cache()->save($cacheKey, '<span>from cache</span>', 60);

        $config               = config(Components::class);
        $originalTtl          = $config->viewCacheTtl;
        $config->viewCacheTtl = 60;

        try {
            $result = $this->invokeMethod($this->renderer, 'renderCached', ['hit-widget', $view, $attrs, null]);
        } finally {
            $config->viewCacheTtl = $originalTtl;
            unlink($view);
        }

        $this->assertSame('<span>from cache</span>', $result);

        cache()->delete($cacheKey);
    }

    public function testRenderCachedClassComponentStoresInCache(): void
    {
        $view = __DIR__ . '/testClassCacheView.php';
        file_put_contents($view, '<b><?= $text ?? "" ?></b>');

        $component = new class () extends Component {
            public ?int $cacheTtl = 60;

            public function render(): string
            {
                return '<b>class result</b>';
            }
        };

        $attrs    = ['text' => 'hello'];
        $cacheKey = $this->invokeMethod($this->renderer, 'buildCacheKey', ['class-widget', $view, $attrs, $component]);
        cache()->delete($cacheKey);

        $result = $this->invokeMethod($this->renderer, 'renderCached', ['class-widget', $view, $attrs, $component]);

        unlink($view);

        $this->assertSame('<b>class result</b>', $result);
        $this->assertSame($result, cache($cacheKey));

        cache()->delete($cacheKey);
    }

    public function testRenderCachedSkipsCacheWhenTtlIsNull(): void
    {
        $view = __DIR__ . '/testNoCacheView.php';
        file_put_contents($view, '<i>no cache</i>');

        $attrs    = [];
        $cacheKey = $this->invokeMethod($this->renderer, 'buildCacheKey', ['no-cache-widget', $view, $attrs, null]);
        cache()->delete($cacheKey);

        // viewCacheTtl stays null (default) — no caching
        $result = $this->invokeMethod($this->renderer, 'renderCached', ['no-cache-widget', $view, $attrs, null]);

        unlink($view);

        $this->assertStringContainsString('no cache', $result);
        $this->assertNull(cache($cacheKey));
    }

    public function testRenderResolvesNestedSelfClosingWithinSelfClosing(): void
    {
        // Create a temporary component view that itself contains a
        // self-closing component tag. This tests the fixed-point loop
        // in render(): a self-closing component whose output contains
        // another self-closing tag must be re-scanned in subsequent
        // iterations instead of leaking the inner tag unrendered.
        $outerView = __DIR__ . '/nested-selfclose-test.php';
        file_put_contents(
            $outerView,
            '<div class="outer"><x-bootstrap-icon img="star" /><p>inner</p></div>',
        );

        $config                        = config(Components::class);
        $originalPaths                 = $config->componentsLookupPaths;
        $config->componentsLookupPaths = [
            __DIR__ . '/',                                  // finds nested-selfclose-test.php
            __DIR__ . '/../src/Components/',                // finds bootstrap-icon.php
        ];

        // Reset the static view-path cache via reflection so locateView()
        // picks up the temporary lookup paths.
        $resetCache = static function (): void {
            $prop = new ReflectionClass(ComponentRenderer::class);
            $prop = $prop->getProperty('viewPathCache');
            $prop->setAccessible(true);
            $prop->setValue([]);
        };
        $resetCache();

        try {
            $html   = '<x-nested-selfclose-test />';
            $result = $this->renderer->render($html);

            $this->assertStringContainsString('bi-star', $result, 'Inner self-closing component should be resolved');
            $this->assertStringContainsString('class="outer"', $result, 'Outer component should be rendered');
            $this->assertStringContainsString('<p>inner</p>', $result, 'Literal HTML in outer view should be preserved');
        } finally {
            unlink($outerView);
            $config->componentsLookupPaths = $originalPaths;
            $resetCache();
        }
    }

    protected function invokeMethod(&$object, $methodName, array $parameters = [])
    {
        $reflection = new ReflectionClass($object::class);
        $method     = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}
