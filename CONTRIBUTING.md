# Working on this repository

## Branches

| Branch | Purpose |
|---|---|
| `main` | Always demo-ready. Protected: changes arrive only through pull requests |
| `feature/<owner>-<topic>` | One branch per task, e.g. `feature/a-session-handler` |

Each member commits from their own GitHub account so the history shows who
built what. This is the evidence for the peer review.

## Commits

Short, present tense, one change per commit:

```
feat(booking): lock event row before taking a seat
fix(auth): regenerate session id after sign-in
test(db): add venue clash check
docs: explain waitlist promotion
```

## Pull requests

1. Open a pull request into `main` and fill in the template.
2. CI must be green: PHP lint and all four test suites on a fresh MySQL 8.
3. One other member reviews and approves.
4. Squash and merge.

## Before pushing

```bash
bash scripts/test.sh
```

All four suites must pass locally. If you changed any wording, the unit suite
checks that every key you used exists in `app/Views/lang/en.php` and that no
template contains literal text.

## Before demo day

```bash
bash scripts/reset_demo.sh
bash scripts/test.sh
bash scripts/reset_demo.sh
```

The test suites reload the demo data themselves, but running the reset last
recalculates every date from the moment you start the presentation.
