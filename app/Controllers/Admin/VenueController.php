<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\VenueRepository;
use PDOException;

final class VenueController
{
    public function __construct(private readonly VenueRepository $venues)
    {
    }

    public function index(): Response
    {
        return view('pages/admin/venues', ['venues' => $this->venues->allWithLoad()]);
    }

    public function create(): Response
    {
        return view('pages/admin/venue-form', ['venue' => null, 'old' => [], 'errors' => []]);
    }

    public function store(Request $request): Response
    {
        return $this->save(null, $request);
    }

    public function edit(Request $request, string $id): Response
    {
        $venue = $this->venues->find((int) $id) ?? abort(404);
        return view('pages/admin/venue-form', ['venue' => $venue, 'old' => [], 'errors' => []]);
    }

    public function update(Request $request, string $id): Response
    {
        $venue = $this->venues->find((int) $id) ?? abort(404);
        return $this->save($venue, $request);
    }

    public function toggle(Request $request, string $id): Response
    {
        $venue = $this->venues->find((int) $id) ?? abort(404);
        $this->venues->setActive((int) $id, (int) $venue['is_active'] !== 1);
        flash('success', t((int) $venue['is_active'] === 1 ? 'venue.closed' : 'venue.reopened'));
        return redirect('/admin/venues');
    }

    /** @param array<string,mixed>|null $venue */
    private function save(?array $venue, Request $request): Response
    {
        $v = new Validator($request->all());
        $v->required('code')->length('code', 2, 20);
        $v->rule('code', preg_match('/^[A-Z0-9-]+$/', strtoupper($v->value('code'))) === 1, 'venue.error.code');
        $v->required('name')->length('name', 3, 120);
        $v->required('building')->length('building', 2, 80);
        $v->length('floor_label', 0, 10);
        $v->required('capacity')->integer('capacity', 1, 5000);
        $v->required('venue_type')->in('venue_type', VenueRepository::TYPES);
        if ($venue !== null && !$v->fails()) {
            // Shrinking a room below an event already planned in it would break that event.
            $v->rule('capacity', (int) $v->value('capacity') >= $this->venues->largestBooking((int) $venue['id']), 'venue.error.capacity_in_use');
        }

        $data = [
            'code' => strtoupper($v->value('code')),
            'name' => $v->value('name'),
            'building' => $v->value('building'),
            'floor_label' => $v->value('floor_label') === '' ? null : $v->value('floor_label'),
            'capacity' => (int) $v->value('capacity'),
            'venue_type' => $v->value('venue_type'),
            'has_projector' => $request->input('has_projector') !== null,
            'is_accessible' => $request->input('is_accessible') !== null,
        ];
        $errors = $v->errors();
        if ($errors === []) {
            try {
                $venue === null ? $this->venues->insert($data) : $this->venues->update((int) $venue['id'], $data);
            } catch (PDOException $e) {
                if (Database::violatedRule($e) !== 'DUPLICATE') {
                    throw $e;
                }
                $errors['code'] = t('venue.error.code_taken');
            }
        }
        if ($errors !== []) {
            return view('pages/admin/venue-form', ['venue' => $venue, 'old' => $request->all(), 'errors' => $errors], 422);
        }
        flash('success', t('venue.saved'));
        return redirect('/admin/venues');
    }
}
