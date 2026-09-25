<?php

declare(strict_types=1);

namespace PhpClaw\Tools;

/**
 * Per-turn tool relevance filter: pins message-named tools, ranks the rest by ordered weighted signals, slices to the budget unless the budget is unlimited, and returns the trimmed set sorted by name so an identical selection is byte-identical across messages.
 */
final class ToolRouter
{
    public const UNLIMITED = -1;

    private const MIN_KEYWORD_LEN = 3;

    private const STOPWORDS = [
        'the', 'and', 'for', 'are', 'was', 'were', 'you', 'your', 'our', 'its', 'their',
        'this', 'that', 'these', 'those', 'from', 'with', 'into', 'about', 'than', 'then',
        'there', 'here', 'have', 'has', 'had', 'been', 'being', 'will', 'would', 'shall',
        'should', 'can', 'could', 'may', 'might', 'must', 'not', 'but', 'any', 'all',
        'some', 'each', 'every', 'per', 'via', 'too', 'very', 'just', 'also', 'only',
        'more', 'most', 'less', 'least', 'such', 'same', 'own', 'how', 'what', 'when',
        'where', 'which', 'who', 'whom', 'why', 'does', 'did', 'done', 'let', 'please',
        'them', 'they', 'one', 'two', 'out', 'off', 'over', 'under', 'again',
    ];

    private const DEFAULT_LIMIT = 10;

    private const WEIGHTS = [
        'intent' => 16,
        'domain' => 8,
        'tag' => 4,
        'example' => 2,
        'name' => 1,
        'description' => 1,
    ];

    private const MODEL_LIMITS = [
        'haiku' => 6,
        'flash' => 6,
        'gpt-3.5' => 5,
        'mini' => 8,
        'gemini' => 15,
        'sonnet' => 12,
        'gpt-4o' => 15,
        'opus' => 20,
    ];

    /**
     * Create a new ToolRouter instance.
     *
     * @param  int  $maxToolsPerTurn  Explicit per-turn budget; 0 derives it from the model id, UNLIMITED disables the cap.
     * @return void
     */
    public function __construct(
        private readonly int $maxToolsPerTurn = 0,
    ) {}

    /**
     * Filter the full schema list down to the most relevant tools for this message.
     *
     * @param  array<int, array<string, mixed>>  $allSchemas  Full schema list from ToolRegistry::schemas().
     * @param  string  $message  Current user message.
     * @param  string  $model  Provider model id.
     * @param  array<string, ToolRoutingMetadata>  $metadata  Routing metadata keyed by lowercased tool name.
     * @return array<int, array<string, mixed>> Pinned schemas followed by the highest-ranked remainder.
     */
    public function filter(array $allSchemas, string $message, string $model = '', array $metadata = []): array
    {
        $limit = $this->resolveLimit($model);

        if ($limit === self::UNLIMITED || count($allSchemas) <= $limit) {
            return $allSchemas;
        }

        $explicit = $this->extractExplicitToolNames($message);
        $pinned = [];
        $scorable = [];

        foreach ($allSchemas as $schema) {
            if ($this->isPinned($this->schemaName($schema), $explicit)) {
                $pinned[] = $schema;

                continue;
            }

            $scorable[] = $schema;
        }

        $ranked = $this->rankByScore($scorable, $message, $metadata);
        $slots = max(0, $limit - count($pinned));
        $chosen = array_merge($pinned, array_slice($ranked, 0, $slots));

        usort($chosen, fn (array $a, array $b): int => strcmp($this->schemaName($a), $this->schemaName($b)));

        return $chosen;
    }

    /**
     * Score every tool against the message and return the confidence of the best match, 0..1.
     *
     * @param  array<int, array<string, mixed>>  $allSchemas  Full schema list.
     * @param  string  $message  Current user message.
     * @param  array<string, ToolRoutingMetadata>  $metadata  Routing metadata keyed by lowercased tool name.
     * @return float Normalised confidence between 0 and 1.
     */
    public function confidence(array $allSchemas, string $message, array $metadata = []): float
    {
        $best = 0;

        foreach ($allSchemas as $schema) {
            $best = max($best, $this->calculateScore($schema, $this->keywords($message), $metadata));
        }

        return $best === 0 ? 0.0 : round(min(1.0, $best / $this->computeMaxPossibleScore()), 4);
    }

