# Branch and release management

## Authoritative branches

`codex/distributor` is both the GitHub default branch and the only production
branch. `master` follows the upstream project and is not deployable. Feature
branches must be based on the current production branch and target it with a
pull request.

The local checkout should resolve GitHub operations to
`Hao-Monster/Xboardme`, use `origin` as `remote.pushDefault`, and keep
the `upstream` remote read-only.

Before publishing a feature branch:

```bash
git fetch origin
git merge-base --is-ancestor origin/codex/distributor HEAD
git diff --check origin/codex/distributor...HEAD
```

An empty GitHub check list is not a passing result. The production branch is
protected against deletion, force-push and direct unreviewed changes, and the
required verification check must pass before future PRs merge.

## Runtime source of truth

The retained Compose container is not necessarily the active application. It
may continue to own the persistent SQLite/Redis mounts while a release web
container serves traffic.

The release scripts therefore determine production in this order:

1. Read the one loopback `reverse_proxy` upstream from the active host Caddy
   configuration.
2. Match that host port to exactly one running Compose or release web
   container.
3. Verify the container's immutable image, revision label, Laravel version,
   shared mounts, SQLite WAL/integrity and Redis health.

Fixed color names and historical ports are not authoritative. An isolated
stage uses a free port and its approved port is reused by the live candidate
only after the exact stage container is removed.

## Release sequence

1. Build the exact production-branch SHA and immutable multi-architecture
   digest.
2. Run the read-only production preflight.
3. Rehearse the artifact and approved migrations on an online SQLite snapshot
   in an isolated container.
4. Prepare the live candidate on an inactive loopback port, take an integrity-
   checked backup and apply only migrations listed in
   `.github/release/approved-migrations.txt`.
5. Run authenticated direct smoke tests, atomically switch Caddy and run public
   smoke tests.
6. Drain and transfer Horizon/Scheduler ownership, supporting both the original
   Compose topology and subsequent release-to-release rotations.
7. Keep the previous web and roles stopped or running as required for immediate
   rollback. Preserve release evidence.

Production mutation jobs reject any ref other than `codex/distributor` and
require the supplied 40-character SHA to equal the workflow SHA.
Production preflight and isolated staging use the same branch gate because
their SSH credentials are production-sensitive even when the intended script
is read-only or isolated. Feature branches receive no production-host secrets.
