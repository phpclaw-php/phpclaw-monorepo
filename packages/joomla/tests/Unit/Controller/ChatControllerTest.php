<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Controller;

use Joomla\CMS\Factory;
use PhpClaw\Joomla\Component\Api\Controller\ChatController;
use PHPUnit\Framework\TestCase;

final class ChatControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Factory::$application = null;
    }

    private function controller(): ChatController
    {
        return (new \ReflectionClass(ChatController::class))->newInstanceWithoutConstructor();
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $reflected = new \ReflectionMethod(ChatController::class, $method);
        $reflected->setAccessible(true);

        return $reflected->invoke($this->controller(), ...$args);
    }

    private function bindApplication(?string $username, bool $authorises, array $json = [], array $post = []): void
    {
        $identity = $username === null ? null : new class($authorises)
        {
            public array $asked = [];

            public function __construct(private bool $authorises) {}

            public function authorise(string $action, string $asset): bool
            {
                $this->asked[] = $action.'@'.$asset;

                return $this->authorises;
            }
        };

        Factory::$application = new class($identity, $json, $post)
        {
            public object $input;

            public function __construct(private ?object $identity, array $json, array $post)
            {
                $this->input = new class($json, $post)
                {
                    public object $json;

                    public object $post;

                    public function __construct(array $json, array $post)
                    {
                        $bag = static fn (array $values): object => new class($values)
                        {
                            public function __construct(private array $values) {}

                            public function getString(string $key, string $default = ''): string
                            {
                                return (string) ($this->values[$key] ?? $default);
                            }
                        };

                        $this->json = $bag($json);
                        $this->post = $bag($post);
                    }
                };
            }

            public function getIdentity(): ?object
            {
                return $this->identity;
            }

            public function getInput(): object
            {
                return $this->input;
            }
        };
    }

    public function test_an_empty_message_is_rejected(): void
    {
        self::assertSame('COM_PHPCLAW_ERROR_EMPTY_MESSAGE', $this->invoke('messageRejection', ''));
    }

    public function test_a_message_over_the_limit_is_rejected(): void
    {
        self::assertSame(
            'COM_PHPCLAW_ERROR_MESSAGE_TOO_LONG',
            $this->invoke('messageRejection', str_repeat('a', 40001)),
        );
    }

    public function test_a_message_on_the_limit_is_accepted(): void
    {
        self::assertSame('', $this->invoke('messageRejection', str_repeat('a', 40000)));
    }

    public function test_an_ordinary_message_is_accepted(): void
    {
        self::assertSame('', $this->invoke('messageRejection', 'how many articles?'));
    }

    public function test_the_length_limit_counts_characters_not_bytes(): void
    {
        self::assertSame('', $this->invoke('messageRejection', str_repeat('é', 40000)));
    }

    public function test_a_json_body_is_read(): void
    {
        $this->bindApplication('admin', true, json: ['message' => 'from json']);

        self::assertSame('from json', $this->invoke('requestString', 'message'));
    }

    public function test_a_form_body_is_read_when_no_json_body_is_present(): void
    {
        $this->bindApplication('admin', true, post: ['message' => 'from form']);

        self::assertSame('from form', $this->invoke('requestString', 'message'));
    }

    public function test_a_json_body_wins_over_a_form_body(): void
    {
        $this->bindApplication('admin', true, json: ['message' => 'json'], post: ['message' => 'form']);

        self::assertSame('json', $this->invoke('requestString', 'message'));
    }

    public function test_a_read_value_is_trimmed(): void
    {
        $this->bindApplication('admin', true, json: ['message' => "  padded \n"]);

        self::assertSame('padded', $this->invoke('requestString', 'message'));
    }

    public function test_a_missing_key_reads_as_an_empty_string(): void
    {
        $this->bindApplication('admin', true);

        self::assertSame('', $this->invoke('requestString', 'conversation_id'));
    }

    public function test_a_granted_identity_may_chat(): void
    {
        $this->bindApplication('manager1', true);

        self::assertTrue($this->invoke('callerMayChat'));
    }

    public function test_an_ungranted_identity_may_not_chat(): void
    {
        $this->bindApplication('publisher', false);

        self::assertFalse($this->invoke('callerMayChat'));
    }

    public function test_an_absent_identity_may_not_chat(): void
    {
        $this->bindApplication(null, false);

        self::assertFalse($this->invoke('callerMayChat'));
    }

    public function test_the_capability_is_checked_on_the_component_asset(): void
    {
        $this->bindApplication('manager1', true);

        $this->invoke('callerMayChat');

        self::assertSame(
            ['phpclaw.chat.use@com_phpclaw'],
            Factory::$application->getIdentity()->asked,
        );
    }
}
