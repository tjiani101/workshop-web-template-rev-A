<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'These credentials do not match our records.'])->onlyInput('email');
        }

        if ($request->user()->hasRole('customer') && $request->user()->customer_status !== 'active') {
            Auth::logout();

            return back()->withErrors(['email' => 'This customer account is currently inactive. Contact MINEX for assistance.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended($this->dashboardPath($request->user()));
    }

    public function showRegister(): View
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:254', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ]);

        $customerRole = Role::query()->where('name', 'customer')->firstOrFail();
        $user = User::create([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'phone' => $validated['phone'] ?? null,
            'password' => $validated['password'],
        ]);
        $user->roles()->attach($customerRole);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('portal.home');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    private function dashboardPath(User $user): string
    {
        return match (true) {
            $user->hasRole(['owner', 'admin']) => route('admin.dashboard'),
            $user->hasRole('mechanic') => route('mechanic.dashboard'),
            default => route('portal.home'),
        };
    }
}
