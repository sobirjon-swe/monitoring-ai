<?php

namespace App\Domain\DeveloperAnalysis;

use InvalidArgumentException;

final class ResultGate
{
    private const BUCKETS = ['generated', 'vendor', 'tests', 'config', 'docs', 'application', 'unknown'];

    public function __construct(private readonly DeveloperContract $contract) {}

    public function validate(array $input, array $output): array
    {
        try {
            $this->contract->input($input);
            $this->contract->output($output);
            foreach (['run_id', 'request_id', 'input_hash', 'pins', 'analyzer_version', 'rule_version'] as $key) {
                $this->require(DeveloperContract::canonical([$input[$key]]) === DeveloperContract::canonical([$output[$key]]));
            }
            $snapshot = $input['snapshot'];
            $bound = array_intersect_key($snapshot, array_flip(['id', 'hash', 'base_sha', 'head_sha']));
            $this->require(DeveloperContract::canonical($bound) === DeveloperContract::canonical($output['snapshot']));
            $this->require($output['redaction_version'] === $snapshot['redaction']['version'] && $output['redaction_hash'] === $snapshot['redaction']['hash']);
            $expected = $this->reconstruct($snapshot);
            foreach ($expected as $key => $value) {
                $this->require(DeveloperContract::canonical([$value]) === DeveloperContract::canonical([$output[$key]]));
            }
            $facts = ['severity' => 'informational', 'ai_interpretation' => 'unavailable',
                'source_confidence' => ['label' => 'synthetic_registered', 'reasons' => ['immutable_safe_snapshot', 'synthetic_sha_not_verified_in_git']],
                'link_confidence' => ['label' => 'snapshot_bound', 'reasons' => ['validated_logical_coordinates', 'no_semantic_criterion_acceptance']],
                'claim_confidence' => ['label' => 'deterministic_observation', 'reasons' => ['independently_reconstructed_counts', $output['coverage']['status'] === 'partial' ? 'partial_observed_evidence' : 'supported_diff_observations']],
                'references' => array_intersect_key($input, array_flip(['run_id', 'request_id', 'input_hash', 'pins', 'analyzer_version', 'rule_version'])) + ['contract_version' => 'developer-analysis-output-v1', 'snapshot' => $bound],
                'assumptions' => ['synthetic_sha_not_verified_in_git', 'logical_locators_only', 'counts_do_not_measure_effort_quality_or_acceptance', ...$output['coverage']['notices']],
                'suggested_validation' => ['authorized_human_review_of_snapshot_and_context'],
                'counter_evidence' => $output['coverage']['notices'] ?: ['human_review_pending'],
            ];

            return ['output' => $output, 'findings' => [
                ['kind' => 'composition_fact', 'facts' => $facts + ['counts' => $output['counts'], 'buckets' => $output['buckets']], 'summary' => 'Observed changed lines: '.$output['counts']['added'].' added, '.$output['counts']['deleted'].' deleted. These counts do not measure effort or accepted progress.'],
                ['kind' => 'coverage_notice', 'facts' => $facts + ['coverage' => $output['coverage'], 'omitted_locator_count' => $output['omitted_locator_count']], 'summary' => $output['coverage']['status'] === 'complete' ? 'Supported diff observations are complete for this snapshot.' : 'Diff observations are partial; missing or unsupported evidence remains visible.'],
                ['kind' => 'provenance_notice', 'facts' => $facts + ['snapshot' => $bound, 'patch' => $output['patch']], 'summary' => $output['patch']['status'] === 'exact' ? 'Exact patch bytes are bound to the immutable snapshot; separate source events remain distinct.' : 'Patch equivalence is unresolved; separate source events remain distinct.'],
            ]];
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid developer result.');
        }
    }

