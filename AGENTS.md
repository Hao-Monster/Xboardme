# Xboardme repository rules

## Repository and branches

- The writable GitHub repository is `FengHaoyun-MONSTER/Xboardme`. Always pass
  `-R FengHaoyun-MONSTER/Xboardme` to `gh` commands that can read or mutate
  repository state.
- `origin/codex/distributor` is the default and production branch.
- `origin/master` is an upstream-tracking baseline, not a production branch.
  Never infer production state from `master` or deploy it.
- Start feature branches from the current `origin/codex/distributor`. Before a
  PR or release, verify `git merge-base --is-ancestor origin/codex/distributor
  HEAD`. Bring the production branch into the feature branch with a normal
  merge; do not rewrite shared history.
- Production changes reach `codex/distributor` through a PR. Do not force-push
  or push directly to the production branch.

## Production truth and release safety

- Determine the active production runtime from the host Caddy
  `reverse_proxy` loopback upstream and the container bound to that port.
  The retained Compose `xboard` container is a storage/Redis/rollback anchor
  and may not be the active web runtime.
- Never identify production solely from Compose labels, container age, a
  mutable image tag, or a historical fixed port.
- Only a workflow running the exact `codex/distributor` commit may receive
  production-host credentials or execute production preflight, isolated
  staging, cleanup, `prepare`, `switch`, role activation, rollback or admin
  asset hotfix tasks. Feature branches must not access production secrets.
- Build and deploy immutable image digests. The approved commit SHA, image
  revision label and workflow SHA must match.
- Pending production migrations must be backward compatible and listed in
  `.github/release/approved-migrations.txt`. Unknown migrations block staging
  and production preparation.
- Preserve the previous active web and role containers until post-switch smoke
  tests pass. Keep release state and database/Caddy backups as audit evidence.
- A production deployment requires a passing preflight, isolated database-clone
  rehearsal, authenticated smoke test, switch smoke test and rollback path.

## Required checks

- Run the targeted PHPUnit and JavaScript tests for the change.
- Run the complete PHPUnit suite, JavaScript suite, PHPStan, Composer audit,
  deployment script syntax checks, workflow validation and `git diff --check`.
- Treat an empty PR check list as missing evidence, not as success.
