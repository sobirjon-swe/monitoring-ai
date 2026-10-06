"""Behavioral tests with independently counted synthetic unified patches."""
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[2]
PARSER = ROOT / 'analytics/developer_analysis/parser.py'
spec = importlib.util.spec_from_file_location('developer_parser', PARSER)
parser = importlib.util.module_from_spec(spec)
spec.loader.exec_module(parser)


def patch(path='app/a.py', old=1, new=1, body='-old\n+new\n', start_old=1, start_new=1):
    return (f'diff --git a/{path} b/{path}\n--- a/{path}\n+++ b/{path}\n'
            f'@@ -{start_old},{old} +{start_new},{new} @@\n{body}')


def paths(*names):
    return [{'old_path': name, 'new_path': name} for name in names]


def canonical(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(',', ':'), allow_nan=False).encode('utf-8')


def envelope(diff, descriptors=None):
    digest = hashlib.sha256(diff.replace('\r\n', '\n').encode('utf-8')).hexdigest()
    value = {
        'version': 'developer-analysis-input-v1', 'run_id': '11111111-1111-1111-1111-111111111111',
        'request_id': '22222222-2222-2222-2222-222222222222', 'pins': {'criterion_version_ids': [], 'task_version_ids': []},
        'analyzer_version': 'unified-diff-v1', 'rule_version': 'developer-taxonomy-v1',
        'snapshot': {'id': '33333333-3333-3333-3333-333333333333', 'hash': digest,
            'base_sha': 'a' * 40, 'head_sha': 'b' * 40, 'source_instance_id': 'synthetic',
            'repository_id': 'repo', 'mr_id': '42', 'revision_ordinal': 1,
            'paths': descriptors if descriptors is not None else paths('app/a.py'), 'redacted_diff': diff,
            'redaction': {'version': 'secret-gate-v1', 'policy': 'block-v1', 'hash': digest, 'mapping': []}},
    }
    value['input_hash'] = hashlib.sha256(canonical(value)).hexdigest()
    return value


def rehash(value):
    value.pop('input_hash', None)
    value['input_hash'] = hashlib.sha256(canonical(value)).hexdigest()
    return value


def assert_schema(case, value, schema):
    """Independent local checks for the finite contract schema vocabulary."""
    if 'const' in schema:
        case.assertEqual(schema['const'], value)
    if 'enum' in schema:
        case.assertIn(value, schema['enum'])
    kinds = {'object': dict, 'array': list, 'string': str, 'integer': int, 'null': type(None)}
    if 'type' in schema:
        types = schema['type'] if isinstance(schema['type'], list) else [schema['type']]
        case.assertIn(type(value), [kinds[k] for k in types])
    if isinstance(value, dict):
        case.assertTrue(set(schema.get('required', [])) <= set(value))
        if schema.get('additionalProperties') is False:
            case.assertTrue(set(value) <= set(schema['properties']))
        for key, item in value.items():
            assert_schema(case, item, schema['properties'][key])
    elif isinstance(value, list):
        case.assertLessEqual(len(value), schema.get('maxItems', len(value)))
        if schema.get('uniqueItems'):
            case.assertEqual(len(value), len({json.dumps(item, sort_keys=True) for item in value}))
        for item in value:
            assert_schema(case, item, schema['items'])
    elif isinstance(value, str):
        case.assertGreaterEqual(len(value), schema.get('minLength', 0))
        case.assertLessEqual(len(value), schema.get('maxLength', len(value)))
        if 'pattern' in schema:
            case.assertRegex(value, schema['pattern'])
    elif type(value) is int:
        case.assertGreaterEqual(value, schema.get('minimum', value))
        case.assertLessEqual(value, schema.get('maximum', value))


