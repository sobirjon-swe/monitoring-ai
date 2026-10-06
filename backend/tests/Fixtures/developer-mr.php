<?php

return [
    'source_instance_id' => 'synthetic-gitlab', 'repository_id' => 'synthetic-repository', 'mr_id' => '42',
    'revision_ordinal' => 1, 'base_sha' => str_repeat('a', 40), 'head_sha' => str_repeat('b', 40),
    'source_status' => 'open', 'occurred_at' => '2026-10-06T00:00:00Z',
    'received_at' => '2026-10-06T00:00:01Z', 'source_updated_at' => '2026-10-06T00:00:00Z',
    'diff' => "diff --git a/app/example.php b/app/example.php\n--- a/app/example.php\n+++ b/app/example.php\n@@ -1 +1 @@\n-old\n+new\n",
    'redaction_policy' => 'block-v1',
];
