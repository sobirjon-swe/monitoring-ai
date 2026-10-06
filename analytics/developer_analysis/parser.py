"""Bounded, deterministic unified-diff facts. Never opens or executes source paths."""
import hashlib
import json
import re
import sys

RULE_VERSION = 'developer-taxonomy-v1'
BUCKETS = ('generated', 'vendor', 'tests', 'config', 'docs', 'application', 'unknown')
UUID = re.compile(r'[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\Z')
HEX64 = re.compile(r'[0-9a-f]{64}\Z')
HEX40 = re.compile(r'[0-9a-f]{40}\Z')
HUNK = re.compile(r'@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@(?: .*)?\Z')
HEADER_GENERATED = {'// @generated', '# @generated', '/* @generated */', '<!-- @generated -->'}
REGION_START = {'// BEGIN GENERATED', '# BEGIN GENERATED', '<!-- BEGIN GENERATED -->'}
REGION_END = {'// END GENERATED', '# END GENERATED', '<!-- END GENERATED -->'}
TRUNCATION = {'[TRUNCATED]', '... diff truncated ...'}


def canonical_json(value):
    """Contract UTF-8 canonical encoding, including literal Unicode separators."""
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(',', ':'), allow_nan=False).encode('utf-8')


def _require(condition):
    if not condition:
        raise ValueError('invalid_input')


def _shape(value, keys):
    _require(type(value) is dict and set(value) == set(keys))


def _text(value, minimum=0, maximum=500):
    _require(type(value) is str and minimum <= len(value) <= maximum)
    _require('\x00' not in value)
    value.encode('utf-8', 'strict')


def _matches(value, pattern):
    _require(type(value) is str and pattern.fullmatch(value) is not None)


def _integer(value, minimum, maximum):
    _require(type(value) is int and minimum <= value <= maximum)


def _path(value):
    if value is None:
        return
    _text(value, 1, 500)
    _require(len(value.encode('utf-8')) <= 500 and not value.startswith('/'))
    _require(not any(ord(c) <= 32 or ord(c) == 127 or c in '\\:' for c in value))
    _require(not any(part in ('', '.', '..') for part in value.split('/')))


def _descriptors(files):
    _require(type(files) is list and len(files) <= 100)
    pairs = []
    for file in files:
        _shape(file, ('old_path', 'new_path'))
        _path(file['old_path'])
        _path(file['new_path'])
        _require(file['old_path'] is not None or file['new_path'] is not None)
        pairs.append((file['old_path'], file['new_path']))
    _require(len(set(pairs)) == len(pairs))
    return pairs


def _classification(path):
    """Ordered visible path heuristics, with overlap retained as independent flags."""
    if path is None:
        return 'unknown', [], []
    parts = path.split('/')
    name = parts[-1]
    candidates = []
    if any(p in ('generated', 'gen', 'dist', 'build') for p in parts[:-1]) or name.endswith(('.generated.py', '.generated.php', '.generated.ts', '.g.cs', '.min.js', '.min.css')):
        candidates.append('generated')
    if any(p in ('vendor', 'node_modules', 'third_party', 'third-party') for p in parts[:-1]):
        candidates.append('vendor')
    if any(p in ('tests', 'test', '__tests__', 'spec') for p in parts[:-1]) or name.startswith('test_') or name.endswith(('_test.py', '.test.js', '.test.ts', '.spec.js', '.spec.ts', 'Test.php')):
        candidates.append('tests')
    if any(p in ('config', 'configuration') for p in parts[:-1]) or name.endswith(('.ini', '.toml', '.yaml', '.yml', '.json', '.env')) or name in ('Dockerfile', 'Makefile', '.gitignore'):
        candidates.append('config')
    if any(p in ('docs', 'documentation') for p in parts[:-1]) or name.endswith(('.md', '.rst', '.txt', '.adoc')):
        candidates.append('docs')
    if name.endswith(('.py', '.php', '.js', '.ts', '.jsx', '.tsx', '.java', '.cs', '.go', '.rs', '.c', '.cpp', '.h', '.rb', '.sql', '.html', '.css', '.vue', '.svelte')):
        candidates.append('application')
    primary = next((bucket for bucket in BUCKETS if bucket in candidates), 'unknown')
    flags = []
    if 'generated' in candidates:
        flags.append('generated')
    if len(candidates) > 1:
        flags.append('mixed')
    if any(p in ('templates', 'template') for p in parts[:-1]) or 'template' in name:
        flags.append('template')
    if any(p in ('migrations', 'migration') for p in parts[:-1]):
        flags.append('migration')
    return primary, flags, candidates


