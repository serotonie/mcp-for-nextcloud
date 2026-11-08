# Upstream maintenance and publication

## Baseline and divergences

Inspection on 2026-11-08 found latest stable upstream `v1.3.2` and branch `main`
at `534b981c97be6541d97338c1c9a82f1aa9c2e559`. The annotated release tag resolves
to the same commit. `fork-base.json` records this identity; upstream was not
downgraded. The GitHub fork is `serotonie/mcp-for-nextcloud`. Work is prepared
on `fork/extension-api`, the source publication branch. No release is published.

Remaining deliberate changes to upstream files:

- Bootstrap composition and generic extension registration event/metadata port.
- Dynamic policy catalog access in grant policy, matrix, groups, resolver,
  inspector and policy event normalization; upstream constants remain unchanged.
- Tool name collision checks and external-tool metadata presentation.
- JSON Schema finite `number` validation, preserving integer semantics.
- Generic matrix/group metadata labels and denied-by-default descriptions.
- Generated admin bundle and class inventory/REUSE entries for the new classes.
- Exact fork-author attribution accepted by the architecture style test, without
  removing the original attribution or accepting the existing invalid mutants.

The integration-specific MCP patch is **not** applied to the fork. Review with
`git diff upstream/main -- mcp/lib mcp/src` and inspect new files too.
No future-conflict guarantee is made.

## Workflows (one task type per file)

Existing upstream Tests, Integration and Package workflows remain authoritative.
Fork - Upstream automation tests exercises the release proposal logic offline.
Fork - Upstream merge compatibility runs on PRs, manually and daily: it fetches
current main and five recent stable releases, merges each into a disposable
branch/worktree, tests grants/schema/API, then aborts and removes that branch.
Conflicts and test failures are explicit failures. PR CI separately runs the
complete upstream suite on the proposed integrated tree.

Fork - Propose upstream release runs daily/manually, checks version ordering,
creates an integration branch and PR, and never auto-merges, tags or deploys.
A conflict creates an issue with exact paths and fails the workflow. Repeated
runs do not overwrite an existing PR/branch; closed or partially pushed proposals
require manual recovery. Unsupported upstream tag conventions fail explicitly.
Never use force-push as automated recovery.

Configure `UPSTREAM_SYNC_TOKEN` as a narrowly scoped repository GitHub App token
or fine-grained PAT permitting contents, pull requests and issues. The credential
also needs workflows permission when an upstream merge changes Actions. A static
secret must be renewed before expiry; prefer separately managed App token minting.
No `GITHUB_TOKEN` fallback: PRs made with it do not trigger normal PR CI.
Do not send credentials in a remote URL. Enable Actions on the fork and require
upstream tests, package, architecture, compatibility, automation and provider
checks in branch protection. No protection was altered during preparation.

The Fuelin compatibility workflow needs a private **Nextcloud Fuelin app** repo
(not the TypeScript `fuelin-mcp` server), its read-only checkout token and a
digest-pinned Nextcloud 35 test image. Until these prerequisites exist, its
explicit failure blocks an update; local passing tests are not GitHub checks.
Set `FUELIN_REF` to the full reviewed provider commit SHA; CI must test the
intended paired versions, not a moving default branch. Update that pin through
the same review and provider compatibility process when Fuelin changes.

## Conflict resolution

1. Fetch upstream branch and tag without moving any fork/published tag.
2. Create a local temporary branch from the intended fork base; merge normally.
3. Inspect every conflicted path, preserving upstream fixes and the generic API.
   Never choose entire "ours"/"theirs" trees or rewrite shared history.
4. Run extension/provider tests, complete upstream tests, package and architecture
   checks. Repeat compatibility merges against current main/releases.
5. Open/update a PR. Resolve all required checks and review/approval blockers.
   Merge normally into the intended protected base before publication.

`sh scripts/check-upstream.sh refs/remotes/upstream/main refs/tags/v1.3.2`
checks **committed HEAD** only. Set `MCP_COMPAT_RUN_TESTS=1` to test each merged
tree. Uncommitted local work needs an isolated snapshot commit in a temporary
branch/index; do not mistake a clean HEAD check for validation of working edits.

## Versions and distribution

Keep upstream `vX.Y.Z` tags intact. Fork tags are exclusively
`fork/vX.Y.Z-rN`, where X.Y.Z is the integrated upstream release and N increments
for fork changes. Use numeric Nextcloud app version `X.Y.Z.N` at approved fork
publication (example `1.3.2.1`); API version remains separately `1`. Before every
release verify Nextcloud's actual version ordering and upgrade path; if upstream
changes its scheme, stop and define a migration, not a downgrade. Development
metadata currently retains upstream app version 1.3.2 and says `unreleased`.

Source commits and branches may be published without a Nextcloud certificate.
According to the [Nextcloud signing documentation](https://docs.nextcloud.com/server/latest/developer_manual/app_publishing_maintenance/code_signing.html),
signing is required for App Store publication, and previously signed apps must
remain signed on future updates. A manually distributed, non-Featured fork is
not universally required to be signed; its actual installation/update policy
must be checked in staging, especially when retaining a previously signed app ID.

Only after approved PR merge, green required checks, provider compatibility and
the chosen distribution's integrity prerequisites: update/verify numeric app version and provenance in the PR,
tag the merged base commit, build with upstream packaging tooling, sign using an
authorized Nextcloud app certificate, verify installation integrity in staging,
then publish the approved artifacts plus hashes/source/provenance. Never move a
published tag or replace a release. Hashes and an out-of-band signature verify
artifact provenance, **not** Nextcloud app signing. The fork has no signing key
or authorized `mcp` certificate, so it cannot currently produce a
Nextcloud-signature-verified distribution. An explicitly approved manual unsigned
distribution must be labelled unsigned, verify immutable provenance/hashes
separately, and preserve Nextcloud's integrity settings and visible diagnostics.
It does not satisfy a signed-distribution requirement. Do not bundle an upstream
`signature.json` as if it authenticated modified files.
