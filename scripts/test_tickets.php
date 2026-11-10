<?php

declare(strict_types=1);

/*
 * Tests for QR ticket signing. No web server and no database needed.
 *
 *   php scripts/test_tickets.php
 *
 * Exit code 0 = all passed.
 */

use App\Security\TicketSigner;

require dirname(__DIR__) . '/app/bootstrap.php';

$passed = 0;
$failed = 0;

function check(string $name, callable $test): void
{
    global $passed, $failed;
    try {
        $result = $test();
        if ($result === true) {
            $passed++;
            echo "  PASS  $name\n";
            return;
        }
        $failed++;
        echo "  FAIL  $name\n        got: " . var_export($result, true) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL  $name\n        " . $e::class . ': ' . $e->getMessage() . "\n";
    }
}

function throws(callable $fn, string $class): bool
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e instanceof $class;
    }
    return false;
}

$secret = str_repeat('s3cret-', 6);           // 42 characters
$signer = new TicketSigner($secret);
$nonce = TicketSigner::newNonce();
$ticket = $signer->sign(7, 1234, $nonce);

echo "Signing\n";

check('nonce is 32 hex characters and different each time', fn () => preg_match('/^[0-9a-f]{32}$/', $nonce) === 1 && $nonce !== TicketSigner::newNonce());
check('ticket has the expected shape and fits a small QR code', fn () => preg_match('/^v1\.7\.1234\.[0-9a-f]{32}\.[A-Za-z0-9_-]{43}$/', $ticket) === 1 && strlen($ticket) < 100);
check('signing is repeatable for the same input', fn () => $signer->sign(7, 1234, $nonce) === $ticket);
check('a different nonce gives a different ticket', fn () => $signer->sign(7, 1234, TicketSigner::newNonce()) !== $ticket);
check('bad inputs are refused when signing', fn () => throws(fn () => $signer->sign(0, 1, $nonce), InvalidArgumentException::class)
    && throws(fn () => $signer->sign(1, 1, 'not-hex'), InvalidArgumentException::class));
check('a short secret is refused', fn () => throws(fn () => new TicketSigner('too-short'), InvalidArgumentException::class));

echo "Verifying\n";

check('genuine ticket verifies and returns its fields', function () use ($signer, $ticket, $nonce) {
    $r = $signer->verify($ticket);
    return $r['valid'] === true && $r['event_id'] === 7 && $r['registration_id'] === 1234 && $r['nonce'] === $nonce ? true : $r;
});
check('changed event id is rejected', fn () => $signer->verify(str_replace('v1.7.', 'v1.8.', $ticket))['reason'] === 'bad_signature');
check('changed registration id is rejected', fn () => $signer->verify(str_replace('.1234.', '.1235.', $ticket))['reason'] === 'bad_signature');
check('changed nonce is rejected', function () use ($signer, $ticket, $nonce) {
    $other = $nonce[0] === 'a' ? 'b' . substr($nonce, 1) : 'a' . substr($nonce, 1);
    return $signer->verify(str_replace($nonce, $other, $ticket))['reason'] === 'bad_signature';
});
check('changed signature is rejected', function () use ($signer, $ticket) {
    $last = substr($ticket, -1);
    $tampered = substr($ticket, 0, -1) . ($last === 'A' ? 'B' : 'A');
    return $signer->verify($tampered)['reason'] === 'bad_signature';
});
check('ticket signed with another secret is rejected', fn () => (new TicketSigner(str_repeat('other-secret-', 4)))->verify($ticket)['reason'] === 'bad_signature');
check('malformed text is rejected without errors', function () use ($signer, $ticket) {
    $bad = ['', 'hello', 'v1.7.1234', $ticket . 'x', $ticket . "\n", ' ' . $ticket, 'v2' . substr($ticket, 2), str_repeat('a', 5000),
        'v1.0.1234.' . substr($ticket, strpos($ticket, '.', 8) + 1), "v1.7.1234.\x00"];
    foreach ($bad as $text) {
        $r = $signer->verify($text);
        if ($r['valid'] !== false || $r['reason'] !== 'malformed') {
            return [$text, $r];
        }
    }
    return true;
});
check('a dropped signature never verifies', fn () => $signer->verify(substr($ticket, 0, strrpos($ticket, '.')))['valid'] === false);

echo "Scan evidence\n";

check('payloadHash is a 64-character SHA-256 hex and differs per input', fn () => preg_match('/^[0-9a-f]{64}$/', TicketSigner::payloadHash($ticket)) === 1
    && TicketSigner::payloadHash($ticket) !== TicketSigner::payloadHash('x'));

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
