<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Support\AdminTotp;
use App\Support\TravelGoFields;
use App\Support\TravelGoType;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    public function index(): View
    {
        $admins = AdminUser::query()->orderByDesc('is_superadmin')->orderBy('email')->paginate(25);

        return view('admin.staff.index', compact('admins'));
    }

    public function edit(AdminUser $admin): View
    {
        abort_if($admin->is_superadmin, 404);

        return view('admin.staff.permissions', compact('admin'));
    }

    public function store(Request $request, AdminTotp $totp): Response
    {
        $request->merge(['email' => Str::lower($request->string('email')->toString())]);
        $data = $request->validate([
            'currentPassword' => ['required', 'current_password:admin'],
            'email' => ['required', 'email', 'max:255', Rule::unique('admin_users')],
            'password' => ['required', 'string', 'min:14', 'regex:/\A[^\x00]{1,72}\z/', 'confirmed'],
        ]);
        $actor = $request->user('admin');
        $secret = $totp->generateSecret();
        $admin = DB::transaction(function () use ($actor, $data, $secret): AdminUser {
            $admin = new AdminUser;
            $admin->email = $data['email'];
            $admin->password = $data['password'];
            $admin->totp_secret = $secret;
            $admin->permissions = [];
            $admin->save();
            $this->audit($actor, $admin, 'staff_created');

            return $admin;
        });

        return response()->view('admin.staff.setup', compact('admin', 'secret'), 201, ['Cache-Control' => 'private, no-store']);
    }

    public function update(Request $request, AdminUser $admin): RedirectResponse
    {
        abort_if($admin->is_superadmin, 404);
        $allowed = ['travelgo.*', 'travelgo.publication.view', 'travelgo.audit.view',
            'integrations.alphasms.view', 'integrations.alphasms.update', ...array_keys(MobileUserController::PERMISSIONS)];
        foreach (TravelGoType::cases() as $type) {
            foreach (array_keys(TravelGoFields::actions()) as $action) {
                $allowed[] = $type->permission($action);
            }
        }
        $input = $request->validate([
            'currentPassword' => ['required', 'current_password:admin'],
            'permissions' => ['sometimes', 'array', 'list', 'max:40'],
            'permissions.*' => ['required', 'distinct', Rule::in($allowed)],
        ]);
        $actor = $request->user('admin');
        DB::transaction(function () use ($admin, $input, $actor): void {
            $target = AdminUser::query()->whereKey($admin->id)->where('is_superadmin', false)->lockForUpdate()->firstOrFail();
            $previous = $target->permissions ?? [];
            $target->permissions = $input['permissions'] ?? [];
            $target->save();
            $this->audit($actor, $target, 'staff_permissions', ['previous' => $previous, 'permissions' => $target->permissions]);
        });

        return to_route('admin.staff.edit', $admin)->with('status', 'Права сохранены.');
    }

    private function audit(AdminUser $actor, AdminUser $target, string $action, array $details = []): void
    {
        DB::table('travelgo_audit_events')->insert([
            'admin_id' => $actor->id, 'actor' => $actor->email, 'type' => 'staff', 'action' => $action,
            'details' => json_encode(['account' => $target->email] + $details, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
