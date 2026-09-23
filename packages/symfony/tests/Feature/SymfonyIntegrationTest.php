<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Feature;

use PhpClaw\Claw as PhpClaw;
use PhpClaw\Symfony\Command\PhpClawCommand;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\LazyCommand;

/**
 * Feature tests that boot the Symfony kernel; only the send test reaches a real provider, and it skips itself without an API key.
 */
#[Group('feature')]
final class SymfonyIntegrationTest extends KernelTestCase
{
    private function requireApiKey(): void
    {
        if (! getenv('ANTHROPIC_API_KEY') && ! getenv('OPENAI_API_KEY') && ! getenv('GROQ_API_KEY') && ! getenv('GEMINI_API_KEY')) {
            $this->markTestSkipped('No LLM API key found in environment. Set ANTHROPIC_API_KEY, OPENAI_API_KEY, GROQ_API_KEY, or GEMINI_API_KEY to run feature tests.');
        }
    }

    public function test_phpclaw_command_is_registered_in_application(): void
    {
        $application = new Application(self::bootKernel());

        $this->assertTrue(
            $application->has('phpclaw'),
            'The "phpclaw" console command must be registered.',
        );

        $command = $application->find('phpclaw');

        if ($command instanceof LazyCommand) {
            $command = $command->getCommand();
        }

        $this->assertInstanceOf(PhpClawCommand::class, $command);
        $this->assertSame('phpclaw', $command->getName());
    }

    public function test_phpclaw_send_returns_agent_response(): void
    {
        $this->requireApiKey();

        $kernel = self::bootKernel();
        $container = $kernel->getContainer();

        /** @var PhpClaw $phpClaw */
        $phpClaw = $container->get(PhpClaw::class);

        $response = $phpClaw->send('Reply with the single word: PONG');

        $this->assertStringContainsStringIgnoringCase('PONG', $response->text);
        $this->assertNotEmpty($response->provider);
        $this->assertNotEmpty($response->model);
    }
}
