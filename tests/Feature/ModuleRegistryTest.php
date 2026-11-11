<?php

namespace Tests\Feature;

use App\Support\Modules;
use App\Support\Roles;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The registry and the route gates must agree, in **both** directions.
 *
 * `App\Support\Modules` documents that adding a module takes four edits — the
 * registry, the sidebar, the route group and a grant migration — and the
 * failure mode is that one is quietly skipped. These assert the two halves that
 * can be checked mechanically.
 *
 * This file exists because of a real miss: a route group was added gating on
 * `document-types.*` while the registry edit silently did not apply, so the
 * permission existed in the database but could not be granted from the Roles
 * screen. The module worked for SUPERADMIN, whose `Gate::before` short-circuits
 * every check, and would have been ungrantable to anybody else.
 */
class ModuleRegistryTest extends TestCase
{
    /**
     * Every permission a route gates on must be one the registry can generate.
     *
     * The direction that was missing. Without it, a route can gate on a
     * permission no role can ever be given — invisible, because SUPERADMIN
     * bypasses the check entirely.
     */
    public function test_every_gated_route_names_a_permission_the_registry_knows(): void
    {
        $known = Modules::permissions();
        $unknown = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'permission:')) {
                    continue;
                }

                foreach (explode('|', substr($middleware, strlen('permission:'))) as $permission) {
                    if (! in_array($permission, $known, true)) {
                        $unknown[$permission][] = $route->uri();
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $unknown,
            "These routes gate on permissions App\\Support\\Modules cannot generate, so no role can be granted them:\n"
                .collect($unknown)->map(fn (array $uris, string $p) => "  {$p} — /".implode(', /', array_unique($uris)))->implode("\n"),
        );
    }

    /**
     * And the other direction: a permission that gates nothing is a checkbox on
     * the roles screen that grants the administrator nothing.
     *
     * A handful are read in code rather than on a route — `files.manage` is the
     * administrator's read, resolved inside AccessResolver — so those are named
     * here rather than silently tolerated.
     */
    public function test_every_registry_permission_gates_something(): void
    {
        /*
         * Two are resolved in code rather than on a route, and both are
         * deliberate:
         *
         * - `documents-all.read` gates the All *tab* on the Documents page. A
         *   tab is a filter on one screen, so an unreachable one degrades to a
         *   reachable one inside the controller — middleware would turn a stale
         *   link into a refusal of the whole page.
         * - `documents.share` gates naming recipients on a private upload,
         *   which is one branch of `documents.store` rather than a route.
         *
         * @var list<string>
         */
        $resolvedInCode = ['documents-all.read', 'documents.share'];

        $gated = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                    $gated = [...$gated, ...explode('|', substr($middleware, strlen('permission:')))];
                }
            }
        }

        $orphans = array_values(array_diff(Modules::permissions(), $gated, $resolvedInCode));

        $this->assertSame(
            [],
            $orphans,
            'These permissions gate no route and are not read in code: '.implode(', ', $orphans),
        );
    }

    /** Every module the registry names must offer at least one action. */
    public function test_every_module_offers_at_least_one_action(): void
    {
        foreach (Modules::MODULES as $module) {
            $this->assertNotEmpty(
                Modules::actionsFor($module),
                "Module '{$module}' offers no actions, so it can never be granted.",
            );
        }
    }

    /** A read-only module must never be able to offer anything but read. */
    public function test_a_read_only_module_offers_only_read(): void
    {
        foreach (Modules::READ_ONLY_MODULES as $module) {
            $this->assertSame(['read'], Modules::actionsFor($module), "Read-only module '{$module}'.");
        }
    }

    /** Every grouped module must exist, and every module must be grouped. */
    public function test_the_display_groups_match_the_module_list(): void
    {
        $grouped = collect(Modules::MODULE_GROUPS)->flatten()->all();

        $this->assertSame(
            [],
            array_values(array_diff($grouped, Modules::MODULES)),
            'MODULE_GROUPS names modules that do not exist.',
        );
        $this->assertSame(
            [],
            array_values(array_diff(Modules::MODULES, $grouped)),
            'These modules are in no display group, so the roles screen will not show them.',
        );
    }

    /** Staff's grant must only name permissions the registry can generate. */
    public function test_the_staff_role_names_only_real_permissions(): void
    {
        $this->assertSame(
            [],
            array_values(array_diff(Roles::STAFF_PERMISSIONS, Modules::permissions())),
        );
    }
}
