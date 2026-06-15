<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright 2026 Joaquim Homrighausen; all rights reserved.

namespace Joho\Mtxctl;

/** Runtime configuration loaded from config/config.php. */
final class Config
{
    public function __construct(
        public readonly string $homeserverUrl,
        public readonly string $adminToken,
    ) {}

    public static function fromFile( string $path ): self
    {
        $c = require $path;

        if ( !is_array( $c ) ) {
            throw new \RuntimeException( 'Config file must return an array: ' . $path );
        }

        if ( empty( $c['homeserver_url'] ) ) {
            throw new \RuntimeException( '"homeserver_url" is not set in config.php' );
        }

        if ( empty( $c['admin_token'] ) ) {
            throw new \RuntimeException( '"admin_token" is not set in config.php' );
        }

        return new self(
            homeserverUrl: (string) $c['homeserver_url'],
            adminToken:    (string) $c['admin_token'],
        );
    }
}
