#!/usr/bin/env php
<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright 2026 Joaquim Homrighausen; all rights reserved.

if ( PHP_VERSION_ID < 80400 ) {
    fwrite( STDERR, 'mtxctl requires PHP 8.4 or later. Current: ' . PHP_VERSION . PHP_EOL );
    exit( 1 );
}

require_once __DIR__ . '/../vendor/autoload.php';

use Joho\Matrix\Client\SynapseAdminClient;
use Joho\Matrix\Http\HttpClient;
use Joho\Mtxctl\Command\FederationCommand;
use Joho\Mtxctl\Command\MediaCommand;
use Joho\Mtxctl\Command\RoomCommand;
use Joho\Mtxctl\Command\ServerCommand;
use Joho\Mtxctl\Command\TokenCommand;
use Joho\Mtxctl\Command\UserCommand;
use Joho\Mtxctl\Command\VersionCommand;
use Joho\Mtxctl\Config;
use Joho\Mtxctl\NullAdminClient;
use Symfony\Component\Console\Application;

$configPath  = __DIR__ . '/../config/config.php';
$configError = null;

if ( !file_exists( $configPath ) ) {
    $configError = 'Config not found: ' . $configPath . PHP_EOL
        . 'Copy config/config.example.php to config/config.php and edit it.';
    $admin = new NullAdminClient( $configError );
} else {
    try {
        $config = Config::fromFile( $configPath );
        $admin  = new SynapseAdminClient( new HttpClient(), $config->homeserverUrl, $config->adminToken );
    } catch ( \RuntimeException $e ) {
        $admin = new NullAdminClient( 'Config error: ' . $e->getMessage() );
    }
}

$version = '0.95.0';

$app = new Application( 'mtxctl', $version );
$app->setAutoExit( false );
$app->addCommands( [
    new VersionCommand( $version ),
    new UserCommand( $admin ),
    new RoomCommand( $admin ),
    new MediaCommand( $admin ),
    new TokenCommand( $admin ),
    new FederationCommand( $admin ),
    new ServerCommand( $admin ),
] );

$exitCode = $app->run();
echo PHP_EOL;
exit( $exitCode );
