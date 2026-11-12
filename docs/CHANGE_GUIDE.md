# Where to change things

Every change a lecturer is likely to ask for during the presentation is one
line in one file. Reload the page to see it; there is no build step.

## Wording

All text is in **`app/Views/lang/en.php`**. Search the file for the words you
see on screen.

| Change | Line to edit in `app/Views/lang/en.php` |
|---|---|
| Rename the "Book a seat" button | `'booking.book' => 'Book a seat',` |
| Rename "Join the waitlist" | `'booking.join_waitlist' => ...` |
| Rename "Submit for approval" | `'hosted.submit' => ...` |
| Rename "Approve and publish" | `'approval.approve' => ...` |
| Change the heading of the browse page | `'browse.heading' => ...` |
| Rename a menu item | the `nav.*` lines |
| Change what a status is called everywhere | the `status.*` lines |
| Change an error message | the `rule.*`, `validation.*` or `auth.error.*` lines |
| Change the name of the product | `'app.name' => ...` |

## Numbers and behaviour

All settings are in **`config/app.php`**.

| Change | Line to edit in `config/app.php` |
|---|---|
| How many events are listed per page | `'per_page' => 8,` |
| When the seat meter turns red | `'low_seats' => 5,` |
| When the seat meter turns amber | `'filling_percent' => 70,` |
| Longest event an organiser can create | `'max_hours' => 12,` |
| How early the door opens for check-in | `'checkin_opens_minutes' => 60,` |
| Minimum password length | `'password_min' => 10,` |
| How long a reset link lasts | `'reset_minutes' => 30,` |
| Wrong passwords before an account locks | `'login_max_fails' => 5,` |
| Which email domains can register | `'email_domains' => [...]` |
| Rows per page in the audit log | `'audit_per_page' => 40,` |

## Look

All styling is in **`public/assets/css/app.css`**, and the first block of that
file holds every colour, font size and spacing value as a custom property.

| Change | Line to edit in `public/assets/css/app.css` |
|---|---|
| Brand colour | `--brand: #c1121f;` |
| Page background | `--wash: #f3f4f7;` |
| Corner roundness | `--radius: 6px;` |
| Heading font | `--font-display: ...` |
| Width of the page | `--page: 72rem;` |

## Access

Who can reach which URL is in **`config/routes.php`**. The third argument of
each route is the rule, for example `['auth', 'role:admin']`.

## Structure, for anything larger

| To change | Look in |
|---|---|
| What a page shows | `app/Views/pages/...` (HTML only, no SQL, no rules) |
| What happens when a form is sent | `app/Controllers/...` (reads input, calls a service, picks a page) |
| A business rule | `app/Services/...` |
| A query | `app/Repositories/...` |
| A rule the database enforces | `database/schema.sql` |
| The demo data | `scripts/generate_seed.php`, then `php scripts/generate_seed.php` |
