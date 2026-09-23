<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit;

use Drupal\Core\Session\AccountInterface;
use PhpClaw\Drupal\DrupalConsole;
use PhpClaw\Drupal\DrupalIdentityResolver;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

final class PermissionContractTest extends TestCase
{
    private const TOOL_PERMISSION = 'use phpclaw chat';

    private const ADMIN_PERMISSION = 'administer phpclaw';

    private const MANAGE_ALL_PERMISSION = 'manage all phpclaw conversations';

    private const ROUTE_PERMISSIONS = [
        'phpclaw.admin.settings' => 'administer phpclaw',
        'phpclaw.admin.chat' => 'use phpclaw chat',
        'phpclaw.admin.chat.send' => 'use phpclaw chat',
        'phpclaw.admin.chat.stream' => 'use phpclaw chat',
        'phpclaw.admin.chat.load' => 'use phpclaw chat',
        'phpclaw.admin.chat.delete' => 'use phpclaw chat',
        'phpclaw.admin.analytics' => 'use phpclaw chat',
        'phpclaw.admin.guide' => 'use phpclaw chat',
        'phpclaw.admin.about' => 'use phpclaw chat',
        'phpclaw.admin.test_connection' => 'administer phpclaw',
        'phpclaw.api.send' => 'use phpclaw chat',
        'phpclaw.api.stream' => 'use phpclaw chat',
    ];

    protected function tearDown(): void
    {
        if (class_exists(\Drupal::class)) {
            \Drupal::unsetContainer();
        }
    }

    private function packageRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function permissions(): array
    {
        $path = $this->packageRoot().'/phpclaw.permissions.yml';
        self::assertFileExists($path);

        return (array) Yaml::parse((string) file_get_contents($path));
    }

    private function manifest(string $relative): string
    {
        $path = $this->packageRoot().'/'.$relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_the_package_declares_exactly_three_permissions(): void
    {
        self::assertSame(
            [self::ADMIN_PERMISSION, self::MANAGE_ALL_PERMISSION, self::TOOL_PERMISSION],
            $this->sorted(array_keys($this->permissions())),
        );
    }

    public function test_every_permission_is_marked_restricted(): void
    {
        foreach ($this->permissions() as $name => $definition) {
            self::assertTrue(
                $definition['restrict access'] ?? false,
                $name.' grants administrator equivalent reach and must carry restrict access',
            );
        }
    }

    public function test_the_chat_permission_description_warns_it_is_administrator_equivalent(): void
    {
        $description = (string) ($this->permissions()[self::TOOL_PERMISSION]['description'] ?? '');

        self::assertStringContainsString(
            'Administrator equivalent',
            $description,
            'the chat permission grants the whole tool surface, so its description must say so',
        );
    }

    public function test_the_chat_permission_description_names_what_the_menu_also_needs(): void
    {
        $description = (string) ($this->permissions()[self::TOOL_PERMISSION]['description'] ?? '');

        self::assertStringContainsString('Use the administration pages', $description);
        self::assertStringContainsString('View the administration theme', $description);
    }

    public function test_every_route_keeps_the_permission_it_shipped_with(): void
    {
        $routes = (array) Yaml::parse($this->manifest('phpclaw.routing.yml'));

        $actual = [];
        foreach ($routes as $name => $definition) {
            $actual[$name] = $definition['requirements']['_permission'] ?? null;
        }

        self::assertSame(self::ROUTE_PERMISSIONS, $actual);
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_marked_console_process_resolves_the_acting_id_to_zero(): void
    {
        if (! class_exists(\Drupal::class)) {
            $this->markTestSkipped('Drupal container not available.');
        }

        $this->bootContainerWithUser(42, allPermissions: true);
        DrupalConsole::mark();

        self::assertSame(
            0,
            DrupalIdentityResolver::actingUserId(),
            'A phpClaw console entrypoint owns no session, so the acting id must be 0 whatever a '
            .'session claims.',
        );
    }

    public function test_an_unmarked_process_resolves_the_acting_id_from_the_session(): void
    {
        if (! class_exists(\Drupal::class)) {
            $this->markTestSkipped('Drupal container not available.');
        }

        $this->bootContainerWithUser(42, allPermissions: true);

        self::assertSame(
            42,
            DrupalIdentityResolver::actingUserId(),
            'A web request must act as the logged-in user. Answering 0 here would stamp every '
            .'conversation as unowned and hide every existing one.',
        );
    }

    public function test_manage_all_follows_the_drupal_permission_on_every_path(): void
    {
        if (! class_exists(\Drupal::class)) {
            $this->markTestSkipped('Drupal container not available.');
        }

        $this->bootContainerWithUser(42, allPermissions: true);

        self::assertTrue(
            DrupalIdentityResolver::manageAll(),
            'An account holding the permission must reach every conversation.',
        );

        $this->bootContainerWithUser(2, allPermissions: false);

        self::assertFalse(
            DrupalIdentityResolver::manageAll(),
            'An account without the permission must be scoped to its own conversations.',
        );
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_marking_the_process_does_not_change_manage_all(): void
    {
        if (! class_exists(\Drupal::class)) {
            $this->markTestSkipped('Drupal container not available.');
        }

        DrupalConsole::mark();
        $this->bootContainerWithUser(42, allPermissions: true);

        self::assertTrue(
            DrupalIdentityResolver::manageAll(),
            'manage-all reads the Drupal permission and nothing else. A console branch here would '
            .'be a second control for a fact the permission already decides, and nothing could '
            .'prove it load-bearing.',
        );
    }

    /**
     * @depends test_a_marked_console_process_resolves_the_acting_id_to_zero
     */
    #[Depends('test_a_marked_console_process_resolves_the_acting_id_to_zero')]
    public function test_this_file_never_leaks_the_console_marker(): void
    {
        self::assertFalse(
            DrupalConsole::isActive(),
            'This file defines '.DrupalConsole::MARKER.' in isolated processes only. A leak into the '
            .'shared process would silently give every test after it the console identity.',
        );
    }

    private function bootContainerWithUser(int $uid, bool $allPermissions): void
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('id')->willReturn($uid);
        $account->method('hasPermission')->willReturn($allPermissions);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static fn (string $id): object => $id === 'current_user'
                ? $account
                : throw new \RuntimeException("Service '{$id}' not mocked."),
        );

        \Drupal::setContainer($container);
    }

    public function test_no_tool_declares_its_own_execute(): void
    {
        $offenders = [];

        foreach ($this->shippedPhpFiles() as $path) {
            if (! str_contains($path, '/src/Tools/') || str_contains($path, '/Concerns/')) {
                continue;
            }

            $class = 'PhpClaw\\Drupal\\Tools\\'.basename($path, '.php');

            if (! class_exists($class) || ! method_exists($class, 'execute')) {
                continue;
            }

            $origin = basename((string) (new \ReflectionMethod($class, 'execute'))->getFileName());

            if ($origin !== 'HasToolExecutionContract.php') {
                $offenders[] = basename($path).' declares its own execute() in '.$origin;
            }
        }

        self::assertSame([], $offenders, 'execute() must come from the core trait, never from a tool');
    }

    private function shippedPhpFiles(): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->packageRoot().'/src', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);
        self::assertNotEmpty($files);

        return $files;
    }

    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
