# Machinjiri PHP Framework

Machinjiri is a PHP framework for building web applications, APIs, and internal tools. This guide is written as a first project tutorial: install Machinjiri, run an app, add a route, and render a page.

![Machinjiri PHP Framework](https://img.shields.io/badge/Machinjiri-PHP_Framework-6C2EB9?logo=php&logoColor=white)
![Version 2.3.2](https://img.shields.io/badge/Version-2.3.2-2563EB)
![PHP ^8.4](https://img.shields.io/badge/PHP-%5E8.4-777BB4?logo=php&logoColor=white)
![Composer 2.0+](https://img.shields.io/badge/Composer-2.0%2B-885630?logo=composer&logoColor=white)
![Proprietary license](https://img.shields.io/badge/License-Proprietary-lightgrey)

## What you'll build

By the end of this tutorial, your app will respond to `/` with a page that is rendered from a view template. You will learn how routes, controllers, and views work together.

## Before you begin

You will need:

- PHP 8.4 or newer
- Composer 2.0 or newer
- A terminal and a code editor

Machinjiri also uses PHP extensions including PDO, cURL, JSON, and OpenSSL. Some features need additional extensions, such as LDAP or FTP.

## 1. Create a new application

Install the Machinjiri installer globally with Composer:

```bash
composer global require machinjiri/installer
```

Create a project. You can replace `hello-machinjiri` with your preferred project name:

```bash
machinjiri create hello-machinjiri
```

Follow the installer's prompts, then change into the new project directory:

```bash
cd hello-machinjiri
```

If your terminal cannot find the `machinjiri` command, make sure Composer's global vendor `bin` directory is on your `PATH`.

## 2. Start the development server

Run the app's local development server:

```bash
php artisan run:dev
```

Open [http://localhost:3000](http://localhost:3000) in your browser. Keep the terminal open while you work; it displays server output. Press `Ctrl+C` to stop it.

## 3. Add your first route

A route connects a URL to the code that handles a request. Open `routes/web.php` and add a route for the home page:

```php
<?php

use Mlangeni\Machinjiri\Core\Routing\Router;

Router::get('/', function () {
    return '<h1>Hello from Machinjiri!</h1>';
}, 'home');

Router::dispatch();
```

Refresh `http://localhost:3000`. The route matches `GET /`, runs the function, and sends the returned HTML to the browser. The optional name, `home`, lets you refer to this route elsewhere in your app.

> If your generated `routes/web.php` already dispatches routes, keep that existing `Router::dispatch()` call and add only the `Router::get(...)` definition.

## 4. Move the page into a controller

As an app grows, it is useful to move request handling out of the route file. Create `app/Controllers/HomeController.php`:

```php
<?php

namespace App\Controllers;

use Mlangeni\Machinjiri\Core\Artisans\Base\AbstractController;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;

class HomeController extends AbstractController
{
    public function index(HttpRequest $request, HttpResponse $response): string
    {
        return $this->view('home', [
            'title' => 'My first Machinjiri page',
            'message' => 'You have connected a route, controller, and view.',
        ]);
    }
}
```

Update the route in `routes/web.php` to point to this controller:

```php
<?php

use App\Controllers\HomeController;
use Mlangeni\Machinjiri\Core\Routing\Router;

Router::get('/', [HomeController::class, 'index'], 'home');

Router::dispatch();
```

The router creates the controller and passes it the current request and response. `AbstractController` provides the `view()` helper, which renders a template and returns its HTML.

## 5. Create a view

Create `resources/views/home.view.php`:

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
</head>
<body>
    <main>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>
    </main>
</body>
</html>
```

The `{{ ... }}` syntax prints and HTML-escapes a value, which is the safe default for text. The controller passes `$title` and `$message` to the view as an array. Save the files and refresh the browser to see the finished page.

Your app's request flow is now:

1. The browser requests `/`.
2. The router matches the request to `HomeController::index`.
3. The controller passes data to the `home` view.
4. The view renders HTML for the browser.

## 6. Add another page

You can add a second route that accepts a URL parameter. Add this method to `HomeController`:

```php
public function greeting(HttpRequest $request, HttpResponse $response, string $name): string
{
    return $this->view('greeting', ['name' => $name]);
}
```

Register the route in `routes/web.php`:

```php
Router::get('/hello/{name}', [HomeController::class, 'greeting'], 'greeting');
```

Create `resources/views/greeting.view.php`:

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hello</title>
</head>
<body>
    <h1>Hello, {{ $name }}!</h1>
    <p><a href="/">Back to the home page</a></p>
</body>
</html>
```

Visit `http://localhost:3000/hello/Ada` to try it. The `{name}` part of the route is passed to the controller method, and the view escapes it when displaying it.

## Everyday development

From your application directory:

```bash
# See the commands available in this app
php artisan list

# Run the test suite
composer test
```

If you change a route or view and do not see the change in your browser, check that the development server is still running and reload the page. If a command is not recognized, run it from the application directory and confirm the project dependencies were installed.

## Where to go next

Machinjiri includes tools for building beyond this first page:

- **Routing:** HTTP methods, route parameters, groups, middleware, named routes, and CORS
- **Views:** layouts, sections, includes, and template directives
- **Database:** query builder, migrations, schema builder, seeders, and factories
- **Security:** sessions, cookies, password hashing, CSRF protection, and encryption
- **Queues and scheduling:** background jobs, queue workers, and persistent scheduled tasks
- **Integrations:** webhooks, SMS transports, mail, Redis, and FTP storage

Explore the generated `app/`, `config/`, `database/`, `resources/`, and `routes/` directories as you build. Keep credentials and environment-specific settings in your `.env` file rather than hard-coding them in controllers or views.

## System requirements

- **PHP:** 8.4 or newer (`^8.4` in Composer)
- **Composer:** 2.0 or newer
- **Required PHP extensions:** PDO, cURL, JSON, and OpenSSL
- **Optional extensions:** FTP for FTP filesystem support; LDAP for LDAP integration
- **Database options:** MySQL 5.7+, PostgreSQL 10+, or SQLite 3+

The database is only needed when your app uses database-backed features.

## About Machinjiri 2.3.2

Version 2.3.2 focuses on more reliable queue processing, stronger security APIs, and a leaner framework surface. This release also updates terminal tooling and aligns the documentation with features included in the package.

- Refined queue job contracts, drivers, retries, and worker commands
- Added configurable password policies and password hashing options
- Added configurable encryption, including authenticated AES-GCM support
- Strengthened CSRF token handling
- Removed bundled Bootstrap UI components and framework-specific testing helpers; use the UI library and PHPUnit setup that fit your application

## Documentation

The beginner-to-advanced wiki is available in [Machinjiri Wiki Documentation](https://github.com/mlangeni-group/machinjiri/wiki/). Start with [Getting Started](https://github.com/mlangeni-group/machinjiri/wiki/Getting-Started), then follow the topic links from its home page.

## License

Machinjiri is distributed under a **proprietary license**. See [LICENSE](LICENSE) for details.

## Help and support

- [Report a bug or request a feature](https://github.com/mlangeni/machinjiri/issues)
- [Ask a question or share ideas](https://github.com/mlangeni/machinjiri/discussions)
- Email: [precious.lyson@gmail.com](mailto:precious.lyson@gmail.com)

## Authors

- **Precious Lyson** — [GitHub](https://github.com/precious-lyson)
- **Mlangeni Group** — [Website](https://www.facebook.com/profile.php?id=61590389113242)
