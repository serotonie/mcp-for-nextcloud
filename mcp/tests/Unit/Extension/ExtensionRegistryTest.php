<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Extension;

use OCA\Mcp\Access\Grants\GrantCatalog;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Extension\Extension;
use OCA\Mcp\Extension\ExtensionRegistry;
use OCA\Mcp\Extension\RegistrationEvent;
use OCA\Mcp\Mcp\Tools\ToolModule;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ExtensionRegistryTest extends TestCase {
	private function module(): ToolModule {
		return new class implements ToolModule {
			public array $calls = [];
			public function definitions(): array {
				return array_map(static fn (string $operation): array => [
					'name' => 'acme_' . $operation, 'description' => $operation,
					'module' => 'acme', 'operation' => $operation,
					'inputSchema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
				], ['read', 'create', 'edit', 'delete']);
			}
			public function call(string $name, array $arguments, string $userId): array {
				$this->calls[] = [$name, $arguments, $userId];
				return ['content' => [['type' => 'text', 'text' => 'ok']]];
			}
		};
	}

	private function extension(?ToolModule $tools = null, int $version = 1, string $module = 'acme'): Extension {
		return new Extension($version, 'acme', $module, 'Acme', [
			'read' => 'Read', 'create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete',
		], $tools ?? $this->module());
	}

	public function testRegistrationIsSealedAndCannotChangeUpstreamCatalog(): void {
		$registry = new ExtensionRegistry();
		$registry->register($this->extension());
		$this->assertSame(['acme' => ['read', 'create', 'edit', 'delete']], $registry->catalog());
		$this->assertSame(['acme' => 'acme'], $registry->apps());
		$this->assertFalse($registry->metadata()['acme']['defaultGranted']);
		$registry->seal();
		$this->expectException(\LogicException::class);
		$registry->register($this->extension());
	}

	public function testConflictingModuleIsRejectedAtomically(): void {
		$registry = new ExtensionRegistry();
		$registry->register($this->extension());
		try {
			$registry->register($this->extension());
			$this->fail('Duplicate accepted');
		} catch (\InvalidArgumentException) {
			$this->assertCount(1, $registry->modules());
		}
	}

	public function testUnsupportedVersionIsExplicit(): void {
		$this->expectException(\LogicException::class);
		(new ExtensionRegistry())->register($this->extension(version: 2));
	}

	public function testUpstreamModuleCannotBeClaimed(): void {
		$this->expectException(\InvalidArgumentException::class);
		(new ExtensionRegistry())->register($this->extension(module: 'files'));
	}

	public function testForeignToolNamesAndAlternativeGrantsAreRejected(): void {
		$tools = $this->module();
		foreach (['name' => 'files_edit', 'module' => 'files', 'app' => 'files', 'grantAnyOf' => ['read']] as $field => $value) {
			$invalid = new class($tools, $field, $value) implements ToolModule {
				public function __construct(private ToolModule $tools, private string $field, private mixed $value) {}
				public function definitions(): array {
					$definition = $this->tools->definitions()[0];
					$definition[$this->field] = $this->value;
					return [$definition];
				}
				public function call(string $name, array $arguments, string $userId): array { throw new \LogicException('Must not run'); }
			};
			$registry = new ExtensionRegistry();
			try {
				$registry->register($this->extension($invalid));
				$this->fail('Invalid definition accepted');
			} catch (\InvalidArgumentException) {
				$this->assertSame([], $registry->catalog());
			}
		}
	}

	public function testLazyEventCatalogPreservesLegacyGrantsAndDefaultDenial(): void {
		$extension = $this->extension();
		ExtensionRegistry::configure(static fn (RegistrationEvent $event) => $event->register($extension));
		\OCA\Mcp\Access\Grants\ExtensionCatalog::configure(static fn (): array => ExtensionRegistry::current()->metadata());
		$this->assertSame(GrantCatalog::CATALOG + ['acme' => ['read', 'create', 'edit', 'delete']], GrantCatalog::all());
		$this->assertTrue(GrantPolicy::defaultGranted('files', 'read'));
		$this->assertFalse(GrantPolicy::defaultGranted('logs', 'read'));
		foreach (GrantCatalog::all()['acme'] as $operation) {
			$this->assertFalse(GrantPolicy::defaultGranted('acme', $operation));
		}
		$data = new \OCA\Mcp\Tests\Unit\InMemoryConfig();
		$config = $data->mock($this);
		$policy = \OCA\Mcp\Tests\Unit\InMemoryConfig::policy($config, new \OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore());
		$policy->setGrant('alice', 'acme', 'edit', true);
		$this->assertTrue($policy->granted('alice', 'acme', 'edit'));
		$this->assertFalse($policy->granted('alice', 'acme', 'create'));
		$this->assertFalse($policy->granted('bob', 'acme', 'edit'));
	}

	public function testAppGuestsIdentityPermissionsAndConfirmationAreRechecked(): void {
		$tools = $this->module();
		$extensions = new ExtensionRegistry();
		$extensions->register($this->extension($tools));
		$granted = ['read'];
		$policy = $this->createMock(GrantPolicy::class);
		$policy->method('grantedAny')->willReturnCallback(static function (string $uid, string $module, array $operations) use (&$granted): bool {
			return $uid === 'alice' && $module === 'acme' && array_intersect($operations, $granted) !== [];
		});
		$enabled = true;
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(static function () use (&$enabled): bool { return $enabled; });
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid) => $uid === 'alice' ? $this->createMock(IUser::class) : null);
		$guestAllowed = true;
		$guests = $this->createMock(\OCA\Mcp\Access\Guests\GuestRestrictions::class);
		$guests->method('allowsApp')->willReturnCallback(static function () use (&$guestAllowed): bool { return $guestAllowed; });
		$registry = new ToolRegistry($extensions->modules(), $policy, $apps, $users, new NullLogger(), $guests);
		$this->assertCount(2, $registry->list('alice'));
		$this->assertCount(1, $registry->list('bob'));
		$granted[] = 'create';
		$preview = $registry->call('acme_create', [], 'alice');
		$this->assertStringContainsString('requiresConfirmation', json_encode($preview));
		$this->assertSame([], $tools->calls);
		$registry->call('acme_create', ['confirm' => true], 'alice');
		$this->assertSame('alice', $tools->calls[0][2]);
		$enabled = false;
		$this->assertCount(1, $registry->list('alice'));
		$enabled = true;
		$guestAllowed = false;
		$this->assertCount(1, $registry->list('alice'));
		$this->expectException(\InvalidArgumentException::class);
		$registry->call('acme_create', ['confirm' => true], 'alice');
	}

	public function testDuplicateToolRegistryNamesAreRejected(): void {
		$this->expectException(\LogicException::class);
		new ToolRegistry([$this->module(), $this->module()], $this->createMock(GrantPolicy::class),
			$this->createMock(IAppManager::class), $this->createMock(IUserManager::class), new NullLogger());
	}

	public function testRealBootstrapDispatchesOnceAndWiresPolicyCatalog(): void {
		$extension = $this->extension();
		$dispatcher = $this->createMock(\OCP\EventDispatcher\IEventDispatcher::class);
		$dispatcher->expects($this->once())->method('dispatchTyped')->willReturnCallback(static function (RegistrationEvent $event) use ($extension): void {
			$event->register($extension);
		});
		$boot = $this->createMock(\OCP\AppFramework\Bootstrap\IBootContext::class);
		$boot->expects($this->once())->method('injectFn')->willReturnCallback(static function (callable $callback) use ($dispatcher): void {
			$callback($dispatcher, new NullLogger());
		});
		$application = (new \ReflectionClass(\OCA\Mcp\AppInfo\Application::class))->newInstanceWithoutConstructor();
		$application->boot($boot);
		$this->assertSame(['read', 'create', 'edit', 'delete'], GrantCatalog::all()['acme']);
		$this->assertSame('acme', \OCA\Mcp\Access\Grants\GrantMatrix::moduleApps()['acme']);
		$this->assertCount(1, ExtensionRegistry::current()->modules());
	}
}
