<?php
/**
 * Forbidden — Dynamic 403 handler
 * Loaded by .htaccess RewriteRule when blocked files are accessed.
 * Uses __DIR__ so it works at any deployment path automatically.
 */
http_response_code(403);
require __DIR__ . '/../error.php';
