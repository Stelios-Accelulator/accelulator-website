<?php
require_once __DIR__ . '/../../httpd.private/env.php';
echo 'Master key: ' . substr(getenv('ACCELULATOR_MASTER_KEY'), 0, 10) . "...\n";