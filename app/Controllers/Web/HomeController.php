<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\Request;
use App\Core\Response;
use App\Models\Event;

final class HomeController
{
    public function index(Request $request): Response
    {
        return view('pages/home', [
            'title' => t('home.title'),
            'events' => Event::upcoming(6),
        ]);
    }
}
