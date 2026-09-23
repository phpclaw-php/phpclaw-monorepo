<?php

declare(strict_types=1);

namespace PhpClaw\Skills;

use PhpClaw\AutoDiscovery\Attributes\Skill;
use PhpClaw\Skills\Contracts\SkillInterface;

/**
 * Injects PHP coding-standards context when the message contains relevant keywords.
 */
#[Skill(
    name: 'php_best_practices',
    label: 'PHP Best Practices',
    keywords: ['php', 'code', 'review', 'refactor', 'best', 'practices', 'clean', 'quality', 'standard', 'style', 'lint', 'analyse', 'class', 'method'],
    since: '1.0.0',
)]
final class PhpBestPracticesSkill implements SkillInterface
{
    private const DEFAULT_TAGS = [
        'php', 'code', 'review', 'refactor', 'best', 'practices',
        'clean', 'quality', 'standard', 'style', 'lint', 'analyse',
        'class', 'method',
    ];

    private const DEFAULT_CONTENT = <<<'SKILL'
## PHP Best Practices (apply when reviewing or writing PHP code)

- `declare(strict_types=1)` must be the first statement in every PHP file.
- Classes must be `final` by default; open for extension only via interfaces.
- Use constructor property promotion to eliminate boilerplate.
- Every method and property must have an explicit return type; never omit it.
- Never use bare `array`: use typed arrays (`string[]`), value objects, or collections.
- Constants belong in dedicated constant files; never hardcode string literals.
- No static state unless it is a registry with a `reset()` method for tests.
- Exceptions must extend the package's base exception class.
- Unit tests must mock all I/O (HTTP, DB, filesystem), no real network calls.
- Each public API method needs at least one happy-path and one error-path test.
- `declare(strict_types=1)` + `final` + explicit return types is the minimum bar for any new class.
SKILL;

    private readonly array $tags;

    private readonly string $renderedContent;

    /**
     * Build the PHP best-practices skill with optional tag/rule extensions or full content override.
     *
     * @param  string  $name  Unique machine-readable identifier.
     * @param  string  $description  Short human-readable description.
     * @param  list<string>  $extraTags  Tags appended to the default 14 keywords.
     * @param  list<string>  $extraRules  Additional rules appended to the default content.
     * @param  string|null  $contentOverride  Replace the entire content block.
     * @return void
     */
    public function __construct(
        private readonly string $name = 'php_best_practices',
        private readonly string $description = 'PHP coding standards and best practices injected for code review and refactoring tasks',
        array $extraTags = [],
        array $extraRules = [],
        ?string $contentOverride = null,
    ) {
        $this->tags = array_values(array_unique([...self::DEFAULT_TAGS, ...$extraTags]));

        if ($contentOverride !== null) {
            $this->renderedContent = $contentOverride;
        } else {
            $extra = '';
            foreach ($extraRules as $rule) {
                $extra .= "\n- {$rule}";
            }
            $this->renderedContent = self::DEFAULT_CONTENT.$extra;
        }
    }

    /**
     * Unique machine-readable identifier used to register and match this skill.
     *
     * @return string The configured skill name (defaults to 'php_best_practices').
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Short human-readable description shown in admin / debug surfaces.
     *
     * @return string The configured description string.
     */
    public function description(): string
    {
        return $this->description;
    }

    /**
     * Keyword tags used by SkillRegistry::match() for relevance scoring.
     *
     * @return list<string> Defaults merged with any extraTags passed to the constructor.
     */
    public function tags(): array
    {
        return $this->tags;
    }

    /**
     * Skill content injected as context when the skill matches.
     *
     * @return string Either the contentOverride or DEFAULT_CONTENT plus any extraRules.
     */
    public function content(): string
    {
        return $this->renderedContent;
    }
}
