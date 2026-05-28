# Laravel Clone — Learning Framework Guide

> Build a minimal but functional PHP framework inspired by Laravel 13.
> Focus: **clarity, structure, reasoning** — not production completeness.

---

## 🗺 Architecture Map

```
┌────────────────────────────────────────────────────────────────────────┐
│                          HTTP Lifecycle (Web)                          │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
                             public/index.php              ← Step 01: Entry Point
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                        CLI / Console Lifecycle                         │
│  artisan                         ← Step 21: Artisan Console            │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                             Application Container                      │
│  Resolves and wires dependencies dynamically   ← Steps 02–03: Container│
│  Provides static proxies                       ← Step 16: Facades      │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
       ┌────────────────────────────┴────────────────────────────┐
       ▼                                                         ▼
  HTTP Kernel (Step 05)                                     Queue Worker (Step 20)
       │                                                         │
       ├─► Request / Response (Step 04)                          ├─► Async Jobs
       ├─► Pipeline / Middleware (Step 07)                       └─► Background tasks
       │
       ▼
  Router (Step 06) ──► Guard / Providers (Step 17: Auth)
       │
       ▼
  Controller (Step 09)
       │
       ├─► View Engine (Step 10: View templates)
       ├─► Config / Env (Step 11: Configuration repository)
       ├─► Validation (Step 12: User input validation)
       ├─► Database & ORM (Steps 13-15: Query Builder, Eloquent, Migrations)
       ├─► Events & Listeners (Step 18: Decoupled domain events)
       └─► Cache (Step 19: High-performance key-value caching)
```

---

## 📚 Steps Index

| Step                                 | Name                     | Key Problem Solved                                          | Laravel Equivalent                                      |
| ------------------------------------ | ------------------------ | ----------------------------------------------------------- | ------------------------------------------------------- |
| [01](./01-entry-point.md)            | Entry Point              | Where do all HTTP requests go?                              | `public/index.php`                                      |
| [02](./02-container.md)              | IoC Container            | How do objects find their dependencies?                     | `Illuminate\Container\Container`                        |
| [03](./03-application.md)            | Application              | What is the central hub of the framework?                   | `Illuminate\Foundation\Application`                     |
| [04](./04-request-response.md)       | Request & Response       | How do we represent HTTP cleanly?                           | `Illuminate\Http\Request/Response`                      |
| [05](./05-http-kernel.md)            | HTTP Kernel              | What orchestrates the full request lifecycle?               | `Illuminate\Foundation\Http\Kernel`                     |
| [06](./06-router.md)                 | Router                   | How does a URL map to a handler?                            | `Illuminate\Routing\Router`                             |
| [07](./07-pipeline.md)               | Middleware Pipeline      | How do cross-cutting concerns wrap a request?               | `Illuminate\Pipeline\Pipeline`                          |
| [08](./08-service-providers.md)      | Service Providers        | Where does service registration code live?                  | `Illuminate\Support\ServiceProvider`                    |
| [09](./09-controller.md)             | Controller               | How are related actions grouped?                            | `Illuminate\Routing\Controller`                         |
| [10](./10-view-engine.md)            | View Engine              | How is HTML separated from logic?                           | `Illuminate\View\View`                                  |
| [11](./11-config-env.md)             | Config & Env             | How does config change per environment?                     | `Illuminate\Config\Repository`                          |
| [12](./12-validation.md)             | Validation               | How is input validated consistently?                        | `Illuminate\Validation\Validator`                       |
| [13](./13-database-query-builder.md) | Database & Query Builder | How do we safely construct database queries?                | `Illuminate\Database\DatabaseManager` & `Query\Builder` |
| [14](./14-eloquent-orm.md)           | Eloquent ORM             | How do we map database rows to active-record models?        | `Illuminate\Database\Eloquent\Model`                    |
| [15](./15-migrations-schema.md)      | Migrations & Schema      | How do we track database schema changes over time?          | `Illuminate\Database\Schema\Builder`                    |
| [16](./16-facades.md)                | Facades                  | How do we provide static proxies to container services?     | `Illuminate\Support\Facades\Facade`                     |
| [17](./17-authentication.md)         | Authentication           | How do we identify users and protect routes?                | `Illuminate\Auth\AuthManager` & `SessionGuard`          |
| [18](./18-events-listeners.md)       | Events & Listeners       | How do we decouple side-effects from controllers?           | `Illuminate\Events\Dispatcher`                          |
| [19](./19-cache.md)                  | Cache                    | How do we avoid running heavy tasks repeatedly?             | `Illuminate\Cache\CacheManager`                         |
| [20](./20-queues-jobs.md)            | Queues & Jobs            | How do we run slow, blocking tasks in background processes? | `Illuminate\Queue\QueueManager` & `Worker`              |
| [21](./21-artisan-console.md)        | Artisan Console          | How do we run command-line tools and schedule tasks?        | `Illuminate\Console\Application` & `Kernel`             |

