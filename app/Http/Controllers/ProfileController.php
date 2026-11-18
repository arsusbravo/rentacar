<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/** The signed-in staff member: who they are, their name and their password. */
class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->present($request));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $request->user()->update($data);

        return response()->json($this->present($request));
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min((int) config('admin.min_password_length'))],
        ]);

        $request->user()->update(['password' => $data['password']]);

        return response()->json(['message' => __('Your password has been changed.')]);
    }

    private function present(Request $request): array
    {
        $user = $request->user();

        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role->value, 'is_admin' => $user->isAdmin(), 'min_password_length' => (int) config('admin.min_password_length')];
    }
}
