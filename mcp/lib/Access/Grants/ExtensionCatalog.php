<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP extension fork contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants;

/** Policy metadata port; Access does not depend on the MCP tool implementation. */
final class ExtensionCatalog {
	private static ?\Closure $reader = null;

	public static function configure(\Closure $reader): void {
		if (self::$reader !== null) {
			throw new \LogicException('MCP extension catalog already configured');
		}
		self::$reader = $reader;
	}

	public static function metadata(): array {
		return self::$reader === null ? [] : (self::$reader)();
	}

	public static function operations(): array {
		return array_map(static fn (array $metadata): array => array_keys($metadata['operations']), self::metadata());
	}

	public static function apps(): array {
		return array_map(static fn (array $metadata): string => $metadata['app'], self::metadata());
	}
}
