<?php

declare(strict_types=1);

use App\Core\Database;
use App\Security\TicketSigner;
use App\Services\BookingService;
use App\Services\EventManagementService;
use App\Services\TicketService;
require dirname(__DIR__) . '/app/bootstrap.php';

if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "BLOCKED: pdo_mysql is not installed; no database test was run.\n");
    exit(2);
}

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "  PASS  $message\n";
}

function expectDomainError(callable $action, string $message): void
{
    try {
        $action();
    } catch (DomainException) {
        echo "  PASS  $message\n";
        return;
    }
    throw new RuntimeException($message . ' (no domain error was thrown)');
}

function testUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

$pdo = null;
try {
    $pdo = Database::connection();
    $pdo->beginTransaction();

    $venue = Database::fetchOne('SELECT id, capacity FROM venues WHERE is_active = 1 ORDER BY capacity DESC LIMIT 1');
    $club = Database::fetchOne('SELECT id FROM clubs WHERE is_active = 1 ORDER BY id LIMIT 1');
    $category = Database::fetchOne('SELECT id FROM event_categories ORDER BY id LIMIT 1');
    if ($venue === null || $club === null || $category === null) {
        throw new RuntimeException('Seed at least one active venue, club and event category before running this test.');
    }

    $emailTag = bin2hex(random_bytes(6));
    $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $adminId = Database::insert(
        "INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, 'admin')",
        ['Member 3 test admin', 'm3-admin-' . $emailTag . '@example.test', $passwordHash],
    );
    $studentIds = [];
    for ($index = 1; $index <= 3; $index++) {
        $studentIds[] = Database::insert(
            "INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, 'student')",
            ['Member 3 test student ' . $index, 'm3-student-' . $index . '-' . $emailTag . '@example.test', $passwordHash],
        );
    }

    $start = '2099-08-20 10:00:00';
    $end = '2099-08-20 11:00:00';
    $eventId = EventManagementService::createDraft($adminId, 'admin', [
        'club_id' => (int) $club['id'],
        'category_id' => (int) $category['id'],
        'venue_id' => (int) $venue['id'],
        'title' => 'Member 3 transactional test ' . $emailTag,
        'summary' => 'Temporary rollback-only integration test',
        'description' => 'Temporary event created inside a transaction that is always rolled back.',
        'starts_at' => $start,
        'ends_at' => $end,
        'registration_closes_at' => '2099-08-20 09:00:00',
        'capacity' => 1,
    ]);
    EventManagementService::transition($eventId, $adminId, 'admin', 'pending_approval');
    EventManagementService::transition($eventId, $adminId, 'admin', 'published');

    $first = BookingService::reserve($eventId, $studentIds[0]);
    $second = BookingService::reserve($eventId, $studentIds[1]);
    $third = BookingService::reserve($eventId, $studentIds[2]);
    verify($first['status'] === 'confirmed' && $second['status'] === 'waitlisted' && $third['status'] === 'waitlisted', 'capacity confirms one seat and waitlists overflow');
    expectDomainError(fn () => BookingService::reserve($eventId, $studentIds[0]), 'duplicate active booking is rejected');

    $cancelled = BookingService::cancel($eventId, $studentIds[0]);
    $firstPromotion = Database::fetchOne('SELECT id, status FROM registrations WHERE event_id = ? AND user_id = ?', [$eventId, $studentIds[1]]);
    verify($cancelled['promoted_registration_id'] === (int) $firstPromotion['id'] && $firstPromotion['status'] === 'confirmed', 'cancellation promotes the earliest waitlisted student');
    $secondCancel = BookingService::cancel($eventId, $studentIds[1]);
    $secondPromotion = Database::fetchOne('SELECT id, status FROM registrations WHERE event_id = ? AND user_id = ?', [$eventId, $studentIds[2]]);
    verify($secondCancel['promoted_registration_id'] === (int) $secondPromotion['id'] && $secondPromotion['status'] === 'confirmed', 'waitlist promotion preserves FIFO order');
    verify((int) Database::fetchValue('SELECT confirmed_count FROM events WHERE id = ?', [$eventId]) === 1, 'confirmed seat counter stays within event capacity');

    EventManagementService::transition($eventId, $adminId, 'admin', 'ongoing');
    $registration = Database::fetchOne('SELECT id, ticket_nonce FROM registrations WHERE event_id = ? AND user_id = ?', [$eventId, $studentIds[2]]);
    $ticket = TicketSigner::fromConfig()->sign($eventId, (int) $registration['id'], (string) $registration['ticket_nonce']);
    $scan = TicketService::checkIn($eventId, $adminId, 'admin', $ticket, '127.0.0.1');
    $duplicateScan = TicketService::checkIn($eventId, $adminId, 'admin', $ticket, '127.0.0.1');
    $forgedScan = TicketService::checkIn($eventId, $adminId, 'admin', $ticket . 'x', '127.0.0.1');
    verify($scan['result'] === 'admitted' && $duplicateScan['result'] === 'duplicate', 'valid QR ticket checks in once and rejects replay');
    verify($forgedScan['result'] === 'invalid_signature', 'forged QR ticket is rejected and logged');

    $clashId = EventManagementService::createDraft($adminId, 'admin', [
        'club_id' => (int) $club['id'],
        'category_id' => (int) $category['id'],
        'venue_id' => (int) $venue['id'],
        'title' => 'Member 3 venue clash test ' . $emailTag,
        'summary' => 'Temporary overlapping event',
        'description' => 'This draft overlaps the active event in the same venue.',
        'starts_at' => $start,
        'ends_at' => $end,
        'capacity' => 1,
    ]);
    expectDomainError(fn () => EventManagementService::transition($clashId, $adminId, 'admin', 'pending_approval'), 'overlapping venue reservation is rejected');
    expectDomainError(fn () => EventManagementService::transition($clashId, $adminId, 'admin', 'archived'), 'invalid lifecycle transition is rejected');

    echo "All event workflow checks passed; rolling back fixture transaction.\n";
    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'FAIL: ' . $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
}