-- REVIEW ONLY; wrapper supplies expected marker, administrator, role and owner settings.
-- ON_ERROR_STOP and a fresh-connection rollback check are enforced by the wrapper.
BEGIN;
DO $probe$
DECLARE
 expected text := current_setting('f02.expected_installation_marker', true);
 expected_admin text := current_setting('f02.expected_admin', true);
 expected_owner text := current_setting('f02.expected_owner', true);
 actual text;
 actual_owner text;
 probe_name text := current_setting('f02.probe_name', true);
 probe_oid oid;
BEGIN
 SELECT shobj_description(oid, 'pg_database'), pg_get_userbyid(datdba)
 INTO actual, actual_owner FROM pg_database WHERE datname = current_database();
 IF current_database() <> 'monitoring_ai_test'
    OR inet_server_addr() IS DISTINCT FROM '127.0.0.1'::inet
    OR inet_server_port() IS DISTINCT FROM 55432
    OR current_setting('server_version_num')::int < 170000
    OR expected IS NULL OR expected !~ '^monitoring-ai-local-v1:[0-9a-f-]{36}$'
    OR actual IS DISTINCT FROM expected
    OR expected_owner IS DISTINCT FROM 'monitoring_ai_owner'
    OR actual_owner IS DISTINCT FROM expected_owner
    OR expected_admin IS NULL OR expected_admin !~ '^[a-z_][a-z0-9_]*$'
    OR session_user IS DISTINCT FROM expected_admin OR current_user IS DISTINCT FROM expected_admin
    OR probe_name IS NULL OR probe_name !~ '^f02_test_probe_[0-9a-f]{32}$' THEN
   RAISE EXCEPTION 'TEST role probe refused';
 END IF;
 IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = probe_name) THEN
   RAISE EXCEPTION 'TEST role probe name collision';
 END IF;
 EXECUTE format('CREATE ROLE %I NOLOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS', probe_name);
 SELECT oid INTO STRICT probe_oid FROM pg_roles
 WHERE rolname = probe_name AND NOT rolcanlogin AND NOT rolinherit AND NOT rolsuper
   AND NOT rolcreatedb AND NOT rolcreaterole AND NOT rolreplication AND NOT rolbypassrls;
 IF EXISTS (SELECT 1 FROM pg_auth_members WHERE member = probe_oid OR roleid = probe_oid)
    OR EXISTS (SELECT 1 FROM pg_class WHERE relowner = probe_oid)
    OR EXISTS (SELECT 1 FROM pg_namespace WHERE nspowner = probe_oid)
    OR EXISTS (SELECT 1 FROM pg_database WHERE datdba = probe_oid)
    OR EXISTS (SELECT 1 FROM pg_database d WHERE has_database_privilege(probe_name, d.oid, 'CONNECT'))
    OR EXISTS (SELECT 1 FROM pg_namespace n WHERE n.nspname = 'public'
      AND (has_schema_privilege(probe_name, n.oid, 'USAGE') OR has_schema_privilege(probe_name, n.oid, 'CREATE')))
    OR EXISTS (SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
      WHERE n.nspname = 'public' AND has_function_privilege(probe_name, p.oid, 'EXECUTE'))
    OR EXISTS (SELECT 1 FROM pg_default_acl a CROSS JOIN LATERAL aclexplode(a.defaclacl) x
      WHERE x.grantee IN (0, probe_oid) AND a.defaclnamespace IN (0, 'public'::regnamespace)) THEN
   RAISE EXCEPTION 'TEST role probe privilege boundary refused';
 END IF;
END
$probe$;
ROLLBACK;
