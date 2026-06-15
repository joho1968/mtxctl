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

/** Manage Matrix users: list, show, deactivate, password. */
final class UserCommand extends Command
{
    public function __construct( private readonly SynapseAdminClientInterface $admin )
    {
        parent::__construct( 'user' );
    }

    protected function configure(): void
    {
        $this
            ->setDescription( 'Manage Matrix users' )
            ->setHelp( <<<'HELP'
                Actions:
                  list        List users (paginated)
                  show        Show details for one user
                  whois       Show active sessions and last-seen IPs for a user
                  deactivate  Deactivate a user account
                  password    Reset a user's password
                  admin       Grant admin status (use --revoke to remove)
                  shadow-ban  Shadow-ban a user (use --revoke to lift)
                HELP )
            ->addArgument( 'action',   InputArgument::REQUIRED, 'list | show | whois | deactivate | password | admin | shadow-ban' )
            ->addArgument( 'user-id',  InputArgument::OPTIONAL, 'Matrix user ID (@user:server)' )
            ->addArgument( 'password', InputArgument::OPTIONAL, 'New password (password action only)' )
            ->addOption( 'limit',       'l', InputOption::VALUE_REQUIRED, 'Max results for list',   100 )
            ->addOption( 'from',        null, InputOption::VALUE_REQUIRED, 'Pagination offset',       0 )
            ->addOption( 'guests',      null, InputOption::VALUE_NONE,     'Include guest accounts' )
            ->addOption( 'deactivated', null, InputOption::VALUE_NONE,     'Include deactivated accounts' )
            ->addOption( 'revoke',      null, InputOption::VALUE_NONE,     'Remove admin status (admin action)' )
            ->addOption( 'erase',       null, InputOption::VALUE_NONE,     'Erase content when deactivating' )
            ->addOption( 'confirm',     null, InputOption::VALUE_NONE,     'Skip confirmation prompt' )
            ->addOption( 'json',        null, InputOption::VALUE_NONE,     'Output as JSON' );
    }

    protected function execute( InputInterface $input, OutputInterface $output ): int
    {
        return match ( $input->getArgument( 'action' ) ) {
            'list'       => $this->actionList( $input, $output ),
            'show'       => $this->actionShow( $input, $output ),
            'whois'      => $this->actionWhois( $input, $output ),
            'deactivate' => $this->actionDeactivate( $input, $output ),
            'password'   => $this->actionPassword( $input, $output ),
            'admin'      => $this->actionAdmin( $input, $output ),
            'shadow-ban' => $this->actionShadowBan( $input, $output ),
            default      => $this->unknownAction( (string) $input->getArgument( 'action' ), $output ),
        };
    }

