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

/** Manage Matrix rooms: list, show, members, make-admin, delete, tombstone. */
final class RoomCommand extends Command
{
    public function __construct( private readonly SynapseAdminClientInterface $admin )
    {
        parent::__construct( 'room' );
    }

    protected function configure(): void
    {
        $this
            ->setDescription( 'Manage Matrix rooms' )
            ->setHelp( <<<'HELP'
                Actions:
                  list        List rooms on the homeserver (paginated)
                  show        Show details for one room
                  members     List members of a room
                  make-admin  Promote a local user to room admin
                  delete      Kick all members and delete a room
                  tombstone   Mark a room as superseded, redirecting users to another room
                HELP )
            ->addArgument( 'action',      InputArgument::REQUIRED, 'list | show | members | make-admin | delete | tombstone' )
            ->addArgument( 'room-id',     InputArgument::OPTIONAL, 'Room ID (!abc:server) or alias (#alias:server) — quote either in shells' )
            ->addArgument( 'target-room', InputArgument::OPTIONAL, 'Replacement room (tombstone action only)' )
            ->addOption( 'limit',    'l',  InputOption::VALUE_REQUIRED, 'Max results for list', 100 )
            ->addOption( 'from',     null, InputOption::VALUE_REQUIRED, 'Pagination offset',      0 )
            ->addOption( 'user',     null, InputOption::VALUE_REQUIRED, 'Matrix user ID for make-admin (@user:server)' )
            ->addOption( 'no-purge', null, InputOption::VALUE_NONE,    'Delete but do not purge event history' )
            ->addOption( 'body',     null, InputOption::VALUE_REQUIRED, 'Tombstone message shown to users', 'This room has been replaced.' )
            ->addOption( 'confirm',  null, InputOption::VALUE_NONE,    'Skip confirmation prompt' )
            ->addOption( 'json',     null, InputOption::VALUE_NONE,    'Output as JSON' );
    }

    protected function execute( InputInterface $input, OutputInterface $output ): int
    {
        return match ( $input->getArgument( 'action' ) ) {
            'list'       => $this->actionList( $input, $output ),
            'show'       => $this->actionShow( $input, $output ),
            'members'    => $this->actionMembers( $input, $output ),
            'make-admin' => $this->actionMakeAdmin( $input, $output ),
            'delete'     => $this->actionDelete( $input, $output ),
            'tombstone'  => $this->actionTombstone( $input, $output ),
            default      => $this->unknownAction( (string) $input->getArgument( 'action' ), $output ),
        };
    }

