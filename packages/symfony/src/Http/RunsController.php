<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Http;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\RunState;
use PhpClaw\Agent\RunStatus;
use PhpClaw\Claw;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\RunConflictException;
use PhpClaw\Exceptions\RunStateException;
use PhpClaw\Exceptions\RunSuspendedException;
use PhpClaw\Support\Log;
use PhpClaw\Symfony\RunApprovals;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * REST endpoints that list runs paused for approval and approve or deny one, resuming it.
 */
#[AsController]
final class RunsController
{
    private const MAX_CALL_ID_LENGTH = 200;

    private const MAX_REASON_LENGTH = 500;

    private const NOT_FOUND = 'Not found.';

    private const NOT_DURABLE = 'Durable runs are not available on this engine.';

    private const NO_SUCH_RUN = 'No saved run with that id.';

    private const FORBIDDEN = 'You do not have permission to decide this run.';

    private const NO_SUCH_CALL = 'This run has no paused call with that id waiting for a decision.';

    private const CONFLICT = 'Another request changed this run first. Reload and try again.';

    private const INVALID = 'call_id must be a string of at most 200 characters, and reason at most 500.';

    private const INTERNAL_ERROR = 'An internal error occurred. Please try again.';

    /**
     * Bind the engine, the run rules and whether durable runs are on.
     *
     * @param  PhpClawInterface  $agent  The engine web requests use.
     * @param  RunApprovals  $approvals  Owner checks and decisions.
     * @param  bool  $durableRuns  phpclaw.durable_runs; off answers 404 on every route.
     */
    public function __construct(
        private readonly PhpClawInterface $agent,
        private readonly RunApprovals $approvals,
        private readonly bool $durableRuns = false,
    ) {}

    /**
     * GET /phpclaw/runs, the runs waiting for a decision that the acting user may decide.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $unavailable = $this->unavailable();

        if ($unavailable !== null || ! $this->agent instanceof Claw) {
            return $unavailable ?? new JsonResponse(['error' => self::NOT_DURABLE], 501);
        }

        $runs = array_values(array_filter($this->agent->pendingApprovals(), $this->approvals->canDecide(...)));

        return new JsonResponse(['runs' => array_map($this->approvals->describe(...), $runs)]);
    }

    /**
     * POST /phpclaw/runs/{runId}/approve, approve the paused call and finish the run.
     *
     * @param  Request  $request  JSON body with `call_id`.
     * @param  string  $runId  Saved run id.
     * @return JsonResponse
     */
    public function approve(Request $request, string $runId): JsonResponse
    {
        return $this->decide($request, $runId, isDenial: false);
    }

    /**
     * POST /phpclaw/runs/{runId}/deny, deny the paused call and finish the run.
     *
     * @param  Request  $request  JSON body with `call_id` and an optional `reason`.
     * @param  string  $runId  Saved run id.
     * @return JsonResponse
     */
    public function deny(Request $request, string $runId): JsonResponse
    {
        return $this->decide($request, $runId, isDenial: true);
    }

    /**
     * Check the request and the caller, record the decision, resume, and answer with the outcome.
     *
     * @param  Request  $request  JSON body.
     * @param  string  $runId  Saved run id.
     * @param  bool  $isDenial  True to deny, false to approve.
     * @return JsonResponse
     */
    private function decide(Request $request, string $runId, bool $isDenial): JsonResponse
    {
        $unavailable = $this->unavailable();

        if ($unavailable !== null || ! $this->agent instanceof Claw) {
            return $unavailable ?? new JsonResponse(['error' => self::NOT_DURABLE], 501);
        }

        $input = $this->decisionInput($request);

        if ($input === null) {
            return new JsonResponse(['error' => self::INVALID], 422);
        }

        [$callId, $reason] = $input;

        try {
            $state = $this->approvals->load($this->agent, $runId);
        } catch (RunStateException) {
            return new JsonResponse(['error' => self::NO_SUCH_RUN], 404);
        }

        if (! $this->approvals->canDecide($state)) {
            return new JsonResponse(['error' => self::FORBIDDEN], 403);
        }

        try {
            $response = $this->approvals->decide($this->agent, $state, $callId, $isDenial ? $reason : null);
        } catch (RunSuspendedException $e) {
            return new JsonResponse($this->approvals->describe($this->approvals->load($this->agent, $e->runId)), 202);
        } catch (RunStateException) {
            return new JsonResponse(['error' => self::NO_SUCH_CALL], 409);
        } catch (RunConflictException) {
            return new JsonResponse(['error' => self::CONFLICT], 409);
        } catch (\Throwable $e) {
            Log::error('[phpClaw] run decision failed: '.$e::class);

            return new JsonResponse(['error' => self::INTERNAL_ERROR], 500);
        }

        return $this->completed($state, $response);
    }

    /**
     * The call id and reason from the JSON body, or null when either is missing or too long.
     *
     * @param  Request  $request  JSON body with `call_id` and an optional `reason`.
     * @return array{0: string, 1: string}|null
     */
    private function decisionInput(Request $request): ?array
    {
        $body = json_decode((string) $request->getContent(), true);
        $callId = is_array($body) ? ($body['call_id'] ?? null) : null;
        $reason = is_array($body) ? ($body['reason'] ?? '') : '';

        if (! is_string($callId) || $callId === '' || strlen($callId) > self::MAX_CALL_ID_LENGTH || ! is_string($reason) || strlen($reason) > self::MAX_REASON_LENGTH) {
            return null;
        }

        return [$callId, $reason];
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
        return new JsonResponse([
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

    /**
     * A 404 when durable runs are off, so the routes behave as if they did not exist; null otherwise.
     *
     * @return JsonResponse|null
     */
    private function unavailable(): ?JsonResponse
    {
        return $this->durableRuns ? null : new JsonResponse(['error' => self::NOT_FOUND], 404);
    }
}
