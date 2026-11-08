<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP extension fork contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Extension;

use OCP\EventDispatcher\Event;

/** Dispatched lazily after all applications have registered their listeners. */
final class RegistrationEvent extends Event {
	public function __construct(private readonly ExtensionRegistry $registry) {
		parent::__construct();
	}

	public function register(Extension $extension): void {
		$this->registry->register($extension);
	}
}
