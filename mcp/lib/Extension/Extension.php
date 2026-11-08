<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP extension fork contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Extension;

use OCA\Mcp\Mcp\Tools\ToolModule;

final class Extension {
	/**
	 * Operation descriptions are plain text; every operation starts denied.
	 * The module ID is the app ID or an app-prefixed ID, stable across upgrades.
	 * @param array<string,string> $operations
	 */
	public function __construct(
		public readonly int $apiVersion,
		public readonly string $app,
		public readonly string $module,
		public readonly string $label,
		public readonly array $operations,
		public readonly ToolModule $tools,
	) {
	}
}
