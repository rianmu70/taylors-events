<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Domain\RuleViolation;
use App\Repositories\EventRepository;
use App\Repositories\VenueRepository;
use App\Services\ApprovalService;

final class ApprovalController
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly EventRepository $events,
        private readonly VenueRepository $venues,
    ) {
    }

    public function index(): Response
    {
        $pending = $this->events->pending();
        foreach ($pending as &$event) {
            // Two requests for the same room can both be waiting only if one was
            // submitted outside the application; show it rather than hide it.
            $event['clash'] = $this->venues->clash((int) $event['venue_id'], (string) $event['starts_at'], (string) $event['ends_at'], (int) $event['id']);
        }
        unset($event);
        return view('pages/admin/approvals', ['pending' => $pending, 'decided' => $this->events->recentlyDecided(6)]);
    }

    public function approve(Request $request, string $id): Response
    {
        return $this->decide(fn () => $this->approvals->approve((int) $id, (int) Auth::id()), 'approval.approved');
    }

    public function reject(Request $request, string $id): Response
    {
        return $this->decide(fn () => $this->approvals->reject((int) $id, (int) Auth::id(), $request->string('reason')), 'approval.rejected');
    }

    private function decide(callable $decision, string $successKey): Response
    {
        try {
            $decision();
            flash('success', t($successKey));
        } catch (RuleViolation $e) {
            deny_unless_allowed($e);
            flash('error', $e->text());
        }
        return redirect('/admin/approvals');
    }
}