    /**
     * Order schemas by descending score, breaking ties on tool name so equal scores never fall back
     * to the order the tools happened to be declared in.
     *
     * @param  array<int, array<string, mixed>>  $schemas  Schemas eligible for scoring.
     * @param  string  $message  Current user message.
     * @param  array<string, ToolRoutingMetadata>  $metadata  Routing metadata keyed by lowercased tool name.
     * @return array<int, array<string, mixed>> Schemas in ranked order.
     */
    private function rankByScore(array $schemas, string $message, array $metadata): array
    {
        $words = $this->keywords($message);
        $rows = [];

        foreach ($schemas as $schema) {
            $rows[] = [
                'schema' => $schema,
                'score' => $this->calculateScore($schema, $words, $metadata),
                'name' => $this->schemaName($schema),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'] ?: strcmp($a['name'], $b['name']);
        });

        return array_map(static fn (array $row): array => $row['schema'], $rows);
    }

    /**
     * Score one schema against the message words using the ordered weight map.
     *
     * @param  array<string, mixed>  $schema  Single tool schema.
     * @param  string[]  $words  Keyword tokens from the message.
     * @param  array<string, ToolRoutingMetadata>  $metadata  Routing metadata keyed by lowercased tool name.
     * @return int Weighted score.
     */
    private function calculateScore(array $schema, array $words, array $metadata): int
    {
        $name = $this->schemaName($schema);
        $routing = $metadata[$name] ?? ToolRoutingMetadata::empty();

        $score = 0;
        $score += self::WEIGHTS['intent'] * $this->countOverlap($words, $routing->intents);
        $score += self::WEIGHTS['domain'] * $this->countOverlap($words, $routing->domains);
        $score += self::WEIGHTS['tag'] * $this->countOverlap($words, $routing->tags);
        $score += self::WEIGHTS['example'] * $this->countOverlap($words, $routing->examples);
        $score += self::WEIGHTS['name'] * $this->countOverlap($words, [$name]);
        $score += self::WEIGHTS['description'] * $this->countOverlap($words, [$this->schemaDescription($schema)]);

        return $score;
    }

    /**
     * Count how many message words appear in the tokenised signal list.
     *
     * @param  string[]  $words  Keyword tokens from the message.
     * @param  string[]  $signals  Raw signal strings from routing metadata or the schema.
     * @return int Number of distinct overlapping tokens.
     */
    private function countOverlap(array $words, array $signals): int
    {
        if ($signals === [] || $words === []) {
            return 0;
        }

        return count(array_intersect($words, $this->keywords(implode(' ', $signals))));
    }

    /**
     * Highest score a single tool could reach, used to normalise confidence.
     *
     * @return int
     */
    private function computeMaxPossibleScore(): int
    {
        return array_sum(self::WEIGHTS);
    }

    /**
     * Extract a tool name from either the Anthropic or OpenAI schema shape.
     *
     * @param  array<string, mixed>  $schema  Single tool schema.
     * @return string Lowercased tool name, or '' when absent.
     */
    private function schemaName(array $schema): string
    {
        return strtolower((string) ($schema['name'] ?? ($schema['function']['name'] ?? '')));
    }

    /**
     * Extract a tool description from either schema shape.
     *
     * @param  array<string, mixed>  $schema  Single tool schema.
     * @return string Tool description, or '' when absent.
     */
    private function schemaDescription(array $schema): string
    {
        return (string) ($schema['description'] ?? ($schema['function']['description'] ?? ''));
    }

