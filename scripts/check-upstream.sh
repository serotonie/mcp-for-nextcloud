#!/bin/sh
# SPDX-FileCopyrightText: 2026 MCP extension fork contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -eu
if [ "$#" -eq 0 ]; then
	echo "Usage: sh scripts/check-upstream.sh REF [REF ...]" >&2
	exit 2
fi
root="$(git rev-parse --show-toplevel)"
stage="$(mktemp -d)"
worktree="$stage/merge"
branch="compatibility-$(date +%s)-$$"
cleanup() {
	if [ -d "$worktree" ]; then
		git -C "$root" worktree remove --force "$worktree"
	fi
	if git -C "$root" show-ref --verify --quiet "refs/heads/$branch"; then
		git -C "$root" branch -D "$branch"
	fi
	rmdir "$stage"
}
trap cleanup EXIT
git worktree add -b "$branch" "$worktree" HEAD
git -C "$worktree" config user.name "MCP compatibility"
git -C "$worktree" config user.email "compatibility@localhost"
failed=0
for ref in "$@"; do
	git check-ref-format "$ref"
	git rev-parse --verify "$ref^{commit}" >/dev/null
	if git -C "$worktree" merge --no-commit --no-ff "$ref"; then
		echo "Merge compatible: $ref"
		if [ "${MCP_COMPAT_RUN_TESTS:-0}" = 1 ]; then
			if ! (cd "$worktree/mcp" && composer install --no-interaction --no-progress &&
				vendor/bin/phpunit tests/Unit/Extension tests/Unit/Mcp/Tools/Validation tests/Unit/Access/Grants); then
				echo "Tests failed after merging $ref" >&2
				failed=1
			fi
		fi
	else
		echo "Upstream merge conflict: $ref" >&2
		git -C "$worktree" diff --name-only --diff-filter=U
		failed=1
	fi
	if git -C "$worktree" rev-parse --verify -q MERGE_HEAD >/dev/null; then
		git -C "$worktree" merge --abort
	fi
done
exit "$failed"
