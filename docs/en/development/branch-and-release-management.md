# Branch and release management

## Authoritative branches

`main` is the GitHub default and the only production branch. `develop` is the
only development branch. Keep only these two local and origin branch heads.
Follow the upstream project using read-only `upstream/*` remote-tracking refs;
do not keep a local or origin `master` branch or deploy upstream refs.

Develop in the primary checkout on `develop`. Do not create task branches or
allow concurrent writers in historical worktrees. A release PR must come from
this repository's `develop` and target `main`. After merging, fast-forward
`develop` to `main` when possible, or merge `main` into `develop` normally if new
development has already started. Never rebase or force-push shared history.

Keep GitHub's automatic head-branch deletion disabled: `develop` is permanent.
Both branches reject deletion and force-push. `main` additionally requires the
up-to-date `verify` check, a PR and resolved conversations, including for admins.
An active branch-creation ruleset must exclude only `main` and `develop` to
prevent new long-lived branches. Do not grant `develop` production environments.

Install local accident-prevention hooks with:

```bash
git config core.hooksPath .githooks
```

The hooks allow commits only on `develop` and pushes only to `origin/develop`.
They are locally configurable and are not a security boundary; GitHub protection
enforces the remote rules. Historical detached worktrees are retained for
recovery only, not active development. Preserve their ignored files before any
later archival or removal.

The local checkout should resolve GitHub operations to
`Hao-Monster/Xboardme`, use `origin` as `remote.pushDefault`, and keep
the `upstream` remote read-only.

Before publishing development changes:

```bash
git fetch origin
git merge-base --is-ancestor origin/main HEAD
git diff --check origin/main...HEAD
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

Production mutation jobs reject any ref other than `main` and
require the supplied 40-character SHA to equal the workflow SHA.
Production preflight and isolated staging use the same branch gate because
their SSH credentials are production-sensitive even when the intended script
is read-only or isolated. The development branch receives no production-host secrets.

## Branch migration and releases

Renaming the production branch does not deploy a new application version. Build
a new signed image from the merged `main` SHA before the next release; historical
images attested to the retired branch do not satisfy the new source-ref gate.
Existing release-state backups and immutable prior images remain the rollback
records. Do not alter them to match the new branch name.
