#!/usr/bin/env php
<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Stages the project in a temp directory, runs `composer install --no-dev`
 * there to produce a clean autoloader, then copies to a target path or
 * bundles into a tarball.
 *
 * The staging area mirrors the project layout so the relative path dependency
 * (../matrix-php) resolves correctly during the composer run.
 *
 * Usage:
 *   php8.4 deploy/deploy.php --target=/path/to/dest [--dry-run]
 *   php8.4 deploy/deploy.php --tar=/path/to/output.tar.gz [--dry-run]
 */

if ( PHP_VERSION_ID < 80400 ) {
    fwrite( STDERR, 'mtxctl requires PHP 8.4 or later. Current version: ' . PHP_VERSION . PHP_EOL );
    exit( 1 );
}

$options = getopt( '', [ 'target:', 'tar:', 'dry-run' ] );
$dryRun  = isset( $options['dry-run'] );
$target  = isset( $options['target'] ) ? (string) $options['target'] : null;
$tarPath = isset( $options['tar'] )    ? (string) $options['tar']    : null;

if ( $target === null && $tarPath === null ) {
    fwrite( STDERR, 'Usage: php8.4 deploy/deploy.php --target=/path [--dry-run]' . PHP_EOL );
    fwrite( STDERR, '       php8.4 deploy/deploy.php --tar=/path/to.tar.gz [--dry-run]' . PHP_EOL );
    exit( 1 );
}

$projectRoot  = dirname( __DIR__ );
$manifestPath = __DIR__ . '/manifest.txt';

if ( !is_readable( $manifestPath ) ) {
    fwrite( STDERR, 'Cannot read manifest.txt' . PHP_EOL );
    exit( 1 );
}

