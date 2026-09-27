<?php

declare(strict_types=1);

namespace PhpClaw\Tools;

use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tools\Concerns\HasCoreToolBinding;
use PhpClaw\Tools\Contracts\AuthorizableToolInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;

/**
 * Lets the model read a registered skill's full instructions by name, the way LangChain Deep Agents load a SKILL.md.
 */
final class LoadSkillTool implements AuthorizableToolInterface, ToolInterface, ToolRoutingInterface
{
    use HasCoreToolBinding;

    public const NAME = 'load_skill';

    /**
     * Build the Skills System section for the system prompt: every registered skill's name and description, and how to load one.
     *
     * @return string The section, or an empty string when no skill is registered.
     */
    public static function systemPromptSection(): string
    {
        if (SkillRegistry::count() === 0) {
            return '';
        }

        $lines = [];
        foreach (SkillRegistry::all() as $skill) {
            $lines[] = '- **'.$skill->name().'**: '.$skill->description();
            $lines[] = '  -> Call `load_skill` with name `'.$skill->name().'` for full instructions';
        }

        return "\n\n## Skills System\n\nYou have access to a skills library that provides specialized capabilities and domain knowledge.\n\n**Available Skills:**\n\n".implode("\n", $lines)
            ."\n\n**How to Use Skills (Progressive Disclosure):**\n\nSkills follow a **progressive disclosure** pattern - you see their name and description above, but only read full instructions when needed:\n\n"
            ."1. **Recognize when a skill applies**: Check if the user's task matches a skill's description\n"
            ."2. **Read the skill's full instructions**: Call `load_skill` with the skill name shown in the skill list above.\n"
            ."3. **Follow the skill's instructions**: The skill contains step-by-step workflows, best practices, and examples\n\n"
            ."**When to Use Skills:**\n\n- User's request matches a skill's domain (e.g., \"research X\" -> web-research skill)\n- You need specialized knowledge or structured workflows\n- A skill provides proven patterns for complex tasks\n\n"
            ."**Example Workflow:**\n\nUser: \"Can you research the latest developments in quantum computing?\"\n\n"
            ."1. Check available skills -> See \"web-research\" skill\n2. Read the full skill: `load_skill(name=\"web-research\")`\n3. Follow the skill's research workflow (search -> organize -> synthesize)\n\n"
            .'Remember: Skills make you more capable and consistent. When in doubt, check if a skill exists for the task!';
    }

    /**
     * Return the tool name the model calls.
     *
     * @return string The tool name.
     */
    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Return the one-line description sent to the model.
     *
     * @return string The description.
     */
    public function description(): string
    {
        return 'Read the full instructions of a skill listed in the Skills System section of the system prompt.';
    }

    /**
     * Return the input schema: one required skill name.
     *
     * @return array<string, mixed> The JSON schema.
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string', 'description' => 'Exact skill name']],
            'required' => ['name'],
        ];
    }

    /**
     * Authorize the caller and reject any argument other than the skill name.
     *
     * @param  array<string, mixed>  $input  Tool arguments; `name` is the skill name.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $refused = $this->guardCapability('read a skill') ?? $this->rejectUnknownArguments($input, ['name']);

        return ['input' => ['name' => (string) ($input['name'] ?? '')], 'result' => $refused];
    }

    /**
     * Find the registered skill with exactly the requested name.
     *
     * @param  array<string, mixed>  $input  Validated input.
     * @return array<string, mixed> The matched skill under `skill`, or null when no skill has that name.
     */
    protected function perform(array $input): array
    {
        foreach (SkillRegistry::all() as $skill) {
            if ($skill->name() === $input['name']) {
                return ['skill' => $skill];
            }
        }

        return ['skill' => null];
    }

    /**
     * Return an UNKNOWN_SKILL error listing the registered names when no skill matched.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     */
    protected function verify(array $execution, array $input): array
    {
        if ($execution['skill'] instanceof SkillInterface) {
            return ['result' => null];
        }

        return ['result' => $this->error(
            'UNKNOWN_SKILL',
            sprintf('No skill is named "%s". Call load_skill with one of the available names.', $input['name']),
            ['available' => SkillRegistry::names()],
        )];
    }

    /**
     * Return the skill's name and full instructions in the success envelope.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded success envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        return $this->success(
            ['name' => $execution['skill']->name(), 'instructions' => $execution['skill']->content()],
            ['mode' => self::NAME],
        );
    }

    /**
     * Describe the skill-reading domain for the router.
     *
     * @return ToolRoutingMetadata
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return new ToolRoutingMetadata(
            domains: ['skills'],
            tags: ['skill', 'instructions', 'workflow'],
            intents: ['read skill instructions'],
            examples: ['load the skill named in the system prompt'],
        );
    }
}
