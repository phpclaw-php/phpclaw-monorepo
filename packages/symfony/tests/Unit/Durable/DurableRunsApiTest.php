<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Durable;

use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\Agent\RunStatus;
use PhpClaw\Claw;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\RunSuspendedException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Symfony\Http\ApiController;
use PhpClaw\Symfony\Http\RunsController;
use PhpClaw\Symfony\Http\StreamEventBridge;
use PhpClaw\Symfony\Memory\DoctrineMemory;
use PhpClaw\Symfony\Memory\DoctrineRouterMemory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class DurableRunsApiTest extends TestCase
{
    use DurableFixture;

    private Claw $claw;

    protected function setUp(): void
    {
        $this->bootDurableFixture();
        $this->claw = $this->durableClaw(ScriptedProvider::refundPlan());
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    private function json(array $body): Request
    {
        return Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($body));
    }

    private function decode(JsonResponse $response): array
    {
        return (array) json_decode((string) $response->getContent(), true);
    }

    private function api(?ClawInterface $agent = null): ApiController
    {
        return new ApiController($agent ?? $this->claw, new StreamEventBridge, $this->approvals());
    }

    private function runs(bool $durableRuns = true, ?ClawInterface $agent = null): RunsController
    {
        return new RunsController($agent ?? $this->claw, $this->approvals(), $durableRuns);
    }

    private function pauseAs(string $userId): array
    {
        $this->actAsUser($userId);
        $response = $this->api()->send($this->json(['message' => 'refund order 7']));
        self::assertSame(202, $response->getStatusCode());

        return $this->decode($response);
    }

    public function test_send_pauses_a_mutating_call_and_writes_nothing_to_the_conversation(): void
    {
        $paused = $this->pauseAs('alice');

        self::assertSame('awaiting_approval', $paused['status']);
        self::assertSame(['call_id' => 'c1', 'tool_name' => 'refund', 'tool_input' => ['order' => 7]], $paused['pending']);
        self::assertSame([], CountingTool::$runs);
        self::assertSame([], $this->messageRoles($paused['conversation_id']));
    }

    public function test_the_owner_approves_and_the_run_finishes_in_the_same_request(): void
    {
        $paused = $this->pauseAs('alice');

        $response = $this->runs()->approve($this->json(['call_id' => 'c1']), $paused['run_id']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'completed', 'conversation_id' => $paused['conversation_id'], 'text' => 'refund handled'], array_intersect_key($this->decode($response), ['status' => 1, 'conversation_id' => 1, 'text' => 1]));
        self::assertSame(['refund' => 1], CountingTool::$runs);
        self::assertSame(['user', 'assistant'], $this->messageRoles($paused['conversation_id']));
    }

    public function test_the_owner_denies_and_the_tool_never_runs(): void
    {
        $paused = $this->pauseAs('alice');

        $response = $this->runs()->deny($this->json(['call_id' => 'c1', 'reason' => 'not today']), $paused['run_id']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('completed', $this->decode($response)['status']);
        self::assertSame([], CountingTool::$runs);
    }

    public function test_another_user_cannot_approve_and_the_run_stays_paused(): void
    {
        $paused = $this->pauseAs('alice');

        $this->actAsUser('bob');
        self::assertSame(403, $this->runs()->approve($this->json(['call_id' => 'c1']), $paused['run_id'])->getStatusCode());

        self::assertSame([], CountingTool::$runs);
        $this->actAsUser('alice');
        self::assertCount(1, $this->decode($this->runs()->index())['runs']);
    }

    public function test_a_manage_all_user_can_approve_another_users_run(): void
    {
        $paused = $this->pauseAs('alice');

        $this->actAsUser('admin', manageAll: true);
        self::assertSame(200, $this->runs()->approve($this->json(['call_id' => 'c1']), $paused['run_id'])->getStatusCode());

        self::assertSame(['refund' => 1], CountingTool::$runs);
        self::assertSame(['user', 'assistant'], $this->messageRoles($paused['conversation_id']));
    }

    public function test_the_pending_list_shows_only_the_users_own_runs(): void
    {
        $paused = $this->pauseAs('alice');

        self::assertSame($paused['run_id'], $this->decode($this->runs()->index())['runs'][0]['run_id']);
        $this->actAsUser('bob');
        self::assertSame([], $this->decode($this->runs()->index())['runs']);
    }

    public function test_a_wrong_call_id_an_unknown_run_or_a_missing_call_id_is_refused(): void
    {
        $paused = $this->pauseAs('alice');

        self::assertSame(409, $this->runs()->approve($this->json(['call_id' => 'nope']), $paused['run_id'])->getStatusCode());
        $unknown = $this->runs()->approve($this->json(['call_id' => 'c1']), '01JUNKNOWNRUN0000000000000');
        self::assertSame(404, $unknown->getStatusCode());
        self::assertSame('No saved run with that id.', $this->decode($unknown)['error']);
        self::assertSame(422, $this->runs()->approve($this->json([]), $paused['run_id'])->getStatusCode());
        self::assertSame([], CountingTool::$runs);
    }

    public function test_the_stream_reports_the_pause_as_an_event(): void
    {
        $this->actAsUser('alice');
        $response = $this->api()->stream($this->json(['message' => 'refund order 7']));

        ob_start();
        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();
        $body .= (string) ob_get_clean();

        self::assertStringContainsString("event: approval_required\n", $body);
        self::assertStringContainsString('"tool_name":"refund"', $body);
        self::assertSame([], CountingTool::$runs);
    }

    public function test_a_run_that_spends_its_step_budget_reports_suspended(): void
    {
        $this->claw = $this->durableClaw(ScriptedProvider::lookupPlan(2), stepBudget: 1);
        $this->actAsUser('alice');

        $response = $this->api()->send($this->json(['message' => 'look twice']));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['status' => 'suspended', 'pending' => null], array_intersect_key($this->decode($response), ['status' => 1, 'pending' => 1]));
    }

    public function test_approving_a_run_that_pauses_again_answers_with_the_next_paused_call(): void
    {
        $this->claw = $this->durableClaw(ScriptedProvider::twoRefundPlan());
        $paused = $this->pauseAs('alice');

        $response = $this->runs()->approve($this->json(['call_id' => 'c1']), $paused['run_id']);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('c2', $this->decode($response)['pending']['call_id']);
        self::assertSame(['refund' => 1], CountingTool::$runs);
    }

    public function test_with_durable_runs_off_the_run_routes_answer_not_found(): void
    {
        $this->actAsUser('alice');

        self::assertSame(404, $this->runs(durableRuns: false)->index()->getStatusCode());
        self::assertSame(404, $this->runs(durableRuns: false)->approve($this->json(['call_id' => 'c1']), '01JUNKNOWNRUN0000000000000')->getStatusCode());
    }

    public function test_an_engine_that_is_not_a_claw_cannot_serve_runs(): void
    {
        $agent = $this->createMock(ClawInterface::class);
        $this->actAsUser('alice');

        self::assertSame(501, $this->runs(agent: $agent)->index()->getStatusCode());
        self::assertSame(501, $this->runs(agent: $agent)->approve($this->json(['call_id' => 'c1']), '01JUNKNOWNRUN0000000000000')->getStatusCode());
    }

    public function test_a_pause_from_an_engine_that_is_not_a_claw_is_still_reported(): void
    {
        $agent = $this->createMock(ClawInterface::class);
        $agent->method('conversation')->willReturn($this->claw->conversation());
        $agent->method('sendInConversation')->willThrowException(new RunSuspendedException('01JFAKERUN000000000000000000', RunStatus::Suspended));
        $this->actAsUser('alice');

        $response = $this->api($agent)->send($this->json(['message' => 'go']));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['run_id' => '01JFAKERUN000000000000000000', 'status' => 'suspended', 'conversation_id' => null, 'pending' => null], $this->decode($response));
    }

    public function test_a_run_saved_by_another_request_meanwhile_is_a_conflict(): void
    {
        $paused = $this->pauseAs('alice');
        $this->conflicting->armed = true;

        self::assertSame(409, $this->runs()->approve($this->json(['call_id' => 'c1']), $paused['run_id'])->getStatusCode());
        self::assertSame([], CountingTool::$runs);
    }

    public function test_a_failure_while_resuming_is_a_generic_error(): void
    {
        $this->claw = $this->durableClaw([ScriptedProvider::refundPlan()[0], 'throw']);
        $paused = $this->pauseAs('alice');

        $response = $this->runs()->approve($this->json(['call_id' => 'c1']), $paused['run_id']);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('An internal error occurred. Please try again.', $this->decode($response)['error']);
    }

    public function test_with_durable_runs_off_a_web_request_is_refused_as_before_and_saves_no_run(): void
    {
        $this->claw = Claw::builder()
            ->providerOverride(new ScriptedProvider(ScriptedProvider::refundPlan()))
            ->memory(new DoctrineRouterMemory($this->conversations, new DoctrineMemory($this->connection)))
            ->useDefaultGuards(false)
            ->tools([new CountingTool('refund')])
            ->approvalGate(new CliApprovalGate)
            ->build();
        $this->actAsUser('alice');

        $response = $this->api()->send($this->json(['message' => 'refund order 7']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], CountingTool::$runs);
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM phpclaw_memory WHERE namespace = 'runs'"));
    }
}
