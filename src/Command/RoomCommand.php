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

/** Manage Matrix rooms: list, show, members, make-admin, kick, power, retention, delete, tombstone. */
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
                  kick        Kick a member from a room
                  power       Show or set a user's power level in a room
                  retention   Show, set, or clear the m.room.retention policy
                  delete      Kick all members and delete a room
                  tombstone   Mark a room as superseded, redirecting users to another room

                Power levels: 0 = default member, 50 = moderator, 100 = admin.
                You can lower your own power level (e.g. to demote yourself).
                HELP )
            ->addArgument( 'action',      InputArgument::REQUIRED, 'list | show | members | make-admin | kick | power | retention | delete | tombstone' )
            ->addArgument( 'room-id',     InputArgument::OPTIONAL, 'Room ID (!abc:server) or alias (#alias:server) — quote either in shells' )
            ->addArgument( 'target-room', InputArgument::OPTIONAL, 'Replacement room (tombstone action only)' )
            ->addOption( 'limit',    'l',  InputOption::VALUE_REQUIRED, 'Max results for list', 100 )
            ->addOption( 'from',     null, InputOption::VALUE_REQUIRED, 'Pagination offset',       0 )
            ->addOption( 'search',   's',  InputOption::VALUE_REQUIRED, 'Filter by name, alias, or room ID (list / retention)' )
            ->addOption( 'user',      null, InputOption::VALUE_REQUIRED, 'Matrix user ID for make-admin (@user:server)' )
            ->addOption( 'no-purge', null, InputOption::VALUE_NONE,    'Delete but do not purge event history' )
            ->addOption( 'body',     null, InputOption::VALUE_REQUIRED, 'Tombstone message shown to users', 'This room has been replaced.' )
            ->addOption( 'reason',   null, InputOption::VALUE_REQUIRED, 'Reason for kick' )
            ->addOption( 'level',    null, InputOption::VALUE_REQUIRED, 'Power level to set (0=member, 50=moderator, 100=admin)' )
            ->addOption( 'days',     null, InputOption::VALUE_REQUIRED, 'Retention period in days (retention action)' )
            ->addOption( 'clear',    null, InputOption::VALUE_NONE,    'Clear retention policy (retention action)' )
            ->addOption( 'retention',null, InputOption::VALUE_NONE,    'Fetch and show retention for each room (list action; slower)' )
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
            'kick'       => $this->actionKick( $input, $output ),
            'power'      => $this->actionPower( $input, $output ),
            'retention'  => $this->actionRetention( $input, $output ),
            'delete'     => $this->actionDelete( $input, $output ),
            'tombstone'  => $this->actionTombstone( $input, $output ),
            default      => $this->unknownAction( (string) $input->getArgument( 'action' ), $output ),
        };
    }

    private function actionList( InputInterface $input, OutputInterface $output ): int
    {
        $search = $input->getOption( 'search' );
        $result = $this->admin->listRooms(
            limit:      (int) $input->getOption( 'limit' ),
            from:       (int) $input->getOption( 'from' ),
            searchTerm: $search !== null ? (string) $search : null,
        );

        $rooms       = $result['rooms'] ?? [];
        $showRetention = (bool) $input->getOption( 'retention' );

        if ( (bool) $input->getOption( 'json' ) ) {
            $output->writeln( json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
            return Command::SUCCESS;
        }

        $output->writeln( sprintf(
            'Total: %d  (showing %d)',
            $result['total_rooms'] ?? 0,
            count( $rooms ),
        ) );

        $headers = [ 'Room ID', 'Name', 'Members', 'Public', 'Encrypted' ];
        if ( $showRetention ) {
            $headers[] = 'Retention';
        }

        $table = new Table( $output );
        $table->setHeaders( $headers );

        foreach ( $rooms as $room ) {
            $row = [
                $room['room_id']         ?? '',
                $room['name']            ?? '',
                (string) ( $room['joined_members'] ?? 0 ),
                ( $room['public'] ?? false ) ? 'yes' : 'no',
                isset( $room['encryption'] ) && $room['encryption'] !== null ? 'yes' : 'no',
            ];

            if ( $showRetention ) {
                $ret = $this->admin->getStateEvent( $room['room_id'], 'm.room.retention' );
                $row[] = isset( $ret['max_lifetime'] )
                    ? $this->msTodays( (int) $ret['max_lifetime'] ) . 'd'
                    : '-';
            }

            $table->addRow( $row );
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

        $room      = $this->admin->getRoom( $roomId );
        $retention = $this->admin->getStateEvent( $roomId, 'm.room.retention' );

        if ( isset( $retention['max_lifetime'] ) ) {
            $room['retention'] = $this->msTodays( (int) $retention['max_lifetime'] ) . ' days';
        }

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
        $output->writeln( sprintf( '<info>%s</info> is now admin in <info>%s</info>.', $userId, $this->roomLabel( $roomId ) ) );
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
        $label = $this->roomLabel( $roomId );

        if ( !(bool) $input->getOption( 'confirm' ) ) {
            $output->writeln( sprintf(
                'Will delete <comment>%s</comment> (purge=%s). Re-run with --confirm to proceed.',
                $label,
                $purge ? 'true' : 'false',
            ) );
            return Command::SUCCESS;
        }

        $output->write( sprintf( 'Deleting <comment>%s</comment>… ', $label ) );
        $deleteId = $this->admin->deleteRoom( $roomId, $purge );
        $this->admin->waitForRoomDeletion( $deleteId );
        $output->writeln( '<info>done</info>.' );

        return Command::SUCCESS;
    }

    private function actionKick( InputInterface $input, OutputInterface $output ): int
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

        $reason = (string) ( $input->getOption( 'reason' ) ?? '' );

        $label = $this->roomLabel( $roomId );

        if ( !(bool) $input->getOption( 'confirm' ) ) {
            $output->writeln( sprintf(
                'Will kick <comment>%s</comment> from <comment>%s</comment>%s. Re-run with --confirm to proceed.',
                $userId,
                $label,
                $reason !== '' ? ' (reason: ' . $reason . ')' : '',
            ) );
            return Command::SUCCESS;
        }

        $this->admin->kickRoomMember( $roomId, $userId, $reason );
        $output->writeln( sprintf( '<info>%s</info> kicked from <info>%s</info>.', $userId, $label ) );
        return Command::SUCCESS;
    }

    private function actionPower( InputInterface $input, OutputInterface $output ): int
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

        $levelOpt = $input->getOption( 'level' );

        $label = $this->roomLabel( $roomId );

        // No --level: show current power level.
        if ( $levelOpt === null ) {
            $pls          = $this->admin->getStateEvent( $roomId, 'm.room.power_levels' );
            $defaultLevel = (int) ( $pls['users_default'] ?? 0 );
            $level        = (int) ( $pls['users'][$userId] ?? $defaultLevel );
            $output->writeln( sprintf( '<info>%s</info> has power level <info>%d</info> in <info>%s</info>.', $userId, $level, $label ) );
            return Command::SUCCESS;
        }

        $level = (int) $levelOpt;
        $this->admin->setRoomPowerLevel( $roomId, $userId, $level );
        $output->writeln( sprintf( 'Power level for <info>%s</info> set to <info>%d</info> in <info>%s</info>.', $userId, $level, $label ) );
        return Command::SUCCESS;
    }

    private function actionRetention( InputInterface $input, OutputInterface $output ): int
    {
        $days   = $input->getOption( 'days' );
        $clear  = (bool) $input->getOption( 'clear' );
        $search = $input->getOption( 'search' );
        $raw    = $input->getArgument( 'room-id' );

        if ( $days !== null && $clear ) {
            $output->writeln( '<error>Use either --days or --clear, not both.</error>' );
            return Command::FAILURE;
        }

        if ( $days !== null && (int) $days < 1 ) {
            $output->writeln( '<error>--days must be at least 1. Use --clear to remove a retention policy.</error>' );
            return Command::FAILURE;
        }

        // Bulk path: --search without a room-id argument.
        if ( ( $raw === null || $raw === '' ) && $search !== null && $search !== '' ) {
            if ( $days === null && !$clear ) {
                return $this->retentionBulkShow( (string) $search, $output );
            }
            $content = $clear ? [] : [ 'max_lifetime' => (int) $days * 86400 * 1000 ];
            return $this->retentionBulkSet( (string) $search, $content, $days, $clear, $input, $output );
        }

        // Single-room path.
        $roomId = $raw !== null && $raw !== '' ? $this->resolveRoomId( (string) $raw, $output ) : null;
        if ( $roomId === null ) {
            if ( $search === null ) {
                $output->writeln( '<error>Provide a room ID/alias argument or --search for bulk.</error>' );
            }
            return Command::FAILURE;
        }

        if ( $days === null && !$clear ) {
            $ret = $this->admin->getStateEvent( $roomId, 'm.room.retention' );
            if ( $ret === null || !isset( $ret['max_lifetime'] ) ) {
                $output->writeln( 'No retention policy set.' );
            } else {
                $output->writeln( sprintf( 'Retention: <info>%d days</info>.', $this->msTodays( (int) $ret['max_lifetime'] ) ) );
            }
            return Command::SUCCESS;
        }

        $content = $clear ? [] : [ 'max_lifetime' => (int) $days * 86400 * 1000 ];
        $label   = $clear
            ? sprintf( 'clear retention on <comment>%s</comment>', $this->roomLabel( $roomId ) )
            : sprintf( 'set retention on <comment>%s</comment> to <comment>%d days</comment>', $this->roomLabel( $roomId ), (int) $days );

        if ( !(bool) $input->getOption( 'confirm' ) ) {
            $output->writeln( sprintf( 'Will %s. Re-run with --confirm to proceed.', $label ) );
            return Command::SUCCESS;
        }

        $adminId = $this->admin->whoami();
        $this->applyStateEventWithPromotion( $roomId, 'm.room.retention', '', $content, $adminId );
        $output->writeln( sprintf( 'Done: %s.', $label ) );
        return Command::SUCCESS;
    }

    private function retentionBulkShow( string $search, OutputInterface $output ): int
    {
        $rooms = $this->fetchAllRooms( $search );

        if ( empty( $rooms ) ) {
            $output->writeln( sprintf( 'No rooms found matching "%s".', $search ) );
            return Command::SUCCESS;
        }

        $table = new Table( $output );
        $table->setHeaders( [ 'Room ID', 'Name', 'Retention' ] );

        foreach ( $rooms as $room ) {
            $roomId = (string) ( $room['room_id'] ?? '' );
            $ret    = $this->admin->getStateEvent( $roomId, 'm.room.retention' );
            $table->addRow( [
                $roomId,
                $room['name'] ?? '',
                isset( $ret['max_lifetime'] ) ? $this->msTodays( (int) $ret['max_lifetime'] ) . 'd' : '-',
            ] );
        }

        $table->render();
        return Command::SUCCESS;
    }

    private function retentionBulkSet( string $search, array $content, mixed $days, bool $clear, InputInterface $input, OutputInterface $output ): int
    {
        $rooms = $this->fetchAllRooms( $search );

        if ( empty( $rooms ) ) {
            $output->writeln( sprintf( 'No rooms found matching "%s".', $search ) );
            return Command::SUCCESS;
        }

        $label = $clear
            ? 'clear retention'
            : sprintf( 'set retention to %d days', (int) $days );

        if ( !(bool) $input->getOption( 'confirm' ) ) {
            $output->writeln( sprintf( 'Will %s on %d room(s) matching "%s":', $label, count( $rooms ), $search ) );
            foreach ( $rooms as $room ) {
                $output->writeln( sprintf( '  %s  %s', $room['room_id'] ?? '', $room['name'] ?? '' ) );
            }
            $output->writeln( 'Re-run with --confirm to proceed.' );
            return Command::SUCCESS;
        }

        $adminId = $this->admin->whoami();
        $ok      = 0;
        $skipped = 0;

        $output->writeln( sprintf( 'Applying: %s on %d room(s) matching "%s":', $label, count( $rooms ), $search ) );

        foreach ( $rooms as $room ) {
            $roomId   = (string) ( $room['room_id'] ?? '' );
            $roomName = (string) ( $room['name'] ?? '' );
            $retries  = 0;

            while ( true ) {
                try {
                    $this->applyStateEventWithPromotion( $roomId, 'm.room.retention', '', $content, $adminId );
                    $output->writeln( sprintf( '  [ok]   %s  %s', $roomId, $roomName ) );
                    $ok++;
                    break;
                } catch ( \Joho\Matrix\Exception\HttpException $e ) {
                    if ( $e->response->statusCode === 429 && $retries < 4 ) {
                        $waitMs = $this->retryAfterMs( $e );
                        $output->writeln( sprintf( '  [wait] %s — rate limited, retrying in %.1fs…', $roomId, $waitMs / 1000 ) );
                        usleep( $waitMs * 1000 );
                        $retries++;
                        continue;
                    }
                    $output->writeln( sprintf( '  [skip] %s — %s', $roomId, $e->getMessage() ) );
                    $skipped++;
                    break;
                } catch ( \RuntimeException $e ) {
                    $output->writeln( sprintf( '  [skip] %s — %s', $roomId, $e->getMessage() ) );
                    $skipped++;
                    break;
                }
            }
        }

        $output->writeln( sprintf( 'Done. %d updated, %d skipped.', $ok, $skipped ) );
        return Command::SUCCESS;
    }

    /**
     * Send a state event, promoting the admin user via the Synapse admin API if
     * the client API returns 403. After setting the event, reverts power level
     * and leaves the room so no permanent trace is left.
     *
     * Note: the join and leave events are visible to room members.
     */
    private function applyStateEventWithPromotion( string $roomId, string $eventType, string $stateKey, array $content, string $adminId ): void
    {
        try {
            $this->admin->sendStateEvent( $roomId, $eventType, $stateKey, $content );
            return;
        } catch ( \Joho\Matrix\Exception\HttpException $e ) {
            if ( $e->response->statusCode !== 403 ) {
                throw $e;
            }
        }

        // Invite @adminId with PL 100 (Synapse admin API — works without being a member).
        $this->admin->makeRoomAdmin( $roomId, $adminId );
        // Accept the invite immediately as the admin token user (client API).
        $this->admin->forceJoinRoom( $roomId, $adminId );

        try {
            $this->admin->sendStateEvent( $roomId, $eventType, $stateKey, $content );
        } catch ( \RuntimeException $e ) {
            // Best-effort leave before re-throwing.
            try { $this->admin->leaveRoom( $roomId ); } catch ( \RuntimeException ) {}
            throw $e;
        }

        // Revert power level — remove admin from the users map (falls back to users_default).
        $pls = $this->admin->getStateEvent( $roomId, 'm.room.power_levels' );
        if ( $pls !== null ) {
            unset( $pls['users'][$adminId] );
            try {
                $this->admin->sendStateEvent( $roomId, 'm.room.power_levels', '', $pls );
            } catch ( \RuntimeException ) {
                // Non-fatal — leave anyway.
            }
        }

        $this->admin->leaveRoom( $roomId );
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchAllRooms( string $search ): array
    {
        $rooms = [];
        $from  = 0;

        do {
            $result = $this->admin->listRooms( limit: 100, from: $from, searchTerm: $search );
            $page   = $result['rooms'] ?? [];
            $rooms  = array_merge( $rooms, $page );
            $from   = $result['next_batch'] ?? null;
        } while ( $from !== null );

        return $rooms;
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
        $output->writeln( '<error>Unknown action "' . $action . '". Valid: list, show, members, make-admin, kick, power, retention, delete, tombstone</error>' );
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

    private function retryAfterMs( \Joho\Matrix\Exception\HttpException $e ): int
    {
        if ( preg_match( '/"retry_after_ms"\s*:\s*(\d+)/', $e->getMessage(), $m ) ) {
            return max( 500, (int) $m[1] );
        }
        return 5000;
    }

    private function roomLabel( string $roomId ): string
    {
        try {
            $name = (string) ( $this->admin->getRoom( $roomId )['name'] ?? '' );
        } catch ( \RuntimeException ) {
            $name = '';
        }
        return $name !== '' ? sprintf( '%s (%s)', $roomId, $name ) : $roomId;
    }

    private function msTodays( int $ms ): int
    {
        return (int) round( $ms / 86400000 );
    }
}
