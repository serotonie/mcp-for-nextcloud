<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP extension fork contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Extension;

use OCA\Mcp\Access\Grants\GrantCatalog;
use OCA\Mcp\Mcp\Guide\ToolGuide;
use OCA\Mcp\Mcp\Tools\ToolModule;

/** Request-local, sealed registration; failures never produce a partial catalog. */
final class ExtensionRegistry {
	public const API_VERSION = 1;
	private static ?\Closure $loader = null;
	private static ?self $current = null;
	private static bool $loading = false;
	private bool $sealed = false;
	private array $extensions = [];
	private array $modules = [];
	private array $names = [];

	public static function configure(\Closure $loader): void {
		if (self::$loader !== null || self::$current !== null) {
			throw new \LogicException('MCP extension loader already configured');
		}
		self::$loader = $loader;
	}

	public static function current(): self {
		if (self::$current !== null) {
			return self::$current;
		}
		if (self::$loading) {
			throw new \LogicException('Recursive MCP extension registration');
		}
		$registry = new self();
		if (self::$loader === null) {
			$registry->seal();
			return $registry;
		}
		self::$loading = true;
		try {
			(self::$loader)(new RegistrationEvent($registry));
			$registry->seal();
			self::$current = $registry;
		} finally {
			self::$loading = false;
		}
		return $registry;
	}

	public function register(Extension $extension): void {
		if ($this->sealed) {
			throw new \LogicException('MCP extension registration is closed');
		}
		if ($extension->apiVersion !== self::API_VERSION) {
			throw new \LogicException('Unsupported MCP extension API version: ' . $extension->apiVersion);
		}
		if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $extension->app)
			|| !preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $extension->module)
			|| ($extension->module !== $extension->app && !str_starts_with($extension->module, $extension->app . '_'))
			|| isset(GrantCatalog::CATALOG[$extension->module]) || isset($this->extensions[$extension->module])
			|| trim($extension->label) === '' || strlen($extension->label) > 200 || $extension->operations === []) {
			throw new \InvalidArgumentException('Invalid or conflicting MCP extension module: ' . $extension->module);
		}
		foreach ($extension->operations as $operation => $description) {
			if (!is_string($operation) || !preg_match('/^[a-z][a-z0-9_.]{0,47}$/D', $operation)
				|| strlen('grant_' . $extension->module . '_' . $operation) > 64
				|| !is_string($description) || trim($description) === '' || strlen($description) > 2000) {
				throw new \InvalidArgumentException('Invalid MCP extension operation metadata');
			}
		}
		$definitions = $extension->tools->definitions();
		$names = $this->names;
		foreach ($definitions as &$definition) {
			$name = $definition['name'] ?? null;
			if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]{0,127}$/D', $name)
				|| !str_starts_with($name, $extension->module . '_') || $name === ToolGuide::TOOL
				|| isset($names[$name]) || ($definition['module'] ?? null) !== $extension->module
				|| !isset($extension->operations[$definition['operation'] ?? ''])
				|| (isset($definition['app']) && $definition['app'] !== $extension->app)
				|| isset($definition['grantAnyOf'])
				|| !is_string($definition['description'] ?? null)
				|| !is_array($definition['inputSchema'] ?? null)
				|| ($definition['inputSchema']['type'] ?? null) !== 'object') {
				throw new \InvalidArgumentException('Invalid or conflicting MCP extension tool: ' . ($name ?? '(missing)'));
			}
			$definition['app'] = $extension->app;
			$names[$name] = true;
		}
		unset($definition);
		$this->names = $names;
		$this->extensions[$extension->module] = $extension;
		$this->modules[] = new RegisteredModule($extension->tools, $definitions);
	}

	public function seal(): void {
		$this->sealed = true;
	}

	public function catalog(): array {
		return array_map(static fn (Extension $extension): array => array_keys($extension->operations), $this->extensions);
	}

	public function apps(): array {
		return array_map(static fn (Extension $extension): string => $extension->app, $this->extensions);
	}

	public function metadata(): array {
		return array_map(static fn (Extension $extension): array => [
			'app' => $extension->app, 'label' => $extension->label,
			'operations' => $extension->operations, 'defaultGranted' => false,
			'apiVersion' => $extension->apiVersion,
		], $this->extensions);
	}

	/** @return list<ToolModule> */
	public function modules(): array {
		return $this->modules;
	}
}
