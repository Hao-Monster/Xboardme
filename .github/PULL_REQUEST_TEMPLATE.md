## Scope

- [ ] The PR has one clearly stated product or engineering objective.
- [ ] The base branch is `codex/distributor`.
- [ ] The head contains the current production baseline without history rewriting.

## Verification

- [ ] Targeted tests pass.
- [ ] Full PHP/JavaScript/static-analysis/security gates pass.
- [ ] Deployment scripts and workflow syntax pass.
- [ ] The final diff contains no unrelated changes or secrets.

## Release impact

- [ ] No production deployment is required, or the exact SHA and rollback path are recorded.
- [ ] Every pending migration is backward compatible and listed in `.github/release/approved-migrations.txt`.
- [ ] Active production was identified from Caddy plus the bound container, not from a retained Compose container alone.
