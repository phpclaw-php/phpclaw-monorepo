<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Helpers;

trait AssertsDocumentedColumnsAreEmitted
{
    protected function assertEveryDocumentedColumnIsEmitted(object $tool): void
    {
        $documented = $this->documentedColumnNames($tool);
        $emitted = $this->emittedColumnNames($tool);

        self::assertNotSame([], $documented, 'No documented column names were found to check.');
        self::assertNotSame([], $emitted, 'No emitted column names were found to check against.');

        $ghosts = array_values(array_diff($documented, $emitted));

        self::assertSame(
            [],
            $ghosts,
            'The tool documents column(s) it never returns: '.implode(', ', $ghosts),
        );
    }

    protected function documentedColumnNames(object $tool): array
    {
        $names = [];
        $description = $tool->description();

        if (preg_match('/AVAILABLE COLUMNS:\s*\n((?:\s{2,}\S.*\n)+)/', $description, $matches) === 1) {
            $names = array_merge($names, $this->wordsIn($matches[1]));
        }

        $schema = $tool->inputSchema();
        $columnsText = (string) ($schema['properties']['columns']['description'] ?? '');

        foreach ([$description, $columnsText] as $text) {
            if (preg_match_all('/defaults \(([^)]+)\)/', $text, $found) >= 1) {
                foreach ($found[1] as $list) {
                    $names = array_merge($names, $this->wordsIn($list));
                }
            }
        }

        return array_values(array_unique($names));
    }

    protected function emittedColumnNames(object $tool): array
    {
        $names = [];

        foreach ([['columns' => ['*']], []] as $input) {
            $decoded = json_decode($tool->execute($input), true);

            foreach ((array) ($decoded['data'] ?? []) as $list) {
                if (! is_array($list)) {
                    continue;
                }

                foreach ($list as $row) {
                    if (is_array($row)) {
                        $names = array_merge($names, array_keys($row));
                    }
                }
            }

            foreach ((array) ($decoded['meta']['columns_returned'] ?? []) as $column) {
                $names[] = (string) $column;
            }
        }

        $schema = json_decode($tool->execute(['schema' => true]), true);

        foreach (['available_columns', 'default_columns'] as $key) {
            foreach ((array) ($schema['data'][$key] ?? []) as $column) {
                $names[] = (string) $column;
            }
        }

        return array_values(array_unique($names));
    }

    private function wordsIn(string $text): array
    {
        $words = [];

        foreach (preg_split('/[,\s]+/', trim($text)) ?: [] as $word) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $word) === 1) {
                $words[] = $word;
            }
        }

        return $words;
    }
}
