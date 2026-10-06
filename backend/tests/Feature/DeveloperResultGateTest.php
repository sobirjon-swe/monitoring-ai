<?php

namespace Tests\Feature;

use App\Domain\DeveloperAnalysis\DeveloperContract;
use App\Domain\DeveloperAnalysis\ResultGate;
use InvalidArgumentException;
use Tests\TestCase;

class DeveloperResultGateTest extends TestCase
{
    private function envelopes(): array
    {
        return array_map(fn ($kind) => json_decode(file_get_contents(base_path('../analytics/tests/fixtures/contract-'.$kind.'.json')), true), ['input', 'output']);
    }

    public function test_bound_observed_facts_use_only_server_templates(): void
    {
        [$input, $output] = $this->envelopes();
        $result = app(ResultGate::class)->validate($input, $output);
        $this->assertSame(['added' => 1, 'deleted' => 1], $result['output']['counts']);
        $this->assertSame(['composition_fact', 'coverage_notice', 'provenance_notice'], array_column($result['findings'], 'kind'));
        $this->assertSame('informational', $result['findings'][0]['facts']['severity']);
        $this->assertArrayNotHasKey('probability', $result['findings'][0]['facts']);
        foreach (['source_confidence', 'link_confidence', 'claim_confidence'] as $key) {
            $this->assertIsString($result['findings'][0]['facts'][$key]['label']);
            $this->assertNotEmpty($result['findings'][0]['facts'][$key]['reasons']);
        }
        $this->assertSame($input['run_id'], $result['findings'][0]['facts']['references']['run_id']);
        $this->assertSame($input['pins'], $result['findings'][0]['facts']['references']['pins']);
        $this->assertContains('synthetic_sha_not_verified_in_git', $result['findings'][0]['facts']['assumptions']);
        $this->assertNotEmpty($result['findings'][0]['facts']['suggested_validation']);
        $this->assertNotEmpty($result['findings'][0]['facts']['counter_evidence']);
    }

    public function test_forged_bindings_coordinates_counts_and_prose_are_rejected(): void
    {
        [$input, $output] = $this->envelopes();
        $changes = [
            fn (&$o) => $o['input_hash'] = str_repeat('a', 64),
            fn (&$o) => $o['snapshot']['head_sha'] = str_repeat('c', 40),
            fn (&$o) => $o['locators'][0]['line'] = 20,
            fn (&$o) => $o['locators'][0]['path'] = 'app/other.py',
            fn (&$o) => $o['locators'][0]['side'] = 'new',
            fn (&$o) => $o['locators'][0]['hunk_index'] = 1,
            fn (&$o) => $o['locators'][0]['snapshot_hash'] = str_repeat('d', 64),
            fn (&$o) => $o['counts']['added'] = 2,
            fn (&$o) => $o['buckets']['application']['added'] = 0,
            fn (&$o) => $o['buckets']['generated']['added'] = 1,
            fn (&$o) => $o['patch']['hash'] = str_repeat('d', 64),
            fn (&$o) => $o['omitted_locator_count'] = 1,
            fn (&$o) => $o['summary'] = 'Untrusted arbitrary prose',
        ];
        foreach ($changes as $change) {
            $forged = $output;
            $change($forged);
            try {
                app(ResultGate::class)->validate($input, $forged);
                $this->fail('Forged output was accepted.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Invalid developer result.', $e->getMessage());
            }
        }
    }

    public function test_metadata_and_region_coverage_cannot_be_promoted_to_complete(): void
    {
        $cases = json_decode(file_get_contents(base_path('../analytics/tests/fixtures/metadata-and-region-input-output.json')), true);
        foreach ($cases as $case) {
            $result = app(ResultGate::class)->validate($case['input'], $case['output']);
            $this->assertSame($case['output']['coverage'], $result['output']['coverage'], $case['name']);
            if ($case['output']['coverage']['status'] === 'partial') {
                $forged = $case['output'];
                $forged['coverage'] = ['status' => 'complete', 'notices' => []];
                try {
                    app(ResultGate::class)->validate($case['input'], $forged);
                    $this->fail('Partial output was promoted.');
                } catch (InvalidArgumentException) {
                    $this->assertTrue(true);
                }
            }
        }
    }

