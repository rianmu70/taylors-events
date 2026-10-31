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
2. CI must be green (database checks and PHP lint).
3. One other member reviews and approves.
4. Squash and merge.

## Before demo day

```bash
bash scripts/reset_demo.sh -uroot -p
bash scripts/verify_database.sh -uroot -p
bash scripts/reset_demo.sh -uroot -p
```