$lines = file( $manifestPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

if ( $lines === false ) {
    fwrite( STDERR, 'Cannot read manifest.txt' . PHP_EOL );
    exit( 1 );
}

$entries = array_filter( $lines, static fn( string $line ) => !str_starts_with( $line, '#' ) );
$entries = array_values( array_map( 'trim', $entries ) );

// ── Staging area ──────────────────────────────────────────────────────────────
// Stage at <tmp>/mtxctl-stage-XXXX/mtxctl/ so the path-dep sibling
// (../matrix-php) resolves correctly when composer runs inside the staging dir.

$stagingBase   = sys_get_temp_dir() . '/mtxctl-stage-' . bin2hex( random_bytes( 8 ) );
$stagingMtxctl = $stagingBase . '/mtxctl';

if ( !$dryRun ) {
    mkdir( $stagingMtxctl, 0755, true );
}

$copied = 0;

foreach ( $entries as $entry ) {
    if ( str_ends_with( $entry, '/' ) ) {
        $srcDir  = rtrim( $projectRoot . '/' . $entry, '/' );
        $destDir = $stagingMtxctl . '/' . rtrim( $entry, '/' );
        $copied += copyDirectory( $srcDir, $destDir, $dryRun, $projectRoot );
    } else {
        $src  = $projectRoot . '/' . $entry;
        $dest = $stagingMtxctl . '/' . $entry;
        if ( copyFile( $src, $dest, $dryRun ) ) {
            $copied++;
        }
    }
}

// ── Stage path dependencies ───────────────────────────────────────────────────
// Copy each path repo as a sibling of the staging mtxctl/ dir so
// ../relative-path references in composer.json resolve correctly.

$composerJson = json_decode( (string) file_get_contents( $projectRoot . '/composer.json' ), true ) ?? [];

foreach ( $composerJson['repositories'] ?? [] as $repo ) {
    if ( ( $repo['type'] ?? '' ) !== 'path' ) {
        continue;
    }

    $relUrl = $repo['url'];
    $absSrc = realpath( $projectRoot . '/' . $relUrl );

    if ( $absSrc === false || !is_dir( $absSrc ) ) {
        fwrite( STDERR, 'WARNING: path repo not found: ' . $relUrl . PHP_EOL );
        continue;
    }

    $destName = basename( $absSrc );
    $absDest  = $stagingBase . '/' . $destName;

    if ( $dryRun ) {
        echo '[dry-run] stage path-dep: ' . $relUrl . ' -> staging/' . $destName . PHP_EOL;
    } else {
        copyDirectory( $absSrc, $absDest, false, $absSrc, [ 'vendor', '.git' ] );
    }
}

// ── composer install --no-dev ─────────────────────────────────────────────────

$composer = findComposer( $projectRoot );

if ( $dryRun ) {
    echo '[dry-run] composer install --no-dev in staging dir' . PHP_EOL;
} else {
    $cmd      = PHP_BINARY . ' ' . escapeshellarg( $composer )
        . ' install --no-dev --no-interaction --working-dir=' . escapeshellarg( $stagingMtxctl )
        . ' 2>&1';
    $output   = [];
    $exitCode = 0;
    exec( $cmd, $output, $exitCode );

    foreach ( $output as $line ) {
        echo $line . PHP_EOL;
    }

    if ( $exitCode !== 0 ) {
        exec( 'rm -rf ' . escapeshellarg( $stagingBase ) );
        fwrite( STDERR, 'composer install failed' . PHP_EOL );
        exit( 1 );
    }
}

// ── Deliver ───────────────────────────────────────────────────────────────────

if ( $tarPath !== null ) {
    if ( !$dryRun ) {
        $escaped = escapeshellarg( $tarPath );
        $tmpEsc  = escapeshellarg( $stagingMtxctl );
        exec( 'tar -czf ' . $escaped . ' -C ' . $tmpEsc . ' .', $tarOut, $tarCode );
        exec( 'rm -rf ' . escapeshellarg( $stagingBase ) );

        if ( $tarCode !== 0 ) {
            fwrite( STDERR, 'tar failed with exit code ' . $tarCode . PHP_EOL );
            exit( 1 );
        }

        echo PHP_EOL . 'Tarball written to ' . $tarPath . PHP_EOL;
    } else {
        echo PHP_EOL . '[dry-run] Would write tarball to ' . $tarPath . PHP_EOL;
    }
} else {
    if ( !$dryRun ) {
        if ( !is_dir( $target ) ) {
            mkdir( $target, 0755, true );
        }
        exec( 'cp -a ' . escapeshellarg( $stagingMtxctl . '/.' ) . ' ' . escapeshellarg( $target ) );
        exec( 'rm -rf ' . escapeshellarg( $stagingBase ) );
        echo PHP_EOL . 'Done. Deployed to ' . $target . PHP_EOL;
    } else {
        echo PHP_EOL . '[dry-run] Would deploy ' . $copied . ' source file(s) to ' . $target . PHP_EOL;
    }
}

exit( 0 );

// ── helpers ───────────────────────────────────────────────────────────────────

function findComposer( string $projectRoot ): string
{
    $candidates = [
        dirname( $projectRoot ) . '/composer',
        dirname( $projectRoot ) . '/composer.phar',
        '/usr/local/bin/composer',
        '/usr/bin/composer',
    ];

    foreach ( $candidates as $c ) {
        if ( is_executable( $c ) ) {
            return $c;
        }
    }

    $which = trim( (string) shell_exec( 'which composer 2>/dev/null' ) );
    if ( $which !== '' ) {
        return $which;
    }

    fwrite( STDERR, 'Cannot find composer. Install it or place it alongside the project directory.' . PHP_EOL );
    exit( 1 );
}

function copyFile( string $src, string $dest, bool $dryRun ): bool
{
    if ( !file_exists( $src ) ) {
        fwrite( STDERR, 'WARNING: missing ' . $src . PHP_EOL );
        return false;
    }

    echo ( $dryRun ? '[dry-run] ' : '' ) . ltrim( str_replace( dirname( __DIR__ ), '', $src ), '/' ) . PHP_EOL;

    if ( !$dryRun ) {
        $dir = dirname( $dest );
        if ( !is_dir( $dir ) ) {
            mkdir( $dir, 0755, true );
        }
        copy( $src, $dest );
    }

    return true;
}

/**
 * @param string   $labelBase  Project root, used to compute display-relative paths.
 * @param string[] $skipDirs   Top-level directory names to exclude entirely (e.g. vendor, .git).
 */
function copyDirectory( string $src, string $dest, bool $dryRun, string $labelBase, array $skipDirs = [] ): int
{
    if ( !is_dir( $src ) ) {
        fwrite( STDERR, 'WARNING: missing directory ' . $src . PHP_EOL );
        return 0;
    }

    $count    = 0;
    $labelLen = strlen( rtrim( $labelBase, '/' ) . '/' );

    $dirIterator = new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS );

    $filtered = $skipDirs !== []
        ? new RecursiveCallbackFilterIterator(
            $dirIterator,
            static fn ( \SplFileInfo $item ): bool =>
                !( $item->isDir() && in_array( $item->getFilename(), $skipDirs, true ) ),
        )
        : $dirIterator;

    $iterator = new RecursiveIteratorIterator( $filtered, RecursiveIteratorIterator::SELF_FIRST );

    foreach ( $iterator as $item ) {
        if ( $item->isDir() ) {
            continue;
        }

        $relative = substr( $item->getPathname(), $labelLen );
        $destFile = $dest . '/' . substr( $item->getPathname(), strlen( $src ) + 1 );

        echo ( $dryRun ? '[dry-run] ' : '' ) . $relative . PHP_EOL;

        if ( !$dryRun ) {
            $dir = dirname( $destFile );
            if ( !is_dir( $dir ) ) {
                mkdir( $dir, 0755, true );
            }
            copy( $item->getPathname(), $destFile );
        }

        $count++;
    }

    return $count;
}
