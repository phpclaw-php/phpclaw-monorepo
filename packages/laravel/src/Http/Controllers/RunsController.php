<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\RunState;
use PhpClaw\Agent\RunStatus;
use PhpClaw\Claw;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\RunConflictException;
use PhpClaw\Exceptions\RunStateException;
use PhpClaw\Exceptions\RunSuspendedException;
use PhpClaw\Laravel\RunApprovals;

/**
 * REST endpoints that list runs paused for approval and approve or deny one, resuming it.
 */
final class RunsController extends Controller
{
    private const NOT_DURABLE = 'Durable runs are not available on this engine.';

    private const NOT_FOUND = 'No saved run with that id.';

    private const FORBIDDEN = 'You do not have permission to decide this run.';

    private const NO_SUCH_CALL = 'This run has no paused call with that id waiting for a decision.';

    private const CONFLICT = 'Another request changed this run first. Reload and try again.';

    private const INTERNAL_ERROR = 'An internal error occurred. Please try again.';

    /**
     * Build the controller.
     *
     * @param  PhpClawInterface  $agent  The bound engine; durable runs need the concrete Claw.
     * @return void
     */
    public function __construct(
        private readonly PhpClawInterface $agent,
    ) {}

    /**
     * List the runs waiting for a decision that the acting user may decide.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        if (! $this->agent instanceof Claw) {
            return response()->json(['error' => self::NOT_DURABLE], 501);
        }

        $runs = array_values(array_filter($this->agent->pendingApprovals(), RunApprovals::canDecide(...)));

        return response()->json(['runs' => array_map(RunApprovals::describe(...), $runs)]);
    }

    /**
     * Approve the paused call and finish the run.
     *
     * @param  Request  $request  Carries call_id.
     * @param  string  $runId  Run id from the path.
     * @return JsonResponse
     */
    public function approve(Request $request, string $runId): JsonResponse
    {
        $data = $request->validate(['call_id' => 'required|string|max:200']);

        return $this->decide($runId, (string) $data['call_id'], denyReason: null);
    }

    /**
     * Deny the paused call and finish the run; the model is told the call was refused.
     *
     * @param  Request  $request  Carries call_id and an optional reason.
     * @param  string  $runId  Run id from the path.
     * @return JsonResponse
     */
    public function deny(Request $request, string $runId): JsonResponse
    {
        $data = $request->validate(['call_id' => 'required|string|max:200', 'reason' => 'nullable|string|max:500']);

        return $this->decide($runId, (string) $data['call_id'], denyReason: (string) ($data['reason'] ?? ''));
    }

    /**
     * Record the decision as the owner or a manage-all user, resume the run and answer with its result.
     *
     * @param  string  $runId  Run id.
     * @param  string  $callId  Paused call id.
     * @param  string|null  $denyReason  Null approves; a string denies.
     * @return JsonResponse
     */
    private function decide(string $runId, string $callId, ?string $denyReason): JsonResponse
    {
        if (! $this->agent instanceof Claw) {
            return response()->json(['error' => self::NOT_DURABLE], 501);
        }

        try {
            $state = RunApprovals::load($this->agent, $runId);
        } catch (RunStateException) {
            return response()->json(['error' => self::NOT_FOUND], 404);
        }

        if (! RunApprovals::canDecide($state)) {
            return response()->json(['error' => self::FORBIDDEN], 403);
        }

        try {
            $response = RunApprovals::decide($this->agent, $state, $callId, $denyReason);
        } catch (RunSuspendedException $e) {
            return response()->json(RunApprovals::describe(RunApprovals::load($this->agent, $e->runId)), 202);
        } catch (RunStateException) {
            return response()->json(['error' => self::NO_SUCH_CALL], 409);
        } catch (RunConflictException) {
            return response()->json(['error' => self::CONFLICT], 409);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => self::INTERNAL_ERROR], 500);
        }

        return $this->completed($state, $response);
    }

    /**
     * The 200 body for a run that finished after the decision.
     *
     * @param  RunState  $state  The decided run.
     * @param  AgentResponse  $response  The finished answer.
     * @return JsonResponse
     */
    private function completed(RunState $state, AgentResponse $response): JsonResponse
    {
        return response()->json([
            'status' => RunStatus::Completed->value,
            'run_id' => $state->runId(),
            'conversation_id' => $state->task->conversationId,
            'text' => $response->text,
            'provider' => $response->provider,
            'model' => $response->model,
            'iterations' => $response->iterations,
            'tokens' => ($response->inputTokens ?? 0) + ($response->outputTokens ?? 0),
        ]);
    }
}
