<?php

namespace App\Domain\Browser\BaDemo;

use Illuminate\Database\Connection;

/** Read-only catalog facts; this is not a setup or publication authorization. */
final class BaDemoSetupCatalog
{
    public function inspect(Connection $connection): array
    {
        $row = $connection->selectOne(<<<'SQL'
SELECT current_database() AS database, session_user AS session_role, current_user AS current_role,
       inet_server_addr()::text AS server_address, inet_server_port() AS server_port,
       shobj_description(d.oid, 'pg_database') AS database_marker,
       r.rolsuper AS superuser, r.rolbypassrls AS bypass_rls,
       r.rolcreatedb AS create_db, r.rolcreaterole AS create_role,
       r.rolreplication AS replication, r.rolinherit AS inherits,
       has_table_privilege(current_user, 'public.ba_demo_receipts', 'INSERT') AS receipt_insert,
       EXISTS (SELECT 1 FROM pg_auth_members m WHERE m.member = r.oid OR m.roleid = r.oid) AS has_membership,
       EXISTS (SELECT 1 FROM pg_class c WHERE c.relowner = r.oid) AS owns_relation,
       EXISTS (SELECT 1 FROM pg_namespace n WHERE n.nspowner = r.oid) AS owns_schema,
       EXISTS (SELECT 1 FROM pg_database owned WHERE owned.datdba = r.oid) AS owns_database
FROM pg_database d JOIN pg_roles r ON r.rolname = current_user
WHERE d.datname = current_database()
SQL);

        return (array) $row;
    }
}
