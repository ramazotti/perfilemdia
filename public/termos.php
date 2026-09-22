<?php

declare(strict_types=1);

$target = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/') . '/termos';
header('Location: ' . $target, true, 301);
exit;
