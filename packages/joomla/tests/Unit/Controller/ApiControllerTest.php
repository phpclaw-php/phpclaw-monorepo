<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Controller;

use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\DatabaseQuery;
use PhpClaw\Joomla\Component\Administrator\Controller\ApiController;
use PhpClaw\Joomla\Component\Administrator\Exceptions\ConversationAccessDeniedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class ApiControllerTest extends TestCase
{
    private string $output = '';

    protected function tearDown(): void
    {
        Factory::$application = null;
        Factory::$container = null;
        Session::$tokenValid = true;
    }

    public function test_the_transport_offers_exactly_the_six_documented_tasks(): void
    {
        $tasks = [];
        foreach ((new \ReflectionClass(ApiController::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === ApiController::class) {
                $tasks[] = $method->getName();
            }
        }
        sort($tasks);

        $this->assertSame(
            ['enablePlugin', 'loadConversation', 'saveSettings', 'send', 'stream', 'testConnection'],
            $tasks,
            'A new public task widens the admin transport without review.',
        );
    }

    public function test_a_chat_action_with_a_bad_csrf_token_answers_403_without_running(): void
    {
        $app = $this->bindApplication(canChat: true);
        Session::$tokenValid = false;
        $ran = false;

        $this->invoke('runAction', function () use (&$ran): void {
            $ran = true;
        });

        $this->assertFalse($ran);
        $this->assertSame(['403'], $app->statuses);
        $this->assertSame([], $app->identity->asked);
        $this->assertStringContainsString('JINVALID_TOKEN', $this->output);
    }

    public function test_a_chat_action_without_the_chat_grant_answers_403_without_running(): void
    {
        $app = $this->bindApplication(canChat: false);
        $ran = false;

        $this->invoke('runAction', function () use (&$ran): void {
            $ran = true;
        });

        $this->assertFalse($ran);
        $this->assertSame(['403'], $app->statuses);
        $this->assertSame(['phpclaw.chat.use@com_phpclaw'], $app->identity->asked);
        $this->assertStringContainsString('JERROR_ALERTNOAUTHOR', $this->output);
    }

    public function test_a_chat_action_runs_with_a_valid_token_and_the_chat_grant(): void
    {
        $app = $this->bindApplication(canChat: true);
        $ran = false;

        $this->invoke('runAction', function () use (&$ran): void {
            $ran = true;
        });

        $this->assertTrue($ran);
        $this->assertSame([], $app->statuses);
    }

    public function test_another_users_conversation_answers_403_not_500(): void
    {
        $app = $this->bindApplication(canChat: true);

        $this->invoke('runAction', function (): void {
            throw new ConversationAccessDeniedException('not yours');
        });

        $this->assertSame(['403'], $app->statuses);
        $this->assertStringContainsString('JERROR_ALERTNOAUTHOR', $this->output);
    }

    public function test_any_other_failure_in_a_chat_action_answers_500_without_the_message(): void
    {
        $app = $this->bindApplication(canChat: true);

        $this->invoke('runAction', function (): void {
            throw new \RuntimeException('secret internal detail');
        });

        $this->assertSame(['500'], $app->statuses);
        $this->assertStringContainsString('COM_PHPCLAW_ERROR_INTERNAL', $this->output);
        $this->assertStringNotContainsString('secret internal detail', $this->output);
    }

    public function test_an_admin_action_with_a_bad_csrf_token_answers_403_without_running(): void
    {
        $app = $this->bindApplication(isAdmin: true);
        Session::$tokenValid = false;
        $ran = false;

        $this->invoke('runAdminAction', function () use (&$ran): void {
            $ran = true;
        });

        $this->assertFalse($ran);
        $this->assertSame(['403'], $app->statuses);
        $this->assertSame([], $app->identity->asked);
    }

    public function test_an_admin_action_by_a_non_admin_answers_403_without_running(): void
    {
        $app = $this->bindApplication(canChat: true, isAdmin: false);
        $ran = false;

        $this->invoke('runAdminAction', function () use (&$ran): void {
            $ran = true;
        });

        $this->assertFalse($ran);
        $this->assertSame(['403'], $app->statuses);
        $this->assertSame(['core.admin@'], $app->identity->asked);
    }

    public function test_an_admin_action_runs_for_a_super_user(): void
    {
        $app = $this->bindApplication(isAdmin: true);
        $ran = false;

        $this->invoke('runAdminAction', function () use (&$ran): void {
            $ran = true;
        });

        $this->assertTrue($ran);
        $this->assertSame([], $app->statuses);
    }

    #[DataProvider('jsonEndpoints')]
    public function test_every_json_endpoint_refuses_a_bad_csrf_token_before_reading_input(string $endpoint): void
    {
        $app = $this->bindApplication(canChat: true, isAdmin: true, post: ['conversation_id' => 'c1']);
        Session::$tokenValid = false;

        $this->invoke($endpoint);

        $this->assertSame(['403'], $app->statuses);
        $this->assertSame(0, $app->input->reads);
    }

    #[DataProvider('adminEndpoints')]
    public function test_every_admin_endpoint_refuses_a_non_admin_before_touching_the_database(string $endpoint): void
    {
        $app = $this->bindApplication(canChat: true, isAdmin: false, post: ['provider' => 'openai']);

        $this->invoke($endpoint);

        $this->assertSame(['403'], $app->statuses);
        $this->assertSame(0, $app->input->reads);
        $this->assertNull(Factory::$container);
    }

    public function test_load_conversation_refuses_a_user_without_the_chat_grant(): void
    {
        $app = $this->bindApplication(canChat: false, post: ['conversation_id' => 'c1']);

        $this->invoke('loadConversation');

        $this->assertSame(['403'], $app->statuses);
        $this->assertSame(0, $app->input->reads);
    }

    #[RunInSeparateProcess]
    public function test_send_refuses_a_bad_csrf_token_before_reading_the_message(): void
    {
        $app = $this->bindApplication(canChat: true, post: ['message' => 'hi']);
        Session::$tokenValid = false;

        $this->invokeUnbuffered('send');

        $this->assertSame(['403'], $app->statuses);
        $this->assertSame(0, $app->input->reads);
    }

    #[RunInSeparateProcess]
    public function test_stream_refuses_a_bad_csrf_token_before_checking_the_grant(): void
    {
        $app = $this->bindApplication(canChat: true, post: ['message' => 'hi']);
        Session::$tokenValid = false;

        $this->invokeUnbuffered('stream');

        $this->assertSame(1, $app->closed);
        $this->assertSame([], $app->identity->asked);
        $this->assertSame(0, $app->input->reads);
    }

    #[RunInSeparateProcess]
    public function test_stream_refuses_a_user_without_the_chat_grant_before_reading_the_message(): void
    {
        $app = $this->bindApplication(canChat: false, post: ['message' => 'hi']);

        $this->invokeUnbuffered('stream');

        $this->assertSame(1, $app->closed);
        $this->assertSame(['phpclaw.chat.use@com_phpclaw'], $app->identity->asked);
        $this->assertSame(0, $app->input->reads);
    }

    public function test_save_settings_keeps_stored_secrets_when_sent_empty_or_masked_and_writes_the_rest(): void
    {
        $this->bindApplication(isAdmin: true, post: [
            'api_key' => '***1234',
            'cloud_key' => '',
            'cloud_signing_secret' => 'PHPCLAW_CLOUD_SIGNING_SECRET=new-secret',
            'store_messages' => '0',
            'cloud_disable' => 'scan, hide_outputs',
        ]);
        $saved = $this->bindDatabase([
            'api_key' => 'sk-stored',
            'cloud_key' => 'ck-stored',
            'cloud_signing_secret' => 'ss-stored',
            'provider' => 'openai',
        ]);

        $this->invoke('saveSettings');

        $this->assertSame([
            'api_key' => 'sk-stored',
            'cloud_key' => 'ck-stored',
            'cloud_signing_secret' => 'new-secret',
            'provider' => 'openai',
            'store_messages' => '0',
            'cloud_disable' => 'scan,hide_outputs',
        ], $saved->params);
        $response = json_decode($this->output, true);
        $this->assertSame(['cloud_signing_secret', 'store_messages', 'cloud_disable'], $response['data']['written']);
        $this->assertSame(['api_key', 'cloud_key'], $response['data']['skipped_secrets']);
    }

    public function test_cloud_disable_accepts_every_feature_and_hide_name(): void
    {
        $app = $this->bindApplication();

        $saved = $this->invoke('validatedCloudDisable', ' scan, observability ,hide_inputs,hide_outputs, hide_metadata,');

        $this->assertSame('scan,observability,hide_inputs,hide_outputs,hide_metadata', $saved);
        $this->assertSame([], $app->statuses);
        $this->assertSame(0, $app->closed);
    }

    public function test_cloud_disable_rejects_a_misspelled_hide_name_with_a_400_listing_every_name(): void
    {
        $app = $this->bindApplication();

        $this->invoke('validatedCloudDisable', 'hide_inputs,hide_input');

        $this->assertSame(['400'], $app->statuses);
        $this->assertSame(1, $app->closed);
        $this->assertMatchesRegularExpression(
            '/cloud_disable accepts only scan, observability, hide_inputs, hide_outputs, hide_metadata\\./',
            $this->output,
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function jsonEndpoints(): array
    {
        return [
            'loadConversation' => ['loadConversation'],
            'saveSettings' => ['saveSettings'],
            'enablePlugin' => ['enablePlugin'],
            'testConnection' => ['testConnection'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function adminEndpoints(): array
    {
        return [
            'saveSettings' => ['saveSettings'],
            'enablePlugin' => ['enablePlugin'],
            'testConnection' => ['testConnection'],
        ];
    }

    private function bindApplication(bool $canChat = false, bool $isAdmin = false, array $post = []): object
    {
        $identity = new class($canChat, $isAdmin)
        {
            public array $asked = [];

            public function __construct(private bool $canChat, private bool $isAdmin) {}

            public function authorise(string $action, ?string $asset = null): bool
            {
                $this->asked[] = $action.'@'.$asset;

                return $action === 'core.admin' ? $this->isAdmin : $this->canChat;
            }
        };

        $input = new class($post)
        {
            public int $reads = 0;

            public object $post;

            public function __construct(array $values)
            {
                $this->post = new class($values, $this)
                {
                    public function __construct(private array $values, private object $owner) {}

                    public function getString(string $key, string $default = ''): string
                    {
                        $this->owner->reads++;

                        return (string) ($this->values[$key] ?? $default);
                    }

                    public function getArray(): array
                    {
                        $this->owner->reads++;

                        return $this->values;
                    }
                };
            }
        };

        $app = new class($identity, $input)
        {
            public array $statuses = [];

            public int $closed = 0;

            public function __construct(public object $identity, public object $input) {}

            public function getIdentity(): object
            {
                return $this->identity;
            }

            public function getInput(): object
            {
                return $this->input;
            }

            public function setHeader(string $name, string $value): void
            {
                if ($name === 'Status') {
                    $this->statuses[] = $value;
                }
            }

            public function sendHeaders(): void {}

            public function close(): void
            {
                $this->closed++;
            }
        };
        Factory::$application = $app;

        return $app;
    }

    private function bindDatabase(array $stored): object
    {
        $saved = new class
        {
            public ?array $params = null;
        };

        $query = $this->createMock(DatabaseQuery::class);
        foreach (['select', 'from', 'update', 'where'] as $fluent) {
            $query->method($fluent)->willReturnSelf();
        }
        $query->method('set')->willReturnCallback(function (string $assignment) use ($saved, $query): DatabaseQuery {
            $saved->params = json_decode(substr($assignment, strpos($assignment, "'") + 1, -1), true);

            return $query;
        });

        $db = $this->createMock(DatabaseInterface::class);
        $db->method('getQuery')->willReturn($query);
        $db->method('quoteName')->willReturnCallback(static fn (string $name): string => $name);
        $db->method('quote')->willReturnCallback(static fn (string $text): string => "'".$text."'");
        $db->method('setQuery')->willReturnSelf();
        $db->method('loadResult')->willReturn(json_encode($stored));
        $db->method('execute')->willReturn(true);

        Factory::$container = new class($db)
        {
            public function __construct(private object $db) {}

            public function get(string $id): object
            {
                return $this->db;
            }
        };

        return $saved;
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $controller = (new \ReflectionClass(ApiController::class))->newInstanceWithoutConstructor();
        $level = ob_get_level();
        ob_start();
        ob_start();

        try {
            return (new \ReflectionMethod(ApiController::class, $method))->invoke($controller, ...$args);
        } finally {
            $this->output = '';
            while (ob_get_level() > $level) {
                $this->output = (string) ob_get_clean().$this->output;
            }
        }
    }

    private function invokeUnbuffered(string $method): void
    {
        $controller = (new \ReflectionClass(ApiController::class))->newInstanceWithoutConstructor();
        $level = ob_get_level();

        try {
            $controller->{$method}();
        } finally {
            while (ob_get_level() < $level) {
                ob_start();
            }
        }
    }
}
