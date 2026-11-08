# SPDX-FileCopyrightText: 2026 MCP extension fork contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("upstream", Path(__file__).with_name("propose-upstream.py"))
upstream = importlib.util.module_from_spec(spec)
spec.loader.exec_module(upstream)


class ProposalTests(unittest.TestCase):
    def setUp(self):
        self.commands = []
        self.tag = "v1.3.3"
        self.existing = []
        self.conflict = False

    def fake_run(self, *args, check=True):
        self.commands.append(args)
        output = ""
        code = 0
        if args[:2] == ("gh", "api"):
            output = json.dumps({"tag_name": self.tag, "html_url": "https://github.com/upstream/releases/example"})
        elif args[:3] == ("gh", "pr", "list"):
            output = json.dumps(self.existing)
        elif args[:3] == ("gh", "issue", "list"):
            output = "[]"
        elif args[:2] == ("git", "rev-parse"):
            output = "a" * 40
        elif args[:2] == ("git", "merge") and "--abort" not in args and self.conflict:
            code = 1
        elif args[:2] == ("git", "diff"):
            output = "mcp/lib/example.php"
        return subprocess.CompletedProcess(args, code, output, "")

    def invoke(self):
        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, {
            "GH_TOKEN": "test-only", "FORK_REPOSITORY": "example/fork", "FORK_BASE_BRANCH": "main",
        }), patch.object(upstream, "run", self.fake_run):
            old = os.getcwd()
            os.chdir(directory)
            try:
                Path("fork-base.json").write_text(json.dumps({
                    "tag": "v1.3.2", "upstream": "sintax-tech/mcp-for-nextcloud",
                }))
                upstream.main()
            finally:
                os.chdir(old)

    def test_new_release_proposes_pr_without_publishing(self):
        self.invoke()
        self.assertIn(("git", "push", "origin", "HEAD:refs/heads/upstream/v1.3.3"), self.commands)
        self.assertTrue(any(command[:3] == ("gh", "pr", "create") for command in self.commands))
        self.assertFalse(any(command[:3] in [("gh", "pr", "merge"), ("gh", "release", "create")] for command in self.commands))

    def test_older_release_does_not_downgrade(self):
        self.tag = "v1.3.1"
        self.invoke()
        self.assertEqual(len(self.commands), 1)

    def test_existing_pr_does_not_force_push(self):
        self.existing = [{"number": 1, "state": "OPEN"}]
        self.invoke()
        self.assertFalse(any(command[:2] == ("git", "push") for command in self.commands))

    def test_conflict_reports_issue_and_never_pushes(self):
        self.conflict = True
        with self.assertRaisesRegex(RuntimeError, "blocked"):
            self.invoke()
        self.assertTrue(any(command[:3] == ("gh", "issue", "create") for command in self.commands))
        self.assertFalse(any(command[:2] == ("git", "push") for command in self.commands))

    def test_unknown_version_is_explicit(self):
        self.tag = "release-next"
        with self.assertRaisesRegex(ValueError, "Unsupported"):
            self.invoke()

    def test_missing_ci_trigger_token_fails(self):
        with patch.dict(os.environ, {"GH_TOKEN": ""}):
            with self.assertRaisesRegex(RuntimeError, "UPSTREAM_SYNC_TOKEN"):
                upstream.main()


class MergeCompatibilityTests(unittest.TestCase):
    def test_disposable_merge_detects_conflicts_and_preserves_original_branch(self):
        script = Path(__file__).with_name("check-upstream.sh").resolve()
        with tempfile.TemporaryDirectory() as directory:
            def git(*arguments):
                return subprocess.run(["git", *arguments], cwd=directory, check=True, text=True, capture_output=True).stdout.strip()
            git("init", "-b", "fork")
            git("config", "user.name", "Compatibility test")
            git("config", "user.email", "test@localhost")
            file = Path(directory, "example")
            file.write_text("base\n")
            git("add", "example")
            trailer = "\n\nCo-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
            git("commit", "-m", "Base" + trailer)
            git("branch", "upstream")
            result = subprocess.run(["sh", str(script), "refs/heads/upstream"], cwd=directory, text=True, capture_output=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            git("switch", "upstream")
            file.write_text("upstream correction\n")
            git("commit", "-am", "Upstream correction" + trailer)
            git("switch", "fork")
            file.write_text("fork feature\n")
            git("commit", "-am", "Fork feature" + trailer)
            before = git("rev-parse", "HEAD")
            result = subprocess.run(["sh", str(script), "refs/heads/upstream"], cwd=directory, text=True, capture_output=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn("Upstream merge conflict", result.stderr)
            self.assertIn("example", result.stdout)
            self.assertEqual(git("rev-parse", "HEAD"), before)
            self.assertEqual(git("branch", "--show-current"), "fork")
            self.assertEqual(git("status", "--porcelain"), "")
            self.assertNotIn("compatibility-", git("branch", "--list"))


if __name__ == "__main__":
    unittest.main()
