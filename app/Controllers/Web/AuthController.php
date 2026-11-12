<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\UserRepository;
use App\Security\RateLimiter;
use App\Security\SafeRedirect;
use App\Services\PasswordResetService;

final class AuthController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordResetService $resets,
    ) {
    }

    public function showLogin(Request $request): Response
    {
        if (Auth::check()) {
            return redirect($this->home());
        }
        return view('pages/auth/login', ['next' => $request->string('next'), 'email' => '', 'error' => null]);
    }

    public function login(Request $request): Response
    {
        $result = Auth::attempt($request, $request->string('email'), (string) $request->input('password', ''));
        if ($result['ok']) {
            return redirect(SafeRedirect::path($request->input('next'), $this->home()));
        }
        $limited = isset($result['retry_after']);
        $response = view('pages/auth/login', [
            'next' => $request->string('next'),
            'email' => $request->string('email'),
            'error' => $limited
                ? t('auth.error.too_many_wait', ['minutes' => (int) ceil($result['retry_after'] / 60)])
                : t('auth.error.login_failed'),
        ], $limited ? 429 : 422);
        return $limited ? $response->withHeader('Retry-After', (string) $result['retry_after']) : $response;
    }

    public function showRegister(): Response
    {
        if (Auth::check()) {
            return redirect($this->home());
        }
        return view('pages/auth/register', ['faculties' => $this->users->faculties(), 'old' => [], 'errors' => []]);
    }

    public function register(Request $request): Response
    {
        $result = Auth::register(
            $request,
            $request->string('full_name'),
            $request->string('email'),
            (string) $request->input('password', ''),
            $request->int('faculty_id'),
            $request->string('student_number'),
        );
        if (isset($result['errors'])) {
            return view('pages/auth/register', [
                'faculties' => $this->users->faculties(),
                'old' => $request->all(),
                'errors' => $result['errors'],
            ], 422);
        }
        flash('success', t('auth.register.welcome'));
        return redirect('/events');
    }

    public function logout(Request $request): Response
    {
        Auth::logout($request);
        return redirect('/login');
    }

    public function showForgot(): Response
    {
        return view('pages/auth/forgot', ['sent' => false, 'error' => null]);
    }

    public function forgot(Request $request): Response
    {
        [$max, $window] = (array) config('security.reset_per_ip');
        if (RateLimiter::hit('reset:ip:' . $request->ip(), (int) $max, (int) $window) > 0) {
            return view('pages/auth/forgot', ['sent' => false, 'error' => t('auth.error.too_many')], 429);
        }
        $this->resets->request($request->string('email'), $request->ip());
        // The same answer for every address: the form never reveals who has an account.
        return view('pages/auth/forgot', ['sent' => true, 'error' => null]);
    }

    public function showReset(Request $request, string $token): Response
    {
        return view('pages/auth/reset', ['token' => $token, 'usable' => $this->resets->isUsable($token), 'error' => null]);
    }

    public function reset(Request $request, string $token): Response
    {
        $password = (string) $request->input('password', '');
        $error = $password !== (string) $request->input('password_confirm', '')
            ? t('auth.error.password_mismatch')
            : $this->resets->reset($token, $password);
        if ($error !== null) {
            return view('pages/auth/reset', ['token' => $token, 'usable' => $this->resets->isUsable($token), 'error' => $error], 422);
        }
        flash('success', t('auth.reset.done'));
        return redirect('/login');
    }

    /** Where each role lands after signing in. */
    private function home(): string
    {
        return match (Auth::user()['role'] ?? '') {
            'admin' => '/admin',
            'organizer' => '/organise/events',
            default => '/events',
        };
    }
}
