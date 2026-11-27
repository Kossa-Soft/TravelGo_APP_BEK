<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\MobileSession;
use App\Models\User;
use App\Services\AvatarImages;
use App\Services\MobileAuth;
use App\Services\MobileProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MobileUserController extends Controller
{
    public const array PERMISSIONS = [
        'users.view' => 'Просмотр пользователей',
        'users.plan' => 'Изменение плана',
        'users.block' => 'Блокировка пользователей',
        'users.delete' => 'Удаление аккаунтов',
    ];

    public function __construct(private readonly MobileAuth $auth, private readonly MobileProfile $profile) {}

    public function index(Request $request): View|JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:32'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest'])],
        ]);
        $query = User::query()->whereNotNull('phone');
        $phone = preg_replace('/\D/', '', $filters['phone'] ?? '');
        if ($phone !== '') {
            $query->whereLike('phone', '%'.$phone.'%');
        }
        $search = trim($filters['q'] ?? '');
        if ($search !== '') {
            $this->addSearch($query, $search);
        }
        $order = ($filters['sort'] ?? '') === 'oldest' ? 'asc' : 'desc';
        $users = $query->orderBy('created_at', $order)->orderBy('id', $order)->paginate(25)->withQueryString();

        if ($this->api($request)) {
            return response()->json(['items' => $users->getCollection()->map($this->details(...)), 'total' => $users->total(), 'page' => $users->currentPage()]);
        }

        return view('admin.users.index', compact('users', 'filters'));
    }

    /** @param Builder<User> $query */
    private function addSearch(Builder $query, string $search): void
    {
        if (preg_match('/\A[+\d\s().-]+\z/u', $search)) {
            $digits = preg_replace('/\D/', '', $search);
            if ($digits !== '') {
                $query->whereLike('phone', '%'.$digits.'%');
            }

            return;
        }
        foreach (preg_split('/\s+/u', $search, limit: 6, flags: PREG_SPLIT_NO_EMPTY) as $part) {
            $pattern = '%'.$part.'%';
            $query->where(fn (Builder $group) => $group
                ->whereLike('last_name', $pattern)->orWhereLike('first_name', $pattern)
                ->orWhereLike('middle_name', $pattern)->orWhereLike('name', $pattern));
        }
    }

    public function show(Request $request, string $id): View|JsonResponse
    {
        $user = $this->user($id);

        return $this->api($request) ? response()->json($this->details($user)) : view('admin.users.show', compact('user'));
    }

    public function update(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $updatesPlan = $request->hasAny(['plan', 'planUntil']);
        $updatesBlock = $request->has('blocked');
        if (! $updatesPlan && ! $updatesBlock) {
            throw ValidationException::withMessages(['plan' => 'Выберите действие для пользователя.']);
        }
        if ($updatesPlan) {
            Gate::authorize('users.plan');
        }
        if ($updatesBlock) {
            Gate::authorize('users.block');
        }

        $data = $request->validate([
            'plan' => ['required_with:planUntil', 'sometimes', 'required', Rule::in(['free', 'pro'])],
            'planUntil' => ['nullable', 'date'],
            'blocked' => ['sometimes', 'required', 'boolean'],
        ]);
        if (array_key_exists('planUntil', $data) && ! array_key_exists('plan', $data)) {
            throw ValidationException::withMessages(['plan' => 'Укажите план вместе со сроком.']);
        }

        $user = DB::transaction(function () use ($id, $data, $request): User {
            $user = $this->user($id, lock: true);
            $actor = $request->user('admin');
            if (isset($data['plan'])) {
                $this->setPlan($user, $data['plan'], $data['planUntil'] ?? null, $actor);
            }
            if (isset($data['blocked'])) {
                $this->setBlocked($user, (bool) $data['blocked'], $actor);
            }

            return $user;
        });

        return $this->api($request) ? response()->json($this->details($user))
            : to_route('admin.users.show', $user->public_id)->with('status', 'Изменения сохранены.');
    }

    private function setPlan(User $user, string $plan, ?string $until, AdminUser $actor): void
    {
        $before = $this->plan($user);
        $user->plan = $plan;
        $user->plan_until = $plan === 'pro' && $until !== null ? Carbon::parse($until, 'UTC')->utc() : null;
        if ($user->isDirty(['plan', 'plan_until'])) {
            $user->save();
            $this->audit($actor, $user, 'plan_changed', ['from' => $before, 'to' => $this->plan($user)]);
        }
    }

    private function setBlocked(User $user, bool $blocked, AdminUser $actor): void
    {
        if ($user->isBlocked() === $blocked) {
            return;
        }
        $user->blocked_at = $blocked ? now() : null;
        $user->save();
        if ($blocked) {
            MobileSession::query()->where('user_id', $user->id)->delete();
            $this->auth->clearAccountChallenges($user);
        }
        $this->audit($actor, $user, $blocked ? 'blocked' : 'unblocked');
    }

    public function destroy(Request $request, string $id): RedirectResponse|Response
    {
        $request->validate([
            'confirm' => ['accepted'],
            'currentPassword' => ['bail', 'required', 'string', 'regex:/\A[^\x00]{1,72}\z/', 'current_password:admin'],
        ]);
        DB::transaction(function () use ($id, $request): void {
            $user = $this->user($id, lock: true);
            $this->audit($request->user('admin'), $user, 'deleted');
            $this->profile->eraseAccount($user);
        });

        return $this->api($request) ? response()->noContent() : to_route('admin.users.index')->with('status', 'Аккаунт удалён.');
    }

    public function journal(Request $request): View|JsonResponse
    {
        $input = $request->validate(['user' => ['nullable', 'uuid']]);
        $events = DB::table('travelgo_audit_events')->where('type', 'users')
            ->when($input['user'] ?? null, fn ($query, string $id) => $query->where('content_id', $id))
            ->orderByDesc('id')->paginate(30)->withQueryString();
        $events->through(fn (object $event): array => [
            'user' => $event->content_id, 'actor' => $event->actor, 'action' => $event->action,
            'details' => json_decode($event->details, true, flags: JSON_THROW_ON_ERROR),
            'createdAt' => Carbon::parse($event->created_at)->utc(),
        ]);

        return $this->api($request) ? response()->json($events) : view('admin.users.journal', compact('events', 'input'));
    }

    private function audit(AdminUser $actor, User $user, string $action, array $details = []): void
    {
        DB::table('travelgo_audit_events')->insert([
            'content_id' => $user->public_id, 'admin_id' => $actor->id, 'actor' => $actor->email,
            'type' => 'users', 'action' => $action, 'details' => json_encode((object) $details, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    private function plan(User $user): array
    {
        return ['plan' => $user->plan, 'planUntil' => $user->plan_until?->utc()->toISOString()];
    }

    private function details(User $user): array
    {
        return [
            'id' => $user->public_id, 'name' => $user->displayName(), 'phone' => $user->phone,
            'language' => $user->language, 'avatarUrl' => $user->avatar_id === null ? null : AvatarImages::url($user->public_id, $user->avatar_id),
            'createdAt' => $user->created_at?->utc()->toISOString(), 'lastLoginAt' => $user->last_login_at?->utc()->toISOString(),
            'effectivePlan' => $user->effectivePlan(), 'blocked' => $user->isBlocked(),
        ] + $this->plan($user);
    }

    private function user(string $id, bool $lock = false): User
    {
        return User::query()->whereNotNull('phone')->where('public_id', $id)->when($lock, fn (Builder $query) => $query->lockForUpdate())->firstOrFail();
    }

    private function api(Request $request): bool
    {
        return $request->is('admin/v1/*');
    }
}
