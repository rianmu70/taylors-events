<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Domain\RuleViolation;
use App\Repositories\EventRepository;
use App\Repositories\RegistrationRepository;
use App\Services\BookingService;
use App\Services\CheckInService;

final class BookingController
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly EventRepository $events,
        private readonly RegistrationRepository $registrations,
        private readonly CheckInService $checkIn,
    ) {
    }

    public function book(Request $request, string $slug): Response
    {
        $event = $this->events->findBySlug($slug) ?? abort(404);
        try {
            $result = $this->bookings->book((int) $event['id'], (int) Auth::id(), $request->string('idempotency_key'));
        } catch (RuleViolation $e) {
            flash('error', $e->text());
            return redirect('/events/' . $event['slug']);
        }
        if ($result['status'] === 'confirmed') {
            flash('success', t('booking.confirmed'));
            return redirect('/my/tickets/' . $result['registration_id']);
        }
        flash('info', t('booking.waitlisted'));
        return redirect('/events/' . $event['slug']);
    }

    public function cancel(Request $request, string $id): Response
    {
        try {
            $this->bookings->cancel((int) $id, (int) Auth::id());
            flash('success', t('booking.cancelled'));
        } catch (RuleViolation $e) {
            deny_unless_allowed($e);
            flash('error', $e->text());
        }
        return redirect('/my/registrations');
    }

    public function index(): Response
    {
        $userId = (int) Auth::id();
        $upcoming = $past = [];
        foreach ($this->registrations->forUser($userId) as $row) {
            if ($row['status'] === 'waitlisted') {
                $row['position'] = $this->registrations->waitlistPosition((int) $row['event_id'], (int) $row['id']);
            }
            $isPast = in_array($row['event_status'], ['completed', 'archived'], true) || $row['status'] === 'cancelled';
            if ($isPast) {
                $past[] = $row;
            } else {
                array_unshift($upcoming, $row);   // soonest first
            }
        }
        return view('pages/me/registrations', [
            'upcoming' => $upcoming,
            'past' => $past,
            'rated' => $this->registrations->ratingsBy($userId),
        ]);
    }

    public function ticket(Request $request, string $id): Response
    {
        $registration = $this->owned((int) $id);
        if ($registration['status'] !== 'confirmed') {
            flash('info', t('ticket.not_confirmed'));
            return redirect('/my/registrations');
        }
        return view('pages/me/ticket', [
            'registration' => $registration,
            'payload' => $this->checkIn->payloadFor($registration),
            'doorCode' => CheckInService::doorCode((string) $registration['ticket_nonce']),
        ]);
    }

    public function certificate(Request $request, string $id): Response
    {
        $registration = $this->owned((int) $id);
        if ($registration['certificate_code'] === null || !in_array($registration['event_status'], ['completed', 'archived'], true)) {
            flash('info', t('certificate.not_ready'));
            return redirect('/my/registrations');
        }
        return view('pages/me/certificate', ['registration' => $registration, 'public' => false]);
    }

    /** Anyone holding a certificate code can confirm it is genuine. Shows the name and event, nothing else. */
    public function verify(Request $request, string $code): Response
    {
        $registration = $this->registrations->findByCertificate(strtoupper($code));
        if ($registration === null || !in_array($registration['event_status'], ['completed', 'archived'], true)) {
            abort(404);
        }
        return view('pages/me/certificate', ['registration' => $registration, 'public' => true]);
    }

    /**
     * A registration, only if it belongs to the signed-in user.
     * Someone else's id answers 403, the same as an id that does not exist.
     *
     * @return array<string,mixed>
     */
    private function owned(int $id): array
    {
        $registration = $this->registrations->find($id);
        if ($registration === null || (int) $registration['user_id'] !== Auth::id()) {
            abort(403);
        }
        return $registration;
    }
}
