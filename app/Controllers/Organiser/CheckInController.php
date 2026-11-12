<?php

declare(strict_types=1);

namespace App\Controllers\Organiser;

use App\Auth\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Domain\RuleViolation;
use App\Repositories\RegistrationRepository;
use App\Services\CheckInService;
use App\Services\EventService;

final class CheckInController
{
    public function __construct(
        private readonly EventService $events,
        private readonly CheckInService $checkIn,
        private readonly RegistrationRepository $registrations,
    ) {
    }

    public function show(Request $request, string $id): Response
    {
        $event = $this->load((int) $id);
        return view('pages/organise/checkin', [
            'event' => $event,
            'open' => $this->checkIn->doorIsOpen($event),
            'counts' => $this->registrations->doorCounts((int) $id),
            'attendees' => $this->registrations->attendees((int) $id),
            'scans' => $this->registrations->recentScans((int) $id),
        ]);
    }

    /** Called by the scanner page for each code read by the camera or typed by hand. Answers JSON. */
    public function scan(Request $request, string $id): Response
    {
        $this->load((int) $id);
        $payload = $request->string('payload');
        $code = $request->string('code');
        $outcome = $payload !== ''
            ? $this->checkIn->scan((int) $id, $payload, (int) Auth::id(), $request->ip())
            : $this->checkIn->scanDoorCode((int) $id, $code, (int) Auth::id(), $request->ip());

        return Response::json([
            'result' => $outcome['result'],
            'admitted' => $outcome['result'] === CheckInService::ADMITTED,
            'name' => $outcome['name'],
            'registration_id' => $outcome['registration_id'],
            'status_label' => t('status.checked_in'),
            'message' => t('scan.' . $outcome['result'], ['name' => $outcome['name'] ?? '']),
            'counts' => $this->registrations->doorCounts((int) $id),
        ]);
    }

    /** @return array<string,mixed> */
    private function load(int $id): array
    {
        try {
            return $this->events->manageable($id, (int) Auth::id());
        } catch (RuleViolation $e) {
            deny_unless_allowed($e);
            throw $e;
        }
    }
}