def _coverage(notices):
    return {'status': 'partial' if notices else 'complete', 'notices': sorted(set(notices))}


def _header_path(line, prefix):
    _require(line.startswith(prefix))
    path = line[len(prefix):]
    if path == '/dev/null':
        return None
    if path.startswith(('a/', 'b/')):
        path = path[2:]
    _path(path)
    return path


def _sections(lines):
    sections = []
    current = None
    remaining_old = remaining_new = 0
    has_new_header = wrapped = False
    for index, line in enumerate(lines, 1):
        if line.startswith(('diff --git ', 'diff --cc ', 'diff --combined ')):
            current = []
            sections.append(current)
            _require(len(sections) <= 100)
            has_new_header = False
            wrapped = True
            remaining_old = remaining_new = 0
        elif current is None and line.startswith('--- '):
            current = []
            sections.append(current)
        elif current is not None and line.startswith('--- ') and has_new_header and not wrapped and remaining_old == remaining_new == 0:
            # A completed ordinary unified patch can omit Git wrapper headers.
            current = []
            sections.append(current)
            _require(len(sections) <= 100)
            has_new_header = False
        _require(current is not None)
        current.append((index, line))
        match = HUNK.fullmatch(line)
        if match:
            remaining_old, remaining_new = int(match.group(2) or 1), int(match.group(4) or 1)
        elif remaining_old or remaining_new:
            if line.startswith((' ', '-')):
                remaining_old -= 1
            if line.startswith((' ', '+')):
                remaining_new -= 1
        elif line.startswith('+++ '):
            has_new_header = True
    return sections


