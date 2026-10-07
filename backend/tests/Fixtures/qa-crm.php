<?php

// Synthetic CRM scenarios; these are never release approval or employee scores.
return [
    'schema_version' => 'qa-evidence-v1',
    'synthetic' => true,
    'ai_interpretation' => 'unavailable',
    'definitions' => [
        ['logical_key' => 'crm-151-boundary', 'title' => 'Synthetic CRM-151 authorization boundary', 'steps' => 'Attempt a cross-project synthetic read', 'expected_result' => 'Read denied', 'test_type' => 'security', 'release_critical' => true],
        ['logical_key' => 'crm-import-regression', 'title' => 'Synthetic duplicate import regression', 'steps' => 'Repeat the same synthetic input', 'expected_result' => 'No duplicate event', 'test_type' => 'regression', 'release_critical' => false],
    ],
    'outcomes' => ['crm-151-boundary' => 'failed', 'crm-import-regression' => 'passed', 'crm-environment' => 'blocked'],
];
