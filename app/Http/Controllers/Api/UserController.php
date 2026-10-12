<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function __construct(protected UserService $users) {}

    public function index(Request $request): JsonResponse
    {
        $q = User::query()->with('role:id,name,label')
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->whereLike('name', '%'.$request->string('q').'%')->orWhereLike('email', '%'.$request->string('q').'%')))
            ->when($request->filled('role_id'), fn ($q) => $q->where('role_id', $request->integer('role_id')))
            ->when($request->filled('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderByDesc('is_active')->orderBy('name');

        return response()->json($q->paginate(50)->through(fn (User $u) => $this->present($u)));
    }

    public function show(User $user): JsonResponse
    {
        $activity = ActivityLog::query()->where('user_id', $user->id)->orderByDesc('id')->limit(50)->get(['id', 'action', 'description', 'entity_type', 'entity_id', 'ip_address', 'created_at']);

        return response()->json(['data' => $this->present($user->load('role')) + ['recent_activity' => $activity]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:30'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'password' => ['nullable', 'string', Password::defaults()],
        ]);
        $result = $this->users->create($data, $request->user());

        return response()->json(['data' => $this->present($result['user']->load('role')), 'temporary_password' => $result['temporary_password']], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user)],
            'phone' => ['nullable', 'string', 'max:30'],
            'role_id' => ['sometimes', 'integer', Rule::exists('roles', 'id')],
        ]);

        return response()->json(['data' => $this->present($this->users->update($user, $data, $request->user()))]);
    }

    public function activate(Request $request, User $user): JsonResponse
    {
        return response()->json(['data' => $this->present($this->users->setActive($user, true, $request->user())->load('role'))]);
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        return response()->json(['data' => $this->present($this->users->setActive($user, false, $request->user())->load('role'))]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $temporary = $this->users->resetPassword($user, $request->user());

        return response()->json(['temporary_password' => $temporary, 'message' => 'Give this temporary password to the employee. They must change it when they sign in.']);
    }

    public function roles(): JsonResponse
    {
        return response()->json(['data' => Role::query()->withCount('users')->with('permissions:id,name')->orderBy('id')->get()
            ->map(fn (Role $r) => [
                'id' => $r->id, 'name' => $r->name, 'label' => $r->label, 'description' => $r->description, 'is_system' => $r->is_system,
                'users_count' => $r->users_count, 'locked' => $r->name === Role::OWNER,
                'permissions' => $r->name === Role::OWNER ? Permission::query()->pluck('name') : $r->permissions->pluck('name'),
            ]),
            'permissions' => Permission::query()->orderBy('group')->orderBy('id')->get(['name', 'label', 'group']),
        ]);
    }

    public function updateRole(Request $request, Role $role): JsonResponse
    {
        abort_if($role->name === Role::OWNER, 422, 'The owner role always has every permission.');
        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
            'label' => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        $old = $role->permissions()->pluck('name')->sort()->values()->all();
        $role->fill(array_intersect_key($data, array_flip(['label', 'description'])))->save();
        $role->permissions()->sync(Permission::query()->whereIn('name', $data['permissions'])->pluck('id'));
        $new = collect($data['permissions'])->sort()->values()->all();
        Audit::log('role.permissions_changed', $role, "Permissions of role {$role->label} changed", ['permissions' => $old], ['permissions' => $new]);

        return response()->json(['message' => 'Role permissions saved.']);
    }

    protected function present(User $u): array
    {
        return [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone,
            'role' => $u->role ? ['id' => $u->role->id, 'name' => $u->role->name, 'label' => $u->role->label] : null,
            'is_active' => $u->is_active, 'must_change_password' => $u->must_change_password,
            'last_login_at' => $u->last_login_at?->toIso8601String(), 'last_login_ip' => $u->last_login_ip,
            'created_at' => $u->created_at?->toIso8601String(),
        ];
    }
}