def _parse_section(section, expected, hunk_offset):
    old_path, new_path = expected
    first = section[0][1]
    unsupported = first.startswith(('diff --cc ', 'diff --combined '))
    if unsupported:
        path = first.split(' ', 2)[2]
        _path(path)
        _require(expected == (path, path))
        primary, flags, _ = _classification(path)
        return {'old_path': path, 'new_path': path, 'bucket': primary, 'counts': {'added': 0, 'deleted': 0},
                'coverage': _coverage(['unsupported', 'counts_unknown']), 'flags': sorted(set(flags + ['unsupported']))}, [], [], 0
    if first.startswith('diff --git '):
        match = re.fullmatch(r'diff --git a/(\S+) b/(\S+)', first)
        _require(match is not None)
        for path in match.groups():
            _path(path)
        _require(match.group(1) == (old_path or new_path) and match.group(2) == (new_path or old_path))
    else:
        _require(first.startswith('--- '))
    notices = []
    flags = []
    old_bucket, old_flags, _ = _classification(old_path)
    new_bucket, new_flags, _ = _classification(new_path)
    primary = new_bucket if new_path is not None else old_bucket
    flags.extend(old_flags + new_flags)
    if old_path is not None and new_path is not None and old_path != new_path:
        flags.append('rename')
    records = []
    line_positions = []
    seen_old = seen_new = False
    hunk_count = 0
    hunk = None
    previous_end = {'old': 0, 'new': 0}
    truncated = binary = False
    modes = {}
    rename_from = rename_to = False
    similarity = None
    index_blobs = None

    def finish_hunk():
        if hunk is not None:
            _require(truncated or (hunk['remaining_old'] == 0 and hunk['remaining_new'] == 0))
            previous_end.update(old=hunk['old'], new=hunk['new'])

    for diff_line, line in section:
        if line == first and diff_line == section[0][0] and first.startswith('diff --git '):
            continue
        if line in TRUNCATION:
            truncated = True
            notices.extend(['truncated', 'counts_unknown'])
            flags.append('truncated')
            continue
        _require(not truncated)
        if hunk is not None and line.startswith((' ', '+', '-')) and (hunk['remaining_old'] or hunk['remaining_new']):
            prefix, content = line[0], line[1:]
            old_line = hunk['old'] if prefix in (' ', '-') else None
            new_line = hunk['new'] if prefix in (' ', '+') else None
            for side, coordinate in [('old', old_line), ('new', new_line)]:
                if coordinate is not None:
                    _require(1 <= coordinate <= 2147483647)
                    key = 'remaining_' + side
                    _require(hunk[key] > 0)
                    hunk[key] -= 1
                    hunk[side] += 1
            _require(old_path is not None or old_line is None)
            _require(new_path is not None or new_line is None)
            line_positions.append((diff_line, prefix, content, old_line, new_line))
            if prefix in ('+', '-'):
                records.append({'kind': 'added' if prefix == '+' else 'deleted', 'side': 'new' if prefix == '+' else 'old',
                                'line': new_line if prefix == '+' else old_line, 'hunk_index': hunk_offset + hunk_count - 1,
                                'content': content, 'diff_line': diff_line})
                _require(len(records) <= 50000)
            continue
        if line == '\\ No newline at end of file':
            _require(hunk is not None and line_positions and line_positions[-1][0] == diff_line - 1)
            continue
        if line.startswith('@@ '):
            finish_hunk()
            _require(seen_old and seen_new and not binary)
            match = HUNK.fullmatch(line)
            _require(match is not None)
            a, ac, b, bc = match.groups()
            a, ac, b, bc = int(a), int(ac or 1), int(b), int(bc or 1)
            _require(ac or bc)
            _require(0 <= a <= 2147483647 and 0 <= b <= 2147483647 and ac <= 50000 + 524288 and bc <= 50000 + 524288)
            _require((a > 0 or ac == 0) and (b > 0 or bc == 0))
            _require((old_path is not None or ac == 0) and (new_path is not None or bc == 0))
            _require(a >= previous_end['old'] and b >= previous_end['new'])
            hunk_count += 1
            _require(hunk_offset + hunk_count <= 2000)
            hunk = {'old': a, 'new': b, 'remaining_old': ac, 'remaining_new': bc}
            continue
        _require(hunk is None or hunk['remaining_old'] == hunk['remaining_new'] == 0)
        if line.startswith('--- '):
            _require(not seen_old and hunk is None and _header_path(line, '--- ') == old_path)
            seen_old = True
        elif line.startswith('+++ '):
            _require(seen_old and not seen_new and hunk is None and _header_path(line, '+++ ') == new_path)
            seen_new = True
        elif line.startswith('Binary files ') or line == 'GIT binary patch':
            _require(hunk is None)
            binary = True
            notices.extend(['binary', 'counts_unknown'])
            flags.append('binary')
        elif binary:
            # Encoded binary data is deliberately not decoded or assigned source coordinates.
            continue
        elif line.startswith(('old mode ', 'new mode ', 'new file mode ', 'deleted file mode ')):
            _require(hunk is None and first.startswith('diff --git '))
            mode_match = re.fullmatch(r'(old mode|new mode|new file mode|deleted file mode) ([0-7]{6})', line)
            _require(mode_match is not None and mode_match.group(1) not in modes)
            _require(mode_match.group(2) in ('100644', '100755', '120000', '160000'))
            modes[mode_match.group(1)] = mode_match.group(2)
        elif line.startswith(('similarity index ', 'dissimilarity index ')):
            _require(hunk is None and first.startswith('diff --git '))
            similarity_match = re.fullmatch(r'(similarity|dissimilarity) index (\d{1,3})%', line)
            _require(similarity_match is not None and int(similarity_match.group(2)) <= 100)
            if similarity_match.group(1) == 'similarity':
                _require(similarity is None)
                similarity = int(similarity_match.group(2))
        elif line.startswith('index '):
            _require(hunk is None and index_blobs is None and first.startswith('diff --git '))
            index_match = re.fullmatch(r'index ([0-9a-f]{7,40})\.\.([0-9a-f]{7,40})(?: (100644|100755|120000|160000))?', line)
            _require(index_match is not None)
            index_blobs = index_match.group(1), index_match.group(2)
        elif line.startswith('rename from '):
            _require(hunk is None and not rename_from and old_path is not None and new_path is not None
                     and old_path != new_path and line[12:] == old_path and first.startswith('diff --git '))
            rename_from = True
            flags.append('rename')
        elif line.startswith('rename to '):
            _require(hunk is None and not rename_to and old_path is not None and new_path is not None
                     and old_path != new_path and line[10:] == new_path and first.startswith('diff --git '))
            rename_to = True
            flags.append('rename')
        else:
            raise ValueError('invalid_input')
    finish_hunk()
    _require(seen_old == seen_new)
    _require(rename_from == rename_to)
    _require(('old mode' in modes) == ('new mode' in modes))
    if 'new file mode' in modes:
        _require(old_path is None and new_path is not None and len(modes) == 1)
    if 'deleted file mode' in modes:
        _require(new_path is None and old_path is not None and len(modes) == 1)
    mode_pair = 'old mode' in modes and 'new mode' in modes
    if mode_pair:
        _require(old_path is not None and new_path is not None and len(modes) == 2
                 and modes['old mode'] != modes['new mode'])
    if not binary and hunk_count == 0 and not truncated:
        # Inferred path flags are not evidence that omitted text is unchanged.
        _require(not seen_old and not seen_new)
        _require(first.startswith('diff --git ') and (mode_pair or rename_from))
        _require(old_path == new_path or rename_from)
        if rename_from and similarity != 100:
            notices.extend(['unsupported', 'counts_unknown'])
            flags.append('unsupported')
        if mode_pair and {modes['old mode'], modes['new mode']} != {'100644', '100755'}:
            notices.extend(['unsupported', 'counts_unknown'])
            flags.append('unsupported')
        if index_blobs is not None and index_blobs[0] != index_blobs[1]:
            notices.extend(['unsupported', 'counts_unknown'])
            flags.append('unsupported')
    else:
        _require(binary or (seen_old and seen_new))
    generated_side = {'old': False, 'new': False}
    for _, _, content, old_line, new_line in line_positions:
        if content in HEADER_GENERATED:
            if old_line == 1:
                generated_side['old'] = True
            if new_line == 1:
                generated_side['new'] = True
    regions = {'old': False, 'new': False}
    last_observed = {'old': None, 'new': None}
    classifications = {}
    for diff_line, prefix, content, old_line, new_line in line_positions:
        for side, coordinate in [('old', old_line), ('new', new_line)]:
            if coordinate is None:
                continue
            if regions[side] is True and last_observed[side] is not None and coordinate != last_observed[side] + 1:
                # Unobserved source may contain another region boundary; never extrapolate it.
                regions[side] = None
                notices.append('unsupported')
                flags.append('unsupported')
            last_observed[side] = coordinate
            if content in REGION_START:
                _require(not regions[side])
                regions[side] = True
            bucket = old_bucket if side == 'old' else new_bucket
            if generated_side[side] or regions[side] is True:
                bucket = 'generated'
            elif regions[side] is None and bucket != 'generated':
                bucket = 'unknown'
            if prefix == ('-' if side == 'old' else '+'):
                classifications[diff_line] = bucket
            if content in REGION_END:
                _require(regions[side] is not False)
                regions[side] = False
    _require(truncated or not any(regions.values()))
    if any(generated_side.values()):
        primary = 'generated'
    used = {classifications[r['diff_line']] for r in records}
    if 'generated' in used:
        flags.append('generated')
    if len(used) > 1:
        flags.append('mixed')
    if 'unknown' in used or primary == 'unknown':
        notices.append('unknown_path')
    counts = {'added': 0, 'deleted': 0}
    contributions = []
    for record in records:
        counts[record['kind']] += 1
        contributions.append((classifications[record['diff_line']], record['kind']))
        record['path'] = new_path if record['side'] == 'new' else old_path
        del record['content']
    return {'old_path': old_path, 'new_path': new_path, 'bucket': primary, 'counts': counts,
            'coverage': _coverage(notices), 'flags': sorted(set(flags))}, records, contributions, hunk_count