    /** Independent reconstruction uses approved bytes, never worker totals or coordinates. */
    private function reconstruct(array $snapshot): array
    {
        $diff = str_replace("\r\n", "\n", $snapshot['redacted_diff']);
        $this->require(! str_contains($diff, "\r"));
        $lines = explode("\n", rtrim($diff, "\n"));
        $sections = [];
        foreach ($lines as $index => $line) {
            if (str_starts_with($line, 'diff --git ')) {
                $sections[] = [];
            }
            $this->require(count($sections) > 0);
            $sections[count($sections) - 1][] = [$index + 1, $line];
        }
        $this->require(count($sections) === count($snapshot['paths']));
        $totals = ['added' => 0, 'deleted' => 0];
        $buckets = array_fill_keys(self::BUCKETS, $totals);
        $files = $locators = $notices = [];
        $hunkIndex = -1;
        $changed = 0;
        $redacted = array_column($snapshot['redaction']['mapping'], 'prefix', 'diff_line');
        $mapped = [];
        foreach ($sections as $index => $section) {
            $paths = $snapshot['paths'][$index];
            [$oldPath, $newPath] = [$paths['old_path'], $paths['new_path']];
            $first = $section[0][1];
            $this->require(preg_match('/\Adiff --git a\/(\S+) b\/(\S+)\z/D', $first, $header) === 1
                && $header[1] === ($oldPath ?? $newPath) && $header[2] === ($newPath ?? $oldPath));
            [$oldBucket, $oldFlags] = $this->classify($oldPath);
            [$newBucket, $newFlags] = $this->classify($newPath);
            $primary = $newPath === null ? $oldBucket : $newBucket;
            $flags = [...$oldFlags, ...$newFlags];
            if ($oldPath !== null && $newPath !== null && $oldPath !== $newPath) {
                $flags[] = 'rename';
            }
            $fileNotices = $records = $positions = $modes = [];
            $oldSeen = $newSeen = $binary = $truncated = $renameFrom = $renameTo = false;
            $similarity = $blobs = $hunk = null;
            $previous = ['old' => 0, 'new' => 0];
            $finish = function () use (&$hunk, &$previous, &$truncated): void {
                if ($hunk !== null) {
                    $this->require($truncated || ($hunk['remaining_old'] === 0 && $hunk['remaining_new'] === 0));
                    $previous = ['old' => $hunk['old'], 'new' => $hunk['new']];
                }
            };
            foreach (array_slice($section, 1) as [$diffLine, $line]) {
                if (in_array($line, ['[TRUNCATED]', '... diff truncated ...'], true)) {
                    $truncated = true;
                    $fileNotices = [...$fileNotices, 'truncated', 'counts_unknown'];
                    $flags[] = 'truncated';

                    continue;
                }
                $this->require(! $truncated);
                $prefix = $line[0] ?? '';
                if ($hunk !== null && in_array($prefix, [' ', '+', '-'], true) && ($hunk['remaining_old'] || $hunk['remaining_new'])) {
                    $old = in_array($prefix, [' ', '-'], true) ? $hunk['old'] : null;
                    $new = in_array($prefix, [' ', '+'], true) ? $hunk['new'] : null;
                    foreach (['old' => $old, 'new' => $new] as $side => $coordinate) {
                        if ($coordinate !== null) {
                            $this->require($coordinate >= 1 && $coordinate <= 2147483647 && $hunk['remaining_'.$side] > 0);
                            $hunk['remaining_'.$side]--;
                            $hunk[$side]++;
                        }
                    }
                    $this->require(($oldPath !== null || $old === null) && ($newPath !== null || $new === null));
                    $positions[] = ['diff_line' => $diffLine, 'prefix' => $prefix, 'content' => substr($line, 1), 'old' => $old, 'new' => $new];
                    if (isset($redacted[$diffLine])) {
                        $this->require($redacted[$diffLine] === $prefix && $line === $prefix.'[REDACTED]');
                        $mapped[$diffLine] = true;
                        $fileNotices[] = 'redacted';
                        $flags[] = 'redacted';
                    }
                    if ($prefix !== ' ') {
                        $kind = $prefix === '+' ? 'added' : 'deleted';
                        $records[] = ['diff_line' => $diffLine, 'path' => $prefix === '+' ? $newPath : $oldPath, 'side' => $prefix === '+' ? 'new' : 'old', 'line' => $prefix === '+' ? $new : $old, 'hunk_index' => $hunkIndex, 'kind' => $kind];
                    }

                    continue;
                }
                if ($line === '\\ No newline at end of file') {
                    $this->require($positions && end($positions)['diff_line'] === $diffLine - 1);

                    continue;
                }
                if (str_starts_with($line, '@@ ')) {
                    $finish();
                    $this->require($oldSeen && $newSeen && ! $binary && preg_match('/\A@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@(?: .*)?\z/D', $line, $match) === 1);
                    [$a, $ac, $b, $bc] = [(int) $match[1], ($match[2] ?? '') === '' ? 1 : (int) $match[2], (int) $match[3], ($match[4] ?? '') === '' ? 1 : (int) $match[4]];
                    $this->require(($ac || $bc) && ($a > 0 || $ac === 0) && ($b > 0 || $bc === 0) && $a >= $previous['old'] && $b >= $previous['new'] && ($oldPath !== null || $ac === 0) && ($newPath !== null || $bc === 0));
                    $this->require(++$hunkIndex < 2000);
                    $hunk = ['old' => $a, 'new' => $b, 'remaining_old' => $ac, 'remaining_new' => $bc];

                    continue;
                }
                $this->require($hunk === null || ($hunk['remaining_old'] === 0 && $hunk['remaining_new'] === 0));
                if (str_starts_with($line, '--- ')) {
                    $this->require(! $oldSeen && $hunk === null && substr($line, 4) === ($oldPath === null ? '/dev/null' : 'a/'.$oldPath));
                    $oldSeen = true;
                } elseif (str_starts_with($line, '+++ ')) {
                    $this->require($oldSeen && ! $newSeen && $hunk === null && substr($line, 4) === ($newPath === null ? '/dev/null' : 'b/'.$newPath));
                    $newSeen = true;
                } elseif (str_starts_with($line, 'Binary files ') || $line === 'GIT binary patch') {
                    $this->require($hunk === null);
                    $binary = true;
                    $fileNotices = [...$fileNotices, 'binary', 'counts_unknown'];
                    $flags[] = 'binary';
                } elseif ($binary) {
                    continue;
                } elseif (preg_match('/\A(old mode|new mode|new file mode|deleted file mode) (100644|100755|120000|160000)\z/D', $line, $m)) {
                    $this->require($hunk === null && ! isset($modes[$m[1]]));
                    $modes[$m[1]] = $m[2];
                } elseif (preg_match('/\A(similarity|dissimilarity) index (\d{1,3})%\z/D', $line, $m)) {
                    $this->require($hunk === null && (int) $m[2] <= 100);
                    if ($m[1] === 'similarity') {
                        $this->require($similarity === null);
                        $similarity = (int) $m[2];
                    }
                } elseif (preg_match('/\Aindex ([0-9a-f]{7,40})\.\.([0-9a-f]{7,40})(?: (100644|100755|120000|160000))?\z/D', $line, $m)) {
                    $this->require($hunk === null && $blobs === null);
                    $blobs = [$m[1], $m[2]];
                } elseif (str_starts_with($line, 'rename from ')) {
                    $this->require($hunk === null && ! $renameFrom && $oldPath !== $newPath && substr($line, 12) === $oldPath);
                    $renameFrom = true;
                } elseif (str_starts_with($line, 'rename to ')) {
                    $this->require($hunk === null && ! $renameTo && $oldPath !== $newPath && substr($line, 10) === $newPath);
                    $renameTo = true;
                } else {
                    $this->require(false);
                }
            }
            $finish();
            $this->require($oldSeen === $newSeen && $renameFrom === $renameTo && isset($modes['old mode']) === isset($modes['new mode']));
            $modePair = isset($modes['old mode'], $modes['new mode']);
            if ($modePair) {
                $this->require($oldPath !== null && $newPath !== null && count($modes) === 2 && $modes['old mode'] !== $modes['new mode']);
            }
            if (isset($modes['new file mode'])) {
                $this->require($oldPath === null && $newPath !== null && count($modes) === 1);
            }
            if (isset($modes['deleted file mode'])) {
                $this->require($newPath === null && $oldPath !== null && count($modes) === 1);
            }
            if (! $binary && $hunk === null && ! $truncated) {
                $this->require(! $oldSeen && ! $newSeen && ($modePair || $renameFrom) && ($oldPath === $newPath || $renameFrom));
                if (($renameFrom && $similarity !== 100) || ($modePair && array_diff(array_values($modes), ['100644', '100755'])) || ($blobs && $blobs[0] !== $blobs[1])) {
                    $fileNotices = [...$fileNotices, 'unsupported', 'counts_unknown'];
                    $flags[] = 'unsupported';
                }
            } else {
                $this->require($binary || ($oldSeen && $newSeen));
            }
            $generated = ['old' => false, 'new' => false];
            foreach ($positions as $position) {
                if (in_array($position['content'], ['// @generated', '# @generated', '/* @generated */', '<!-- @generated -->'], true)) {
                    foreach (['old', 'new'] as $side) {
                        if ($position[$side] === 1) {
                            $generated[$side] = true;
                        }
                    }
                }
            }
            $regions = ['old' => false, 'new' => false];
            $last = ['old' => null, 'new' => null];
            $classified = [];
            foreach ($positions as $position) {
                foreach (['old' => $oldBucket, 'new' => $newBucket] as $side => $bucket) {
                    $coordinate = $position[$side];
                    if ($coordinate === null) {
                        continue;
                    }
                    if ($regions[$side] === true && $last[$side] !== null && $coordinate !== $last[$side] + 1) {
                        $regions[$side] = null;
                        $fileNotices[] = 'unsupported';
                        $flags[] = 'unsupported';
                    }
                    $last[$side] = $coordinate;
                    if (in_array($position['content'], ['// BEGIN GENERATED', '# BEGIN GENERATED', '<!-- BEGIN GENERATED -->'], true)) {
                        $this->require(! $regions[$side]);
                        $regions[$side] = true;
                    }
                    if ($generated[$side] || $regions[$side] === true) {
                        $bucket = 'generated';
                    } elseif ($regions[$side] === null && $bucket !== 'generated') {
                        $bucket = 'unknown';
                    }
                    if ($position['prefix'] === ($side === 'old' ? '-' : '+')) {
                        $classified[$position['diff_line']] = $bucket;
                    }
                    if (in_array($position['content'], ['// END GENERATED', '# END GENERATED', '<!-- END GENERATED -->'], true)) {
                        $this->require($regions[$side] !== false);
                        $regions[$side] = false;
                    }
                }
            }
            $this->require($truncated || ! in_array(true, $regions, true));
            if (in_array(true, $generated, true)) {
                $primary = 'generated';
            }
            $used = array_unique(array_values($classified));
            if (in_array('generated', $used, true)) {
                $flags[] = 'generated';
            }
            if (count($used) > 1) {
                $flags[] = 'mixed';
            }
            if ($primary === 'unknown' || in_array('unknown', $used, true)) {
                $fileNotices[] = 'unknown_path';
            }
            $counts = ['added' => 0, 'deleted' => 0];
            foreach ($records as $record) {
                $kind = $record['kind'];
                $counts[$kind]++;
                $totals[$kind]++;
                $buckets[$classified[$record['diff_line']]][$kind]++;
                $this->require(++$changed <= 50000);
                if (count($locators) < 200) {
                    unset($record['diff_line']);
                    $locators[] = $record + ['snapshot_id' => $snapshot['id'], 'snapshot_hash' => $snapshot['hash'], 'base_sha' => $snapshot['base_sha'], 'head_sha' => $snapshot['head_sha']];
                }
            }
            $flags = array_values(array_unique($flags));
            sort($flags, SORT_STRING);
            $files[] = $paths + ['bucket' => $primary, 'counts' => $counts, 'coverage' => $this->coverage($fileNotices), 'flags' => $flags];
            $notices = [...$notices, ...$fileNotices];
        }
        $this->require(count($mapped) === count($redacted));
        $unresolved = (bool) array_intersect($notices, ['binary', 'unsupported', 'truncated', 'redacted']);

        return ['counts' => $totals, 'buckets' => $buckets, 'files' => $files, 'locators' => $locators, 'omitted_locator_count' => $changed - count($locators), 'coverage' => $this->coverage($notices), 'patch' => ['status' => $unresolved ? 'unresolved' : 'exact', 'hash' => $unresolved ? null : hash('sha256', $diff)]];
    }

