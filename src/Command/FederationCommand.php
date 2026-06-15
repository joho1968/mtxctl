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

/** Inspect Matrix federation destinations. */
final class FederationCommand extends Command
{
    public function __construct( private readonly SynapseAdminClientInterface $admin )
    {
        parent::__construct( 'federation' );
    }

    protected function configure(): void
    {
        $this
            ->setDescription( 'Inspect federation destinations' )
            ->setHelp( <<<'HELP'
                Actions:
                  list  List known federation destinations (paginated)
                  show  Show details for one destination server
                HELP )
            ->addArgument( 'action',      InputArgument::REQUIRED, 'list | show' )
            ->addArgument( 'destination', InputArgument::OPTIONAL, 'Remote server name (show)' )
            ->addOption( 'limit', 'l',  InputOption::VALUE_REQUIRED, 'Max results for list', 100 )
            ->addOption( 'from',  null, InputOption::VALUE_REQUIRED, 'Pagination offset',      0 )
            ->addOption( 'json',  null, InputOption::VALUE_NONE,    'Output as JSON' );
    }

    protected function execute( InputInterface $input, OutputInterface $output ): int
    {
        return match ( $input->getArgument( 'action' ) ) {
            'list' => $this->actionList( $input, $output ),
            'show' => $this->actionShow( $input, $output ),
            default => $this->unknownAction( (string) $input->getArgument( 'action' ), $output ),
        };
    }

    private function actionList( InputInterface $input, OutputInterface $output ): int
    {
        $result       = $this->admin->listFederationDestinations(
            limit: (int) $input->getOption( 'limit' ),
            from:  (int) $input->getOption( 'from' ),
        );
        $destinations = $result['destinations'] ?? [];

        if ( (bool) $input->getOption( 'json' ) ) {
            $output->writeln( json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
            return Command::SUCCESS;
        }

        $output->writeln( sprintf(
            'Total: %d  (showing %d)',
            $result['total'] ?? 0,
            count( $destinations ),
        ) );

        $table = new Table( $output );
        $table->setHeaders( [ 'Destination', 'Last success', 'Retry at', 'Failures' ] );

        foreach ( $destinations as $dest ) {
            $lastSuccess = isset( $dest['last_successful_stream_ordering'] )
                ? (string) $dest['last_successful_stream_ordering']
                : 'none';
            $retryAt = isset( $dest['retry_last_ts'] ) && $dest['retry_last_ts'] > 0
                ? date( 'Y-m-d H:i', (int) ( ( $dest['retry_last_ts'] + ( $dest['retry_interval'] ?? 0 ) ) / 1000 ) )
                : '';
            $failures = isset( $dest['failure_ts'] ) && $dest['failure_ts'] !== null ? 'yes' : 'no';

            $table->addRow( [
                $dest['destination'] ?? '',
                $lastSuccess,
                $retryAt,
                $failures,
            ] );
        }

        $table->render();

        if ( !empty( $result['next_token'] ) ) {
            $output->writeln( sprintf( '  >> more results available; use --from=%s', $result['next_token'] ) );
        }

        return Command::SUCCESS;
    }

    private function actionShow( InputInterface $input, OutputInterface $output ): int
    {
        $server = $input->getArgument( 'destination' );
        if ( $server === null || $server === '' ) {
            $output->writeln( '<error>Missing required argument: destination</error>' );
            return Command::FAILURE;
        }

        $data = $this->admin->getFederationDestination( (string) $server );

        if ( (bool) $input->getOption( 'json' ) ) {
            $output->writeln( json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
            return Command::SUCCESS;
        }

        $table = new Table( $output );
        $table->setStyle( 'compact' );

        foreach ( $data as $key => $value ) {
            $display = match ( true ) {
                in_array( $key, [ 'retry_last_ts', 'failure_ts' ], true ) && is_int( $value ) && $value > 0
                    => date( 'Y-m-d H:i', (int) ( $value / 1000 ) ),
                is_null( $value )  => '',
                is_bool( $value )  => $value ? 'true' : 'false',
                is_array( $value ) => json_encode( $value, JSON_UNESCAPED_UNICODE ) ?: '',
                default            => (string) $value,
            };
            $table->addRow( [ '<info>' . $key . '</info>', $display ] );
        }

        $table->render();
        return Command::SUCCESS;
    }

    private function unknownAction( string $action, OutputInterface $output ): int
    {
        $output->writeln( '<error>Unknown action "' . $action . '". Valid: list, show</error>' );
        return Command::FAILURE;
    }
}
