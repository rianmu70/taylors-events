<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Models\Event;
use App\Services\EventManagementService;
use App\Services\TicketService;
use DomainException;
use PDOException;

final class ManagementController
{
    public function index(Request $request): Response
    {
        $events = Event::manageableBy((int) Auth::id(), (string) Auth::user()['role']);
        $noticeKey = $request->query('notice');
        $errorKey = $request->query('error');
        if ($request->path === '/admin/events/pending') {
            $events = array_values(array_filter($events, static fn (array $event): bool => $event['status'] === 'pending_approval'));
        }
        return view('pages/manage-events', [
            'title' => t($request->path === '/admin/events/pending' ? 'manage.pending_title' : 'manage.title'),
            'events' => $events,
            'notice' => is_string($noticeKey) && $noticeKey !== '' ? t('manage.notice.' . $noticeKey) : '',
            'error' => is_string($errorKey) && $errorKey !== '' ? t('manage.error.' . $errorKey) : '',
        ]);
    }

    public function createForm(Request $request): Response
    {
        return $this->formPage([], null, []);
    }

    public function create(Request $request): Response
    {
        try {
            EventManagementService::createDraft(
                (int) Auth::id(),
                (string) Auth::user()['role'],
                $request->all(),
            );
            return redirect('/organize/events?notice=created');
        } catch (DomainException $e) {
            return $this->formPage(self::safeInput($request->all()), null, ['form' => t('manage.error.' . $e->getMessage())])->withStatus(422);
        } catch (PDOException $e) {
            $rule = Database::violatedRule($e);
            if ($rule !== null) {
                return $this->formPage(self::safeInput($request->all()), null, ['form' => t('db.' . $rule)])->withStatus(422);
            }
            throw $e;
        }
    }

    public function editForm(Request $request, string $id): Response
    {
        $event = Event::findById((int) $id) ?? abort(404);
        $this->assertManageable((int) $event['id']);
        if ($event['status'] !== 'draft') {
            abort(409);
        }
        return $this->formPage($event, (int) $event['id'], []);
    }

    public function update(Request $request, string $id): Response
    {
        try {
            EventManagementService::updateDraft(
                (int) $id,
                (int) Auth::id(),
                (string) Auth::user()['role'],
                $request->all(),
            );
            return redirect('/organize/events?notice=updated');
        } catch (DomainException $e) {
            $event = Event::findById((int) $id) ?? abort(404);
            return $this->formPage(array_merge($event, self::safeInput($request->all())), (int) $id,
                ['form' => t('manage.error.' . $e->getMessage())])->withStatus(422);
        } catch (PDOException $e) {
            $rule = Database::violatedRule($e);
            if ($rule !== null) {
                $event = Event::findById((int) $id) ?? abort(404);
                return $this->formPage(array_merge($event, self::safeInput($request->all())), (int) $id,
                    ['form' => t('db.' . $rule)])->withStatus(422);
            }
            throw $e;
        }
    }

    public function transition(Request $request, string $id): Response
    {
        try {
            EventManagementService::transition(
                (int) $id,
                (int) Auth::id(),
                (string) Auth::user()['role'],
                self::stringInput($request->input('target', '')),
                self::stringInput($request->input('reason', '')),
            );
            return redirect('/organize/events?notice=transitioned');
        } catch (DomainException $e) {
            return redirect('/organize/events?error=' . rawurlencode($e->getMessage()));
        }
    }

    public function checkInForm(Request $request, string $id): Response
    {
        $event = Event::findById((int) $id) ?? abort(404);
        $this->assertManageable((int) $event['id']);
        return view('pages/check-in', [
            'title' => t('checkin.title'),
            'event' => $event,
            'result' => null,
            'error' => null,
        ]);
    }

    public function checkIn(Request $request, string $id): Response
    {
        $event = Event::findById((int) $id) ?? abort(404);
        try {
            $result = TicketService::checkIn(
                (int) $id,
                (int) Auth::id(),
                (string) Auth::user()['role'],
                trim(self::stringInput($request->input('ticket', ''))),
                $request->ip(),
            );
            return view('pages/check-in', [
                'title' => t('checkin.title'),
                'event' => $event,
                'result' => $result,
                'error' => null,
            ]);
        } catch (DomainException $e) {
            return view('pages/check-in', [
                'title' => t('checkin.title'),
                'event' => $event,
                'result' => null,
                'error' => t('manage.error.' . $e->getMessage()),
            ], 403);
        }
    }

    /** @param array<string, mixed> $old @param array<string, string> $errors */
    private function formPage(array $old, ?int $eventId, array $errors): Response
    {
        $userId = (int) Auth::id();
        $role = (string) Auth::user()['role'];
        $clubs = $role === 'admin'
            ? Database::fetchAll('SELECT id, name FROM clubs WHERE is_active = 1 ORDER BY name')
            : Database::fetchAll(
                "SELECT c.id, c.name FROM clubs c JOIN club_members cm ON cm.club_id = c.id
                  WHERE c.is_active = 1 AND cm.user_id = ? AND cm.role IN ('advisor','president','committee')
                  ORDER BY c.name",
                [$userId],
            );
        return view('pages/event-form', [
            'title' => t($eventId === null ? 'manage.create_title' : 'manage.edit_title'),
            'old' => $old,
            'errors' => $errors,
            'eventId' => $eventId,
            'clubs' => $clubs,
            'categories' => Database::fetchAll('SELECT id, name FROM event_categories ORDER BY name'),
            'venues' => Database::fetchAll('SELECT id, name, building, capacity FROM venues WHERE is_active = 1 ORDER BY building, name'),
        ]);
    }

    private function assertManageable(int $eventId): void
    {
        foreach (Event::manageableBy((int) Auth::id(), (string) Auth::user()['role']) as $event) {
            if ((int) $event['id'] === $eventId) {
                return;
            }
        }
        abort(404);
    }

    /** @param array<string, mixed> $input @return array<string, scalar|null> */
    private static function safeInput(array $input): array
    {
        return array_map(static fn (mixed $value): mixed => is_scalar($value) ? $value : '', $input);
    }

    private static function stringInput(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}