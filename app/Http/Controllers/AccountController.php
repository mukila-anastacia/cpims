<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255', 'confirmed', 'different:current_password', Password::min(8)->letters()->numbers()],
        ], [
            'password.confirmed' => 'The two new passwords do not match.',
            'password.different' => 'Choose a password you are not using now.',
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            AuditService::log('PASSWORD_CHANGE_FAILED', 'user', $user->id);
            throw ValidationException::withMessages(['current_password' => 'Your current password is wrong.']);
        }

        $user->update(['password' => $data['password']]);
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        $request->session()->regenerate();
        AuditService::log('PASSWORD_CHANGED', 'user', $user->id);

        return response()->json(['message' => 'Password changed.']);
    }
}