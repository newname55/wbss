<?php
declare(strict_types=1);

/**
 * Backward-compatible alias for the canonical geo request endpoint.
 *
 * Canonical rules:
 * - actual source of truth: attendances
 * - LINE geo prompt flow is handled by attendance_geo_request.php + webhook
 */

require __DIR__ . '/attendance_geo_request.php';