def analyze_diff(diff, files, rules_version):
    """Count observed lines; source declarations never supply numeric totals."""
    _require(rules_version == RULE_VERSION)
    _text(diff, 0, 524288)
    _require(len(diff.encode('utf-8')) <= 524288)
    expected = _descriptors(files)
    normalized = diff.replace('\r\n', '\n')
    _require('\r' not in normalized)
    lines = normalized.split('\n')
    if lines and lines[-1] == '':
        lines.pop()
    if normalized == '':
        lines = []
    sections = _sections(lines)
    _require(len(sections) == len(expected))
    totals = {'added': 0, 'deleted': 0}
    buckets = {name: {'added': 0, 'deleted': 0} for name in BUCKETS}
    output_files, locators, notices = [], [], []
    omitted = hunk_offset = 0
    for section, descriptor in zip(sections, expected):
        file, records, contributions, hunks = _parse_section(section, descriptor, hunk_offset)
        hunk_offset += hunks
        output_files.append(file)
        notices.extend(file['coverage']['notices'])
        for kind in totals:
            totals[kind] += file['counts'][kind]
        _require(sum(totals.values()) <= 50000)
        for bucket, kind in contributions:
            buckets[bucket][kind] += 1
        for record in records:
            if len(locators) < 200:
                locators.append({key: record[key] for key in ('path', 'side', 'line', 'hunk_index', 'kind')})
            else:
                omitted += 1
    unresolved = bool(set(notices) & {'binary', 'unsupported', 'truncated', 'redacted'})
    return {'counts': totals, 'buckets': buckets, 'files': output_files, 'locators': locators,
            'omitted_locator_count': omitted, 'coverage': _coverage(notices),
            'patch': {'status': 'unresolved' if unresolved else 'exact', 'hash': None if unresolved else hashlib.sha256(normalized.encode('utf-8')).hexdigest()}}


