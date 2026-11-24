# AGENTS.md

**Read [CLAUDE.md](CLAUDE.md) first.** It is the single source of truth for
this repository: overview, commands, architecture, conventions and the full
rules. This file repeats only the non-negotiable rules.

## Non-negotiable rules

1. Plan first; no file changes before the plan is approved.
2. NEVER COMMIT to this repository without human approval. Never branch,
   create a worktree, push, rebase, merge or open a pull request unless
   explicitly asked. Leave work uncommitted and suggest a commit message.
3. Never fix production code speculatively; pin defects with a test and report
   them.
4. No ticket numbers, commit hashes as history, dates or people's names in the
   repository. Open defects are present-tense `@todo`s in the nearest docblock.
5. A green run that executed zero tests is a failure; always report the count.
6. No retries, sleeps or skips as a fix.
7. Never run two test stacks at the same time.
8. Document before you test.
9. Report failures, skips and partial work faithfully.
10. Existing tests are read-only by default: never change, loosen or delete a
    test to make a run green; name the cause of every failing test, ask
    before changing anything but a flipped pin or a pure rename, and run
    `bin/check-test-changes.sh` before the hand-off.
