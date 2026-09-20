# Machinjiri Framework Improvements

This document summarizes the major upgrades currently available in Machinjiri 2.2.5.

## Platform and Compatibility

- Updated the framework to support **PHP 8.3+**, with compatibility for PHP 8.4 environments.
- Standardized release metadata and package versioning around the current 2.2.5 release.
- Improved the application bootstrap flow for modern PHP runtimes and dependency management.
- Expanded service-provider registration and container binding behavior for cleaner app wiring.

## Core Framework Upgrades

- Improved dependency injection through the application container and service bindings.
- Added modular provider-based service registration and bootstrapping.
- Added environment-aware configuration and `.env` loading for application setup.
- Expanded Artisan generators and interactive terminal tooling for common app scaffolding.
- Added Symfony Console, Filesystem, and Process integration for CLI and project automation.
- Added FTP filesystem adapter support and Redis integration for distributed workflows.

## Routing and HTTP

- Expanded routing support for REST-style requests and AJAX-aware route flows.
- Improved route groups with shared middleware, prefixes, and CORS handling.
- Added named routes and URL generation with route parameters.
- Improved middleware dispatching, rate limiting, and preflight request handling.
- Added richer HTTP request and response objects, including JSON, redirect, download, and streaming responses.
- Added built-in server management helpers and an HTTP client for external API calls.

## Views, UI Components, and Developer Experience

- Added template inheritance with layouts and sections.
- Added partial includes, shared view data, loop directives, and asset management.
- Added reusable UI components with attribute handling and dynamic CSS class building.
- Added common components for alerts, buttons, cards, forms, inputs, modals, navigation, and progress bars.
- Added a component factory for programmatic UI element creation.
- Improved frontend integration helpers and asset bridging workflows.

## Database and Persistence

- Added support for MySQL, PostgreSQL, and SQLite through a common database layer.
- Added fluent query builders and database grammars for expressive queries.
- Added migrations and schema builders for programmatic database management.
- Added seeders and factories to simplify test and development data setup.
- Added support for multiple database connections, transaction handling, and connection management.
- Added database caching and durable queue persistence support.
- Added Redis-backed queue drivers with delayed jobs, reservation handling, and retries.

## Authentication, Security, and Forms

- Added session and cookie management with configurable security options.
- Added OAuth integrations for third-party authentication providers.
- Added password hashing with bcrypt and Argon2 support.
- Added CSRF protection for form submissions.
- Added AES encryption and JWT token support.
- Added parameterized database queries to reduce SQL injection risk.
- Added LDAP integration and authentication middleware support.
- Added a reusable form request and validation layer with rule builders and file-upload handling.

## Notifications, SMS, and Queues

- Added a notification system with mail, SMS, database, and webhook delivery channels.
- Added configurable SMS transports with immediate and queued delivery.
- Added queue-aware dispatch support for asynchronous processing and long-running jobs.
- Added database-backed job storage and Redis-backed queue support for high-performance workflows.
- Added queue-related Artisan commands and improved job generation support.
- Added event listeners for application and queue lifecycle events.

## Task Scheduler

- Added a persistent task scheduler with repository-backed task storage and execution history.
- Added cron expression scheduling, queued execution, overlap protection, and retry logic.
- Added task grouping, prioritization, caching, and health checks.
- Added task management commands for creating, listing, running, enabling, disabling, and inspecting scheduled work.

## Webhooks

- Added provider subscription support and event-aware webhook routing.
- Added signature verification for HMAC and custom callback checks.
- Added synchronous or asynchronous processing based on application configuration.
- Added idempotency protection and structured response handling.
- Added generated webhook handlers and configuration scaffolding through Artisan workflows.

## Logging, Errors, and Diagnostics

- Added multi-channel logging for database, file, and event-based output.
- Added structured log levels from debug through critical.
- Added environment-aware logging behavior for development and production.
- Reworked exception handling into distinct context, logging, reporting, rendering, and throttling services.
- Added debugging and data-dumping utilities for diagnostics.

## Integrations and Utilities

- Added a cURL-based HTTP client for external API requests.
- Added mail transport integration through PHPMailer.
- Added filesystem abstractions and adapters for storage operations.
- Added UUID validation and dedicated UUID exceptions.
- Added ULID generation, validation, and parsing support.
- Added OTP/TOTP generation and verification utilities for secure one-time authentication.
- Added webhook processing with provider subscription handling, idempotency, and async dispatch.
- Added unified date and time handling with configurable timezone support.

## Testing and Quality

- Added framework testing helpers, assertions, mocks, and a reusable test case.
- Added database refresh support for isolated tests.
- Added Faker integration for generated test data.
- Added ParaTest support for parallel execution.
- Added PHPStan and PHP_CodeSniffer configuration for static analysis and coding standards.
