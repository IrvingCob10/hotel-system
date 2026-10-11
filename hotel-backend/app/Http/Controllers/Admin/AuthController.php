<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('admin.auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (
            Auth::attempt($credentials) &&
            Auth::user()->is_super_admin
        ) {
            $request->session()->regenerate();

            return redirect()->route('admin.dashboard');
        }

        Auth::logout();

        return back()->withErrors([
            'email' => 'Las credenciales no son válidas o no tienes permisos de SuperAdmin.',
        ])->onlyInput('email');
    }

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}