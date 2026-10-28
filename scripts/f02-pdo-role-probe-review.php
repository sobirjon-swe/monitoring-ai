<?php
// REVIEW DRAFT. Execute only via the reviewed Windows ACL-checking wrapper.
// This probe never commits. The administrator and same Windows user are trusted.
declare(strict_types=1);

require_once __DIR__.'/f02-probe-frame-review.php';
$primary = null;
$verify = null;
$started = false;
$identity = null;
$status = 1;
$recordBytes = null;
$passwordBytes = null;
$password = null;
$guard = null;
$check = null;
$name = 'f02_test_probe_'.bin2hex(random_bytes(16));
try {
    throw new RuntimeException('One-time probe disabled after completion');
    [$recordBytes, $passwordBytes] = f02ReadFrames(STDIN);
    $record = json_decode($recordBytes, true, 16, JSON_THROW_ON_ERROR);
    if (($record['version'] ?? null) !== 1 || ($record['host'] ?? null) !== '127.0.0.1'
        || ($record['port'] ?? null) !== 55432 || ($record['test_database'] ?? null) !== 'monitoring_ai_test'
        || ($record['owner_role'] ?? null) !== 'monitoring_ai_owner'
        || !preg_match('/\A[0-9a-f-]{36}\z/D', $record['installation_id'] ?? '')) {
        throw new RuntimeException('Record refused');
    }
    $marker = 'monitoring-ai-local-v1:'.$record['installation_id'];
    $password = rtrim($passwordBytes, "\r\n");
    if ($password === '') { throw new RuntimeException('Credential unavailable'); }
    $connect = static fn () => new PDO('pgsql:host=127.0.0.1;port=55432;dbname=monitoring_ai_test;connect_timeout=5',
        'postgres', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_PERSISTENT => false]);
    $primary = $connect();
    $primary->exec("SET statement_timeout = '10s'; SET idle_in_transaction_session_timeout = '15s'; SET transaction_timeout = '30s'");
    $primary->beginTransaction();
    $started = true;
    $guard = $primary->prepare("SELECT (current_database() = 'monitoring_ai_test' AND session_user = 'postgres' AND current_user = 'postgres'
        AND inet_server_addr() = '127.0.0.1'::inet AND inet_server_port() = 55432
        AND current_setting('server_version_num')::int >= 170000
        AND pg_get_userbyid(d.datdba) = 'monitoring_ai_owner' AND shobj_description(d.oid, 'pg_database') = :marker
        AND (SELECT rolsuper FROM pg_roles WHERE rolname = current_user))::int AS ok,
        pg_backend_pid() AS pid, (SELECT backend_start::text FROM pg_stat_activity WHERE pid = pg_backend_pid()) AS started
        FROM pg_database d WHERE d.datname = current_database()");
    $guard->execute(['marker' => $marker]);
    $identity = $guard->fetch(PDO::FETCH_ASSOC);
    if (!$identity || (int) $identity['ok'] !== 1 || !$identity['pid'] || !$identity['started']) { throw new RuntimeException('Session refused'); }
    $primary->exec('CREATE ROLE "'.$name.'" NOLOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS');
    $check = $primary->prepare("SELECT (NOT rolcanlogin AND NOT rolinherit AND NOT rolsuper AND NOT rolcreatedb AND NOT rolcreaterole AND NOT rolreplication AND NOT rolbypassrls
        AND NOT EXISTS (SELECT FROM pg_auth_members WHERE member = r.oid OR roleid = r.oid))::int AS ok
        FROM pg_roles r WHERE rolname = :name");
    $check->execute(['name' => $name]);
    $result = $check->fetch(PDO::FETCH_ASSOC);
    if (!$result || (int) $result['ok'] !== 1) { throw new RuntimeException('Role checks failed'); }
    $primary->rollBack();
    $started = false;
    // PDO statements retain their connection; release both before testing backend disappearance.
    $guard = null;
    $check = null;
    $primary = null;
    $verify = $connect();
    $verify->exec("SET statement_timeout = '10s'");
    $post = $verify->prepare("SELECT (current_database() = 'monitoring_ai_test' AND session_user = 'postgres' AND current_user = 'postgres'
        AND inet_server_addr() = '127.0.0.1'::inet AND inet_server_port() = 55432
        AND current_setting('server_version_num')::int >= 170000
        AND (SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = current_database()) = 'monitoring_ai_owner'
        AND (SELECT rolsuper FROM pg_roles WHERE rolname = current_user)
        AND (SELECT shobj_description(oid, 'pg_database') FROM pg_database WHERE datname = current_database()) = :marker
        AND NOT EXISTS (SELECT FROM pg_stat_activity WHERE pid = :pid AND backend_start = CAST(:started AS timestamptz))
        AND NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :name))::int AS ok");
    $post->execute(['marker' => $marker, 'pid' => $identity['pid'], 'started' => $identity['started'], 'name' => $name]);
    if ((int) $post->fetchColumn() !== 1) { throw new RuntimeException('Postcondition unknown'); }
    $status = 0;
} catch (Throwable $e) {
    if ($started && $primary instanceof PDO) { try { $primary->rollBack(); } catch (Throwable $ignored) {} }
} finally {
    $guard = null;
    $check = null;
    $post = null;
    $primary = null;
    $verify = null;
    unset($password, $passwordBytes, $recordBytes, $record, $connect);
}
// A failed path is unknown; never infer backend termination from a client failure.
if ($status !== 0) { fwrite(STDERR, "Probe failed or outcome unknown; do not retry without investigation.\n"); }
exit($status);