---

## 📐 Step Format

Each step follows this exact structure:

1. 🚩 **The Problem** — A concrete real-world problem the step solves
2. 🔍 **Why Naive Solutions Fail** — Why the obvious fix breaks at scale
3. 💡 **The Solution** — The architectural pattern derived from the problem
4. 🏗 **Implementation** — Full working code, no placeholders
5. ✅ **Verify** — Exact command + expected output to confirm it works
6. 📌 **What We Built** — Summary of files and key components
7. ⚠️ **Simplifications** — What was simplified vs real Laravel

---

## 🏗 Target Directory Structure (End State)

```
laravel-clone/
├── app/
│   ├── Console/
│   │   ├── Commands/
│   │   │   └── PruneOldUsers.php
│   │   └── Kernel.php
│   ├── Controllers/
│   │   └── ProfileController.php
│   ├── Events/
│   │   └── UserRegistered.php
│   ├── Listeners/
│   │   └── SendWelcomeEmail.php
│   ├── Jobs/
│   │   └── ProcessPodcast.php
│   ├── Models/
│   │   └── User.php
│   └── Providers/
│       ├── AppServiceProvider.php
│       ├── EventServiceProvider.php
│       ├── RoutingServiceProvider.php
│       └── ViewServiceProvider.php
├── bootstrap/
│   └── app.php
├── config/
│   ├── app.php
│   ├── database.php
│   ├── cache.php
│   └── queue.php
├── database/
│   └── migrations/
│       └── 2026_01_01_000000_create_users_table.php
├── public/
│   └── index.php           ← Entry point for all HTTP requests
├── resources/
│   ├── views/
│   │   ├── layouts/
│   │   │   └── app.php
│   │   ├── profile.php
│   │   └── welcome.php
├── routes/
│   └── web.php
├── src/
│   ├── Auth/
│   │   ├── AuthManager.php
│   │   └── SessionGuard.php
│   ├── Cache/
│   │   ├── CacheManager.php
│   │   └── Repository.php
│   ├── Config/
│   │   └── Repository.php
│   ├── Console/
│   │   ├── Application.php
│   │   ├── Command.php
│   │   └── Kernel.php
│   ├── Container/
│   │   └── Container.php
│   ├── Database/
│   │   ├── Connectors/
│   │   │   └── ConnectionFactory.php
│   │   ├── Eloquent/
│   │   │   └── Model.php
│   │   ├── Query/
│   │   │   └── Builder.php
│   │   ├── Schema/
│   │   │   └── Builder.php
│   │   ├── Connection.php
│   │   ├── DatabaseManager.php
│   │   └── Migration.php
│   ├── Events/
│   │   └── Dispatcher.php
│   ├── Foundation/
│   │   ├── Application.php
│   │   └── Bootstrap/
│   │       ├── BootProviders.php
│   │       ├── LoadConfiguration.php
│   │       ├── LoadEnvironmentVariables.php
│   │       └── RegisterProviders.php
│   ├── Http/
│   │   ├── Kernel.php
│   │   ├── Request.php
│   │   └── Response.php
│   ├── Pipeline/
│   │   └── Pipeline.php
│   ├── Queue/
│   │   ├── Connectors/
│   │   │   └── DatabaseConnector.php
│   │   ├── QueueManager.php
│   │   └── Worker.php
│   ├── Routing/
│   │   ├── Route.php
│   │   └── Router.php
│   ├── Support/
│   │   ├── Facades/
│   │   │   ├── Cache.php
│   │   │   ├── DB.php
│   │   │   ├── Event.php
│   │   │   └── Route.php
│   │   └── ServiceProvider.php
│   ├── Validation/
│   │   └── Validator.php
│   ├── View/
│   │   └── View.php
│   └── helpers.php
├── artisan                 ← CLI entry point
└── composer.json
```

---

## ⚠️ What We Deliberately Exclude

| Laravel Feature                        | Why We Exclude                                                                                                                         |
| -------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| Broadcasting                           | Real-time WebSockets integration requires dedicated servers (Pusher/Reverb) and is outside core PHP framework concepts.                |
| Blade Compiler directives              | Regex translation of tags (like `@if`, `@foreach`) is a parser concern, not a core architecture pattern. Plain PHP templates are used. |
| Multi-channel Queues / Complex Drivers | We build a simple database queue, but complex features like Redis pub-sub, Horizon, and failing job retries are excluded.              |
| Third-party Auth integrations          | OAuth, Socialite, and Passport/Sanctum are external extensions built on the core Guard system.                                         |

---

## 🚀 Getting Started

```bash
mkdir laravel-clone && cd laravel-clone
```

Then follow the steps in order. Each one builds on the last, and each one can be verified before moving to the next.

**First step:** [01 — Entry Point →](./01-entry-point.md)
