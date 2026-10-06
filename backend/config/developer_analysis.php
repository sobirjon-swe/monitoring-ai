<?php

return [
    // This flag is necessary, but actual verified OS evidence is also required.
    'enabled' => (bool) env('DEVELOPER_ANALYSIS_ENABLED', false),
    'python' => base_path('../.local/e04-runtime/python.exe'),
    'broker' => base_path('../analytics/runtime/windows_broker.py'),
    'manifest' => base_path('../.local/e04-runtime/manifest.json'),
    'activation' => base_path('../.local/e04-runtime/activation.json'),
];
