<?php

namespace App\Controllers\Web;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Models\Event;

final class EventController
{
    public function index(Request $request): Response
    {
        return view('pages/home', [
            'title' => t('nav.browse'),
            'events' => Event::browse(),
        ]);
    }

    public function show(Request $request, string $slug): Response
    {
        $event = Event::findBySlug($slug) ?? abort(404);
        $registration = null;
        $userId = Auth::id();
        if ($userId !== null) {
            $registration = Database::fetchOne(
                'SELECT id, status FROM registrations WHERE event_id = ? AND user_id = ?',
                [(int) $event['id'], $userId],
            );
        }

        return view('pages/event', [
            'title' => $event['title'],
            'event' => $event,
            'registration' => $registration,
            'canRegister' => Auth::hasRole('student'),
        ]);
    }
}