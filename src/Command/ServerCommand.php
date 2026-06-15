<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright 2026 Joaquim Homrighausen; all rights reserved.

namespace Joho\Mtxctl\Command;

use Joho\Matrix\Contracts\SynapseAdminClientInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Query server information: version, stats. */
final class ServerCommand extends Command
{
    public function __construct( private readonly SynapseAdminClientInterface $admin )
    {
        parent::__construct( 'server' );
    }

    protected function configure(): void
    {
        $this
            ->setDescription( 'Query homeserver information' )
            ->setHelp( <<<'HELP'
                Actions:
                  version   Show Synapse and Python version
                  stats     Show aggregate user and room counts
                HELP )
            ->addArgument( 'action', InputArgument::REQUIRED, 'version | stats' )
            ->addOption( 'json', null, InputOption::VALUE_NONE, 'Output as JSON' );
    }

    protected function execute( InputInterface $input, OutputInterface $output ): int
    {
        return match ( $input->getArgument( 'action' ) ) {
            'version' => $this->actionVersion( $input, $output ),
            'stats'   => $this->actionStats( $input, $output ),
            default   => $this->unknownAction( (string) $input->getArgument( 'action' ), $output ),
        };
    }

    private function actionVersion( InputInterface $input, OutputInterface $output ): int
    {
        $data = $this->admin->getServerVersion();

        if ( (bool) $input->getOption( 'json' ) ) {
            $output->writeln( json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
            return Command::SUCCESS;
        }

        $table = new Table( $output );
        $table->setStyle( 'compact' );
        $table->addRows( [
            [ '<info>Synapse</info>', $data['server_version'] ?? 'unknown' ],
            [ '<info>Python</info>',  $data['python_version'] ?? 'unknown' ],
        ] );
        $table->render();

        return Command::SUCCESS;
    }

    private function actionStats( InputInterface $input, OutputInterface $output ): int
    {
        $version = $this->admin->getServerVersion();
        $users   = $this->admin->listUsers( limit: 1, from: 0 );
        $rooms   = $this->admin->listRooms( limit: 1, from: 0 );

        $data = [
            'server_version' => $version['server_version'] ?? 'unknown',
            'python_version' => $version['python_version'] ?? 'unknown',
            'total_users'    => $users['total']       ?? 0,
            'total_rooms'    => $rooms['total_rooms'] ?? 0,
        ];

        if ( (bool) $input->getOption( 'json' ) ) {
            $output->writeln( json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
            return Command::SUCCESS;
        }

        $table = new Table( $output );
        $table->setStyle( 'compact' );
        $table->addRows( [
            [ '<info>Synapse</info>',     $data['server_version'] ],
            [ '<info>Python</info>',      $data['python_version'] ],
            [ '<info>Total users</info>', (string) $data['total_users'] ],
            [ '<info>Total rooms</info>', (string) $data['total_rooms'] ],
        ] );
        $table->render();

        return Command::SUCCESS;
    }

    private function unknownAction( string $action, OutputInterface $output ): int
    {
        $output->writeln( '<error>Unknown action "' . $action . '". Valid: version, stats</error>' );
        return Command::FAILURE;
    }
}
