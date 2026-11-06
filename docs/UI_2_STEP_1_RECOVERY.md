# UI 2.0 Step 1 recovery record

Created before structural UI 2.0 edits on 2026-10-23.

- Branch: `dev`
- Starting HEAD: `bf7a6893b525662e1edb56e9ac1e1f9d1e1ad828`
- Remote relationship at start: `dev` was two commits ahead of `origin/dev`.
- Existing stashes preserved: `stash@{0}`, `stash@{1}`, and `stash@{2}`.
- Existing recovery branches preserved, including `recovery/dev-before-ai-seeders-20261022` and `recovery/presentation-before-cdm-buddy-20261022`.
- No reset, clean, stash operation, branch switch, commit, merge, push, migration, or deployment was performed.

Pre-change working-state artifacts:

- Directory: `/tmp/cdm-ui2-step1-prechange.yyugsx`
- Tracked working-tree patch: `tracked-working-tree.patch`
- Untracked-file archive: `untracked-files.tar.gz`
- Git status, stash list, and branch references are recorded in the same directory.
- Tracked patch SHA-256: `068d50a6fb90d428db0ad5dc1b38e3e886cb52cb3b7f5c909957f3ec10894f5c`
- Untracked archive SHA-256: `5ec9c5fd9bb6cfed131e70436fc322df5c45e6297c88ee977064e19f1fc1ac2f`

Recovery is deliberately manual: inspect the current working tree first, then apply the tracked patch to the recorded starting HEAD and extract the untracked archive only into a separate recovery worktree. Never overwrite the active worktree blindly.
