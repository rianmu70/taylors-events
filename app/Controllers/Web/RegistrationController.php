<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Models\Event;
use App\Security\TicketSigner;
use App\Services\BookingService;
use DomainException;

final class RegistrationController
{
    public function store(Request $request, string $slug): Response
    {
        $event = Event::findBySlug($slug) ?? abort(404);
        try {
            $booking = BookingService::reserve((int) $event['id'], (int) Auth::id());
            return redirect('/registrations/' . $booking['registration_id'] . '/confirmation');
        } catch (DomainException $e) {
            return view('pages/event', [
                'title' => $event['title'],
                'event' => $event,
                'bookingError' => t('booking.error.' . $e->getMessage()),
            ], 409);
        }
    }

    public function index(Request $request): Response
    {
        return view('pages/registrations', [
            'title' => t('registration.my_title'),
            'registrations' => Event::registrationsForUser((int) Auth::id()),
        ]);
    }

    public function confirmation(Request $request, string $id): Response
    {
        $registration = $this->ownedRegistration((int) $id);
        return view('pages/registration-confirmation', [
            'title' => t('registration.confirmation_title'),
            'registration' => $registration,
        ]);
    }

    public function ticket(Request $request, string $id): Response
    {
        $registration = $this->ownedRegistration((int) $id);
        if ($registration['status'] !== 'confirmed') {
            abort(404);
        }
        $payload = TicketSigner::fromConfig()->sign(
            (int) $registration['event_id'],
            (int) $registration['id'],
            (string) $registration['ticket_nonce'],
        );
        return view('pages/ticket', [
            'title' => t('ticket.title'),
            'registration' => $registration,
            'payload' => $payload,
        ]);
    }

    public function cancel(Request $request, string $id): Response
    {
        $registration = $this->ownedRegistration((int) $id);
        try {
            BookingService::cancel((int) $registration['event_id'], (int) Auth::id());
            return redirect('/my-registrations?cancelled=1');
        } catch (DomainException $e) {
            return view('pages/registrations', [
                'title' => t('registration.my_title'),
                'registrations' => Event::registrationsForUser((int) Auth::id()),
                'error' => t('booking.error.' . $e->getMessage()),
            ], 409);
        }
    }

    /** @return array<string, mixed> */
    private function ownedRegistration(int $registrationId): array
    {
        $registration = Event::registration($registrationId);
        if ($registration === null || (int) $registration['user_id'] !== (int) Auth::id()) {
            abort(404);
        }
        return $registration;
    }
}