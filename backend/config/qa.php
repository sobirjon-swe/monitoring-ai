<?php

return [
    // Operational guards intentionally refuse endpoints outside the approved local substrate.
    'local_port' => (int) env('QA_LOCAL_PORT', 55432),
    'runtime_role' => 'monitoring_ai_app',
    'database_marker' => 'monitoring-ai-local-v1:',
    'local_databases' => ['local' => 'monitoring_ai_dev', 'testing' => 'monitoring_ai_test'],
];
