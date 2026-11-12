<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Domain\EventStatus;
use App\Domain\RuleViolation;
use App\Repositories\ClubRepository;
use App\Repositories\EventRepository;
use App\Repositories\RegistrationRepository;

final class EventController
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly RegistrationRepository $registrations,
        private readonly ClubRepository $clubs,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = [
            'q' => mb_substr($request->string('q'), 0, 80),
            'category' => $request->string('category'),
            'when' => $request->string('when'),
        ];
        $perPage = (int) config('events.per_page');
        $page = current_page();
        $result = $this->events->browse($filters, $page, $perPage);

        return view('pages/events/index', [
            'events' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => $perPage,
            'filters' => $filters,
            'categories' => $this->events->categories(),
        ]);
    }

    public function show(Request $request, string $slug): Response
    {
        $event = $this->events->findBySlug($slug) ?? abort(404);
        $user = Auth::user();

        // Drafts and events awaiting approval are visible only to their club and to administrators.
        if (!in_array($event['status'], EventStatus::PUBLIC, true) && $event['status'] !== 'archived') {
            $allowed = $user !== null && ($user['role'] === 'admin' || $this->clubs->manages($user['id'], (int) $event['club_id']));
            if (!$allowed) {
                abort(404);
            }
        }

        $registration = $user === null ? null : $this->registrations->forUserAndEvent($user['id'], (int) $event['id']);
        if ($registration !== null && $registration['status'] === 'cancelled') {
            $registration = null;
        }

        return view('pages/events/show', [
            'event' => $event,
            'registration' => $registration,
            'position' => $registration !== null && $registration['status'] === 'waitlisted'
                ? $this->registrations->waitlistPosition((int) $event['id'], (int) $registration['id'])
                : null,
            'ratings' => $this->events->ratings((int) $event['id']),
            'ratingSummary' => $this->events->ratingSummary((int) $event['id']),
            'canRate' => $registration !== null && $registration['checked_in_at'] !== null
                && in_array($event['status'], ['completed', 'archived'], true)
                && !$this->events->hasRated((int) $event['id'], $user['id']),
            'idempotencyKey' => self::uuid(),
        ]);
    }

    public function rate(Request $request, string $slug): Response
    {
        $event = $this->events->findBySlug($slug) ?? abort(404);
        $rating = $request->int('rating');
        $comment = mb_substr($request->string('comment'), 0, 1000);
        try {
            if ($rating === null || $rating < 1 || $rating > 5) {
                throw new RuleViolation('RATING_RANGE');
            }
            RuleViolation::guard(fn () => $this->events->addRating((int) $event['id'], (int) Auth::id(), $rating, $comment === '' ? null : $comment));
            flash('success', t('rating.saved'));
        } catch (RuleViolation $e) {
            flash('error', $e->text());
        }
        return redirect('/events/' . $event['slug']);
    }

    private static function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
