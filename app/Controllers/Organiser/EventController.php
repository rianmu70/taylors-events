<?php

declare(strict_types=1);

namespace App\Controllers\Organiser;

use App\Auth\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Domain\RuleViolation;
use App\Repositories\ClubRepository;
use App\Repositories\EventRepository;
use App\Repositories\RegistrationRepository;
use App\Repositories\VenueRepository;
use App\Services\CheckInService;
use App\Services\EventService;

/**
 * An organiser's own events. Every action goes through
 * EventService::manageable(), so an organiser of one club gets 403 on the
 * events of another, whatever id they type.
 */
final class EventController
{
    public function __construct(
        private readonly EventService $service,
        private readonly EventRepository $events,
        private readonly ClubRepository $clubs,
        private readonly VenueRepository $venues,
        private readonly RegistrationRepository $registrations,
    ) {
    }

    public function index(): Response
    {
        return view('pages/organise/index', [
            'events' => $this->events->hostedBy((int) Auth::id()),
            'clubs' => $this->clubs->managedBy((int) Auth::id()),
        ]);
    }

    public function create(): Response
    {
        return $this->form(null, [], []);
    }

    public function store(Request $request): Response
    {
        $checked = $this->service->validate($request->all(), (int) Auth::id());
        if ($checked['errors'] !== []) {
            return $this->form(null, $request->all(), $checked['errors'], 422);
        }
        try {
            $id = $this->service->createDraft($checked['data'], (int) Auth::id());
        } catch (RuleViolation $e) {
            return $this->form(null, $request->all(), ['form' => $e->text()], 422);
        }
        flash('success', t('event.saved_draft'));
        return redirect("/organise/events/$id/edit");
    }

    public function edit(Request $request, string $id): Response
    {
        $event = $this->load((int) $id);
        return $this->form($event, [], []);
    }

    public function update(Request $request, string $id): Response
    {
        $event = $this->load((int) $id);
        $checked = $this->service->validate($request->all(), (int) Auth::id());
        if ($checked['errors'] !== []) {
            return $this->form($event, $request->all(), $checked['errors'], 422);
        }
        try {
            $this->service->updateDraft((int) $id, $checked['data'], (int) Auth::id());
        } catch (RuleViolation $e) {
            return $this->form($event, $request->all(), ['form' => $e->text()], 422);
        }
        flash('success', t('event.saved_draft'));
        return redirect("/organise/events/$id/edit");
    }

    public function submit(Request $request, string $id): Response
    {
        return $this->act(fn () => $this->service->submit((int) $id, (int) Auth::id()), 'event.submitted', "/organise/events/$id/edit");
    }

    public function delete(Request $request, string $id): Response
    {
        return $this->act(fn () => $this->service->deleteDraft((int) $id, (int) Auth::id()), 'event.deleted');
    }

    public function archive(Request $request, string $id): Response
    {
        return $this->act(fn () => $this->service->archive((int) $id, (int) Auth::id()), 'event.archived');
    }

    /** The attendee list as a CSV file that opens in Excel. */
    public function export(Request $request, string $id): Response
    {
        $event = $this->load((int) $id);
        $out = fopen('php://temp', 'w+');
        fputcsv($out, [t('export.name'), t('export.student_number'), t('export.email'), t('export.school'), t('export.status'), t('export.door_code'), t('export.checked_in')]);
        foreach ($this->registrations->attendees((int) $id) as $row) {
            fputcsv($out, array_map([self::class, 'csvSafe'], [
                $row['full_name'], $row['student_number'], $row['email'], $row['faculty_code'],
                t('registration.status.' . $row['status']),
                CheckInService::doorCode((string) $row['ticket_nonce']),
                $row['checked_in_at'] ?? '',
            ]));
        }
        rewind($out);
        $csv = "\xEF\xBB\xBF" . stream_get_contents($out);
        return (new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8']))
            ->withHeader('Content-Disposition', 'attachment; filename="attendees-' . $event['slug'] . '.csv"');
    }

    /** Stop a cell that starts with = + - @ from being run as a formula by a spreadsheet. */
    private static function csvSafe(mixed $value): string
    {
        $value = (string) $value;
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }

    /** @return array<string,mixed> */
    private function load(int $id): array
    {
        try {
            return $this->service->manageable($id, (int) Auth::id());
        } catch (RuleViolation $e) {
            deny_unless_allowed($e);
            throw $e;
        }
    }

    private function act(callable $action, string $successKey, string $failureRedirect = '/organise/events'): Response
    {
        try {
            $action();
        } catch (RuleViolation $e) {
            deny_unless_allowed($e);
            flash('error', $e->text());
            return redirect($failureRedirect);
        }
        flash('success', t($successKey));
        return redirect('/organise/events');
    }

    /**
     * @param array<string,mixed>|null $event
     * @param array<string,mixed> $old
     * @param array<string,string> $errors
     */
    private function form(?array $event, array $old, array $errors, int $status = 200): Response
    {
        return view('pages/organise/form', [
            'event' => $event,
            'old' => $old,
            'errors' => $errors,
            'clubs' => $this->clubs->managedBy((int) Auth::id()),
            'venues' => $this->venues->bookable(),
            'categories' => $this->events->categories(),
            'clash' => $event !== null && $event['status'] === 'draft' ? $this->service->clashFor($event) : null,
        ], $status);
    }
}
