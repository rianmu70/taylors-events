<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\AuditRepository;

final class AuditController
{
    public function __construct(private readonly AuditRepository $audit)
    {
    }

    public function index(Request $request): Response
    {
        $filters = [
            'action' => mb_substr($request->string('action'), 0, 40),
            'entity' => in_array($request->string('entity'), ['event', 'registration', 'user'], true) ? $request->string('entity') : '',
            'actor' => mb_substr($request->string('actor'), 0, 80),
        ];
        $perPage = (int) config('admin.audit_per_page');
        $page = current_page();
        $result = $this->audit->search($filters, $page, $perPage);
        return view('pages/admin/audit', [
            'entries' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => $perPage,
            'filters' => $filters,
        ]);
    }

    public function outbox(): Response
    {
        return view('pages/admin/outbox', ['messages' => $this->audit->outbox()]);
    }
}
