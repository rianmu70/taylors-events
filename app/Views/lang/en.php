<?php

declare(strict_types=1);

/*
 * Every label, button and message in the interface.
 * To rename something everywhere (for example "Submit" to "Save"),
 * change the text on the right once. Never put these words in templates.
 *
 * :name placeholders are filled by t('key', ['name' => $value]).
 */

return [
    // App
    'app.name' => "Taylor's Events",
    'app.tagline' => 'Every event on campus, one account.',
    'app.footer' => "Taylor's University, Lakeside Campus",

    // Navigation
    'nav.main' => 'Main',
    'nav.skip' => 'Skip to content',
    'nav.browse' => 'Browse',
    'nav.my_events' => 'My events',
    'nav.organize' => 'Organize',
    'nav.admin' => 'Admin',
    'nav.profile' => 'Profile',
    'nav.sign_in' => 'Sign in',
    'nav.sign_out' => 'Sign out',
    'nav.create_account' => 'Create account',

    // Buttons and actions
    'action.submit' => 'Submit',
    'action.save' => 'Save',
    'action.cancel' => 'Cancel',
    'action.delete' => 'Delete',
    'action.edit' => 'Edit',
    'action.back' => 'Back',
    'action.search' => 'Search',
    'action.register' => 'Reserve a seat',
    'action.cancel_booking' => 'Cancel booking',
    'action.join_waitlist' => 'Join waitlist',
    'action.approve' => 'Approve',
    'action.reject' => 'Reject',
    'action.submit_for_approval' => 'Submit for approval',
    'action.check_in' => 'Check in',
    'action.download_certificate' => 'Download certificate',
    'action.view_details' => 'View details',

    // Event lifecycle (matches events.status in the database)
    'status.draft' => 'Draft',
    'status.pending_approval' => 'Waiting for approval',
    'status.published' => 'Open for booking',
    'status.ongoing' => 'Happening now',
    'status.completed' => 'Finished',
    'status.archived' => 'Archived',

    // Registration status (matches registrations.status)
    'registration.confirmed' => 'Confirmed',
    'registration.waitlisted' => 'On the waitlist',
    'registration.cancelled' => 'Cancelled',

    // Event fields
    'event.when' => 'When',
    'event.where' => 'Where',
    'event.host' => 'Hosted by',
    'event.capacity' => 'Capacity',
    'event.seats_left' => ':count seats left',
    'event.seat_left' => '1 seat left',
    'event.full' => 'Full, waitlist open',

    // Home page
    'home.title' => 'Upcoming events',
    'home.intro' => 'Reserve a seat, keep your ticket on your phone and collect an attendance certificate.',
    'home.empty' => 'No upcoming events yet. Check back soon.',

    // Messages from database rules (see Database::violatedRule)
    'db.VENUE_CLASH' => 'This venue is already booked for an overlapping time. Choose another time or venue.',
    'db.CAPACITY' => 'The event capacity is larger than the venue can hold.',
    'db.LIFECYCLE' => 'This change is not allowed at the current stage of the event.',
    'db.RATING' => 'Only people who attended a finished event can rate it.',
    'db.AUDIT' => 'The audit log cannot be changed.',
    'db.SCANS' => 'The check-in log cannot be changed.',
    'db.DUPLICATE' => 'This record already exists.',
    'db.chk_events_no_overbook' => 'Sorry, the last seat was just taken.',
    'db.chk_events_time' => 'The end time must be after the start time.',

    // Error pages
    'error.code' => 'Error :status',
    'error.home' => 'Go to the home page',
    'error.details' => 'Details (shown because APP_DEBUG is on)',
    'error.400.title' => 'Bad request',
    'error.400.message' => 'The request could not be understood.',
    'error.401.title' => 'Please sign in',
    'error.401.message' => 'You need to sign in to see this page.',
    'error.403.title' => 'Access denied',
    'error.403.message' => 'Your account does not have permission to open this page.',
    'error.404.title' => 'Page not found',
    'error.404.message' => 'The page you are looking for does not exist or was moved.',
    'error.405.title' => 'Method not allowed',
    'error.405.message' => 'This address does not accept that kind of request.',
    'error.419.title' => 'Page expired',
    'error.419.message' => 'The form expired. Go back, refresh the page and try again.',
    'error.422.title' => 'Please check the form',
    'error.422.message' => 'Some fields need your attention.',
    'error.429.title' => 'Too many attempts',
    'error.429.message' => 'Please wait a few minutes and try again.',
    'error.500.title' => 'Something went wrong',
    'error.500.message' => 'An unexpected error happened. Please try again in a moment.',
    'error.503.title' => 'Service unavailable',
    'error.503.message' => 'The service is temporarily unavailable. Please try again shortly.',
];