    private function actionList( InputInterface $input, OutputInterface $output ): int
    {
        $result = $this->admin->listRooms(
            limit: (int) $input->getOption( 'limit' ),
            from:  (int) $input->getOption( 'from' ),
        );

        $rooms = $result['rooms'] ?? [];

        if ( (bool) $input->getOption( 'json' ) ) {
            $output->writeln( json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
            return Command::SUCCESS;
        }

        $output->writeln( sprintf(
            'Total: %d  (showing %d)',
            $result['total_rooms'] ?? 0,
            count( $rooms ),
        ) );

        $table = new Table( $output );
        $table->setHeaders( [ 'Room ID', 'Name', 'Members', 'Public', 'Encrypted' ] );

        foreach ( $rooms as $room ) {
            $table->addRow( [
                $room['room_id']         ?? '',
                $room['name']            ?? '',
                (string) ( $room['joined_members'] ?? 0 ),
                ( $room['public'] ?? false ) ? 'yes' : 'no',
                isset( $room['encryption'] ) && $room['encryption'] !== null ? 'yes' : 'no',
            ] );
        }

        $table->render();

        if ( isset( $result['next_batch'] ) && $result['next_batch'] !== null ) {
            $output->writeln( sprintf( '  >> more results available; use --from=%d', $result['next_batch'] ) );
        }

        return Command::SUCCESS;
    }

    private function actionShow( InputInterface $input, OutputInterface $output ): int
    {
        $raw    = $this->requireArg( $input, 'room-id', $output );
        $roomId = $raw !== null ? $this->resolveRoomId( $raw, $output ) : null;
        if ( $roomId === null ) {
            return Command::FAILURE;
        }

        $room = $this->admin->getRoom( $roomId );

        if ( (bool) $input->getOption( 'json' ) ) {
            $output->writeln( json_encode( $room, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
            return Command::SUCCESS;
        }

        $table = new Table( $output );
        $table->setStyle( 'compact' );

        foreach ( $room as $key => $value ) {
            $table->addRow( [ '<info>' . $key . '</info>', $this->scalar( $value ) ] );
        }

        $table->render();
        return Command::SUCCESS;
    }

    private function actionMembers( InputInterface $input, OutputInterface $output ): int
    {
        $raw    = $this->requireArg( $input, 'room-id', $output );
        $roomId = $raw !== null ? $this->resolveRoomId( $raw, $output ) : null;
        if ( $roomId === null ) {
            return Command::FAILURE;
        }

        $result  = $this->admin->getRoomMembers( $roomId );
        $members = $result['members'] ?? [];

        if ( (bool) $input->getOption( 'json' ) ) {
            $output->writeln( json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
            return Command::SUCCESS;
        }

        $output->writeln( sprintf( 'Members (%d):', count( $members ) ) );
        foreach ( $members as $member ) {
            $output->writeln( '  ' . $member );
        }

        return Command::SUCCESS;
    }

    private function actionMakeAdmin( InputInterface $input, OutputInterface $output ): int
    {
        $raw    = $this->requireArg( $input, 'room-id', $output );
        $roomId = $raw !== null ? $this->resolveRoomId( $raw, $output ) : null;
        if ( $roomId === null ) {
            return Command::FAILURE;
        }

        $userId = (string) $input->getOption( 'user' );
        if ( $userId === '' ) {
            $output->writeln( '<error>Missing required option: --user</error>' );
            return Command::FAILURE;
        }

        $this->admin->makeRoomAdmin( $roomId, $userId );
        $output->writeln( sprintf( '<info>%s</info> is now admin in <info>%s</info>.', $userId, $roomId ) );
        return Command::SUCCESS;
    }

    private function actionDelete( InputInterface $input, OutputInterface $output ): int
    {
        $raw    = $this->requireArg( $input, 'room-id', $output );
        $roomId = $raw !== null ? $this->resolveRoomId( $raw, $output ) : null;
        if ( $roomId === null ) {
            return Command::FAILURE;
        }

        $purge = !(bool) $input->getOption( 'no-purge' );

        if ( !(bool) $input->getOption( 'confirm' ) ) {
            $output->writeln( sprintf(
                'Will delete <comment>%s</comment> (purge=%s). Re-run with --confirm to proceed.',
                $roomId,
                $purge ? 'true' : 'false',
            ) );
            return Command::SUCCESS;
        }

        $output->write( sprintf( 'Deleting <comment>%s</comment>… ', $roomId ) );
        $deleteId = $this->admin->deleteRoom( $roomId, $purge );
        $this->admin->waitForRoomDeletion( $deleteId );
        $output->writeln( '<info>done</info>.' );

        return Command::SUCCESS;
    }

    private function actionTombstone( InputInterface $input, OutputInterface $output ): int
    {
        $rawSource = $this->requireArg( $input, 'room-id',     $output );
        $rawTarget = $this->requireArg( $input, 'target-room', $output );
        if ( $rawSource === null || $rawTarget === null ) {
            return Command::FAILURE;
        }

        $sourceId = $this->resolveRoomId( $rawSource, $output );
        $targetId = $this->resolveRoomId( $rawTarget, $output );
        if ( $sourceId === null || $targetId === null ) {
            return Command::FAILURE;
        }

        $body = (string) ( $input->getOption( 'body' ) ?: 'This room has been replaced.' );

        if ( !(bool) $input->getOption( 'confirm' ) ) {
            $output->writeln( sprintf(
                'Will tombstone <comment>%s</comment> -> <comment>%s</comment>. Re-run with --confirm to proceed.',
                $sourceId,
                $targetId,
            ) );
            return Command::SUCCESS;
        }

        $this->admin->sendTombstone( $sourceId, $targetId, $body );
        $output->writeln( sprintf(
            'Tombstone sent on <info>%s</info>. Users will be directed to <info>%s</info>.',
            $sourceId,
            $targetId,
        ) );
        return Command::SUCCESS;
    }

    private function unknownAction( string $action, OutputInterface $output ): int
    {
        $output->writeln( '<error>Unknown action "' . $action . '". Valid: list, show, members, make-admin, delete, tombstone</error>' );
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

    /**
     * Accept either a room ID (!id:server) or an alias (#alias:server).
     * Resolves aliases to room IDs before returning.
     */
    private function resolveRoomId( string $value, OutputInterface $output ): ?string
    {
        if ( str_starts_with( $value, '!' ) ) {
            return $value;
        }

        if ( str_starts_with( $value, '#' ) ) {
            $roomId = $this->admin->resolveAlias( $value );
            if ( $roomId === null ) {
                $output->writeln( '<error>Alias not found: ' . $value . '</error>' );
                return null;
            }
            return $roomId;
        }

        $output->writeln( '<error>Room identifier must start with ! (room ID) or # (alias): ' . $value . '</error>' );
        return null;
    }

    private function scalar( mixed $value ): string
    {
        if ( is_bool( $value ) )  return $value ? 'true' : 'false';
        if ( $value === null )    return '';
        if ( is_array( $value ) ) return json_encode( $value, JSON_UNESCAPED_UNICODE ) ?: '';
        return (string) $value;
    }
}
