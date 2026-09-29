# Machinjiri Framework Improvements

This document summarizes the major upgrades delivered in the current Machinjiri framework release, version `2.2.6`.

## Overview

Machinjiri continues to evolve as a modular PHP framework aimed at speed, reliability, and developer productivity. The most recent release strengthens the platform across core infrastructure, developer tooling, application lifecycle management, security, and background processing.

## Platform and Compatibility Upgrades

- Added support for modern PHP runtimes, including **PHP 8.3+** and PHP 8.4 compatibility
- Standardized framework metadata and package versioning around the current `2.2.6` release
- Improved application bootstrap flow and container bootstrapping for better startup consistency
- Tightened integration with Symfony Console, Filesystem, and Process components for CLI and generator tooling
- Improved provider registration and service binding behavior for cleaner dependency injection flows

## Core Framework Improvements

- Enhanced the application container with more predictable service resolution and lifecycle behavior
- Expanded modular service-provider management for bootstrapping extensions and framework features
- Added environment-aware configuration loading and better `.env` integration for app initialization
- Improved the framework shell and generator commands for quicker project scaffolding and maintenance tasks
- Refined request lifecycle handling, middleware resolution, and application startup stability

## Routing, HTTP, and Request Handling

- Improved REST-friendly routing patterns and route lifecycle management
- Enhanced middleware flow, grouping, prefixes, and CORS handling for API and web applications
- Added stronger named route handling and parameter-aware URL generation
- Expanded HTTP request and response capabilities, including JSON, redirects, downloads, and streaming responses
- Improved rate limiting, request validation, and preflight handling for modern web workloads
- Added robust HTTP client utilities for external API integration and service communication

## Database and Persistence Enhancements

- Improved multi-database support across MySQL, PostgreSQL, and SQLite
- Expanded fluent query-builder capabilities and grammar support for database-driven applications
- Strengthened migration and schema workflows for manageable database evolution
- Improved transaction handling, connection management, and raw query support
- Added durable queue persistence and better database-backed job workflows
- Improved Redis-backed queue support with delayed jobs, retries, reservation handling, and worker reliability

## Authentication, Security, and Forms

- Added stronger session and cookie handling with configurable security controls
- Improved OAuth integration and third-party authentication workflows
- Expanded password hashing support with modern algorithms and secure defaults
- Improved CSRF protection and security-oriented form handling
- Added encryption, JWT support, and secure token utilities for API and user workflows
- Improved LDAP integration and authentication support for enterprise directory environments
- Expanded validation and form-request capabilities, including file uploads and rule builders

## Notifications, SMS, and Webhooks

- Added a more flexible notification system spanning mail, SMS, database, and webhook channels
- Improved asynchronous delivery and queue-aware processing for long-running outgoing messages
- Expanded SMS transport configuration, provider abstraction, and structured response handling
- Added webhook provider subscriptions, signature verification, and idempotency safeguards
- Improved sync and async webhook routing with better response handling and dispatch control
- Added better operational support for external service integration and event-driven workflows

## Queues, Jobs, and Task Scheduling

- Improved job queue infrastructure with more reliable persistence and worker behavior
- Added stronger task scheduler support for cron-based execution, retries, and collision protection
- Expanded task metadata handling for priority ordering, execution history, and schedule health checks
- Added queue and scheduler commands for managing jobs and tasks directly from the CLI
- Improved queue event handling and operational observability for background processing

## Views, UI Components, and Developer Experience

- Expanded template inheritance and layout-driven view rendering
- Improved partial includes, shared view state, and loop directives for cleaner templates
- Added reusable UI components with dynamic attribute handling and CSS class generation
- Included common building blocks such as alerts, buttons, cards, forms, modals, navbars, and progress indicators
- Added a component factory for more programmatic UI assembly and maintainable front-end code
- Improved asset and frontend-integration helpers for a smoother developer workflow

## Logging, Errors, and Diagnostics

- Improved multichannel logging for files, database, and event-based diagnostics
- Refined structured log levels from debug through critical for operational clarity
- Reworked exception handling into clearer context capture, reporting, rendering, and throttling responsibilities
- Added stronger debugging and inspection utilities for runtime analysis
- Improved development and production error behavior for safer application diagnostics

## Integrations and Utilities

- Added robust filesystem support with adapter-based storage operations
- Extended FTP and Redis support for distributed and storage-oriented application needs
- Added UUID and ULID generation utilities, including validation and parsing support
- Added OTP/TOTP helpers for secure one-time authentication workflows
- Improved date and time handling with timezone-aware utilities and formatting helpers
- Added network utilities and HTTP integration helpers for external service communication

## Testing and Quality Improvements

- Strengthened framework testing support with reusable assertions, mocks, and test-case patterns
- Improved database test reset workflows for isolated test execution
- Added Faker integration for realistic fixture generation
- Improved support for parallel test execution and modern code-quality tooling
- Continued to align the framework with clean, maintainable PHP development practices

## Summary

The `2.2.6` release focuses on making Machinjiri more robust, modular, and developer-friendly. The framework now offers a more complete foundation for building secure, scalable web applications with modern PHP practices, strong operational tooling, and production-oriented infrastructure support.
