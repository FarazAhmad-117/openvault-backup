<?php
/**
 * PLAN STUB — no implementation yet.
 * Phase: MVP
 *
 * Purpose: Chunked pure-PHP SQL export (mysqldump if available & allowed).
 *
 * Responsibilities:
 *  - Table-by-table, N rows per batch
 *  - Respect excluded tables
 *  - Write CREATE TABLE + INSERTs, handle binary/serialized data safely
 */

namespace OpenVault\Backup\Engine\Steps;

defined( 'ABSPATH' ) || exit;
