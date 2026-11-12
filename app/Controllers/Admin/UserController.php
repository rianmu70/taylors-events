<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ClubRepository;
use App\Repositories\UserRepository;

final class UserController
{
    public function __construct(private readonly UserRepository $users, private readonly ClubRepository $clubs)
    {
    }

    public function index(Request $request): Response
    {
        $role = in_array($request->string('role'), Auth::ROLES, true) ? $request->string('role') : '';
        $term = mb_substr($request->string('q'), 0, 80);
        $perPage = (int) config('admin.users_per_page');
        $page = current_page();
        $result = $this->users->search($term, $role, $page, $perPage);
        return view('pages/admin/users', [
            'users' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => $perPage,
            'term' => $term,
            'role' => $role,
            'clubs' => $this->clubs->all(),
        ]);
    }

    /** Change a role. Making someone an organiser also puts them on the committee of one club. */
    public function role(Request $request, string $id): Response
    {
        $target = $this->users->find((int) $id) ?? abort(404);
        $role = $request->string('role');
        $clubId = $request->int('club_id');

        $error = match (true) {
            !in_array($role, Auth::ROLES, true) => t('validation.choice'),
            (int) $target['id'] === Auth::id() => t('user.error.self'),
            $role === 'organizer' && ($clubId === null || !$this->clubs->exists($clubId)) => t('user.error.club_needed'),
            $target['role'] === 'admin' && $role !== 'admin' && $this->users->activeAdminCount() <= 1 => t('user.error.last_admin'),
            default => null,
        };
        if ($error !== null) {
            flash('error', $error);
            return redirect('/admin/users');
        }

        Database::transaction(function () use ($target, $role, $clubId): void {
            $this->users->setRole((int) $target['id'], $role);   // audited by trg_users_au
            if ($role === 'organizer') {
                $this->clubs->addCommitteeMember((int) $clubId, (int) $target['id']);
            } else {
                $this->clubs->removeFromCommittees((int) $target['id']);
            }
        });
        $this->users->endSessions((int) $target['id']);   // the new role applies from their next sign-in
        flash('success', t('user.role_saved', ['name' => (string) $target['full_name']]));
        return redirect('/admin/users');
    }

    public function toggle(Request $request, string $id): Response
    {
        $target = $this->users->find((int) $id) ?? abort(404);
        if ((int) $target['id'] === Auth::id()) {
            flash('error', t('user.error.self'));
            return redirect('/admin/users');
        }
        $activate = (int) $target['is_active'] !== 1;
        if (!$activate && $target['role'] === 'admin' && $this->users->activeAdminCount() <= 1) {
            flash('error', t('user.error.last_admin'));
            return redirect('/admin/users');
        }
        $this->users->setActive((int) $target['id'], $activate);
        if (!$activate) {
            $this->users->endSessions((int) $target['id']);
        }
        flash('success', t($activate ? 'user.activated' : 'user.deactivated', ['name' => (string) $target['full_name']]));
        return redirect('/admin/users');
    }
}
