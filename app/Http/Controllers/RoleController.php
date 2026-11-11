<?php

namespace App\Http\Controllers;

use App\Support\Modules;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles and the permissions they carry.
 *
 * The screen is built from App\Support\Modules, so a module added there shows
 * up here without a change to this controller — which is the point of having a
 * registry at all.
 */
class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        $entries = $this->entries($request);
        $search = $request->string('search')->toString();

        return Inertia::render('roles/index', [
            'roles' => Role::query()
                ->withCount(['users', 'permissions'])
                ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
                ->orderBy('name')
                ->paginate($entries)
                ->withQueryString(),
            'filters' => [
                'entries' => $entries,
                'search' => $search,
            ],
            'protectedRole' => Roles::SUPERADMIN,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('roles/form', $this->formPayload(null));
    }

    public function edit(Role $role): Response
    {
        return Inertia::render('roles/form', $this->formPayload($role));
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, null);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        return $this->save($request, $role);
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->name === Roles::SUPERADMIN) {
            return back()->with('error', 'The '.Roles::SUPERADMIN.' role cannot be deleted.');
        }

        if ($role->users()->exists()) {
            return back()->with('error', 'Reassign the accounts holding this role before deleting it.');
        }

        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return to_route('roles.index')->with('success', 'Role deleted.');
    }

    /**
     * Store and update are the same operation over a nullable role, so they are
     * the same method — the same reason one Vue component serves both forms.
     */
    private function save(Request $request, ?Role $role): RedirectResponse
    {
        // SUPERADMIN is the role the Gate::before hook short-circuits on and the
        // one that can hand out every other grant. Renaming it would silently
        // strip that bypass from whoever holds it.
        if ($role && $role->name === Roles::SUPERADMIN) {
            return back()->with('error', 'The '.Roles::SUPERADMIN.' role cannot be modified.');
        }

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:64',
                Rule::unique('roles', 'name')->ignore($role?->id),
                Rule::notIn([Roles::SUPERADMIN]),
            ],
            'access' => ['array'],
            'access.*' => ['array'],
            'access.*.*' => ['string'],
        ]);

        $granted = $this->resolvePermissions($validated['access'] ?? []);

        $role = $role
            ? tap($role)->update(['name' => $validated['name']])
            : Role::create(['name' => $validated['name'], 'guard_name' => 'web']);

        $role->syncPermissions(
            collect($granted)->map(fn (string $name) => Permission::findOrCreate($name, 'web'))
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return to_route('roles.index')
            ->with('success', $role->wasRecentlyCreated ? 'Role created.' : 'Role updated.');
    }

    /**
     * Turn the screen's {module: [action, …]} payload into permission names.
     *
     * Everything the client sends is re-derived against the registry rather
     * than trusted: an action the module does not offer is dropped, a read-only
     * module is narrowed to `read` no matter what arrived, and `write` expands
     * to the permissions it implies. A posted permission name that the registry
     * does not know about cannot survive this, which is what stops a crafted
     * request from granting something the screen never offered.
     *
     * @param  array<string, list<string>>  $access
     * @return list<string>
     */
    private function resolvePermissions(array $access): array
    {
        $granted = [];

        foreach ($access as $module => $actions) {
            if (! in_array($module, Modules::MODULES, true)) {
                continue;
            }

            $offered = Modules::actionsFor($module);

            foreach (array_intersect($actions, $offered) as $action) {
                $granted = [...$granted, ...Modules::expand($module, $action)];
            }
        }

        return array_values(array_unique($granted));
    }

    /**
     * @return array<string, mixed>
     */
    private function formPayload(?Role $role): array
    {
        return [
            'role' => $role ? [
                'id' => $role->id,
                'name' => $role->name,
                // Grouped the way the screen renders it, so the form hydrates
                // its checkboxes without re-parsing permission names in JS.
                'access' => $this->accessFor($role),
            ] : null,
            'moduleGroups' => Modules::MODULE_GROUPS,
            'moduleActions' => collect(Modules::MODULES)
                ->mapWithKeys(fn (string $module) => [$module => Modules::actionsFor($module)])
                ->all(),
            'moduleLabels' => collect(Modules::MODULES)
                ->mapWithKeys(fn (string $module) => [$module => $this->label($module)])
                ->all(),
            'readOnlyModules' => Modules::READ_ONLY_MODULES,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function accessFor(Role $role): array
    {
        $held = $role->permissions->pluck('name')->all();
        $access = [];

        foreach (Modules::MODULES as $module) {
            $actions = array_values(array_filter(
                Modules::actionsFor($module),
                fn (string $action) => in_array("{$module}.{$action}", $held, true),
            ));

            if ($actions !== []) {
                $access[$module] = $actions;
            }
        }

        return $access;
    }

    private function label(string $module): string
    {
        return match ($module) {
            'documents-all' => 'All Documents',
            'download-logs' => 'Download Logs',
            'roles' => 'Roles and Permissions',
            default => ucfirst(str_replace('-', ' ', $module)),
        };
    }

    private function entries(Request $request): int
    {
        $entries = $request->integer('entries', 10);

        return in_array($entries, [10, 25, 50], true) ? $entries : 10;
    }
}
