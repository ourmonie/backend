<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Auditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PRD 31 - administrative roles and a granular permission system that is
 * individually configurable. Roles, the permission catalogue and the mapping
 * between them are all database rows; nothing about who can do what is fixed
 * in application code.
 */
class AdminRoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::with('permissions:id,code,name,group')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $permissions = Permission::orderBy('group')->orderBy('sort_order')->orderBy('id')->get();

        return response()->json([
            'roles' => $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'code' => $role->code,
                'name' => $role->name,
                'description' => $role->description,
                'is_system' => (bool) $role->is_system,
                'is_active' => (bool) $role->is_active,
                'permission_codes' => $role->permissions->pluck('code'),
                'permission_count' => $role->permissions->count(),
                'user_count' => $role->users()->count(),
                'editable' => ! $role->is_system,
            ]),
            'permissions' => $permissions->map(fn (Permission $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'group' => $p->group,
                'description' => $p->description,
            ]),
            'permission_groups' => $permissions->pluck('group')->unique()->values(),
            'actors' => $this->actorCounts($permissions),
        ]);
    }

    /**
     * Accounts that can hold roles. Ordinary members appear alongside staff so
     * an account can be promoted from the same screen it is found on (PRD 31 -
     * the mapping between people and roles is configuration, not code).
     */
    public function staff(Request $request): JsonResponse
    {
        $users = User::query()
            ->with(['roles:id,code,name', 'member:id,user_id,member_number'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim((string) $request->input('search'));
                $query->where(function ($where) use ($search) {
                    $where->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('is_admin', 'desc')
            ->orderBy('name')
            ->paginate(min(50, max(5, (int) $request->input('per_page', 25))));

        return response()->json([
            'data' => collect($users->items())->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'member_number' => $user->member?->member_number,
                'role_codes' => $user->roles->pluck('code'),
                'role_names' => $user->roles->pluck('name'),
                'is_admin' => $user->isAdmin(),
                'last_login_at' => optional($user->last_login_at)->toDateTimeString(),
            ]),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function updatePermissions(Request $request, int $id): JsonResponse
    {
        $role = Role::with('permissions')->findOrFail($id);

        if ($role->is_system) {
            return response()->json([
                'message' => 'System roles keep every permission and cannot be edited.',
            ], 422);
        }

        $validated = $request->validate([
            'permission_codes' => ['required', 'array'],
            'permission_codes.*' => ['string', 'exists:permissions,code'],
        ]);

        $before = $role->permissions->pluck('code')->sort()->values()->all();

        DB::transaction(function () use ($role, $validated) {
            $ids = Permission::whereIn('code', $validated['permission_codes'])->pluck('id');
            $role->permissions()->sync($ids->all());
        });

        $after = $role->permissions()->pluck('permissions.code')->sort()->values()->all();

        Auditor::record(
            'role.permissions_changed',
            'admin',
            'Permissions updated for role '.$role->name,
            ['permission_codes' => $before],
            ['permission_codes' => $after],
            $role,
        );

        // Anyone currently holding this role picks up the change immediately.
        $role->users()->each(function ($user) {
            $user->forgetPermissionGraph();
        });

        return response()->json([
            'message' => 'Role permissions updated.',
            'role' => [
                'id' => $role->id,
                'code' => $role->code,
                'permission_codes' => $after,
                'permission_count' => count($after),
            ],
        ]);
    }

    /**
     * Assign roles to a staff user. Role assignment itself is a permission,
     * and the super_admin role can only be granted by someone who holds it.
     */
    public function assignUserRoles(Request $request, int $userId): JsonResponse
    {
        $actor = $request->user();
        $user = User::with('roles')->findOrFail($userId);

        $validated = $request->validate([
            'role_codes' => ['present', 'array'],
            'role_codes.*' => ['string', 'exists:roles,code'],
        ]);

        $requested = $validated['role_codes'];

        if (in_array(Role::SUPER_ADMIN, $requested, true) && ! $actor->hasRole(Role::SUPER_ADMIN)) {
            return response()->json([
                'message' => 'Only a Super Admin can grant the Super Admin role.',
            ], 403);
        }

        if ($user->id === $actor->id && ! in_array(Role::SUPER_ADMIN, $requested, true)) {
            return response()->json([
                'message' => 'You cannot remove your own administrative roles.',
            ], 422);
        }

        $before = $user->roles->pluck('code')->sort()->values()->all();

        DB::transaction(function () use ($user, $requested) {
            $ids = Role::whereIn('code', $requested)->pluck('id');
            $user->roles()->sync($ids->all());
        });

        $user->forgetPermissionGraph();
        $after = $user->roles()->pluck('roles.code')->sort()->values()->all();

        Auditor::record(
            'user.roles_changed',
            'admin',
            'Roles updated for '.$user->email,
            ['role_codes' => $before],
            ['role_codes' => $after],
            $user,
        );

        return response()->json([
            'message' => 'Roles updated.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role_codes' => $after,
                'is_admin' => $after !== [],
            ],
        ]);
    }

    /**
     * How many staff hold each permission - shown next to the catalogue so an
     * admin can see the blast radius before narrowing a role.
     *
     * @return array<string, int>
     */
    private function actorCounts($permissions): array
    {
        $rows = DB::table('role_permissions')
            ->join('role_user', 'role_user.role_id', '=', 'role_permissions.role_id')
            ->select('role_permissions.permission_id')
            ->selectRaw('COUNT(DISTINCT role_user.user_id) as total')
            ->groupBy('role_permissions.permission_id')
            ->pluck('total', 'permission_id');

        $counts = [];

        foreach ($permissions as $permission) {
            $counts[$permission->code] = (int) ($rows[$permission->id] ?? 0);
        }

        return $counts;
    }
}
