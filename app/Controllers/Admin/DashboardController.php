<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Response;
use App\Services\AnalyticsService;

final class DashboardController
{
    public function __construct(private readonly AnalyticsService $analytics)
    {
    }

    public function index(): Response
    {
        return view('pages/admin/dashboard', [
            'headline' => $this->analytics->headline(),
            'scarcest' => $this->analytics->scarcest(),
            'categories' => $this->analytics->byCategory(),
            'faculties' => $this->analytics->byFaculty(),
            'daily' => $this->analytics->bookingsPerDay(),
            'venues' => $this->analytics->venueLoad(),
        ]);
    }
}
