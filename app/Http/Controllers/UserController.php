<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Back office users (admins and employees). Admins only. Hidden accounts (config admin.hidden_emails)
 * are not listed and cannot be reached: they answer 404.
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json(User::manageable()->orderBy('name')->get()->map(fn (User $u) => $this->present($u, $request)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate($this->rules() + ['password' => ['required', Password::min((int) config('admin.min_password_length'))]]);

        // Hidden addresses are "taken" like any other, without saying why.
        $this->rejectHiddenEmail($data['email']);

        $user = User::create($data + ['email_verified_at' => now()]);

        return response()->json($this->present($user, $request), 201);
    }

    public function update(Request $request, int $user): JsonResponse
    {
        $this->authorizeAdmin($request);
        $target = User::manageable()->findOrFail($user);

        $data = $request->validate($this->rules($target) + ['password' => ['nullable', Password::min((int) config('admin.min_password_length'))]]);
        $this->rejectHiddenEmail($data['email']);

        if ($target->is($request->user()) && $data['role'] !== $target->role->value) {
            return $this->refuse('role', __('You cannot change your own role.'));
        }

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $target->update($data);

        return response()->json($this->present($target->fresh(), $request));
    }

    public function destroy(Request $request, int $user): JsonResponse|Response
    {
        $this->authorizeAdmin($request);
        $target = User::manageable()->findOrFail($user);

        if ($target->is($request->user())) {
            return $this->refuse('user', __('You cannot delete your own account.'));
        }

        $target->delete();

        return response()->noContent();
    }

    private function rules(?User $user = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')->ignore($user?->id)],
            'role' => ['required', Rule::in(array_map(fn (Role $r) => $r->value, Role::staff()))],
        ];
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403);
    }

    private function rejectHiddenEmail(string $email): void
    {
        if (in_array(strtolower($email), array_map('strtolower', config('admin.hidden_emails', [])), true)) {
            abort(response()->json(['message' => __('validation.unique', ['attribute' => __('email')]), 'errors' => ['email' => [__('validation.unique', ['attribute' => __('email')])]]], 422));
        }
    }

    private function refuse(string $field, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }

    private function present(User $user, Request $request): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role->value, 'is_self' => $user->is($request->user()), 'created_at' => $user->created_at?->format('Y-m-d')];
    }
}