class DeveloperParserTest(unittest.TestCase):
    def test_mode_metadata_cannot_bypass_advertised_text_structure(self):
        mode = 'diff --git a/app/a.py b/app/a.py\nold mode 100644\nnew mode 100755\n'
        for headers in ['--- a/app/a.py\n', '+++ b/app/a.py\n', '--- a/app/a.py\n+++ b/app/a.py\n']:
            with self.subTest(headers=headers), self.assertRaises(ValueError):
                parser.parse_input(envelope(mode + headers))

    def test_mode_metadata_different_index_blobs_discloses_missing_content(self):
        mode = 'diff --git a/app/a.py b/app/a.py\nold mode 100644\nnew mode 100755\n'
        for index in ['index 1111111..2222222\n', 'index 1111111..11111111\n']:
            r = parser.parse_input(envelope(mode + index))
            self.assertEqual({'added': 0, 'deleted': 0}, r['counts'])
            self.assertEqual('partial', r['coverage']['status'])
            self.assertIn('counts_unknown', r['coverage']['notices'])
            self.assertEqual({'status': 'unresolved', 'hash': None}, r['patch'])
        r = parser.parse_input(envelope(mode + 'index 1111111..1111111\n'))
        self.assertEqual({'status': 'complete', 'notices': []}, r['coverage'])
        self.assertEqual({'added': 0, 'deleted': 0}, r['counts'])
        for metadata in ['index malformed\n', 'index 1111111..1111111\nindex 1111111..2222222\n']:
            with self.subTest(metadata=metadata), self.assertRaises(ValueError):
                parser.parse_input(envelope(mode + metadata))

    def test_verified_mode_only_and_rename_only_changes(self):
        mode = 'diff --git a/app/a.py b/app/a.py\nold mode 100644\nnew mode 100755\n'
        rename = 'diff --git a/app/a.py b/app/b.py\nsimilarity index 100%\nrename from app/a.py\nrename to app/b.py\n'
        for diff, descriptors in [(mode, paths('app/a.py')), (rename, [{'old_path': 'app/a.py', 'new_path': 'app/b.py'}])]:
            with self.subTest(diff=diff):
                r = parser.parse_input(envelope(diff, descriptors))
                self.assertEqual({'added': 0, 'deleted': 0}, r['counts'])
                self.assertEqual({'status': 'complete', 'notices': []}, r['coverage'])
                self.assertEqual([], r['locators'])
                self.assertEqual('exact', r['patch']['status'])

    def test_incomplete_non_text_metadata_never_claims_complete_zero(self):
        same = 'diff --git a/app/a.py b/app/a.py\n'
        renamed = 'diff --git a/app/a.py b/app/b.py\n'
        for diff, descriptors in [(same, paths('app/a.py')), (same + 'old mode 100644\n', paths('app/a.py')),
                                 (same + 'new mode 100755\n', paths('app/a.py')),
                                 (same + 'old mode invalid\nnew mode 100755\n', paths('app/a.py')),
                                 (same + 'old mode 000000\nnew mode 100755\n', paths('app/a.py')),
                                 (renamed, [{'old_path': 'app/a.py', 'new_path': 'app/b.py'}]),
                                 (renamed + 'rename from app/a.py\n', [{'old_path': 'app/a.py', 'new_path': 'app/b.py'}]),
                                 (renamed + 'rename to app/b.py\n', [{'old_path': 'app/a.py', 'new_path': 'app/b.py'}])]:
            with self.subTest(diff=diff), self.assertRaises(ValueError):
                parser.parse_input(envelope(diff, descriptors))
        # Paired rename proves the move, but absent/less-than-100 similarity cannot prove no text changes.
        for similarity in ['', 'similarity index 60%\n']:
            r = parser.parse_input(envelope(renamed + similarity + 'rename from app/a.py\nrename to app/b.py\n', [{'old_path': 'app/a.py', 'new_path': 'app/b.py'}]))
            self.assertEqual('partial', r['coverage']['status'])
            self.assertIn('counts_unknown', r['coverage']['notices'])
            self.assertEqual({'status': 'unresolved', 'hash': None}, r['patch'])

    def test_generated_region_gap_does_not_extrapolate_and_conserves(self):
        diff = ('diff --git a/app/a.py b/app/a.py\n--- a/app/a.py\n+++ b/app/a.py\n'
                '@@ -1 +1 @@\n-// BEGIN GENERATED\n+// BEGIN GENERATED\n'
                '@@ -99,2 +99,2 @@\n-manual_old\n-// END GENERATED\n+manual_new\n+// END GENERATED\n')
        r = parser.parse_input(envelope(diff))
        self.assertEqual({'added': 3, 'deleted': 3}, r['counts'])
        self.assertEqual({'added': 1, 'deleted': 1}, r['buckets']['generated'])
        self.assertEqual({'added': 2, 'deleted': 2}, r['buckets']['unknown'])
        self.assertEqual('partial', r['coverage']['status'])
        self.assertIn('unsupported', r['coverage']['notices'])
        self.assertEqual({'status': 'unresolved', 'hash': None}, r['patch'])
        self.assertEqual(6, len(r['locators']))
        for kind in ('added', 'deleted'):
            self.assertEqual(3, sum(bucket[kind] for bucket in r['buckets'].values()))
        # An adjacent hunk boundary retains actually observed contiguous region authority.
        contiguous = diff.replace('@@ -99,2 +99,2 @@', '@@ -2,2 +2,2 @@')
        r = parser.parse_input(envelope(contiguous))
        self.assertEqual({'added': 3, 'deleted': 3}, r['buckets']['generated'])
        self.assertEqual('complete', r['coverage']['status'])

    def test_outputs_match_frozen_schema_for_complete_partial_and_redacted(self):
        input_schema = json.loads((ROOT / 'docs/contracts/developer-analysis-input-v1.schema.json').read_text(encoding='utf-8'))
        output_schema = json.loads((ROOT / 'docs/contracts/developer-analysis-output-v1.schema.json').read_text(encoding='utf-8'))
        samples = [envelope(patch()), envelope('diff --git a/app/a.py b/app/a.py\nBinary files a/app/a.py and b/app/a.py differ\n'), envelope(patch(old=2, new=2, body='-old\n+new\n[TRUNCATED]\n'))]
        redacted = envelope(patch(body='-old\n+[REDACTED]\n'))
        redacted['snapshot']['redaction'].update(policy='redact-lines-v1', mapping=[{'diff_line': 6, 'prefix': '+'}])
        samples.append(rehash(redacted))
        for value in samples:
            assert_schema(self, value, input_schema)
            result = parser.parse_input(value)
            assert_schema(self, result, output_schema)
            for kind in ('added', 'deleted'):
                self.assertEqual(result['counts'][kind], sum(file['counts'][kind] for file in result['files']))
                self.assertEqual(result['counts'][kind], sum(bucket[kind] for bucket in result['buckets'].values()))

    def test_plain_unified_content_that_looks_like_file_headers(self):
        diff = '--- a/app/a.py\n+++ b/app/a.py\n@@ -1 +1 @@\n--- old\n+++ new\n'
        r = parser.analyze_diff(diff, paths('app/a.py'), 'developer-taxonomy-v1')
        self.assertEqual({'added': 1, 'deleted': 1}, r['counts'])
        self.assertEqual([1, 1], [x['line'] for x in r['locators']])

    def test_unicode_canonical_bytes_are_shared_contract_literal(self):
        self.assertEqual('{"a":"Ω/\u2028\u2029","z":[2,1]}'.encode('utf-8'), parser.canonical_json({'z': [2, 1], 'a': 'Ω/\u2028\u2029'}))
        value = envelope(patch(body='-old\n+Ω\u2028\u2029\n'))
        self.assertEqual({'added': 1, 'deleted': 1}, parser.parse_input(value)['counts'])

    def test_creation_deletion_rename_and_context_coordinates(self):
        cases = [
            ('diff --git a/app/new.py b/app/new.py\nnew file mode 100644\n--- /dev/null\n+++ b/app/new.py\n@@ -0,0 +1,2 @@\n+a\n+b\n', [{'old_path': None, 'new_path': 'app/new.py'}], {'added': 2, 'deleted': 0}, ['new', 'new'], [1, 2]),
            ('diff --git a/app/old.py b/app/old.py\ndeleted file mode 100644\n--- a/app/old.py\n+++ /dev/null\n@@ -1,2 +0,0 @@\n-a\n-b\n', [{'old_path': 'app/old.py', 'new_path': None}], {'added': 0, 'deleted': 2}, ['old', 'old'], [1, 2]),
            ('diff --git a/app/old.py b/app/new.py\nsimilarity index 60%\nrename from app/old.py\nrename to app/new.py\n--- a/app/old.py\n+++ b/app/new.py\n@@ -4,3 +7,3 @@\n context\n-old\n+new\n trailing\n\\ No newline at end of file\n', [{'old_path': 'app/old.py', 'new_path': 'app/new.py'}], {'added': 1, 'deleted': 1}, ['old', 'new'], [5, 8]),
        ]
        for diff, descriptors, counts, sides, coordinates in cases:
            with self.subTest(counts=counts):
                r = parser.analyze_diff(diff, descriptors, 'developer-taxonomy-v1')
                self.assertEqual(counts, r['counts'])
                self.assertEqual(sides, [x['side'] for x in r['locators']])
                self.assertEqual(coordinates, [x['line'] for x in r['locators']])
        self.assertIn('rename', r['files'][0]['flags'])

    def test_generated_declaration_only_at_file_start_and_closed_regions(self):
        for body, generated, application in [('+// @generated\n+machine\n', 2, 0), ('+manual\n+// @generated\n', 0, 2)]:
            r = parser.analyze_diff(patch(old=0, new=2, start_old=0, body=body), paths('app/a.py'), 'developer-taxonomy-v1')
            self.assertEqual(generated, r['buckets']['generated']['added'])
            self.assertEqual(application, r['buckets']['application']['added'])
        for body in ['+// BEGIN GENERATED\n+machine\n', '+// END GENERATED\n+machine\n']:
            with self.assertRaises(ValueError):
                parser.analyze_diff(patch(old=0, new=2, start_old=0, body=body), paths('app/a.py'), 'developer-taxonomy-v1')

    def test_hunk_limit_and_untrusted_declared_totals(self):
        too_many = 'diff --git a/app/a.py b/app/a.py\n--- a/app/a.py\n+++ b/app/a.py\n' + ''.join(f'@@ -{i} +{i} @@\n-old\n+new\n' for i in range(1, 2002))
        with self.assertRaises(ValueError): parser.analyze_diff(too_many, paths('app/a.py'), 'developer-taxonomy-v1')
        malformed = patch().replace('@@ -1,1 +1,1', '@@ -1,5172 +1,378')
        with self.assertRaises(ValueError): parser.analyze_diff(malformed, paths('app/a.py'), 'developer-taxonomy-v1')
        for unsafe in ['/absolute', 'app/../secret', 'C:/secret', 'app\\secret', 'app/a b.py', 'app//a.py', 'app/./a.py']:
            with self.subTest(path=unsafe), self.assertRaises(ValueError): parser.analyze_diff('', paths(unsafe), 'developer-taxonomy-v1')

    def test_output_overflow_returns_no_partial_json(self):
        descriptors, parts = [], []
        for i in range(100):
            old = 'old/' + 'x' * 483 + f'/{i:03}.py'
            new = 'new/' + 'y' * 483 + f'/{i:03}.py'
            descriptors.append({'old_path': old, 'new_path': new})
            parts.append(f'diff --git a/{old} b/{new}\n--- a/{old}\n+++ b/{new}\n@@ -1 +1 @@\n-old\n+new\n')
        raw = json.dumps(envelope(''.join(parts), descriptors), ensure_ascii=False).encode('utf-8')
        self.assertLess(len(raw), 1048576)
        proc = subprocess.run([sys.executable, '-I', '-B', str(PARSER)], input=raw, capture_output=True, timeout=10)
        self.assertEqual(1, proc.returncode)
        self.assertEqual(b'', proc.stdout)
        self.assertEqual(b'invalid_input\n', proc.stderr)

    def test_ac03_conserves_observed_added_deleted(self):
        diff = (ROOT / 'analytics/tests/fixtures/ac03.diff').read_text(encoding='utf-8')
        result = parser.analyze_diff(diff, paths('generated/client.py', 'app/service.py', 'tests/service_test.py', 'config/settings.ini', 'docs/guide.md'), 'developer-taxonomy-v1')
        self.assertEqual({'added': 5172, 'deleted': 378}, result['counts'])
        self.assertEqual({'generated': {'added': 3800, 'deleted': 200}, 'vendor': {'added': 0, 'deleted': 0},
            'application': {'added': 900, 'deleted': 100}, 'tests': {'added': 300, 'deleted': 40},
            'config': {'added': 100, 'deleted': 20}, 'docs': {'added': 72, 'deleted': 18}, 'unknown': {'added': 0, 'deleted': 0}}, result['buckets'])
        self.assertEqual(200, len(result['locators']))
        self.assertEqual(5350, result['omitted_locator_count'])

    def test_injection_is_text(self):
        result = parser.analyze_diff(patch(body='-old\n+__import__("os").system("echo INJECTION")\n'), paths('app/a.py'), 'developer-taxonomy-v1')
        self.assertEqual({'added': 1, 'deleted': 1}, result['counts'])
        self.assertEqual('complete', result['coverage']['status'])

    def test_bad_hunk_rejected(self):
        for diff in [patch(old=2), patch(start_new=0), patch(body='-old\n+new\n+extra\n'),
                     patch() + '@@ -1 +1 @@\n-old\n+new\n', patch().replace('+++ b/app/a.py', '+++ b/app/b.py')]:
            with self.subTest(diff=diff), self.assertRaises(ValueError):
                parser.analyze_diff(diff, paths('app/a.py'), 'developer-taxonomy-v1')

    def test_unsupported_truncated_counts_unknown(self):
        binary = 'diff --git a/app/a.py b/app/a.py\nBinary files a/app/a.py and b/app/a.py differ\n'
        unsupported = 'diff --cc app/a.py\n@@@ -1,1 -1,1 +1,1 @@@\n++not a normal hunk\n'
        truncated = patch(old=2, new=2, body='-old\n+new\n[TRUNCATED]\n')
        for diff, notice, counts in [(binary, 'binary', {'added': 0, 'deleted': 0}), (unsupported, 'unsupported', {'added': 0, 'deleted': 0}), (truncated, 'truncated', {'added': 1, 'deleted': 1})]:
            with self.subTest(notice=notice):
                r = parser.analyze_diff(diff, paths('app/a.py'), 'developer-taxonomy-v1')
                self.assertEqual(counts, r['counts'])
                self.assertIn(notice, r['coverage']['notices'])
                self.assertIn('counts_unknown', r['coverage']['notices'])
                self.assertEqual({'status': 'unresolved', 'hash': None}, r['patch'])
                if notice != 'truncated':
                    self.assertEqual([], r['locators'])

    def test_unknown_mixed_generator_relation(self):
        diff = (ROOT / 'analytics/tests/fixtures/mixed.diff').read_text(encoding='utf-8')
        r = parser.analyze_diff(diff, paths('app/template.py', 'vendor/tests/tool.py', 'misc/opaque.xyz', 'database/migrations/add.sql'), 'developer-taxonomy-v1')
        self.assertEqual({'added': 8, 'deleted': 0}, r['counts'])
        self.assertEqual({'added': 4, 'deleted': 0}, r['buckets']['generated'])
        self.assertEqual({'added': 1, 'deleted': 0}, r['buckets']['vendor'])
        self.assertEqual({'added': 1, 'deleted': 0}, r['buckets']['unknown'])
        self.assertIn('mixed', r['files'][0]['flags'])
        self.assertIn('template', r['files'][0]['flags'])
        self.assertIn('mixed', r['files'][1]['flags'])
        self.assertIn('migration', r['files'][3]['flags'])
        # A generated word in arbitrary content does not classify the whole file.
        r = parser.analyze_diff(patch(old=0, new=2, body='+value = "@generated"\n+normal\n', start_old=0), paths('app/a.py'), 'developer-taxonomy-v1')
        self.assertEqual({'added': 2, 'deleted': 0}, r['buckets']['application'])

    def test_limits_and_locator_cap(self):
        r = parser.analyze_diff(patch(old=0, new=201, body='+x\n' * 201, start_old=0), paths('app/a.py'), 'developer-taxonomy-v1')
        self.assertEqual(201, r['counts']['added'])
        self.assertEqual(200, len(r['locators']))
        self.assertEqual(1, r['omitted_locator_count'])
        for diff, descriptors in [('x' * 524289, []), ('', paths(*['app/' + str(i) for i in range(101)])),
                                  (patch(old=0, new=50001, body='+x\n' * 50001, start_old=0), paths('app/a.py'))]:
            with self.assertRaises(ValueError):
                parser.analyze_diff(diff, descriptors, 'developer-taxonomy-v1')

    def test_redacted_dedupe_unresolved(self):
        value = envelope(patch(body='-old\n+[REDACTED]\n'))
        value['snapshot']['redaction'].update(policy='redact-lines-v1', mapping=[{'diff_line': 6, 'prefix': '+'}])
        r = parser.parse_input(rehash(value))
        self.assertEqual({'status': 'unresolved', 'hash': None}, r['patch'])
        self.assertIn('redacted', r['coverage']['notices'])
        for mapping in [[{'diff_line': 4, 'prefix': '+'}], [{'diff_line': 6, 'prefix': '-'}], [{'diff_line': 6, 'prefix': '+'}] * 2]:
            bad = copy.deepcopy(value)
            bad['snapshot']['redaction']['mapping'] = mapping
            with self.assertRaises(ValueError):
                parser.parse_input(rehash(bad))

    def test_strict_patch_normalization(self):
        lf = patch(body='-old\n+new \t\n')
        a = parser.analyze_diff(lf, paths('app/a.py'), 'developer-taxonomy-v1')
        b = parser.analyze_diff(lf.replace('\n', '\r\n'), paths('app/a.py'), 'developer-taxonomy-v1')
        self.assertEqual(a['patch'], b['patch'])
        self.assertEqual(hashlib.sha256(lf.encode()).hexdigest(), a['patch']['hash'])
        for altered in [lf.replace('new \t', 'new'), lf.replace('@@ -1,1 +1,1', '@@ -2,1 +2,1')]:
            self.assertNotEqual(a['patch']['hash'], parser.analyze_diff(altered, paths('app/a.py'), 'developer-taxonomy-v1')['patch']['hash'])

    def test_envelope_hash_shape_paths_and_locators(self):
        value = envelope(patch(start_old=4, start_new=7))
        r = parser.parse_input(value)
        self.assertEqual('developer-analysis-output-v1', r['version'])
        self.assertEqual(value['input_hash'], r['input_hash'])
        self.assertEqual({'snapshot_id': value['snapshot']['id'], 'snapshot_hash': value['snapshot']['hash'], 'base_sha': 'a' * 40, 'head_sha': 'b' * 40, 'path': 'app/a.py', 'side': 'old', 'line': 4, 'hunk_index': 0, 'kind': 'deleted'}, r['locators'][0])
        self.assertEqual(7, r['locators'][1]['line'])
        for mutation in ['hash', 'extra', 'path', 'same_sha', 'bool_ordinal', 'pins']:
            bad = copy.deepcopy(value)
            if mutation == 'hash': bad['input_hash'] = '0' * 64
            elif mutation == 'extra': bad['scope'] = 'arbitrary'
            elif mutation == 'path': bad['snapshot']['paths'][0]['new_path'] = '../secret'
            elif mutation == 'same_sha': bad['snapshot']['head_sha'] = 'a' * 40
            elif mutation == 'bool_ordinal': bad['snapshot']['revision_ordinal'] = True
            else: bad['pins']['criterion_version_ids'] = ['11111111-1111-1111-1111-111111111111'] * 2
            if mutation != 'hash': rehash(bad)
            with self.subTest(mutation=mutation), self.assertRaises(ValueError): parser.parse_input(bad)

    def test_cli_emits_only_complete_json_and_safe_errors(self):
        proc = subprocess.run([sys.executable, '-I', '-B', str(PARSER)], input=json.dumps(envelope(patch())).encode(), capture_output=True, timeout=10)
        self.assertEqual(0, proc.returncode)
        self.assertEqual({'added': 1, 'deleted': 1}, json.loads(proc.stdout)['counts'])
        self.assertEqual(b'', proc.stderr)
        for raw in [b'{"password":"SECRET"}', b'{"x":1,"x":2}', b'{' + b' ' * 1048576, b'\xff']:
            proc = subprocess.run([sys.executable, '-I', '-B', str(PARSER)], input=raw, capture_output=True, timeout=10)
            self.assertNotEqual(0, proc.returncode)
            self.assertEqual(b'', proc.stdout)
            self.assertEqual(b'invalid_input\n', proc.stderr)


if __name__ == '__main__':
    unittest.main()
