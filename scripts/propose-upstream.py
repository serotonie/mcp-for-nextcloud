#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 MCP extension fork contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Only fast-forward a new automation branch or create a PR; never merge/publish."""
import json
import os
import re
import subprocess
from pathlib import Path


def run(*args, check=True):
    result = subprocess.run(args, check=False, text=True, capture_output=True)
    if check and result.returncode:
        raise RuntimeError(f"Command failed ({result.returncode}): {args[0:2]}\n{result.stdout}\n{result.stderr}")
    return result


def version(tag):
    match = re.fullmatch(r"v(\d+)\.(\d+)\.(\d+)", tag)
    if not match:
        raise ValueError(f"Unsupported upstream release tag: {tag}")
    return tuple(map(int, match.groups()))


def main():
    if not os.environ.get("GH_TOKEN"):
        raise RuntimeError("UPSTREAM_SYNC_TOKEN is required so PR creation triggers required CI; no fallback token is used.")
    metadata_path = Path("fork-base.json")
    metadata = json.loads(metadata_path.read_text())
    release = json.loads(run("gh", "api", f"repos/{metadata['upstream']}/releases/latest").stdout)
    tag = release["tag_name"]
    if version(tag) <= version(metadata["tag"]):
        print(f"No upgrade: integrated {metadata['tag']}, latest {tag}")
        return
    repository = os.environ["FORK_REPOSITORY"]
    base = os.environ["FORK_BASE_BRANCH"]
    branch = f"upstream/{tag}"
    existing = json.loads(run("gh", "pr", "list", "--repo", repository, "--head", branch, "--state", "all", "--json", "number,state").stdout)
    if existing:
        print(f"Existing PR for {tag}: {existing}; manual retry after a closed PR.")
        return
    run("git", "remote", "add", "release-upstream", f"https://github.com/{metadata['upstream']}.git")
    run("git", "fetch", "release-upstream", f"refs/tags/{tag}:refs/compatibility/update")
    run("git", "config", "user.name", "MCP upstream sync")
    run("git", "config", "user.email", "upstream-sync@users.noreply.github.com")
    run("git", "switch", "-c", branch)
    result = run("git", "merge", "--no-ff", "--no-commit", "refs/compatibility/update", check=False)
    if result.returncode:
        conflicts = run("git", "diff", "--name-only", "--diff-filter=U").stdout
        run("git", "merge", "--abort")
        title = f"Upstream {tag}: merge blocked"
        issues = json.loads(run("gh", "issue", "list", "--repo", repository, "--search", f'"{title}" in:title', "--json", "number,title").stdout)
        if not any(issue["title"] == title for issue in issues):
            run("gh", "issue", "create", "--repo", repository, "--title", title,
                "--body", f"Automatic merge failed; preserve upstream fixes and fork behavior.\n\nConflicts:\n```\n{conflicts}\n```\n\n{result.stderr}")
        raise RuntimeError(f"Merge of {tag} blocked: {conflicts or result.stderr}")
    metadata.update(tag=tag, commit=run("git", "rev-parse", "refs/compatibility/update^{commit}").stdout.strip(), revision=1, publication="unreleased")
    metadata_path.write_text(json.dumps(metadata, indent=2) + "\n")
    run("git", "add", "fork-base.json")
    run("git", "commit", "-m", f"Track integrated upstream {tag}\n\nCo-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>")
    # gh installs the authenticated Git credential helper without writing credentials into a remote URL.
    run("gh", "auth", "setup-git")
    run("git", "push", "origin", f"HEAD:refs/heads/{branch}")
    run("gh", "pr", "create", "--repo", repository, "--base", base, "--head", branch,
        "--title", f"Integrate upstream {tag}", "--body",
        f"Integrates {release['html_url']} without downgrading.\n\nRequired: complete upstream tests, packaging, extension and Fuelin compatibility, temporary merge checks, human review. No automatic merge, tag, release or deployment.")
    print(f"Proposed {tag}; waiting for checks and human review.")


if __name__ == "__main__":
    main()
