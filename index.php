<?php
declare(strict_types=1);

// Compatibility front controller for cPanel installations whose document root
// currently points to /backend. The preferred document root remains /backend/public.
require __DIR__ . '/public/index.php';