    private function actionList( InputInterface $input, OutputInterface $output ): int
    {
        $result = $this->admin->listUsers(
            limit:       (int)  $input->getOption( 'limit' ),
            from:        (int)  $input->getOption( 'from' ),
            guests:      (bool) $input->getOption( 'guests' ),
            deactivated: (bool) $input->getOption( 'deactivated' ),
        );

        $users = $result['users'] ?? [];

        if ( (bool) $input->getOption( 'json' ) ) {
            $output->writeln( json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
            return Command::SUCCESS;
        }

        $output->writeln( sprintf(
            'Total: %d  (showing %d)',
            $result['total'] ?? 0,
            count( $users ),
        ) );

        $table = new Table( $output );
        $table->setHeaders( [ 'User ID', 'Display name', 'Admin', 'Deactivated' ] );

        foreach ( $users as $user ) {
            $table->addRow( [
                $user['name']        ?? '',
                $user['displayname'] ?? '',
                ( $user['admin']       ?? false ) ? 'yes' : 'no',
                ( $user['deactivated'] ?? false ) ? 'yes' : 'no',
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
        $userId = $this->requireArg( $input, 'user-id', $output );
        if ( $userId === null ) {
            return Command::FAILURE;
        }

        $user = $this->admin->getUser( $userId );

        if ( (bool) $input->getOption( 'json' ) ) {
            $output->writeln( json_encode( $user, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
            return Command::SUCCESS;
        }

        $table = new Table( $output );
        $table->setStyle( 'compact' );

        foreach ( $user as $key => $value ) {
            $table->addRow( [ '<info>' . $key . '</info>', $this->scalar( $value ) ] );
        }

        $table->render();
        return Command::SUCCESS;
    }

    private function actionDeactivate( InputInterface $input, OutputInterface $output ): int
    {
        $userId = $this->requireArg( $input, 'user-id', $output );
        if ( $userId === null ) {
            return Command::FAILURE;
        }

        $erase = (bool) $input->getOption( 'erase' );

        if ( !(bool) $input->getOption( 'confirm' ) ) {
            $output->writeln( sprintf(
                'Will deactivate <comment>%s</comment> (erase=%s). Re-run with --confirm to proceed.',
                $userId,
                $erase ? 'true' : 'false',
            ) );
            return Command::SUCCESS;
        }

        $this->admin->deactivateUser( $userId, $erase );
        $output->writeln( sprintf( 'Deactivated <info>%s</info>.', $userId ) );
        return Command::SUCCESS;
    }

    private function actionPassword( InputInterface $input, OutputInterface $output ): int
    {
        $userId  = $this->requireArg( $input, 'user-id',  $output );
        $newPass = $this->requireArg( $input, 'password', $output );

        if ( $userId === null || $newPass === null ) {
            return Command::FAILURE;
        }

        $this->admin->resetUserPassword( $userId, $newPass );
        $output->writeln( sprintf( 'Password reset for <info>%s</info>.', $userId ) );
        return Command::SUCCESS;
    }

    private function actionWhois( InputInterface $input, OutputInterface $output ): int
    {
        $userId = $this->requireArg( $input, 'user-id', $output );
        if ( $userId === null ) {
            return Command::FAILURE;
        }

        $data = $this->admin->getUserWhois( $userId );

        if ( (bool) $input->getOption( 'json' ) ) {
            $output->writeln( json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
            return Command::SUCCESS;
        }

        $devices = $data['devices'] ?? [];

        if ( empty( $devices ) ) {
            $output->writeln( sprintf( 'No active sessions for <info>%s</info>.', $userId ) );
            return Command::SUCCESS;
        }

        $table = new Table( $output );
        $table->setHeaders( [ 'Device', 'IP', 'Last seen', 'User agent' ] );

        foreach ( $devices as $deviceId => $device ) {
            foreach ( $device['sessions'] ?? [] as $session ) {
                foreach ( $session['connections'] ?? [] as $conn ) {
                    $lastSeen = isset( $conn['last_seen'] )
                        ? date( 'Y-m-d H:i', (int) ( $conn['last_seen'] / 1000 ) )
                        : '';
                    $ua = (string) ( $conn['user_agent'] ?? '' );
                    if ( strlen( $ua ) > 60 ) {
                        $ua = substr( $ua, 0, 57 ) . '...';
                    }
                    $table->addRow( [ (string) $deviceId, (string) ( $conn['ip'] ?? '' ), $lastSeen, $ua ] );
                }
            }
        }

        $table->render();
        return Command::SUCCESS;
    }

    private function actionAdmin( InputInterface $input, OutputInterface $output ): int
    {
        $userId = $this->requireArg( $input, 'user-id', $output );
        if ( $userId === null ) {
            return Command::FAILURE;
        }

        $revoke = (bool) $input->getOption( 'revoke' );
        $this->admin->setUserAdmin( $userId, !$revoke );
        $output->writeln( $revoke
            ? sprintf( 'Admin status removed from <info>%s</info>.', $userId )
            : sprintf( '<info>%s</info> granted admin status.', $userId ),
        );

        return Command::SUCCESS;
    }

    private function actionShadowBan( InputInterface $input, OutputInterface $output ): int
    {
        $userId = $this->requireArg( $input, 'user-id', $output );
        if ( $userId === null ) {
            return Command::FAILURE;
        }

        $revoke = (bool) $input->getOption( 'revoke' );
        $this->admin->shadowBanUser( $userId, !$revoke );
        $output->writeln( $revoke
            ? sprintf( 'Shadow-ban lifted for <info>%s</info>.', $userId )
            : sprintf( '<info>%s</info> has been shadow-banned.', $userId ),
        );

        return Command::SUCCESS;
    }

    private function unknownAction( string $action, OutputInterface $output ): int
    {
        $output->writeln( '<error>Unknown action "' . $action . '". Valid: list, show, whois, deactivate, password, admin, shadow-ban</error>' );
        return Command::FAILURE;
    }

    private function requireArg( InputInterface $input, string $name, OutputInterface $output ): ?string
    {
        $val = $input->getArgument( $name );
        if ( $val === null || $val === '' ) {
            $output->writeln( '<error>Missing required argument: ' . $name . '</error>' );
            return null;
        }
        return (string) $val;
    }

    private function scalar( mixed $value ): string
    {
        if ( is_bool( $value ) )  return $value ? 'true' : 'false';
        if ( $value === null )    return '';
        if ( is_array( $value ) ) return json_encode( $value, JSON_UNESCAPED_UNICODE ) ?: '';
        return (string) $value;
    }
}
