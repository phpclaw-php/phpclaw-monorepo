<?php

declare(strict_types=1);

namespace PhpClaw\Skills;

use PhpClaw\Exceptions\SkillException;
use PhpClaw\Skills\Contracts\SkillInterface;

/**
 * Skill loaded from a Markdown file with YAML frontmatter.
 */
final class FileSkill implements SkillInterface
{
    private const FRONTMATTER_DELIMITER = '---';

    private const FRONTMATTER_DELIMITER_LENGTH = 3;

    private const YAML_TRIM_CHARS = " \t\"'";

    private string $name;

    private string $description;

    private array $tags;

    private string $content;

    /**
     * Create a new FileSkill instance.
     *
     * @param  string  $path  Absolute path to the Markdown skill file.
     * @return void
     *
     * @throws SkillException If the file does not exist or frontmatter is missing.
     */
    public function __construct(string $path)
    {
        if (! file_exists($path)) {
            throw new SkillException("Skill file not found: {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new SkillException("Skill file could not be read: {$path}");
        }
        [$frontmatter, $body] = $this->splitFrontmatter($raw, $path);

        $this->name = $this->extractString($frontmatter, 'name', basename($path, '.md'));
        $this->description = $this->extractString($frontmatter, 'description', '');
        $this->tags = $this->extractTags($frontmatter);
        $this->content = trim($body);
    }

    /**
     * Unique machine-readable identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Short human-readable description used in keyword matching.
     *
     * @return string
     */
    public function description(): string
    {
        return $this->description;
    }

    /**
     * Tags used for keyword matching.
     *
     * @return string[]
     */
    public function tags(): array
    {
        return $this->tags;
    }

    /**
     * Instruction text injected into the user message when this skill matches.
     *
     * @return string
     */
    public function content(): string
    {
        return $this->content;
    }

    /**
     * Split raw file content into frontmatter and body.
     *
     * @param  string  $raw  Full file contents.
     * @param  string  $path  File path used in exception messages.
     * @return array{string, string}
     *
     * @throws SkillException When the frontmatter is missing or unclosed.
     */
    private function splitFrontmatter(string $raw, string $path): array
    {
        $raw = ltrim($raw);

        if (! str_starts_with($raw, self::FRONTMATTER_DELIMITER)) {
            throw new SkillException("Skill file missing YAML frontmatter: {$path}");
        }

        $end = strpos($raw, self::FRONTMATTER_DELIMITER, self::FRONTMATTER_DELIMITER_LENGTH);

        if ($end === false) {
            throw new SkillException("Skill file has unclosed YAML frontmatter: {$path}");
        }

        $frontmatter = substr($raw, self::FRONTMATTER_DELIMITER_LENGTH, $end - self::FRONTMATTER_DELIMITER_LENGTH);
        $body = substr($raw, $end + self::FRONTMATTER_DELIMITER_LENGTH);

        return [$frontmatter, $body];
    }

    /**
     * Extract a string value from a minimal YAML block.
     *
     * @param  string  $yaml  Raw YAML frontmatter string.
     * @param  string  $key  Key name to look up.
     * @param  string  $default  Fallback value when the key is absent.
     * @return string
     */
    private function extractString(string $yaml, string $key, string $default): string
    {
        if (preg_match('/^'.preg_quote($key, '/').'\s*:\s*(.+)$/m', $yaml, $matches)) {
            return trim($matches[1], self::YAML_TRIM_CHARS);
        }

        return $default;
    }

    /**
     * Extract tags from inline YAML array syntax: [tag1, tag2] or bare: tag1, tag2
     *
     * @param  string  $yaml  Raw YAML frontmatter string.
     * @return string[]
     */
    private function extractTags(string $yaml): array
    {
        if (! preg_match('/^tags\s*:\s*(.+)$/m', $yaml, $m)) {
            return [];
        }

        $raw = trim($m[1]);

        if (str_starts_with($raw, '[')) {
            $raw = trim($raw, '[]');
        }

        return array_values(array_filter(array_map(
            fn (string $tag) => trim($tag, self::YAML_TRIM_CHARS),
            explode(',', $raw),
        )));
    }
}
