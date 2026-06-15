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

/** Manage Synapse registration tokens: list, show, create, delete. */
final class TokenCommand extends Command
{
    public function __construct( private readonly SynapseAdminClientInterface $admin )
    {
        parent::__construct( 'token' );
    }

    protected function configure(): void
    {
        $this
            ->setDescription( 'Manage registration tokens' )
            ->setHelp( <<<'HELP'
                Actions:
                  list    List all registration tokens
                  show    Show details for one token
                  create  Create a new registration token
                  delete  Delete a registration token

                Options for create:
                  --token         Token string (Synapse generates one if omitted)
                  --uses          Max number of registrations allowed (default: unlimited)
                  --expires-days  Token expires after N days from now (default: no expiry)
                HELP )
            ->addArgument( 'action', InputArgument::REQUIRED, 'list | show | create | delete' )
            ->addArgument( 'token',  InputArgument::OPTIONAL, 'Token string (show / delete)' )
            ->addOption( 'token',        't', InputOption::VALUE_REQUIRED, 'Token string for create' )
            ->addOption( 'uses',         'u', InputOption::VALUE_REQUIRED, 'Max registrations allowed (create)' )
            ->addOption( 'expires-days', 'e', InputOption::VALUE_REQUIRED, 'Expiry in days from now (create)' )
            ->addOption( 'confirm',      null, InputOption::VALUE_NONE,    'Skip confirmation prompt (delete)' )
            ->addOption( 'json',         null, InputOption::VALUE_NONE,    'Output as JSON' );
    }

    protected function execute( InputInterface $input, OutputInterface $output ): int
    {
        return match ( $input->getArgument( 'action' ) ) {
            'list'   => $this->actionList( $input, $output ),
            'show'   => $this->actionShow( $input, $output ),
            'create' => $this->actionCreate( $input, $output ),
            'delete' => $this->actionDelete( $input, $output ),
            default  => $this->unknownAction( (string) $input->getArgument( 'action' ), $output ),
        };
    }

    private function actionList( InputInterface $input, OutputInterface $output ): int
    {
        $tokens = $this->admin->listRegistrationTokens();

        if ( (bool) $input->getOption( 'json' ) ) {
            $output->writeln( json_encode( $tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
            return Command::SUCCESS;
        }

        $output->writeln( sprintf( 'Total: %d', count( $tokens ) ) );

        $table = new Table( $output );
        $table->setHeaders( [ 'Token', 'Uses allowed', 'Pending', 'Completed', 'Expires' ] );

        foreach ( $tokens as $t ) {
            $table->addRow( [
                $t['token']        ?? '',
                $t['uses_allowed'] !== null ? (string) $t['uses_allowed'] : 'unlimited',
                (string) ( $t['pending']   ?? 0 ),
                (string) ( $t['completed'] ?? 0 ),
                isset( $t['expiry_time'] ) && $t['expiry_time'] !== null
                    ? date( 'Y-m-d H:i', (int) ( $t['expiry_time'] / 1000 ) )
                    : 'never',
            ] );
        }

        $table->render();
        return Command::SUCCESS;
    }

    private function actionShow( InputInterface $input, OutputInterface $output ): int
    {
        $token = $this->requireTokenArg( $input, $output );
        if ( $token === null ) {
            return Command::FAILURE;
        }

        $data = $this->admin->getRegistrationToken( $token );

        if ( (bool) $input->getOption( 'json' ) ) {
            $output->writeln( json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
            return Command::SUCCESS;
        }

        $table = new Table( $output );
        $table->setStyle( 'compact' );
        $table->addRow( [ '<info>token</info>',        $data['token']        ?? '' ] );
        $table->addRow( [ '<info>uses_allowed</info>', $data['uses_allowed'] !== null ? (string) $data['uses_allowed'] : 'unlimited' ] );
        $table->addRow( [ '<info>pending</info>',      (string) ( $data['pending']   ?? 0 ) ] );
        $table->addRow( [ '<info>completed</info>',    (string) ( $data['completed'] ?? 0 ) ] );
        $table->addRow( [ '<info>expiry_time</info>',  isset( $data['expiry_time'] ) && $data['expiry_time'] !== null
            ? date( 'Y-m-d H:i', (int) ( $data['expiry_time'] / 1000 ) )
            : 'never' ] );
        $table->render();

        return Command::SUCCESS;
    }

    private function actionCreate( InputInterface $input, OutputInterface $output ): int
    {
        $tokenStr    = $input->getOption( 'token' ) !== null ? (string) $input->getOption( 'token' ) : null;
        $uses        = $input->getOption( 'uses' )        !== null ? (int) $input->getOption( 'uses' )        : null;
        $expiresDays = $input->getOption( 'expires-days' ) !== null ? (int) $input->getOption( 'expires-days' ) : null;
        $expiryMs    = $expiresDays !== null ? (int) ( ( time() + $expiresDays * 86400 ) * 1000 ) : null;

        $data = $this->admin->createRegistrationToken( $tokenStr, $uses, $expiryMs );

        if ( (bool) $input->getOption( 'json' ) ) {
            $output->writeln( json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
            return Command::SUCCESS;
        }

        $output->writeln( sprintf( 'Token created: <info>%s</info>', $data['token'] ?? '?' ) );

        if ( isset( $data['uses_allowed'] ) && $data['uses_allowed'] !== null ) {
            $output->writeln( sprintf( 'Uses allowed: %d', $data['uses_allowed'] ) );
        }
        if ( isset( $data['expiry_time'] ) && $data['expiry_time'] !== null ) {
            $output->writeln( sprintf( 'Expires: %s', date( 'Y-m-d H:i', (int) ( $data['expiry_time'] / 1000 ) ) ) );
        }

        return Command::SUCCESS;
    }

    private function actionDelete( InputInterface $input, OutputInterface $output ): int
    {
        $token = $this->requireTokenArg( $input, $output );
        if ( $token === null ) {
            return Command::FAILURE;
        }

        if ( !(bool) $input->getOption( 'confirm' ) ) {
            $output->writeln( sprintf(
                'Will delete token <comment>%s</comment>. Re-run with --confirm to proceed.',
                $token,
            ) );
            return Command::SUCCESS;
        }

        $this->admin->deleteRegistrationToken( $token );
        $output->writeln( sprintf( 'Token <info>%s</info> deleted.', $token ) );
        return Command::SUCCESS;
    }

    private function requireTokenArg( InputInterface $input, OutputInterface $output ): ?string
    {
        $val = $input->getArgument( 'token' );
        if ( $val === null || $val === '' ) {
            $output->writeln( '<error>Missing required argument: token</error>' );
            return null;
        }
        return (string) $val;
    }

    private function unknownAction( string $action, OutputInterface $output ): int
    {
        $output->writeln( '<error>Unknown action "' . $action . '". Valid: list, show, create, delete</error>' );
        return Command::FAILURE;
    }
}
