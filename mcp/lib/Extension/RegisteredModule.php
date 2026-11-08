<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP extension fork contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Extension;

use OCA\Mcp\Mcp\Guide\ToolGuideNotes;
use OCA\Mcp\Mcp\Tools\ToolModule;
use OCA\Mcp\Mcp\Tools\RestrictedModule;

/** Freeze validated definitions; use the core generic, side-effect-free write preview. */
final class RegisteredModule implements ToolModule, ToolGuideNotes, RestrictedModule {
	public function __construct(private readonly ToolModule $delegate, private readonly array $definitions) {
	}

	public function definitions(): array {
		return $this->definitions;
	}

	public function call(string $name, array $arguments, string $userId): array {
		return $this->delegate->call($name, $arguments, $userId);
	}

	public function guideNotes(): array {
		return $this->delegate instanceof ToolGuideNotes ? $this->delegate->guideNotes() : [];
	}

	public function permits(string $userId): bool {
		return !$this->delegate instanceof RestrictedModule || $this->delegate->permits($userId);
	}

	public function audit(string $tool, string $userId, string $outcome, array $arguments): void {
		if ($this->delegate instanceof RestrictedModule) {
			$this->delegate->audit($tool, $userId, $outcome, $arguments);
		}
	}
}