def parse_input(envelope):
    """Validate frozen input and bind every representative to immutable source pins."""
    _shape(envelope, ('version', 'run_id', 'request_id', 'input_hash', 'pins', 'analyzer_version', 'rule_version', 'snapshot'))
    _require(len(canonical_json(envelope)) <= 1048576)
    _require(envelope['version'] == 'developer-analysis-input-v1' and envelope['analyzer_version'] == 'unified-diff-v1' and envelope['rule_version'] == RULE_VERSION)
    for key in ('run_id', 'request_id'):
        _matches(envelope[key], UUID)
    _matches(envelope['input_hash'], HEX64)
    _shape(envelope['pins'], ('criterion_version_ids', 'task_version_ids'))
    for values in envelope['pins'].values():
        _require(type(values) is list and len(values) <= 100)
        for value in values:
            _matches(value, UUID)
        _require(values == sorted(set(values)))
    snapshot = envelope['snapshot']
    _shape(snapshot, ('id', 'hash', 'base_sha', 'head_sha', 'source_instance_id', 'repository_id', 'mr_id', 'revision_ordinal', 'paths', 'redacted_diff', 'redaction'))
    _matches(snapshot['id'], UUID)
    _matches(snapshot['hash'], HEX64)
    for key in ('base_sha', 'head_sha'):
        _matches(snapshot[key], HEX40)
    _require(snapshot['base_sha'] != snapshot['head_sha'])
    for key in ('source_instance_id', 'repository_id', 'mr_id'):
        _text(snapshot[key], 1, 200)
    _integer(snapshot['revision_ordinal'], 1, 2147483647)
    _descriptors(snapshot['paths'])
    _text(snapshot['redacted_diff'], 0, 524288)
    safe = snapshot['redacted_diff'].replace('\r\n', '\n')
    _require(len(safe.encode('utf-8')) <= 524288)
    _require(hashlib.sha256(safe.encode('utf-8')).hexdigest() == snapshot['hash'])
    redaction = snapshot['redaction']
    _shape(redaction, ('version', 'policy', 'hash', 'mapping'))
    _require(redaction['version'] == 'secret-gate-v1' and redaction['policy'] in ('block-v1', 'redact-lines-v1'))
    _matches(redaction['hash'], HEX64)
    _require(redaction['hash'] == snapshot['hash'])
    _require(type(redaction['mapping']) is list and len(redaction['mapping']) <= 50000)
    _require(redaction['policy'] != 'block-v1' or not redaction['mapping'])
    mapping_lines = set()
    lines = safe.split('\n')
    for mapped in redaction['mapping']:
        _shape(mapped, ('diff_line', 'prefix'))
        _integer(mapped['diff_line'], 1, min(600000, len(lines)))
        _require(mapped['prefix'] in ('+', '-', ' '))
        _require(mapped['diff_line'] not in mapping_lines)
        _require(lines[mapped['diff_line'] - 1] == mapped['prefix'] + '[REDACTED]')
        mapping_lines.add(mapped['diff_line'])
    unsigned = {key: value for key, value in envelope.items() if key != 'input_hash'}
    _require(hashlib.sha256(canonical_json(unsigned)).hexdigest() == envelope['input_hash'])
    result = analyze_diff(safe, snapshot['paths'], envelope['rule_version'])
    if mapping_lines:
        result['coverage'] = _coverage(result['coverage']['notices'] + ['redacted'])
        result['patch'] = {'status': 'unresolved', 'hash': None}
        # Mapping cannot point into headers or unparsed/unsupported content.
        sections = _sections(lines[:-1] if lines[-1] == '' else lines)
        found = set()
        for file, section in zip(result['files'], sections):
            hunk_seen = False
            for diff_line, line in section:
                hunk_seen = hunk_seen or line.startswith('@@ ')
                if diff_line in mapping_lines:
                    _require(hunk_seen and 'binary' not in file['flags'] and 'unsupported' not in file['flags'])
                    found.add(diff_line)
                    file['coverage'] = _coverage(file['coverage']['notices'] + ['redacted'])
                    file['flags'] = sorted(set(file['flags'] + ['redacted']))
        _require(found == mapping_lines)
    bound = {key: snapshot[key] for key in ('id', 'hash', 'base_sha', 'head_sha')}
    for locator in result['locators']:
        locator.update(snapshot_id=bound['id'], snapshot_hash=bound['hash'], base_sha=bound['base_sha'], head_sha=bound['head_sha'])
    result.update(version='developer-analysis-output-v1', run_id=envelope['run_id'], request_id=envelope['request_id'],
                  input_hash=envelope['input_hash'], pins=envelope['pins'], analyzer_version=envelope['analyzer_version'],
                  rule_version=envelope['rule_version'], snapshot=bound, redaction_version=redaction['version'], redaction_hash=redaction['hash'])
    _require(len(canonical_json(result)) <= 262144)
    return result


def _object(pairs):
    result = {}
    for key, value in pairs:
        _require(key not in result)
        result[key] = value
    return result


def main():
    try:
        raw = sys.stdin.buffer.read(1048577)
        _require(len(raw) <= 1048576)
        value = json.loads(raw.decode('utf-8', 'strict'), object_pairs_hook=_object, parse_constant=lambda _: _require(False))
        output = canonical_json(parse_input(value))
        _require(len(output) + 1 <= 262144)
        sys.stdout.buffer.write(output + b'\n')
        return 0
    except (ValueError, TypeError, KeyError, OverflowError, RecursionError, MemoryError):
        sys.stderr.buffer.write(b'invalid_input\n')
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
