<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Security\SafeRedirect;

/** Sign-in, sign-up and sign-out. The views are bare-bones so Member 3 can restyle them. */
final class AuthController
{
    public function showLogin(Request $request): Response
    {
        if (Auth::check()) {
            return redirect('/');
        }
        return $this->loginPage($request->query('next'), '', null);
    }

    public function login(Request $request): Response
    {
        $email = (string) $request->input('email', '');
        $result = Auth::attempt($request, $email, (string) $request->input('password', ''));

        if ($result['ok']) {
            return redirect(SafeRedirect::path($request->input('next'), '/'));
        }

        $retry = $result['retry_after'] ?? 0;
        $response = $this->loginPage($request->input('next'), $email, $result['error'] ?? 'auth.error.login_failed')
            ->withStatus($retry > 0 ? 429 : 422);
        return $retry > 0 ? $response->withHeader('Retry-After', (string) $retry) : $response;
    }

    public function showRegister(Request $request): Response
    {
        if (Auth::check()) {
            return redirect('/');
        }
        return $this->registerPage([], []);
    }

    public function register(Request $request): Response
    {
        $old = [
            'name' => (string) $request->input('name', ''),
            'email' => (string) $request->input('email', ''),
        ];
        $faculty = $request->input('faculty_id');
        $facultyId = is_string($faculty) && ctype_digit($faculty) ? (int) $faculty : null;

        $result = Auth::register($request, $old['name'], $old['email'], (string) $request->input('password', ''), $facultyId);
        if (isset($result['user_id'])) {
            return redirect('/');
        }
        return $this->registerPage($old, $result['errors'] ?? [])->withStatus(422);
    }

    public function logout(Request $request): Response
    {
        Auth::logout($request);
        return redirect('/login');
    }

    private function loginPage(mixed $next, string $email, ?string $error): Response
    {
        return Response::html(View::render('pages/auth/login', [
            'title' => t('auth.login.title'),
            'next' => is_string($next) ? $next : '',
            'email' => $email,
            'error' => $error,
        ]));
    }

    /** @param array<string,string> $old @param array<string,string> $errors lang keys */
    private function registerPage(array $old, array $errors): Response
    {
        return Response::html(View::render('pages/auth/register', [
            'title' => t('auth.register.title'),
            'old' => $old,
            'errors' => $errors,
            'faculties' => Database::fetchAll('SELECT id, name FROM faculties ORDER BY name'),
        ]));
    }
}
