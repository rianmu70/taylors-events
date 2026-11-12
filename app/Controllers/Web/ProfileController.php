<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\ClubRepository;
use App\Repositories\UserRepository;
use App\Security\Audit;
use App\Services\Notifier;

final class ProfileController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ClubRepository $clubs,
        private readonly Notifier $notifier,
    ) {
    }

    public function show(): Response
    {
        return $this->page([], []);
    }

    public function update(Request $request): Response
    {
        $v = new Validator($request->all());
        $v->required('full_name')->length('full_name', 2, 100);
        $v->length('bio', 0, 500);
        $facultyId = $request->int('faculty_id');
        $v->rule('faculty_id', $facultyId === null || in_array($facultyId, array_column($this->users->faculties(), 'id'), true), 'validation.choice');
        if ($v->fails()) {
            return $this->page($v->errors(), $request->all(), 422);
        }
        $this->users->updateProfile((int) Auth::id(), $v->value('full_name'), $facultyId, $v->value('bio') === '' ? null : $v->value('bio'));
        flash('success', t('profile.saved'));
        return redirect('/profile');
    }

    public function password(Request $request): Response
    {
        $user = $this->users->find((int) Auth::id()) ?? abort(404);
        $new = (string) $request->input('new_password', '');
        $errors = [];
        if (!password_verify((string) $request->input('current_password', ''), (string) $user['password_hash'])) {
            $errors['current_password'] = t('profile.error.current_password');
        } elseif ($problem = Auth::passwordProblem($new, (string) $user['email'])) {
            $errors['new_password'] = $problem;
        } elseif ($new !== (string) $request->input('new_password_confirm', '')) {
            $errors['new_password_confirm'] = t('auth.error.password_mismatch');
        }
        if ($errors !== []) {
            return $this->page($errors, [], 422);
        }
        $this->users->setPassword((int) $user['id'], Auth::hash($new));
        $this->users->endSessions((int) $user['id'], session_id() ?: null);   // sign out every other device
        Audit::record('auth.password_changed', 'user', (int) $user['id'], (int) $user['id']);
        flash('success', t('profile.password_saved'));
        return redirect('/profile');
    }

    public function notifications(): Response
    {
        $userId = (int) Auth::id();
        $items = $this->notifier->listFor($userId);
        $this->notifier->markAllRead($userId);
        return view('pages/me/notifications', ['items' => $items]);
    }

    /**
     * @param array<string,string> $errors
     * @param array<string,mixed> $old
     */
    private function page(array $errors, array $old, int $status = 200): Response
    {
        $userId = (int) Auth::id();
        return view('pages/me/profile', [
            'profile' => $this->users->find($userId),
            'faculties' => $this->users->faculties(),
            'clubs' => $this->clubs->managedBy($userId),
            'errors' => $errors,
            'old' => $old,
        ], $status);
    }
}
