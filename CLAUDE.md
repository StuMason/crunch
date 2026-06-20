## Coding Standards (Read First!)

**Before writing any code**, read the coding standards in `docs/standards/`:

- [README.md](docs/standards/README.md) - Quick reference and checklist
- [general.md](docs/standards/general.md) - Core principles, type hints, imports
- [backend.md](docs/standards/backend.md) - Laravel patterns: models, migrations, controllers, Actions, DTOs
- [frontend.md](docs/standards/frontend.md) - Inertia + React + Tailwind patterns
- [testing.md](docs/standards/testing.md) - Pest v4 testing patterns

These standards take precedence over generic Laravel conventions. When writing similar code to what exists elsewhere, check how it was done and follow the same pattern.

---

## Git Workflow

### Branch Strategy

- Work on feature branches: `feature/short-description`
- Make atomic commits for logical units of work
- Keep commits focused and well-described

### Slice-Based Development

Break features into vertical slices. Each slice should be a complete, testable unit:

1. Migration + Model + Factory
2. Controller + Routes
3. Frontend page/component
4. Tests

Complete one slice before moving to the next. This ensures incremental, testable progress.

### Example Workflow

```bash
git checkout -b feature/user-profiles
# Work on slice 1: database layer
# Commit: "Add users profile fields migration and model updates"
# Work on slice 2: API layer
# Commit: "Add profile controller and routes"
# Work on slice 3: frontend
# Commit: "Add profile page with edit form"
# Work on slice 4: tests
# Commit: "Add profile feature tests"
git push -u origin feature/user-profiles
# Create PR when feature is complete
```

---

## GitHub Interactions

Always use `gh` CLI for GitHub interactions instead of web fetching:

```bash
# Issues
gh issue list
gh issue view 123
gh issue create --title "Title" --body "Body"

# Pull Requests
gh pr list
gh pr view 123
gh pr view 123 --comments --json comments
gh pr diff 123
gh pr create --title "Title" --body "Body"

# Comments
gh pr comment 123 --body "Comment text"
```

The `gh` CLI is more reliable than web fetching and has proper access to private repositories.

---

## Generator Commands

Use these commands instead of writing boilerplate manually:

```bash
# Create an Action class (business logic)
php artisan make:action User/UpdateProfile
php artisan make:action Order/ApproveOrder

# Create a DTO (data transfer object)
php artisan make:dto UserProfile --properties=id:int,name:string,email:string
php artisan make:dto OrderData --properties=id:int,total:int,status:string --model=Order

# Generate TypeScript interfaces from DTOs
php artisan types:generate
```

### When to use these commands

- **make:action**: Always use for business logic. Creates properly structured Action in `app/Actions/{Domain}/`
- **make:dto**: Use when passing data to Inertia pages. Creates readonly DTO with optional `fromModel()` method
- **types:generate**: Run after creating/modifying DTOs. Generates `resources/js/types/generated.d.ts`

### TypeScript Integration

After running `types:generate`, import types in your React components:

```tsx
import type { UserData, OrderData } from '@/types/generated';
```

---

## Async Job Pattern

For queued/async work, use the Controller → Job → Action pattern:

```
Controller → Job → Action
```

- **Controller**: Dispatches the job (keeps HTTP request fast)
- **Job**: Implements `ShouldQueue`, calls the action, handles failures
- **Action**: Contains the reusable business logic

### Example

```php
// Controller - dispatch and return immediately
public function store(Request $request): RedirectResponse
{
    SyncRepositoryJob::dispatch($request->owner, $request->repo, $request->user()->id);
    return back()->with('success', 'Sync started');
}

// Job - queued wrapper with failure handling
class SyncRepositoryJob implements ShouldQueue
{
    public function __construct(
        public string $owner,
        public string $repo,
        public int $userId,
    ) {}

    public function handle(SyncRepository $action): void
    {
        $result = $action->handle($this->owner, $this->repo);
        SyncCompleted::dispatch($result, $this->userId);
    }

    public function failed(Throwable $e): void
    {
        SyncFailed::dispatch($this->owner, $this->repo, $e->getMessage(), $this->userId);
    }
}

// Action - reusable business logic (can be called sync or async)
class SyncRepository
{
    public function handle(string $owner, string $repo): Repository
    {
        // Business logic here
    }
}
```

This keeps Actions reusable (can be called synchronously in tests or other contexts) while providing proper async handling.

---

## Real-Time Updates with Reverb

For real-time frontend updates from async jobs, use Laravel Reverb with Echo React.

### Backend: Broadcasting Events

```php
// Event for success
class SyncCompleted implements ShouldBroadcast
{
    public function __construct(
        public Repository $repository,
        public int $userId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("App.Models.User.{$this->userId}")];
    }
}

// Event for failure
class SyncFailed implements ShouldBroadcast
{
    public function __construct(
        public string $identifier,
        public string $message,
        public int $userId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("App.Models.User.{$this->userId}")];
    }
}
```

### Frontend: Listening with useEcho

```tsx
import { useEcho } from '@laravel/echo-react';
import { toast } from 'sonner';

export default function Dashboard({ auth }: { auth: { user: User } }) {
    useEcho(`App.Models.User.${auth.user.id}`, 'SyncCompleted', (e) => {
        toast.success('Repository synced!', {
            description: e.repository.name,
        });
    });

    useEcho(`App.Models.User.${auth.user.id}`, 'SyncFailed', (e) => {
        toast.error('Sync failed', {
            description: e.message,
        });
    });

    return <div>...</div>;
}
```

### Channel Authorization

In `routes/channels.php`:

```php
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
```

---

## Running Tests

Use this command to run tests (avoids environment variable conflicts):

```bash
env -u APP_ENV -u DB_CONNECTION -u DB_DATABASE -u DB_HOST -u DB_PORT -u DB_USERNAME -u DB_PASSWORD -u SESSION_DRIVER -u CACHE_STORE -u QUEUE_CONNECTION php artisan test
```


---

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v3
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/sanctum (SANCTUM) - v4
- laravel/telescope (TELESCOPE) - v5
- laravel/wayfinder (WAYFINDER) - v0
- larastan/larastan (LARASTAN) - v3
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- @inertiajs/react (INERTIA_REACT) - v3
- react (REACT) - v19
- tailwindcss (TAILWINDCSS) - v4
- @laravel/vite-plugin-wayfinder (WAYFINDER_VITE) - v0
- eslint (ESLINT) - v9
- prettier (PRETTIER) - v3

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>
