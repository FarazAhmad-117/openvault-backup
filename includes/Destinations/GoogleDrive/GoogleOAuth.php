<?php
/**
 * PLAN STUB — no implementation yet.
 * Phase: MVP
 *
 * Purpose: OAuth 2.0 flow (see docs/plan/07-google-drive-oauth.md).
 *
 * Responsibilities:
 *  - Build auth URL with state nonce, handle callback, store refresh token
 *  - Refresh access tokens; detect revoked/expired tokens and alert
 */

namespace OpenVault\Backup\Destinations\GoogleDrive;

defined( 'ABSPATH' ) || exit;
