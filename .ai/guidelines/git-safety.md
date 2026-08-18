# Git safety

Each of these failed *silently* — the build stayed green and the status looked plausible.

## Read `git status` before you commit

`git add -A` sweeps up stray artifacts and, through rename detection, can pair unrelated identical files.

`git add -A <pathspec>` is not the containment it looks like either. A `composer qa-check` run triggered a
`boost sync` post-update hook that deleted and regenerated `AGENTS.md` and `CLAUDE.md`; those deletions were
already staged, and the commit swallowed 429 lines of guidelines unrelated to the change. Recovery:
`git reset --soft HEAD~1`, `git restore --source=HEAD --staged --worktree <files>`, recommit.

The lesson is not "use narrower pathspecs" — it is to read the status output, especially after any command
that runs project hooks. `boost sync` runs on `post-install-cmd` and `post-update-cmd` here.

## A no-op `stash push` pops someone else's work

`git stash push -- src/ ; test ; git stash pop` only works while the fix is uncommitted. Once committed, the
push saves nothing and, with output silenced, looks like it worked — so the paired `pop` applies **and
deletes** whatever was already on the stack, and the BEFORE run it produces is identical to AFTER.

To verify an already-committed fix, revert by path instead: `git checkout HEAD~1 -- <files>`, confirm the
test fails, then `git checkout HEAD -- src/`.

## `git checkout -- <file>` discards uncommitted work, with no confirmation

Reverting a file to HEAD to undo a deliberate mutation also throws away every uncommitted change in it. A
mutation check had just proved a test caught its bug; `git checkout -- src/Transpiler.php` to restore the
code silently reverted the uncommitted feature with it, and the whole thing had to be reconstructed.

Before mutating a file on purpose, copy it aside and restore from the copy.

## Do not hand-edit generated files

`AGENTS.md`, `CLAUDE.md` and `.config/boost.php` are managed by boost sync. Edits belong in the source the
sync reads from, or they are silently reverted on the next `composer install`.

## Exclude the files you own, not the directory they sit in

An exclude entry does not apply to tracked files, so excluding a whole directory can give no protection
where protection is needed while hiding legitimate new files. Name what you own.