    public function test_ac03_aggregate_conservation_and_bounded_representatives(): void
    {
        // Stored output was generated from the trusted standalone synthetic parser, not the OS broker.
        $case = json_decode(file_get_contents(__DIR__.'/../Fixtures/developer-ac03-result.json'), true);
        $result = app(ResultGate::class)->validate($case['input'], $case['output']);
        $this->assertSame(['added' => 5172, 'deleted' => 378], $result['output']['counts']);
        $this->assertSame(['added' => 3800, 'deleted' => 200], $result['output']['buckets']['generated']);
        $this->assertSame(['added' => 900, 'deleted' => 100], $result['output']['buckets']['application']);
        $this->assertSame(['added' => 300, 'deleted' => 40], $result['output']['buckets']['tests']);
        $this->assertSame(['added' => 100, 'deleted' => 20], $result['output']['buckets']['config']);
        $this->assertSame(['added' => 72, 'deleted' => 18], $result['output']['buckets']['docs']);
        $this->assertCount(200, $result['output']['locators']);
        $this->assertSame(5350, $result['output']['omitted_locator_count']);
        $forged = $case['output'];
        $forged['locators'][199]['line']++;
        $this->expectException(InvalidArgumentException::class);
        app(ResultGate::class)->validate($case['input'], $forged);
    }

    public function test_binary_truncated_redacted_and_unknown_inputs_keep_partial_coverage(): void
    {
        foreach (['binary', 'truncated', 'redacted', 'unknown_path'] as $notice) {
            [$input, $output] = $this->envelopes();
            $snapshot = &$input['snapshot'];
            if ($notice === 'binary') {
                $snapshot['redacted_diff'] = "diff --git a/app/a.py b/app/a.py\nBinary files a/app/a.py and b/app/a.py differ\n";
                $output['counts'] = $output['files'][0]['counts'] = ['added' => 0, 'deleted' => 0];
                $output['buckets']['application'] = ['added' => 0, 'deleted' => 0];
                $output['locators'] = [];
            } elseif ($notice === 'truncated') {
                $snapshot['redacted_diff'] = "diff --git a/app/a.py b/app/a.py\n--- a/app/a.py\n+++ b/app/a.py\n@@ -1,2 +1,2 @@\n-old\n+new\n[TRUNCATED]\n";
            } elseif ($notice === 'redacted') {
                $snapshot['redacted_diff'] = "diff --git a/app/a.py b/app/a.py\n--- a/app/a.py\n+++ b/app/a.py\n@@ -1,1 +1,1 @@\n-old\n+[REDACTED]\n";
                $snapshot['redaction']['policy'] = 'redact-lines-v1';
                $snapshot['redaction']['mapping'] = [['diff_line' => 6, 'prefix' => '+']];
            } else {
                $snapshot['redacted_diff'] = str_replace('app/a.py', 'opaque/data.xyz', $snapshot['redacted_diff']);
                $snapshot['paths'] = [['old_path' => 'opaque/data.xyz', 'new_path' => 'opaque/data.xyz']];
                $output['files'][0]['old_path'] = $output['files'][0]['new_path'] = 'opaque/data.xyz';
                $output['files'][0]['bucket'] = 'unknown';
                $output['buckets']['application'] = ['added' => 0, 'deleted' => 0];
                $output['buckets']['unknown'] = ['added' => 1, 'deleted' => 1];
                foreach ($output['locators'] as &$locator) {
                    $locator['path'] = 'opaque/data.xyz';
                } unset($locator);
            }
            $snapshot['hash'] = $snapshot['redaction']['hash'] = hash('sha256', $snapshot['redacted_diff']);
            unset($input['input_hash']);
            $input['input_hash'] = hash('sha256', DeveloperContract::canonical($input));
            $output['input_hash'] = $input['input_hash'];
            $output['snapshot']['hash'] = $output['redaction_hash'] = $snapshot['hash'];
            foreach ($output['locators'] as &$locator) {
                $locator['snapshot_hash'] = $snapshot['hash'];
            } unset($locator);
            $notices = in_array($notice, ['binary', 'truncated'], true) ? ['counts_unknown', $notice] : [$notice];
            sort($notices);
            $output['coverage'] = $output['files'][0]['coverage'] = ['status' => 'partial', 'notices' => $notices];
            $output['files'][0]['flags'] = $notice === 'unknown_path' ? [] : [$notice];
            $output['patch'] = ['status' => $notice === 'unknown_path' ? 'exact' : 'unresolved', 'hash' => $notice === 'unknown_path' ? $snapshot['hash'] : null];
            $result = app(ResultGate::class)->validate($input, $output);
            $this->assertSame('partial', $result['output']['coverage']['status']);
            $this->assertSame($output['counts'], $result['output']['counts']);
            $output['coverage'] = ['status' => 'complete', 'notices' => []];
            try {
                app(ResultGate::class)->validate($input, $output);
                $this->fail('Partial coverage was lost.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }
}
