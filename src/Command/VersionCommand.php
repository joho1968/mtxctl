<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright 2026 Joaquim Homrighausen; all rights reserved.

namespace Joho\Mtxctl\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Show mtxctl version, license, and copyright. */
final class VersionCommand extends Command
{
    public function __construct( private readonly string $version )
    {
        parent::__construct( 'version' );
    }

    protected function configure(): void
    {
        $this
            ->setDescription( 'Show version, license, and copyright' )
            ->addOption( 'json', null, InputOption::VALUE_NONE, 'Output as JSON' );
    }

    protected function execute( InputInterface $input, OutputInterface $output ): int
    {
        $data = [
            'version'   => $this->version,
            'license'   => 'AGPL-3.0-or-later',
            'copyright' => 'Copyright 2026 Joaquim Homrighausen; all rights reserved.',
        ];

        if ( (bool) $input->getOption( 'json' ) ) {
            $output->writeln( json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
            return Command::SUCCESS;
        }

        $output->writeln( '<comment>mtxctl ' . $data['version'] . '</comment>' );
        $output->writeln( '' );
        $output->writeln( 'License: ' . $data['license'] );
        $output->writeln( '' );
        $output->writeln( $data['copyright'] );

        return Command::SUCCESS;
    }
}