    /**
     * Detect tool names the message explicitly names, both CamelCase (use ShellTool) and snake_case (file_read).
     *
     * @param  string  $message  Current user message.
     * @return string[] Unique lowercased tool-name mentions.
     */
    private function extractExplicitToolNames(string $message): array
    {
        $found = [];

        preg_match_all('/\b(?:use|using|with|via)\s+([A-Za-z][A-Za-z0-9]*Tool)\b/i', $message, $camelMatches);
        foreach ($camelMatches[1] as $camel) {
            $found[] = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', substr($camel, 0, -4)));
        }

        preg_match_all('/\b([a-z][a-z0-9]*(?:_[a-z0-9]+)+)\b/', strtolower($message), $snakeMatches);
        foreach ($snakeMatches[1] as $snake) {
            $found[] = $snake;
        }

        return array_unique($found);
    }

    /**
     * Whether a tool is explicitly pinned by any of the detected mentions.
     *
     * @param  string  $toolName  Candidate tool name.
     * @param  string[]  $explicit  Detected tool-name mentions.
     * @return bool
     */
    private function isPinned(string $toolName, array $explicit): bool
    {
        if ($toolName === '') {
            return false;
        }

        foreach ($explicit as $mention) {
            if ($mention === $toolName
                || str_contains($mention, $toolName)
                || str_contains($toolName, $mention)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the per-turn budget from the explicit cap or the model id.
     *
     * @param  string  $model  Provider model id.
     * @return int Effective per-turn budget, or UNLIMITED when the cap is disabled.
     */
    private function resolveLimit(string $model): int
    {
        if ($this->maxToolsPerTurn === self::UNLIMITED) {
            return self::UNLIMITED;
        }

        if ($this->maxToolsPerTurn > 0) {
            return $this->maxToolsPerTurn;
        }

        $model = strtolower($model);
        foreach (self::MODEL_LIMITS as $fragment => $limit) {
            if ($this->modelMatchesFragment($model, $fragment)) {
                return $limit;
            }
        }

        return self::DEFAULT_LIMIT;
    }

    /**
     * Match a model-id fragment on token boundaries, since a bare substring test wrongly resolves
     * every non-Flash Gemini id as "mini" because the word "gemini" ends in those four letters.
     *
     * @param  string  $model  Lowercased provider model id.
     * @param  string  $fragment  Fragment from MODEL_LIMITS.
     * @return bool
     */
    private function modelMatchesFragment(string $model, string $fragment): bool
    {
        return preg_match(
            '/(?<![a-z0-9])'.preg_quote($fragment, '/').'(?![a-z0-9])/',
            $model,
        ) === 1;
    }

    /**
     * Lowercase, split, drop stopwords and short tokens, singularise, and dedupe a string into keyword tokens; snake_case words also contribute their parts so a tool name matches the plain word.
     *
     * @param  string  $text  Source text.
     * @return string[] Unique singularised tokens from words of at least MIN_KEYWORD_LEN characters.
     */
    private function keywords(string $text): array
    {
        $tokens = [];

        foreach (str_word_count(strtolower($text), 1, '_') as $word) {
            $tokens[] = $word;

            if (str_contains($word, '_')) {
                array_push($tokens, ...explode('_', $word));
            }
        }

        $kept = array_filter(
            $tokens,
            static fn (string $w): bool => strlen($w) >= self::MIN_KEYWORD_LEN
                && ! in_array($w, self::STOPWORDS, true),
        );

        return array_values(array_unique(array_map(self::singular(...), $kept)));
    }

    /**
     * Reduce a plural keyword to its singular so "editors" matches the tag "editor" and "categories" matches "category".
     *
     * @param  string  $word  Lowercased keyword.
     * @return string The singular form, or the word unchanged when it is too short or not plural.
     */
    private static function singular(string $word): string
    {
        if (strlen($word) <= self::MIN_KEYWORD_LEN || ! str_ends_with($word, 's')) {
            return $word;
        }

        if (str_ends_with($word, 'ies')) {
            return substr($word, 0, -3).'y';
        }

        return substr($word, 0, -1);
    }
}