    private function classify(?string $path): array
    {
        if ($path === null) {
            return ['unknown', []];
        }
        $parts = explode('/', $path);
        $name = array_pop($parts);
        $suffix = fn (array $values) => (bool) array_filter($values, fn ($s) => str_ends_with($name, $s));
        $candidate = [
            'generated' => array_intersect($parts, ['generated', 'gen', 'dist', 'build']) || $suffix(['.generated.py', '.generated.php', '.generated.ts', '.g.cs', '.min.js', '.min.css']),
            'vendor' => (bool) array_intersect($parts, ['vendor', 'node_modules', 'third_party', 'third-party']),
            'tests' => array_intersect($parts, ['tests', 'test', '__tests__', 'spec']) || str_starts_with($name, 'test_') || $suffix(['_test.py', '.test.js', '.test.ts', '.spec.js', '.spec.ts', 'Test.php']),
            'config' => array_intersect($parts, ['config', 'configuration']) || $suffix(['.ini', '.toml', '.yaml', '.yml', '.json', '.env']) || in_array($name, ['Dockerfile', 'Makefile', '.gitignore'], true),
            'docs' => array_intersect($parts, ['docs', 'documentation']) || $suffix(['.md', '.rst', '.txt', '.adoc']),
            'application' => $suffix(['.py', '.php', '.js', '.ts', '.jsx', '.tsx', '.java', '.cs', '.go', '.rs', '.c', '.cpp', '.h', '.rb', '.sql', '.html', '.css', '.vue', '.svelte']),
        ];
        $buckets = array_keys(array_filter($candidate));
        $flags = [];
        if ($candidate['generated']) {
            $flags[] = 'generated';
        }
        if (count($buckets) > 1) {
            $flags[] = 'mixed';
        }
        if (array_intersect($parts, ['templates', 'template']) || str_contains($name, 'template')) {
            $flags[] = 'template';
        }
        if (array_intersect($parts, ['migrations', 'migration'])) {
            $flags[] = 'migration';
        }

        return [$buckets[0] ?? 'unknown', $flags];
    }

    private function coverage(array $notices): array
    {
        $notices = array_values(array_unique($notices));
        sort($notices, SORT_STRING);

        return ['status' => $notices ? 'partial' : 'complete', 'notices' => $notices];
    }

    private function require(bool $condition): void
    {
        if (! $condition) {
            throw new InvalidArgumentException;
        }
    }
}
