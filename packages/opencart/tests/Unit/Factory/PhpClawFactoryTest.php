<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Factory;

use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Guards\Contracts\GuardInterface;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Guards\RateLimitGuard;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\InMemoryStore;
use PhpClaw\OpenCart\Contracts\OcDbInterface;
use PhpClaw\OpenCart\Factory\PhpClawFactory;
use PhpClaw\OpenCart\Factory\PhpClawFactoryInterface;
use PhpClaw\OpenCart\Tests\Helpers\OcDbTestCase;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\FileWriteTool;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class PhpClawFactoryTest extends OcDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('ANTHROPIC_API_KEY=test-key-unit');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('ANTHROPIC_API_KEY');
    }

    public function test_create_returns_phpclaw_interface(): void
    {
        $factory = new PhpClawFactory;
        $engine = $factory->create();
        self::assertInstanceOf(ClawInterface::class, $engine);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_console_marker_enables_php_write_and_wires_approval_gate(): void
    {
        define('PHPCLAW_OC_CONSOLE', true);

        $factory = new PhpClawFactory;
        $engine = $factory->create();

        self::assertInstanceOf(CliApprovalGate::class, $this->approvalGate($engine));
        self::assertTrue(
            $this->fileWriteAllowsPhp($engine),
            'A process carrying the console marker may write PHP.',
        );
    }

    public function test_absent_console_marker_disables_php_write_but_still_wires_approval_gate(): void
    {
        self::assertFalse(
            defined('PHPCLAW_OC_CONSOLE'),
            'This negative control is only meaningful while the marker is absent.',
        );

        $factory = new PhpClawFactory;
        $engine = $factory->create();

        self::assertInstanceOf(CliApprovalGate::class, $this->approvalGate($engine));
        self::assertFalse($this->fileWriteAllowsPhp($engine));
    }

    private function approvalGate(ClawInterface $engine): ?object
    {
        return $this->readEngineProperty($this->readEngineProperty($engine, 'config'), 'approvalGate');
    }

    private function fileWriteAllowsPhp(ClawInterface $engine): bool
    {
        $config = $this->readEngineProperty($engine, 'config');
        foreach ((array) $this->readEngineProperty($config, 'tools') as $tool) {
            if ($tool instanceof FileWriteTool) {
                return (bool) $this->readEngineProperty($tool, 'allowPhpWrite');
            }
        }
        self::fail('FileWriteTool not present in the built engine.');
    }

    private function readEngineProperty(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }

    public function test_create_with_pdo_registers_opencart_driver(): void
    {
        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$this->prefix}phpclaw_memory` (
                id          VARCHAR(26)  NOT NULL,
                namespace   VARCHAR(100) NOT NULL DEFAULT 'default',
                lookup_key  VARCHAR(255) NOT NULL DEFAULT '',
                value       LONGTEXT     NOT NULL,
                expires_at  DATETIME     DEFAULT NULL,
                created_at  DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
                updated_at  DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
                PRIMARY KEY (id),
                UNIQUE KEY uq_ns_key (namespace, lookup_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $factory = new PhpClawFactory;
        $engine = $factory->create($this->prefix, null, $this->db);
        self::assertInstanceOf(ClawInterface::class, $engine);
    }

    public function test_factory_implements_interface(): void
    {
        $factory = new PhpClawFactory;
        self::assertInstanceOf(PhpClawFactoryInterface::class, $factory);
    }

    public function test_interface_declares_every_parameter_the_implementation_accepts(): void
    {
        $contract = new \ReflectionMethod(PhpClawFactoryInterface::class, 'create');
        $concrete = new \ReflectionMethod(PhpClawFactory::class, 'create');

        $declared = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $contract->getParameters()
        );
        $accepted = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $concrete->getParameters()
        );

        self::assertSame(
            $accepted,
            $declared,
            'A parameter the implementation accepts but the interface omits is unreachable through the contract.'
        );
    }

    public function test_create_with_table_prefix(): void
    {
        $factory = new PhpClawFactory;
        $engine = $factory->create('mystore_');
        self::assertInstanceOf(ClawInterface::class, $engine);
    }

    public function test_create_with_groq_api_key(): void
    {
        putenv('ANTHROPIC_API_KEY');
        putenv('GROQ_API_KEY=test-groq-key');
        try {
            $factory = new PhpClawFactory;
            $engine = $factory->create();
            self::assertSame('test-groq-key', $engine->config()->apiKey);
            self::assertSame('groq', $engine->config()->providerName);
        } finally {
            putenv('GROQ_API_KEY');
            putenv('ANTHROPIC_API_KEY=test-key-unit');
        }
    }

    public function test_create_with_gemini_api_key(): void
    {
        putenv('ANTHROPIC_API_KEY');
        putenv('OPENAI_API_KEY');
        putenv('GROQ_API_KEY');
        putenv('GEMINI_API_KEY=test-gemini-key');
        try {
            $factory = new PhpClawFactory;
            $engine = $factory->create();
            self::assertSame('test-gemini-key', $engine->config()->apiKey);
        } finally {
            putenv('GEMINI_API_KEY');
            putenv('ANTHROPIC_API_KEY=test-key-unit');
        }
    }

    public function test_create_with_phpclaw_hooks_env_valid(): void
    {
        $hooks = json_encode([
            ['event' => 'agent.before', 'handler' => 'strlen'],
        ]);
        putenv('PHPCLAW_HOOKS='.$hooks);
        try {
            $factory = new PhpClawFactory;
            $engine = $factory->create();
            self::assertInstanceOf(ClawInterface::class, $engine);
        } finally {
            putenv('PHPCLAW_HOOKS');
        }
    }

    public function test_create_with_phpclaw_hooks_env_invalid_json(): void
    {
        putenv('PHPCLAW_HOOKS=not-valid-json');
        try {
            $factory = new PhpClawFactory;
            $engine = $factory->create();
            self::assertInstanceOf(ClawInterface::class, $engine);
        } finally {
            putenv('PHPCLAW_HOOKS');
        }
    }

    public function test_create_with_phpclaw_guards_env_invalid_json(): void
    {
        putenv('PHPCLAW_GUARDS=not-valid-json');
        try {
            $factory = new PhpClawFactory;
            $engine = $factory->create();
            self::assertInstanceOf(ClawInterface::class, $engine);
        } finally {
            putenv('PHPCLAW_GUARDS');
        }
    }

    public function test_create_with_phpclaw_guards_nonexistent_class(): void
    {
        $guards = json_encode([['class' => 'NonExistent\\Guard\\Class', 'priority' => 5]]);
        putenv('PHPCLAW_GUARDS='.$guards);
        try {
            $factory = new PhpClawFactory;
            $engine = $factory->create();
            self::assertInstanceOf(ClawInterface::class, $engine);
        } finally {
            putenv('PHPCLAW_GUARDS');
        }
    }

    public function test_create_with_rate_limit_zero(): void
    {
        putenv('PHPCLAW_RATE_LIMIT_PER_MINUTE=0');
        try {
            $factory = new PhpClawFactory;
            $engine = $factory->create();
            self::assertInstanceOf(ClawInterface::class, $engine);
        } finally {
            putenv('PHPCLAW_RATE_LIMIT_PER_MINUTE');
        }
    }

    public function test_create_hooks_entry_missing_event_is_skipped(): void
    {
        $hooks = json_encode([['handler' => 'strlen']]);
        putenv('PHPCLAW_HOOKS='.$hooks);
        try {
            $factory = new PhpClawFactory;
            $engine = $factory->create();
            self::assertInstanceOf(ClawInterface::class, $engine);
        } finally {
            putenv('PHPCLAW_HOOKS');
        }
    }

    public function test_create_hooks_entry_empty_event_is_skipped(): void
    {
        $hooks = json_encode([['event' => '', 'handler' => 'strlen']]);
        putenv('PHPCLAW_HOOKS='.$hooks);
        try {
            $factory = new PhpClawFactory;
            $engine = $factory->create();
            self::assertInstanceOf(ClawInterface::class, $engine);
        } finally {
            putenv('PHPCLAW_HOOKS');
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_create_resolves_phpclaw_api_key_constant(): void
    {
        define('PHPCLAW_API_KEY', 'sk-from-constant-fixture');
        $factory = new PhpClawFactory;
        $engine = $factory->create();
        self::assertSame('sk-from-constant-fixture', $engine->config()->apiKey);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_create_with_phpclaw_guards_constant_valid_json(): void
    {
        define('PHPCLAW_API_KEY', 'sk-fixture');
        define('PHPCLAW_GUARDS', json_encode([
            ['class' => '\\PhpClaw\\Guards\\RateLimitGuard', 'priority' => 5],
            ['class' => 'NonExistent\\Bad', 'priority' => 10],
            ['class' => '', 'priority' => 99],
            ['priority' => 1],
        ]));

        $factory = new PhpClawFactory;
        $engine = $factory->create();
        self::assertTrue(
            GuardRegistry::hasClass(RateLimitGuard::class),
            'the one valid guard entry must register; the three malformed ones must not',
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_create_with_phpclaw_hooks_constant_valid_json(): void
    {
        define('PHPCLAW_API_KEY', 'sk-fixture');
        define('PHPCLAW_HOOKS', json_encode([
            ['event' => 'agent.before', 'handler' => 'strlen', 'priority' => 5],
            ['event' => 'tool.after',   'handler' => 'strlen'],
            ['event' => '',             'handler' => 'strlen'],
            ['handler' => 'strlen'],
            ['event' => 'agent.after',  'handler' => 'strlen', 'priority' => 99],
        ]));

        $factory = new PhpClawFactory;
        $engine = $factory->create();
        self::assertInstanceOf(ClawInterface::class, $engine);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_create_with_phpclaw_guards_invalid_json_constant(): void
    {
        define('PHPCLAW_API_KEY', 'sk-fixture');
        define('PHPCLAW_GUARDS', '{not-valid-json[');
        $factory = new PhpClawFactory;
        $engine = $factory->create();
        self::assertInstanceOf(ClawInterface::class, $engine);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_create_with_phpclaw_hooks_invalid_json_constant(): void
    {
        define('PHPCLAW_API_KEY', 'sk-fixture');
        define('PHPCLAW_HOOKS', '{not-valid-json[');
        $factory = new PhpClawFactory;
        $engine = $factory->create();
        self::assertInstanceOf(ClawInterface::class, $engine);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_create_with_rate_limit_constant_zero_skips_registration(): void
    {
        define('PHPCLAW_API_KEY', 'sk-fixture');
        define('PHPCLAW_RATE_LIMIT_PER_MINUTE', '0');
        $factory = new PhpClawFactory;
        $engine = $factory->create();
        self::assertFalse(
            GuardRegistry::hasClass(RateLimitGuard::class),
            'a rate limit of 0 must skip RateLimitGuard registration',
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_create_with_provider_model_constants(): void
    {
        define('PHPCLAW_API_KEY', 'sk-fixture');
        define('PHPCLAW_PROVIDER', 'anthropic');
        define('PHPCLAW_MODEL', 'claude-haiku-4-5');
        define('PHPCLAW_MAX_ITERATIONS', '7');
        define('PHPCLAW_STORE_MESSAGES', '1');
        define('PHPCLAW_SHELL_ALLOWLIST', 'ls,pwd');
        define('PHPCLAW_MEMORY_DRIVER', 'file');

        $factory = new PhpClawFactory;
        $engine = $factory->create();
        $config = $engine->config();

        self::assertSame('anthropic', $config->providerName);
        self::assertSame('claude-haiku-4-5', $config->model);
        self::assertSame(7, $config->maxIterations);
        self::assertSame(['ls', 'pwd'], $config->shellAllowlist);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_create_with_guards_entry_missing_class_skipped(): void
    {
        define('PHPCLAW_API_KEY', 'sk-fixture');
        define('PHPCLAW_GUARDS', json_encode([
            ['priority' => 5],
            ['class' => 42, 'priority' => 10],
        ]));
        $factory = new PhpClawFactory;
        $engine = $factory->create();
        self::assertInstanceOf(ClawInterface::class, $engine);
    }

    public function test_resolve_api_key_falls_back_to_empty_when_nothing_set(): void
    {
        putenv('ANTHROPIC_API_KEY');
        putenv('OPENAI_API_KEY');
        putenv('GROQ_API_KEY');
        putenv('GEMINI_API_KEY');

        $factory = new PhpClawFactory;
        $ref = new \ReflectionClass($factory);
        $m = $ref->getMethod('resolveApiKey');
        $m->setAccessible(true);

        self::assertSame('', $m->invoke($factory));

        putenv('ANTHROPIC_API_KEY=test-key-unit');
    }

    public function test_create_with_registry_injects_extra_subsystems(): void
    {
        $listeners = [
            'phpclaw/extra/tools' => static function (array &$bucket): void {
                $bucket[] = new class implements ToolInterface
                {
                    public function name(): string
                    {
                        return 'fixture_tool';
                    }

                    public function description(): string
                    {
                        return 'fixture';
                    }

                    public function inputSchema(): array
                    {
                        return ['type' => 'object', 'properties' => new \stdClass];
                    }

                    public function execute(array $input): string
                    {
                        return 'ok';
                    }
                };
                $bucket[] = 'not_a_tool';
            },
            'phpclaw/extra/guards' => static function (array &$bucket): void {
                $bucket[] = new class implements GuardInterface
                {
                    public function scan(string $message): void {}
                };
                $bucket[] = 'not_a_guard';
            },
            'phpclaw/extra/hooks' => static function (array &$bucket): void {
                $bucket[] = ['event' => 'agent.before', 'handler' => 'strlen', 'priority' => 5];
                $bucket[] = ['event' => '',             'handler' => 'strlen'];
                $bucket[] = ['only_event' => 'agent.after'];
                $bucket[] = 'not_an_array';
            },
            'phpclaw/extra/skills' => static function (array &$bucket): void {
                $bucket[] = new class implements SkillInterface
                {
                    public function name(): string
                    {
                        return 'fixture_skill';
                    }

                    public function description(): string
                    {
                        return 'fixture';
                    }

                    public function tags(): array
                    {
                        return ['fixture'];
                    }

                    public function content(): string
                    {
                        return 'fixture content';
                    }
                };
                $bucket[] = 'not_a_skill';
            },
            'phpclaw/extra/memory' => static function (array &$bucket): void {
                $bucket['fixture_mem'] = static fn (): MemoryInterface => new InMemoryStore;
                $bucket[42] = static fn () => null;
                $bucket['not_callable'] = 'not-callable';
            },
            'phpclaw/extra/providers' => static function (array &$bucket): void {
                $bucket['fixture_provider'] = ['class' => 'PhpClaw\\Providers\\AnthropicProvider'];
                $bucket['missing_class'] = ['class' => 'NonExistent\\Bad\\Class'];
                $bucket['not_array'] = 'not-array';
                $bucket[7] = ['class' => 'PhpClaw\\Providers\\AnthropicProvider'];
            },
        ];

        $registry = new class($listeners)
        {
            public function __construct(private readonly array $listeners) {}

            public function has(string $name): bool
            {
                return $name === 'event';
            }

            public function get(string $name): object
            {
                return new class($this->listeners)
                {
                    public function __construct(private readonly array $listeners) {}

                    public function trigger(string $event, array $args): void
                    {
                        if (isset($this->listeners[$event])) {
                            ($this->listeners[$event])($args[0]);
                        }
                    }
                };
            }
        };

        $factory = new PhpClawFactory;
        $engine = $factory->create($this->prefix, $registry);
        self::assertInstanceOf(ClawInterface::class, $engine);
    }

    public function test_create_swallows_registry_trigger_exceptions(): void
    {
        $registry = new class
        {
            public function has(string $name): bool
            {
                return $name === 'event';
            }

            public function get(string $name): object
            {
                return new class
                {
                    public function trigger(string $event, array $args): void
                    {
                        throw new \RuntimeException('listener boom');
                    }
                };
            }
        };

        $factory = new PhpClawFactory;
        $engine = $factory->create($this->prefix, $registry);
        self::assertInstanceOf(ClawInterface::class, $engine);
    }

    public function test_create_without_event_registry_skips_extra_events(): void
    {
        $registry = new class
        {
            public function has(string $name): bool
            {
                return false;
            }

            public function get(string $name): object
            {
                throw new \RuntimeException('not reachable');
            }
        };

        $factory = new PhpClawFactory;
        $engine = $factory->create($this->prefix, $registry);
        self::assertInstanceOf(ClawInterface::class, $engine);
    }

    public function test_create_accepts_oc_db_interface_as_third_arg(): void
    {
        $param = (new \ReflectionMethod(PhpClawFactory::class, 'create'))
            ->getParameters()[2];

        self::assertSame('db', $param->getName());
        $type = $param->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame(
            OcDbInterface::class,
            $type->getName(),
        );
        self::assertTrue($type->allowsNull());
        self::assertTrue($param->isDefaultValueAvailable());
        self::assertNull($param->getDefaultValue());
    }

    public function test_create_with_native_db_stores_it_directly(): void
    {
        $nativeDb = $this->db;

        $factory = new PhpClawFactory;
        $engine = $factory->create($this->prefix, null, $nativeDb);

        self::assertInstanceOf(ClawInterface::class, $engine);

        $dbProp = (new \ReflectionClass($factory))->getProperty('db');
        $dbProp->setAccessible(true);
        self::assertSame($nativeDb, $dbProp->getValue($factory));
    }

    public function test_create_takes_prefix_registry_and_db(): void
    {
        $params = (new \ReflectionMethod(PhpClawFactory::class, 'create'))->getParameters();

        self::assertSame(
            ['tablePrefix', 'registry', 'db'],
            array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $params),
        );
    }

    public function test_create_with_guards_json_scalar_not_array_is_skipped(): void
    {
        putenv('PHPCLAW_GUARDS=123');
        try {
            $factory = new PhpClawFactory;
            $engine = $factory->create();
            self::assertInstanceOf(ClawInterface::class, $engine);
        } finally {
            putenv('PHPCLAW_GUARDS');
        }
    }

    public function test_create_with_hooks_json_scalar_not_array_is_skipped(): void
    {
        putenv('PHPCLAW_HOOKS=42');
        try {
            $factory = new PhpClawFactory;
            $engine = $factory->create();
            self::assertInstanceOf(ClawInterface::class, $engine);
        } finally {
            putenv('PHPCLAW_HOOKS');
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_create_resolves_anthropic_constant_in_api_key_chain(): void
    {
        define('ANTHROPIC_API_KEY', 'sk-ant-from-php-constant');
        $factory = new PhpClawFactory;
        $engine = $factory->create();
        self::assertInstanceOf(ClawInterface::class, $engine);
    }
}
