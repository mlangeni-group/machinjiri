# Machinjiri PHP Framework

Machinjiri is a **flexible, powerful PHP framework** designed for rapid web development. Built for PHP 8.3+, it provides a modular architecture, elegant routing system, comprehensive database abstraction, authentication and authorization, and robust security features. Designed for speed, scalability, and developer experience, Machinjiri empowers developers to build secure, maintainable applications efficiently.

Current stable version: `2.2.3`

## What’s New in 2.2.3

- Added a persistent task scheduler with cron expressions, queued execution, overlap locks, task groups, priorities, retries, caching, and health checks
- Added scheduler Artisan commands for creating, listing, running, enabling, disabling, and inspecting scheduled tasks
- Improved webhook processing with provider subscriptions, configurable synchronous or asynchronous handling, signature verification, response handling, and idempotency support
- Reworked exception handling into separate context, logging, reporting, rendering, and throttling services
- Added configurable SMS transports with synchronous delivery and queue-backed asynchronous delivery
- Updated core routing, middleware resolution, service-provider loading, generators, and mail integration
- Updated framework support to **PHP 8.3+** and package version `2.2.2`

## Table of Contents

- [Introduction](#introduction)
- [Features](#features)
- [System Requirements](#system-requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Project Structure](#project-structure)
- [Core Components](#core-components)
  - [Routing](#routing-system)
  - [Views & Templates](#view-engine)
  - [Database](#database--orm)
  - [Authentication & Security](#authentication--security)
    - [LDAP](#ldap)
  - [Forms & Validation](#forms--validation)
  - [Service Providers](#service-providers)
  - [Mail System](#mail-system)
  - [Queues & Jobs](#queues--jobs)
    - [Task Scheduler](#task-scheduler)
    - [Webhooks](#webhooks)
    - [SMS](#sms)
- [Usage Examples](#usage-examples)
- [Configuration](#-configuration)
- [API Reference](#-api-reference)
- [Error Handling](#-error-handling)
- [Testing](#testing)
- [Console Commands (Artisan)](#console-commands)
- [Contributing](#contributing)
- [License](#license)
- [Support](#support)

## Introduction

Machinjiri is designed to accelerate web development with:
- **Modular architecture** with service providers and dependency injection
- **Elegant routing** with middleware, groups, and named routes
- **Powerful view templating** with inheritance and asset management
- **Multi-database support** with migrations and query builders
- **Built-in authentication** with OAuth, sessions, and cookies
- **Advanced security** with encryption, hashing, CSRF tokens, and SQL injection prevention
- **Job queues** for background processing
- **Task scheduling** with cron expressions and persistent execution history
- **Webhook handling** with signature verification and synchronous or queued dispatch
- **SMS transport** with configurable providers and asynchronous delivery
- **Comprehensive logging** and error handling

## Features

### Core Architecture

- **Singleton Application Container**: Centralized management of application resources and configuration
- **Service Container**: Powerful dependency injection with service binding and resolution
- **Provider System**: Modular service providers with lazy loading and bootstrapping
- **Configuration Management**: Environment-aware config loading with `.env` support
- **Service Facades**: Quick access to complex services with simple syntax
- **FTP Filesystem Adapter**: Remote storage operations with FTP disk support
- **Redis Integration**: Distributed cache and queue support for scalable workflows
- **Webhook Processing**: Signature verification, idempotency, and async dispatch for webhook handling
- **ULID & OTP Utilities**: ULID generation/validation and OTP/TOTP support for secure workflows

### Routing System

- **Flexible HTTP Routing**: RESTful routes with GET, POST, PUT, DELETE, PATCH methods
- **Route Groups**: Apply middleware, prefixes, and CORS to multiple routes
- **Route Parameters**: Capture and validate route parameters
- **AJAX Handling**: Built-in AJAX-only and traditional route segregation
- **Named Routes**: Generate URLs using route names and parameters
- **CORS Support**: Automatic CORS header handling and preflight requests
- **Rate Limiting**: Configurable request rate limiting per route
- **Route Caching**: Performance optimization through route caching

### View Engine

- **Template Inheritance**: Blade-like template inheritance with layouts and sections
- **Custom Template Tags**: `<% %>` syntax with PHP logic support
- **Partial Includes**: Component-based development with fragment includes
- **Asset Management**: Automatic CSS/JS resource loading
- **Data Sharing**: Share data across multiple views globally
- **Loop Directives**: Enhanced foreach loops with context variables

### Database & ORM

- **Multi-Driver Support**: MySQL, PostgreSQL, SQLite database drivers
- **Query Builder**: Expressive, fluent query construction
- **Migrations**: Built-in migration system for database schema management
- **Schema Builder**: Create, modify, and drop tables programmatically
- **Seeders & Factories**: Database seeding and model factories for testing
- **Connection Pool**: Manage multiple database connections
- **Transaction Support**: ACID compliance with transaction handling

### Authentication & Security

- **Session Management**: Secure session handling with configurable drivers
- **OAuth Integration**: Third-party authentication (Google, GitHub, etc.)
- **Cookie Management**: Secure cookie handling with encryption options
- **Password Hashing**: Built-in password hashing with bcrypt/Argon2
- **CSRF Token Protection**: Automatic CSRF token generation and validation
- **SQL Injection Prevention**: Parameterized queries via query builder
- **Encryption**: AES encryption/decryption for sensitive data
- **JWT Tokens**: JSON Web Token support for API authentication

### Forms & Validation

- **Form Validation**: Comprehensive validation rules engine
- **Rule Builder**: Fluent interface for building complex validation rules
- **Password Rules**: Special validation rules for password strength
- **Error Messages**: Custom error messages and localization support
- **Form Handler**: Server-side form processing and CSRF protection

### Queues & Jobs

- **Background Job Processing**: Defer heavy operations to background queues
- **Database Queue Driver**: Persist jobs in database for reliability
- **Redis Queue Driver**: High-performance Redis-backed job queue with delayed dispatch, reservation, and retry handling
- **Job Dispatcher**: Flexible job scheduling and dispatching
- **Workers**: Process jobs with configurable retry logic
- **Event System**: Event listeners and viewers for queue events
- **Artisan Commands**: Generate jobs and manage queue processing

### Task Scheduler

- **Persistent schedules**: Store scheduled tasks and execution history through the database repository
- **Cron expressions**: Define when tasks run using standard cron syntax
- **Queued execution**: Dispatch due tasks to the scheduler queue
- **Overlap protection**: Lock tasks to prevent concurrent execution
- **Task management**: Register, sync, enable, disable, run, and delete tasks
- **Operational controls**: Group and prioritize tasks, configure retries, cache schedule data, and inspect scheduler health

### Webhooks

- **Provider subscriptions**: Register event handlers and provider-specific behavior
- **Signature verification**: Support HMAC, timestamped HMAC, and custom verification callbacks
- **Synchronous or asynchronous processing**: Route providers to immediate handling or the `webhooks` queue
- **Idempotency support**: Track processed webhook keys through the cache-backed idempotency store
- **Response handling**: Return accepted, unauthorized, not-found, and other HTTP responses based on processing results

### SMS

- **Transport registry**: Configure and select SMS transports through the SMS manager
- **Synchronous delivery**: Send messages immediately through the default transport
- **Asynchronous delivery**: Queue SMS sends on the `sms` queue when enabled
- **Extensible providers**: Add custom transports and receive structured delivery responses

### Components

- **Component Factory**: Create UI components programmatically
- **Pre-built Components**: Alert, Button, Card, Form, Input, Modal, Nav, ProgressBar
- **Component Traits**: Reusable component functionality
- **Attributes Management**: Flexible attribute handling for HTML elements
- **CSS Classes Builder**: Dynamic CSS class generation

### HTTP Layer

- **Request/Response Objects**: Object-oriented HTTP request and response handling
- **Request Utilities**: Easy access to GET/POST/FILE data, headers, and server info
- **Response Types**: JSON, redirects, downloads, streaming responses
- **Middleware Support**: Stack-based middleware pipeline with arguments
- **Header Management**: Easy header manipulation and CORS configuration
- **Status Codes**: Comprehensive HTTP status code support

### Logging & Monitoring

- **Multi-Channel Logging**: Database, file, and event-based logging
- **Structured Logging**: Log levels (DEBUG, INFO, WARNING, ERROR, CRITICAL)
- **Event System**: Event listeners for application events
- **Error Logging**: Automatic logging of exceptions and errors
- **Environment-Aware**: Different logging behavior for dev/production

### Date & Time Handling

- **DateTime Handler**: Unified date/time manipulation
- **Timezone Support**: Configurable timezone handling
- **Format Support**: Multiple date format support
- **Utility Methods**: Common date operations built-in

### Network Utilities

- **HTTP Client**: cURL-based HTTP client for API calls
- **Server Management**: PHP built-in server management for development
- **Connection Handling**: Request/response HTTP utilities

## System Requirements

- **PHP**: 8.3 or higher
- **Extensions**:
  - PDO (for database support)
  - cURL (for HTTP client)
  - JSON (for API support)
  - OpenSSL (for encryption)
  - ftp (optional, for FTP filesystem support)
  - ldap (optional, for LDAP authentication and directory integration)
- **Composer**: 2.0 or higher
- **Database**: MySQL 5.7+, PostgreSQL 10+, or SQLite 3+

## Installation

### Install with Composer

Install Machinjiri as a dependency in your project:

```bash
composer require machinjiri/framework
```

### Local development

To run the framework repository locally:

```bash
git clone https://github.com/mlangeni/machinjiri.git
cd machinjiri
composer install
```

### Recommended setup

- Copy `.env.example` to `.env`
- Set `APP_KEY`, database, and mail credentials
- Run `composer test` to verify the installation

## Quick Start

### 1. Create Your First Route

```php
// routes/web.php
use Mlangeni\Machinjiri\Core\Routing\Router;

Router::get('/', function() {
    return 'Welcome to Machinjiri!';
});

Router::get('/hello/{name}', function($name) {
    return "Hello, {$name}!";
}, 'greeting');
```

### 2. Create a Controller

```php
// app/Controllers/HomeController.php
namespace App\Controllers;

class HomeController
{
    public function index()
    {
        return view('home', ['title' => 'Home Page']);
    }
}
```

### 3. Create a View

```html
<!-- resources/views/home.view.php -->
<% extend('layouts.app') %>

<% section('content') %>
    <div class="container">
        <h1><%= $title %></h1>
        <p>Welcome to your Machinjiri application!</p>
    </div>
<% endsection %>
```

### 4. Connect Route to Controller

```php
// routes/web.php
Router::get('/', 'HomeController@index', 'home');
```

### 5. Start Development Server

```bash
php artisan run:dev
```

Visit `http://localhost:3000` in your browser.

## Project Structure

```
your-project/
├── app/
│   ├── Controllers/          # Application controllers
│   ├── Jobs/                 # Application Jobs
│   ├── Middleware/           # Custom middleware classes
│   ├── Queue/                # Queue Drivers
│   │     └── Drivers/        # Driver Files (Custom Queue Drivers)
│   ├── Models/               # Data models
│   ├── Tasks/                # Scheduled Tasks
│   ├── Webhooks/             # Webhooks
│   ├── Providers/            # Service providers
│   └── Exceptions/           # Custom exceptions
├── bootstrap/
│   └── app.php               # App Bootstrap
├── config/
│   ├── core/                 # Application configuration
│   │   ├── app.php        
│   │   ├── cache.php 
│   │   └── auth.php
│   └── services
│       └── providers.php     # Service providers list
├── database/
│   ├── migrations/           # Database migration files
│   ├── seeders/              # Database seeder classes
│   ├── factories/            # Database factory classes
│   └── cache-prefetch-db.php # Prefetch Database Cache file 
├── resources/
│   └── views/                # View templates
│       ├── layouts/          # Layout templates
│       ├── partials/         # Reusable view fragments
│       └── pages/            # Page-specific views
├── routes/
│   └── web.php               # Web application routes
├── storage/
│   ├── cache/                # Cached data files
│   ├── cookies/              # Cookie storage
│   ├── logs/                 # Application logs
│   └── sessions/             # Session files
├── public/
│   ├── src/
│   │   ├── css/              # Stylesheets
│   │   ├── js/               # JavaScript files
│   │   └── images/           # Image assets
│   ├── .htaccess             # .htaccess config file
│   └── index.php             # Application entry point
├── vendor/                   # Composer dependencies
├── .env                      # Environment configuration
├── artisan                   # Console application
├── composer.json             # Project dependencies
└── phpunit.xml               # PHPUnit configuration
```

## Core Components

### Routing System

The router handles all HTTP requests and directs them to appropriate controllers or callbacks.

**Basic Routing:**

```php
use Mlangeni\Machinjiri\Core\Routing\Router;

// Simple routes
Router::get('/users', 'UserController@list');
Router::post('/users', 'UserController@store');
Router::put('/users/{id}', 'UserController@update');
Router::delete('/users/{id}', 'UserController@destroy');
Router::patch('/users/{id}', 'UserController@patch');
Router::any('/path', 'Controller@method');

// Named routes
Router::get('/profile/{id}', 'UserController@show', 'user.profile');

// Route groups
Router::group(['prefix' => '/api', 'middleware' => 'api'], function() {
    Router::get('/users', 'ApiUserController@list');
    Router::post('/users', 'ApiUserController@store');
});

// AJAX routes
Router::ajax('/api/data', 'ApiController@getData');

// Traditional routes
Router::traditional('/contact', 'ContactController@show');

// Generate URLs
$url = Router::route('user.profile', ['id' => 5]);
$absoluteUrl = Router::absoluteRoute('user.profile', ['id' => 5]);
```

**Middleware:**

```php
// Apply middleware to routes
Router::group(['middleware' => 'auth'], function() {
    Router::get('/dashboard', 'DashboardController@index');
});

// Multiple middleware
Router::group(['middleware' => ['auth', 'admin']], function() {
    Router::get('/admin', 'AdminController@dashboard');
});

// Middleware with parameters
Router::group(['middleware' => 'role:admin'], function() {
    Router::delete('/users/{id}', 'UserController@destroy');
});
```

**CORS Configuration:**

```php
Router::cors([
    'allowed_origins' => ['https://example.com'],
    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
    'allowed_headers' => ['Content-Type', 'Authorization'],
], function() {
    Router::get('/api/public', 'ApiController@public');
});
```

### View Engine

Machinjiri provides a powerful templating engine with a clean, readable syntax.

**Template Syntax:**

```php
<% if $user->isAdmin() %>
    <a href="/admin">Admin Panel</a>
<% else %>
    <a href="/profile">My Profile</a>
<% endif %>

<% foreach $products as $product %>
    <div>{{ $product->name }} - {{ $product->price }}</div>
<% endforeach %>
```

**Layout Example:**

```html
<!-- resources/views/layouts/app.view.php -->
<!DOCTYPE html>
<html>
<head>
    <title><%= $title ?? 'My App' %></title>
    <% include 'partials.stylesheets' %>
</head>
<body>
    <header>
        <% include 'partials.navbar' %>
    </header>
    
    <main>
        <% yield('content') %>
    </main>
    
    <footer>
        <% include 'partials.footer' %>
    </footer>
    
    <% include 'partials.scripts' %>
</body>
</html>
```

**Usage in Controller:**

```php
use Mlangeni\Machinjiri\Core\Views\View;

public function index($req, $res)
{
    return View::make('home', [
        'title' => 'Home Page',
        'featured' => $featured,
    ])->render();
    
    // Or display directly
    View::make('home', ['title' => 'Home'])->display();
    
    // Share data globally
    View::share('user', auth()->user());
}
```

### Database & ORM

Machinjiri provides a powerful query builder and migration system for database operations.

**Database Configuration:**

```php
// config/database.php
return [
    'driver' => env('DB_CONNECTION', 'mysql'),
    'host' => env('DB_HOST', 'localhost'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'database' => env('DB_DATABASE', 'machinjiri'),
    'port' => env('DB_PORT', 3306),
];
```

**Query Builder:**

```php
use Mlangeni\Machinjiri\Core\Database\Builders\QueryBuilder;

// Simple queries
$users = (new QueryBuilder('users'))->select()->get();
$user = (new QueryBuilder('users'))
    ->select()
    ->where('id', '=', 5)
    ->first();

// Complex queries
$result = (new QueryBuilder('users'))
    ->select(['id', 'name', 'email'])
    ->where('active', '=', true)
    ->where('role', '=', 'admin')
    ->orderBy('name', 'asc')
    ->limit(10)
    ->get();

// Insert
(new QueryBuilder('users'))->insert([
    'name' => 'John',
    'email' => 'john@example.com',
]);

// Update
(new QueryBuilder('users'))
    ->where('id', '=', 5)
    ->update(['name' => 'Jane']);

// Delete
(new QueryBuilder('users'))->where('id', '=', 5)->delete();

// Aggregate functions
$count = (new QueryBuilder('users'))->select()->count();
$max = (new QueryBuilder('posts'))->max('views');
```

**Migrations:**

```bash
# Create migration
php artisan make:migration create_users_table

# Run migrations
php artisan migrate

# Rollback
php artisan migrate:rollback
```

```php
// database/migrations/2024_01_01_000000_create_users_table.php
class CreateUsersTable
{
    public function up()
    {
        Schema::create('users', function($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }
    
    public function down()
    {
        Schema::dropIfExists('users');
    }
}
```

**Seeders:**

```bash
php artisan make:seeder UserSeeder
php artisan db:seed
```

### Authentication & Security

Machinjiri provides comprehensive authentication and security features.

**Session-Based Authentication:**

```php
// config/auth.php
return [
    'guard' => 'web',
    'providers' => [
        'users' => [
            'driver' => 'database',
            'table' => 'users',
        ],
    ],
];
```

**Login/Logout:**

```php
// In your controller
public function login($req, $res)
{
    $credentials = $req->only(['email', 'password']);
    
    if (auth()->attempt($credentials)) {
        return $res->redirect('/dashboard');
    }
    
    return view('auth.login', ['error' => 'Invalid credentials']);
}

public function logout($req, $res)
{
    auth()->logout();
    return $res->redirect('/');
}
```

**OAuth Integration:**

```php
use Mlangeni\Machinjiri\Core\Authentication\OAuth;

$oauth = new OAuth($config);
$token = $oauth->getAccessToken($code);
$user = $oauth->getUserInfo($token);
```

**Password Security:**

```php
use Mlangeni\Machinjiri\Core\Forms\Password;

// Hash password
$hashed = Password::hash('secret123');

// Verify password
if (Password::verify('secret123', $hashed)) {
    // Password is correct
}
```

**CSRF Protection:**

```php
// Automatically handled in forms
<form method="POST" action="/users">
    <input type="hidden" name="_token" value="<%= csrf_token() %>">
    <!-- form fields -->
</form>
```

**Encryption:**

```php
use Mlangeni\Machinjiri\Core\Security\Encryption\Cipher;

$encrypter = new Cipher($key);
$encrypted = $encrypter->encrypt($data);
$decrypted = $encrypter->decrypt($encrypted);
```

### LDAP

The LDAP component provides configured connections, escaped query filters, and LDAP entry objects. The PHP LDAP extension must be enabled before using it.

**Register an LDAP manager:**

```php
// app/Providers/LdapServiceProvider.php
namespace App\Providers;

use Mlangeni\Machinjiri\Core\Components\LDAP\Manager as LdapManager;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Providers\ServiceProvider;

class LdapServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('ldap.manager', function (Container $app) {
            return new LdapManager([
                'default' => 'directory',
                'connections' => [
                    'directory' => [
                        'hosts' => [env('LDAP_HOST', 'ldap.example.com')],
                        'port' => (int) env('LDAP_PORT', 389),
                        'base_dn' => env('LDAP_BASE_DN', 'dc=example,dc=com'),
                        'username' => env('LDAP_BIND_DN'),
                        'password' => env('LDAP_BIND_PASSWORD'),
                        'use_tls' => (bool) env('LDAP_USE_TLS', true),
                    ],
                ],
            ]);
        });
    }
}
```

Add the provider to `config/providers.php`, then query the directory from a controller or service:

```php
use Mlangeni\Machinjiri\Core\Components\LDAP\Manager as LdapManager;

public function directoryUsers(LdapManager $ldap): array
{
    return $ldap->connection()->query()
        ->where('objectClass', '=', 'inetOrgPerson')
        ->where('mail', 'contains', '@example.com')
        ->select(['uid', 'cn', 'mail'])
        ->sizeLimit(100)
        ->get();
}

foreach (directoryUsers(resolve('ldap.manager')) as $entry) {
    echo $entry->getAttribute('cn');
    echo $entry->getAttribute('mail');
    echo $entry->getDn();
}
```

For LDAP-backed authentication, configure the guard with the `ldap` provider driver. The provider searches by `uid` and `mail`, synchronizes selected attributes to the local user model, and validates passwords by binding with the user DN:

```php
// config/auth.php
return [
    'default' => 'web',
    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => [
                'driver' => 'ldap',
                'model' => App\Models\User::class,
                'username_attribute' => 'uid',
                'search_fields' => ['uid', 'mail'],
                'sync_attributes' => [
                    'name' => 'cn',
                    'email' => 'mail',
                ],
            ],
        ],
    ],
];
```

### Forms & Validation

Create and validate forms with ease.

**Form Validation:**

```php
use Mlangeni\Machinjiri\Core\Forms\FormValidator;

public function store($req, $res)
{
    $validator = new FormValidator($req->all());
    
    $validator->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email|unique:users',
        'password' => 'required|min:8|confirmed',
    ]);
    
    if ($validator->fails()) {
        return view('register', ['errors' => $validator->errors()]);
    }
    
    // Create user
    User::create($validator->validated());
}
```

**Custom Rules:**

```php
$validator = new FormValidator($data);

$validator->validate([
    'age' => [
        'required',
        'integer',
        function($attribute, $value, $fail) {
            if ($value < 18) {
                $fail('Must be 18 or older');
            }
        },
    ],
]);
```

### Queues & Jobs

Process background jobs asynchronously.

**Create a Job:**

```bash
php artisan make:job SendWelcomeEmail
```

```php
// app/Jobs/SendWelcomeEmail.php
namespace App\Jobs;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\JobInterface;

class SendWelcomeEmail implements JobInterface
{
    public $data;
    
    public function __construct($userId)
    {
        $this->data = ['user_id' => $userId];
    }
    
    public function handle()
    {
        $user = User::find($this->data['user_id']);
        Mail::to($user->email)->send(new WelcomeEmail($user));
    }
}
```

**Dispatch Job:**

```php
// In a controller or callback
dispatch(new SendWelcomeEmail($user->id));

// Or queue for later
dispatch(new SendWelcomeEmail($user->id))->onQueue('default');
```

**Process Jobs:**

```bash
php artisan queue:work
```

### Task Scheduler

Scheduled tasks extend `ScheduledTask`, define a cron expression, and implement `handle()`. Register task classes with the task manager or use the generator to create and register one:

```bash
php artisan scheduler:create-task SendDailyReport --cron="0 9 * * *" --register
php artisan scheduler:list
php artisan scheduler:run
```

The scheduler persists task definitions and execution history, dispatches work to the `scheduler` queue, prevents overlapping runs by default, and supports task groups, priorities, retries, maintenance-mode behavior, and cached status data. Use `scheduler:status`, `scheduler:stats`, `scheduler:enable`, and `scheduler:disable` to operate it.

### Webhooks

Configure provider subscriptions with their signing secret, verification method, event handlers, and whether processing should be synchronous or queued. Incoming requests are processed through `WebhookManager`:

```php
$response = $webhookManager->process($payload);
```

Queued providers are dispatched to the `webhooks` queue and return an accepted response. Synchronous providers invoke all matching handlers and return the first failed response, or the final successful response. HMAC, timestamped HMAC, and custom signature callbacks are supported.

### SMS

Configure one or more SMS transports and send through the manager. Delivery can be immediate or queued on the `sms` queue:

```php
$response = $smsManager->send($message);
$queuedResponse = $smsManager->send($message, true);
```

The manager returns a structured response, logs delivery failures, and supports custom transports through the transport registry.

### Components

Create reusable UI components programmatically.

**Available Components:**

```php
use Mlangeni\Machinjiri\Components\Alert;
use Mlangeni\Machinjiri\Components\Button;
use Mlangeni\Machinjiri\Components\Card;
use Mlangeni\Machinjiri\Components\Form;
use Mlangeni\Machinjiri\Components\Input;
use Mlangeni\Machinjiri\Components\Modal;
use Mlangeni\Machinjiri\Components\Nav;
use Mlangeni\Machinjiri\Components\ProgressBar;

// Alert component
$alert = new Alert('Success!', 'success');
echo $alert->render();

// Button component
$button = new Button('Click Me', 'btn-primary');
echo $button->render();

// Form component
$form = new Form('POST', '/submit');
$form->addField('email', 'email');
$form->addField('password', 'password');
echo $form->render();

// Input component
$input = new Input('email', 'user@example.com');
echo $input->render();

// Card component
$card = new Card('Title', 'Content');
echo $card->render();
```

## ⚙️ Configuration

### Environment Configuration

Configuration is managed through `.env` files and config classes:

```env
# .env
APP_NAME=Machinjiri
APP_ENV=local
APP_DEBUG=true
APP_KEY=your-app-key-here

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=machinjiri
DB_USERNAME=root
DB_PASSWORD=

MAIL_DRIVER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=465
MAIL_USERNAME=your-username
MAIL_PASSWORD=your-password
MAIL_FROM_ADDRESS=noreply@machinjiri.com
```

### Application Configuration

```php
// config/app.php
return [
    'name' => env('APP_NAME', 'Machinjiri'),
    'env' => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', false),
    'key' => env('APP_KEY'),
    
    'timezone' => 'UTC',
    'locale' => 'en',
    
    'url' => env('APP_URL', 'http://localhost'),
];
```

### Service Provider Configuration

```php
// config/providers.php
return [
    'providers' => [
        App\Providers\AppServiceProvider::class,
        App\Providers\AuthServiceProvider::class,
        App\Providers\RouteServiceProvider::class,
    ],
    
    'aliases' => [
        'Router' => Mlangeni\Machinjiri\Core\Routing\Router::class,
        'View' => Mlangeni\Machinjiri\Core\Views\View::class,
    ],
];
```

### Mail Configuration

```php
// config/mail.php
return [
  'default' => env('MAIL_DRIVER', 'phpmailer'),
  'transports' => [
    'phpmailer' => [
      'transport' => 'phpmailer',
      'host' => env('MAIL_HOST', 'smtp.mailtrap.io'),
      'port' => env('MAIL_PORT', 2525),
      'encryption' => env('MAIL_ENCRYPTION', 'tls'),
      'username' => env('MAIL_USERNAME'),
      'password' => env('MAIL_PASSWORD'),
      'timeout' => null,
      'auth_mode' => null,
      'debug' => false, //debug mode for smtp
      'from_address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
    ],
      // Add other transports as needed
  ]
];
```

## API Reference

### Application Container

```php
use Mlangeni\Machinjiri\Core\Machinjiri;

// Get application instance
$app = Machinjiri::getInstance();

// Bind service
$app->bind('key', function($app) {
    return new Service();
});

// Resolve service
$service = $app->resolve('key');

// Check environment
$isProduction = Machinjiri::getEnvironment() === 'production';
$isDevelopment = Machinjiri::getEnvironment() === 'development';

// Get configuration
$config = $app->config('app.timezone');
```

### Router API

```php
use Mlangeni\Machinjiri\Core\Routing\Router;

// HTTP Methods
Router::get($pattern, $handler, $name = null, $options = []);
Router::post($pattern, $handler, $name = null, $options = []);
Router::put($pattern, $handler, $name = null, $options = []);
Router::delete($pattern, $handler, $name = null, $options = []);
Router::patch($pattern, $handler, $name = null, $options = []);
Router::any($pattern, $handler, $name = null, $options = []);
Router::match($methods, $pattern, $handler, $name = null, $options = []);

// Special Routes
Router::ajax($pattern, $handler, $name = null, $options = []);
Router::traditional($pattern, $handler, $name = null, $options = []);

// Route Groups
Router::group($attributes, $callback);

// Middleware
Router::middleware($middleware, $callback);

// CORS
Router::cors($config, $callback);

// URL Generation
Router::route($name, $parameters = []);
Router::absoluteRoute($name, $parameters = []);

// Dispatching
Router::dispatch();
```

### View API

```php
use Mlangeni\Machinjiri\Core\Views\View;

// Create and render
View::make($view, $data = []);
View::make($view, $data)->render();
View::make($view, $data)->display();

// Share data globally
View::share($key, $value);

// Template functions (in view files)
<%= $variable %>              // Output variable
<% section('name') %>...<%endsection %>   // Define section
<% yield('name') %>           // Output section
<% extend('layout') %>        // Extend layout
<% include 'partial' %>       // Include partial
<% if($cond): %> ... <% endif; %>   // Conditionals
<% foreach($items as $item): %> ... <% endforeach; %>
```

### Request API

```php
// In route handler or controller
public function handle($request, $response)
{
    // Get data
    $all = $request->all();
    $input = $request->input('name');
    $only = $request->only(['email', 'password']);
    $except = $request->except(['password']);
    
    // Check methods
    $isPost = $request->isPost();
    $isJson = $request->isJson();
    $isAjax = $request->isAjax();
    
    // Get headers
    $auth = $request->header('Authorization');
    $headers = $request->headers();
    
    // Files
    $file = $request->file('avatar');
    $files = $request->files();
    
    // Server info
    $method = $request->method();
    $uri = $request->uri();
    $path = $request->path();
}
```

### Response API

```php
// In route handler or controller
public function handle($request, $response)
{
    // Simple responses
    return "String response";
    
    // JSON response
    return $response->json(['data' => $data]);
    
    // Redirect
    return $response->redirect('/home');
    return $response->redirectBack();
    
    // View response
    return view('page', ['data' => $data]);
    
    // File download
    return $response->download('/path/to/file');
    
    // Set headers
    $response->header('X-Custom', 'value');
    
    // Set status
    $response->status(201);
    
    // Cookies
    $response->cookie('name', 'value', 3600);
}
```

### Database API

```php
use Mlangeni\Machinjiri\Core\Database\Builders\QueryBuilder;

// Query builder
$result = (new QueryBuilder('users'))
    ->select(['id', 'name', 'email'])
    ->where('active', true)
    ->whereIn('role', ['admin', 'moderator'])
    ->orderBy('name', 'asc')
    ->limit(10)
    ->get();

// Retrieve single
$user = (new QueryBuilder('users'))->select()->where('id', 5)->first();

// Insert
(new QueryBuilder('users'))->insert([
    'name' => 'John',
    'email' => 'john@example.com',
]);

// Update
(new QueryBuilder('users'))
    ->where('id', 5)
    ->update(['name' => 'Jane']);

// Delete
(new QueryBuilder('users'))->where('id', 5)->delete();

// Aggregate
$count = (new QueryBuilder('users'))->count();
$max = (new QueryBuilder('posts'))->max('views');
$avg = (new QueryBuilder('orders'))->select()->avg('amount');

// Exists
$exists = (new QueryBuilder('users'))->where('email', $email)->exists();
```

## Error Handling

Machinjiri provides comprehensive error handling with environment-aware output:

```php
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

try {
    // Your code
    if (!$user) {
        throw new MachinjiriException('User not found', 404);
    }
} catch (MachinjiriException $e) {
    // Access error details
    $message = $e->getMessage();
    $code = $e->getCode();
    
    // Display error (different in dev/prod)
    $e->show();
    
    // Or handle manually
    return view('error', ['error' => $e->getMessage()]);
}
```

**Error Handler Features:**

- Environment-specific error pages (development vs production)
- Automatic logging of uncaught exceptions
- User-friendly error messages in production
- Detailed stack traces in development
- HTTP status code mapping
- Custom error handlers per exception type
- Request and application context attached to error reports
- Configurable error reporting by email through the mail manager
- Error throttling to prevent repeated failures from overwhelming logs or notifications
- Error, exception, and shutdown handlers for runtime and fatal errors
- Custom exception renderers through `ErrorHandler::setExceptionRenderer()`

## Console Commands (Artisan)

Machinjiri includes an Artisan console for many development workflows. Common commands include:

```bash
# Server management
php artisan run:dev              # Start development server
php artisan stop:dev               # Stop development server

# Database and migrations
php artisan db:migration:create      # Create a new migration file
php artisan db:migration:list        # List available migrations
php artisan db:migration:migrate     # Run migrations
php artisan db:migration:rollback    # Roll back the last batch of migrations
php artisan db:seeder:create         # Create a new seeder
php artisan db:seeder:run            # Run a seeder
php artisan db:seeder:run-all        # Run all seeders
php artisan db:seeder:refresh        # Refresh seeders
php artisan db:factory:create        # Create a new factory

# Code generation
php artisan make:controller Name     # Create controller
php artisan make:middleware Name     # Create middleware
php artisan make:job Name            # Create job
php artisan make:webhook Name        # Create webhook scaffolding
php artisan view:make Name           # Create a view template
php artisan provider:make Name       # Create a service provider

# Queue and worker management
php artisan queue:init               # Initialize queue resources
php artisan queue:work               # Process jobs
php artisan queue:failed             # List failed jobs
php artisan queue:retry              # Retry a failed job
php artisan queue:clear              # Clear queued jobs
php artisan queue:status             # Show queue status
php artisan queue:health             # Report queue health

# Scheduled task management
php artisan scheduler:create-task Name --register
php artisan scheduler:list
php artisan scheduler:run
php artisan scheduler:status
php artisan scheduler:enable ID
php artisan scheduler:disable ID
php artisan scheduler:stats [ID]
php artisan scheduler:clean --days=30
php artisan scheduler:cache-preload
php artisan scheduler:cache-clear

# Utilities
php artisan test                     # Run test suite
php artisan get:env                  # Display environment variables
php artisan config:cache             # Cache configuration
php artisan route:cache              # Cache routes
php artisan view:cache               # Cache views
```

## Testing

Run tests with PHPUnit:

```bash
# Run all tests
composer test

# Run specific test
vendor/bin/phpunit tests/Unit/UserTest.php

# Run with coverage
vendor/bin/phpunit --coverage-html coverage
```

**Example Test:**

```php
// tests/Unit/UserTest.php
namespace Mlangeni\Machinjiri\Tests\Unit;

use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function testUserCreation()
    {
        $user = User::create([
            'name' => 'John',
            'email' => 'john@example.com',
            'password' => password_hash('secret', PASSWORD_BCRYPT),
        ]);
        
        $this->assertIsNotNull($user->id);
        $this->assertEquals('John', $user->name);
    }
}
```

## Contributing

We welcome contributions! Here's how to get started:

1. **Fork the Repository**
   ```bash
   git clone https://github.com/yourusername/machinjiri.git
   cd machinjiri
   ```

2. **Create a Feature Branch**
   ```bash
   git checkout -b feature/amazing-feature
   ```

3. **Make Your Changes**
   - Follow PSR-12 coding standards
   - Add tests for new features
   - Update documentation

4. **Commit and Push**
   ```bash
   git add .
   git commit -m 'Add amazing feature'
   git push origin feature/amazing-feature
   ```

5. **Open a Pull Request**
   - Describe your changes clearly
   - Reference any related issues
   - Ensure tests pass

### Development Setup

```bash
# Install dependencies
composer install

# Run tests
composer test

# Check code standards
composer cs-check

# Fix code standards
composer cs-fix
```

## License

This project is licensed under a **proprietary license** - see the [LICENSE](LICENSE) file for details.

## Support

- **GitHub Issues**: [Report bugs and request features](https://github.com/mlangeni/machinjiri/issues)
- **Discussions**: [Ask questions and share ideas](https://github.com/mlangeni/machinjiri/discussions)
- **Email**: precious.lyson@gmail.com

---

## Authors

- **Precious Lyson** - [GitHub](https://github.com/precious-lyson) | [Email](mailto:precious.lyson@gmail.com)
- **Mlangeni Group** - [Website](https://mlangeni.com)

## Acknowledgments

- Inspired by Laravel's elegant syntax and structure
- Built on modern PHP 8.3+ features
- Community contributions and feedback

---

Full Documentation: 

**Built with ❤️ by the Machinjiri Team**

*Made for developers, by developers.*