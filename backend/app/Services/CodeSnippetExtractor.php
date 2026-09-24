<?php

namespace App\Services;

/**
 * Every "manual fix" and Optimization change diff used to show the entire
 * file, before and after - correct, but useless for a merchant trying to
 * paste a fix into a 400-line theme.liquid by hand: replacing the whole
 * file risks clobbering unrelated customizations, and finding the one
 * changed line in a wall of unchanged text defeats the point of showing
 * code at all. This trims to just the changed region (plus a little
 * context to locate it), the same idea as a unified diff hunk, without
 * pulling in a full diff library - every fix this app generates is a
 * single localized edit (one script tag, one attribute, one inserted
 * block), so "trim the matching prefix and suffix" finds the changed
 * region exactly, no line-by-line diff algorithm needed.
 */
class CodeSnippetExtractor
{
    /**
     * @return array{before: string, after: string, start_line: int, truncated_before: bool, truncated_after: bool, unchanged: bool}
     */
    public static function extract(string $original, string $updated, int $context = 3): array
    {
        if ($original === $updated) {
            return [
                'before' => $original,
                'after' => $updated,
                'start_line' => 1,
                'truncated_before' => false,
                'truncated_after' => false,
                'unchanged' => true,
            ];
        }

        $originalLines = explode("\n", $original);
        $updatedLines = explode("\n", $updated);

        $prefixLen = self::commonPrefixLength($originalLines, $updatedLines);
        $suffixLen = self::commonSuffixLength($originalLines, $updatedLines, $prefixLen);

        $startBefore = max(0, $prefixLen - $context);
        $endBefore = min(count($originalLines), count($originalLines) - $suffixLen + $context);
        $startAfter = max(0, $prefixLen - $context);
        $endAfter = min(count($updatedLines), count($updatedLines) - $suffixLen + $context);

        return [
            'before' => implode("\n", array_slice($originalLines, $startBefore, $endBefore - $startBefore)),
            'after' => implode("\n", array_slice($updatedLines, $startAfter, $endAfter - $startAfter)),
            'start_line' => $startBefore + 1,
            'truncated_before' => $startBefore > 0,
            'truncated_after' => $endBefore < count($originalLines),
            'unchanged' => false,
        ];
    }

    /**
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    private static function commonPrefixLength(array $a, array $b): int
    {
        $max = min(count($a), count($b));
        $i = 0;

        while ($i < $max && $a[$i] === $b[$i]) {
            $i++;
        }

        return $i;
    }

    /**
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    private static function commonSuffixLength(array $a, array $b, int $prefixLen): int
    {
        $max = min(count($a), count($b)) - $prefixLen;
        $i = 0;

        while ($i < $max && $a[count($a) - 1 - $i] === $b[count($b) - 1 - $i]) {
            $i++;
        }

        return $i;
    }
}
