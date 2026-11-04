# QR ticket signing

Files: `app/Security/TicketSigner.php`, `scripts/test_tickets.php`.
Not run yet (no PHP in my sandbox): `php -l app/Security/TicketSigner.php`, then `php scripts/test_tickets.php`.

## Setup (once per machine)

1. Generate a secret and put it in `.env` (never commit it; add a placeholder to `.env.example`):

   ```
   TICKET_SECRET=<output of: php -r "echo bin2hex(random_bytes(32));">
   ```

2. In `config/app.php`, add inside the `app` section (I have not seen that file, so match its style):

   ```php
   'ticket_secret' => Env::get('TICKET_SECRET', ''),
   ```

   If the `app` array is in another section name, tell me and I will change `config('app.ticket_secret')`.

If the secret changes, every ticket already issued stops verifying. Keep one secret for the whole demo.

## Using it

Booking (Member 3), when a registration becomes confirmed:

```php
$nonce = TicketSigner::newNonce();                       // store in registrations.ticket_nonce
$qrText = TicketSigner::fromConfig()->sign($eventId, $registrationId, $nonce);
```

The QR text is computed on demand from those three values. Do not store it.
`registrations.ticket_nonce` must hold at least 32 characters (CHAR(32) or larger). I have not seen that column's size.

Scanner endpoint (Member 3):

```php
$r = TicketSigner::fromConfig()->verify($scannedText);
$hash = TicketSigner::payloadHash($scannedText);         // for ticket_scans.payload_hash
if (!$r['valid']) { /* log a rejected scan with $hash; reason is 'malformed' or 'bad_signature' */ }
// If valid, the scanner must still check the database before admitting:
//   - registration $r['registration_id'] exists and belongs to event $r['event_id']
//   - $r['event_id'] is the event being scanned (a real ticket for another event is not valid here)
//   - registrations.ticket_nonce === $r['nonce'] (hash_equals)
//   - status is 'confirmed' (not waitlisted or cancelled) and checked_in_at is NULL
```

The signature proves the text is genuine. It does not prove the seat is still valid. Cancelled tickets still have a genuine signature, so the database check is what stops them.

## Design notes

- HMAC-SHA256 with a key derived from the secret using HKDF, compared in constant time (`hash_equals`).
- Strict pattern before any crypto, with a 200 character cap, so odd scanner input cannot reach the HMAC code.
- Format is versioned (`v1`) so it can change later without breaking the parser.
- No expiry in the ticket: validity comes from the registration status and the event time in the database.
