<?php

namespace App\Support;

/**
 * The module and permission registry: the single source of truth that the roles
 * screen, the permission seeder, the route middleware and the sidebar are all
 * built from.
 *
 * It lives here rather than on a controller because it is domain knowledge, not
 * request handling — a seeder or a console command needs these lists without
 * wanting anything to do with HTTP. This is the PROCMIS `App\Support\Modules`
 * pattern, which is why the two read alike.
 *
 * Adding a module means four edits, and all four belong in the same change:
 *
 *   1. MODULES and MODULE_ACTIONS here,
 *   2. the sidebar entry in resources/js/components/AdminShell.js,
 *   3. the route group in routes/web.php,
 *   4. a migration granting the new permissions to the existing SUPERADMIN role
 *      (database/seeders only run on a fresh install).
 *
 * Skipping (2) or (3) is worse than doing neither: a hidden sidebar entry over
 * an open route reads as protection that is not there.
 *
 * This list holds only what actually gates something today. A permission that
 * gates nothing is not harmless — it is a checkbox on the roles screen that
 * grants the administrator nothing, and a name a developer will later gate a
 * route on believing it was already assignable. The file manager, upload
 * settings, activity trails and document types each add their own entries when
 * that module lands.
 */
class Modules
{
    /**
     * Every module that carries permissions.
     *
     * @var list<string>
     */
    public const MODULES = [
        'documents',
        'documents-all',
        'access-requests',
        'files',
        'download-logs',
        'activity-trails',
        'upload-settings',
        'document-types',
        'users',
        'divisions',
        'sections',
        'roles',
    ];

    /**
     * The granular action vocabulary. A permission is "{module}.{action}".
     *
     * @var list<string>
     */
    public const ACTIONS = ['read', 'create', 'write', 'edit', 'delete', 'share', 'download', 'manage'];

    /**
     * Which actions each module actually offers.
     *
     * Not every module offers every action, and an unoffered permission is not
     * harmless: it is a checkbox on the roles screen that grants nothing, and a
     * name a developer will later gate a route on by mistake. `scan.share` is
     * nonsense, so it does not exist.
     *
     * `files` is the only module carrying the full set.
     *
     * @var array<string, list<string>>
     */
    public const MODULE_ACTIONS = [
        'documents' => ['read', 'create', 'write', 'delete', 'share', 'download'],
        'documents-all' => ['read'],

        /*
         * Asking to be let into a document, and answering.
         *
         * **No `write` or `decide` action, deliberately.** Whether somebody may
         * answer a request is a per-record question — it is addressed to the
         * document's uploader — and a module permission for it would create
         * requests nobody holding the checkbox could answer, which is a dead
         * letter rather than a protection. Same distinction as
         * `documents.delete` versus `DocumentController::owns()`.
         */
        'access-requests' => ['read', 'create'],

        /*
         * The file manager carries the widest action set, and `manage` is
         * deliberately separate from the rest: it is the administrator's reach
         * across *other people's* storage — the Browse Storage screen — which
         * no editing checkbox should hand over.
         *
         * `write` is gone. It gated no route and never had: `edit` is the
         * coarse toggle the roles screen offers, and a permission that gates
         * nothing is a checkbox granting the administrator nothing. The two
         * read-only companions went the same way — `files-shared` because
         * Shared with Me is part of My Files now, and `files-browse` because
         * Browse Storage is exactly what `files.manage` already means.
         */
        'files' => ['read', 'create', 'edit', 'delete', 'share', 'download', 'manage'],

        'download-logs' => ['read'],
        'activity-trails' => ['read'],
        'upload-settings' => ['read', 'write'],
        'document-types' => ['read', 'write', 'delete'],
        'users' => ['read', 'write', 'delete'],
        'divisions' => ['read', 'write', 'delete'],
        'sections' => ['read', 'write', 'delete'],
        'roles' => ['read', 'write', 'delete'],
    ];

    /**
     * Modules that can only ever be read. The roles screen refuses to grant
     * anything else on them, server-side, not just by hiding the checkbox.
     *
     * @var list<string>
     */
    public const READ_ONLY_MODULES = ['download-logs', 'activity-trails', 'documents-all'];

    /**
     * Sidebar entries every authenticated user reaches, permissions or not.
     *
     * `scan` and `track` are here because their routes are deliberately public:
     * a QR label has to resolve for a signed-out reader of a public document,
     * so gating the sidebar entry would hide a link to a page the user can
     * reach anyway.
     *
     * @var list<string>
     */
    public const ALWAYS_VISIBLE = ['dashboard', 'account', 'scan', 'track'];

    /**
     * Permissions a module's write toggle carries with it, beyond the one it
     * grants by name.
     *
     * The roles screen offers a coarse toggle; a capability gated on a
     * permission that toggle never grants can only ever be held by SUPERADMIN,
     * which is how PROCMIS ended up with `pr.write` gating nothing at all.
     *
     * Destructive actions stay out on purpose: `.delete` and `.manage` are not
     * something a "can write" checkbox should hand over.
     *
     * @var array<string, array<string, list<string>>>
     */
    public const MODULE_IMPLIED_PERMISSIONS = [
        'documents' => ['write' => ['documents.create', 'documents.download', 'documents.share']],
        'files' => ['edit' => ['files.create', 'files.download']],
    ];

    /**
     * Display grouping for the roles screen and the sidebar. Presentational
     * only — it does not affect validation or route middleware, which key off
     * the flat MODULES array.
     *
     * @var array<string, list<string>>
     */
    public const MODULE_GROUPS = [
        'Documents' => ['documents', 'documents-all', 'access-requests'],
        'Storage' => ['files'],
        'Reports' => ['download-logs', 'activity-trails'],
        'Directory' => ['users', 'divisions', 'sections'],
        'Settings' => ['roles', 'document-types', 'upload-settings'],
    ];

    /**
     * Every permission name the application knows about.
     *
     * @return list<string>
     */
    public static function permissions(): array
    {
        $permissions = [];

        foreach (self::MODULES as $module) {
            foreach (self::actionsFor($module) as $action) {
                $permissions[] = "{$module}.{$action}";
            }
        }

        return $permissions;
    }

    /**
     * The actions a module offers, with the read-only rule applied last so it
     * always wins over a careless MODULE_ACTIONS entry.
     *
     * @return list<string>
     */
    public static function actionsFor(string $module): array
    {
        $actions = self::MODULE_ACTIONS[$module] ?? ['read'];

        if (in_array($module, self::READ_ONLY_MODULES, true)) {
            return ['read'];
        }

        return array_values(array_intersect($actions, self::ACTIONS));
    }

    /**
     * Expand a module/action pair into every permission it implies, including
     * itself. Write always implies read — a role that may edit a record it
     * cannot open is not a coherent grant.
     *
     * @return list<string>
     */
    public static function expand(string $module, string $action): array
    {
        $granted = ["{$module}.{$action}"];

        if ($action !== 'read' && in_array('read', self::actionsFor($module), true)) {
            $granted[] = "{$module}.read";
        }

        foreach (self::MODULE_IMPLIED_PERMISSIONS[$module][$action] ?? [] as $implied) {
            $granted[] = $implied;
        }

        return array_values(array_unique($granted));
    }

    public static function isReadOnly(string $module): bool
    {
        return in_array($module, self::READ_ONLY_MODULES, true);
    }
}
