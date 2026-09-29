# Components for Codeigniter 4

![Build Status](https://github.com/dgvirtual/codeigniter4-components/actions/workflows/phpunit.yml/badge.svg)
![Coverage](https://codecov.io/gh/dgvirtual/codeigniter4-components/branch/develop/graph/badge.svg)

PHP library _Components for Codeigniter 4_ allows you to create custom HTML
elements to use within your views. They allow to encapsulate html and css
classes/styles into reusable website building blocks with the content and
attributes of your choosing. They are written in regular PHP/CSS/HTML. Such
custom HTML elements can include other HTML elements (custom or default).

Custom component tag always starts with `x-` (like
`<x-button-green>Click Me!</x-button-green>`).

To illustrate, with Components you write this in your view:

```php
<x-button-green onclick="alert('I was clicked!')">
   <?= $clickMeLabel ?>
</x-button-green>
```

Which, is merged with the Component definition:

```php
<button
  style="color: white; background-color: green;"
  <?= isset($onclick) ? 'onclick="' . $onclick . '"' : '' ?>
>
  <?= $slot ?>
</button>
```

And results in this in the browser:

```html
<button
  style="color: white; background-color: green;"
  onclick="alert('I was clicked!')"
>
  Click Me!
</button>
```

## Composer Installation

To install in an _existing composer project_, run in command line:

```bash
composer require dgvirtual/codeigniter4-components:dev-develop
```

## Manual Installation

Let's say you want to put the library into the `app/ThirdParty` directory.

1. Download and unzip the code, copy the `codeigniter4-components` folder to the
   `app/ThirdParty` directory.

2. To enable Codeigniter to find the library, edit the `app/Config/Autoload.php`
   file, add the Components library to the `$psr4` property:

```php
public $psr4 = [
  APP_NAMESPACE => APPPATH,
  'Dgvirtual\Components' => APPPATH . 'ThirdParty/codeigniter4-components/src', // this line
];
```

## Configuration

Edit the `app/Config/View.php` file. Add the following array element to the
`$decorators` property:

```php
public array $decorators = [
  'Dgvirtual\Components\Libraries\ComponentDecorator', // this line
];
```

## Try If It Works

**To check if it works with the example components**, put the string
`<x-button-green>This should look like a button</x-button-green>` in any of your
views and see if it renders as a button (if not, all you will see will be simple
text).

Now you can make some components of your own and put them in
`app/Views/Components` folder to make them usable in your app views.

## How To Write and Use Components

Example components of all three types listed below are available in the
`src/Components` folder of the project. They can be used immediately.

### Self-Closing Tag Components

At their most basic, components serve as dynamic templates that allow you to
reduce the typing in your application. This can help boil longer, complex
sections down to a single HTML tag. This is especially useful with CSS utility
frameworks like TailWind, or when using the utilities in Bootstrap 5, etc. Using
components in these situations allows you to keep the style info in one place
where making changes to one file changes every instance of the view throughout
the application.

To create a component, simply create a new view file within the
`app\Views\Components` directory or another place made accessible as described
in the installation step 4 above.

A simple avatar image component `avatar.php` might look something like this:

```php
<img
  src="<?= $src ?? '' ?>"
  class="rounded-circle shadow-4"
  style="width: <?= $width ?? '150px' ?>;"
  alt="<?= $alt ?? '' ?>"
/>
```

When using the component within a view, you would insert a tag with `x-`
prepended to the filename:

```php
<x-avatar src="<?= $userAvatarURL ?>" alt="<?= $userName ?>" />
```

Any attributes provided when you insert the component like this are made
available as variables within the component view. In this case, the `$src` and
`$alt` attributes are passed to the component view, resulting in the following
output:

```html
<img
  src="http://example.com/avatars/foo.jpg"
  class="rounded-circle shadow-4"
  style="width: 150px"
  alt="John Smith"
/>
```

#### Attribute names with hyphens (`hx-*`, `data-*`, `aria-*`, …)

Component views are rendered with PHP's `extract()`, which only creates
variables for valid PHP names. Attributes such as `hx-post`,
`data-bs-toggle`, `aria-label`, `x-on:click` or `:class` would therefore be
**silently dropped**. To avoid that, every character that is not a letter,
digit or underscore is replaced with `_` when the tag is parsed:

| Attribute in the tag | Variable in the component view |
| -------------------- | ------------------------------ |
| `hx-post="/save"`    | `$hx_post`                     |
| `hx-target="#row"`   | `$hx_target`                   |
| `data-bs-toggle`     | `$data_bs_toggle`              |
| `aria-label="Menu"`  | `$aria_label`                  |

The component view then writes the original attribute name itself:

```php
<button
  <?= isset($hx_post) ? 'hx-post="' . $hx_post . '"' : '' ?>
  <?= isset($hx_target) ? 'hx-target="' . $hx_target . '"' : '' ?>
>
  <?= $slot ?>
</button>
```

### Components With Opening and Closing Tags

You can include the content within the opening and closing tags by inserting the
reserved `$slot` variable:

```php
<x-button-green onclick="alert('I was clicked!')">
  Click Me!
</x-button-green>
```

The component `button-green.php` would look like this:

```php
<button
  style="color: white; background-color: green;"
  <?= isset($onclick) ? 'onclick="' . $onclick . '"' : '' ?>
  type="<?= $type ?? 'submit' ?>"
>
  <?= $slot ?>
</button>
```

The rendered html would look like this:

```html
<button
  style="color: white; background-color: green;"
  onclick="alert('I was clicked!')"
  type="submit"
>
  Click Me!
</button>
```

### Controlled Components

Finally, you can create a class to add additional logic to the output. The file
must be in the same directory as the component view and should have a name that
is the PascalCase version of the filename, with 'Component' added to the end of
it.

A `famous-quotes` component would have a view called `famous-quotes.php` and a
controlling class called `FamousQuotesComponent.php`. The class must extend
`Dgvirtual\Components\Libraries\Component`. The only requirement is that you
implement a method called `render()`.

You would call it in one of the ways previously described.

See a usable basic example of `famous-quotes` component in the
`src/Components` folder.

#### Async API example: `famous-quotes`

The `famous-quotes` component demonstrates a hybrid server/client pattern:

1. **On render**, the component checks its server-side cache first.
2. **If a cached quote exists**, it is rendered directly into the HTML — zero
   client-side work, instant display.
3. **If no cached quote exists**, a loading placeholder is rendered together
   with a small inline `<script>` that fetches the quote asynchronously via
   `fetch()` from a dedicated endpoint. That endpoint calls the external
   ZenQuotes API, caches the result, and returns JSON. The JavaScript then
   populates the DOM.

This keeps the external API call off the critical rendering path while still
caching the result so that subsequent page loads serve the cached version
directly (step 2).

**Required route:** Add the following line to `app/Config/Routes.php`:

```php
$routes->get('famous-quotes/fetch', '\Dgvirtual\Components\Controllers\FamousQuotes::fetch');
```

Insert the component in any of these ways:

```html
  <x-famous-quotes />
```

```html
  <x-famous-quotes seconds="30" />
```

```html
  <x-famous-quotes seconds="30">Famous Quotes</x-famous-quotes>
```

The `seconds` attribute controls how long the fetched quote is cached (default:
5 seconds).

## Advanced Configuration

Review the `src/Config/Components.php` file. The default configuration specifies
that the app will search for component files in two locations:

```php
public $componentsLookupPaths = [
  // your local components
  APPPATH . 'Views/Components/',
  // example components
  __DIR__ . '/../Components/',
];
```

If you want the app to find components located elsewhere, copy this file to your
`app/Config` directory, change the namespace of the copy to `namespace Config;`,
and edit the `$componentsLookupPaths` to list all the locations where you want
the app to look for components. The project will then use this config instead of
the library's own.

_Note:_ The _first component found_ in the lookup paths will be used. Therefore,
if you have custom components, list their paths first, and the default ones
last.

## Component Output Caching

By default every component re-renders on every request. For pages with many
components, or for components that hit a database or an external API, you can
enable output caching so that the rendered HTML is stored in CI4's cache service
and returned immediately on subsequent requests.

### How the cache key works

The renderer builds a deterministic cache key from:

- the component name
- the view file path **and its last-modified time** (so the cache automatically
  invalidates after you change the file and deploy)
- all tag attributes (including the `$slot` content for paired-tag components)
- an optional extra contributor from `Component::cacheKey()` (see below)

Different attribute values produce different cache entries, so
`<x-avatar src="a.jpg" />` and `<x-avatar src="b.jpg" />` are cached
independently.

### Caching view-only components (no class)

Set `$viewCacheTtl` in your `app/Config/Components.php`:

```php
namespace Config;

use Dgvirtual\Components\Config\Components as BaseComponents;

class Components extends BaseComponents
{
    public $componentsLookupPaths = [
        APPPATH . 'Views/Components/',
    ];

    /**
     * Cache all view-only components for 10 minutes.
     * Set to null (default) to disable.
     */
    public ?int $viewCacheTtl = 600;
}
```

This applies to every component that has no companion `*Component.php` class.
Components backed by a class are unaffected by this setting; they use
`$cacheTtl` on the class instead (see below).

### Caching class-based components

Add the `$cacheTtl` property to your component class:

```php
class AvatarComponent extends Component
{
    /**
     * Cache the rendered HTML for 1 hour.
     * Null (default) means no renderer caching.
     */
    public ?int $cacheTtl = 3600;
}
```

### Personalised components: adding extra cache-key contributors

If a component's output depends on something beyond its attributes — such as the
currently logged-in user — override `cacheKey()` so that different contexts
produce different cache entries:

```php
class UserCardComponent extends Component
{
    public ?int $cacheTtl = 300;

    public function cacheKey(): string
    {
        // One cache entry per user
        return (string) session('user_id');
    }
}
```

Without this, every user would receive the first user's cached HTML.

### Important: know what your component renders

Before enabling caching, make sure you account for **every** input that
influences the HTML output:

| Input source | Covered automatically? |
| --- | --- |
| Tag attributes | Yes |
| `$slot` content | Yes (it is part of attributes) |
| View file content | Yes (mtime-based) |
| Database / API calls | **No** — set a suitable TTL |
| `session()` / logged-in user | **No** — use `cacheKey()` |
| `$_SERVER`, `$_GET`, etc. | **No** — use `cacheKey()` |

When in doubt, leave `$cacheTtl = null` (the default) and let the component
manage its own caching internally (as the `famous-quotes` example does).

### CI4 cache backend

The renderer uses CI4's `cache()` helper, which reads your application's
`app/Config/Cache.php` settings. Switching from file cache to Redis or Memcached
there automatically applies to component caching as well — no changes needed
here.

## Credits

This project is an adaptation of Bonfire2 Component rendering functionality for
general CodeIgniter 4 use. Bonfire2 was created by Lonnie Ezell
<lonnieje@gmail.com> and contributors. For more information, visit the
[Bonfire2 project](https://github.com/lonnieezell/Bonfire2).

The adaptation and package was created by Donatas Glodenis. You can reach out to
me at [dg@lapas.info] for any questions or feedback.

## License

This project is licensed under the MIT License. See the LICENSE file for
details.
