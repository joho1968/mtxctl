<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright 2026 Joaquim Homrighausen; all rights reserved.

namespace Joho\Mtxctl;

use Joho\Matrix\Contracts\SynapseAdminClientInterface;

/**
 * Stand-in admin client used when config is missing or invalid.
 *
 * Every method throws, producing a useful error message instead of a PHP fatal
 * when a command tries to call the API before credentials are configured.
 */
final class NullAdminClient implements SynapseAdminClientInterface
{
    public function __construct( private readonly string $reason ) {}

    private function fail(): never
    {
        throw new \RuntimeException( $this->reason );
    }

    public function listUsers( int $limit = 100, int $from = 0, bool $guests = false, bool $deactivated = false ): array
    {
        $this->fail();
    }

    public function getUser( string $userId ): array
    {
        $this->fail();
    }

    public function setUserAdmin( string $userId, bool $admin ): void
    {
        $this->fail();
    }

    public function resetUserPassword( string $userId, string $newPassword, bool $logoutDevices = true ): void
    {
        $this->fail();
    }

    public function deactivateUser( string $userId, bool $erase = true ): void
    {
        $this->fail();
    }

    public function getUserWhois( string $userId ): array
    {
        $this->fail();
    }

    public function shadowBanUser( string $userId, bool $ban = true ): void
    {
        $this->fail();
    }

    public function resolveAlias( string $alias ): ?string
    {
        $this->fail();
    }

    public function listRooms( int $limit = 100, int $from = 0, ?string $searchTerm = null ): array
    {
        $this->fail();
    }

    public function getRoom( string $roomId ): array
    {
        $this->fail();
    }

    public function getRoomMembers( string $roomId ): array
    {
        $this->fail();
    }

    public function makeRoomAdmin( string $roomId, string $userId ): void
    {
        $this->fail();
    }

    public function whoami(): string
    {
        $this->fail();
    }

    public function forceJoinRoom( string $roomId, string $userId ): void
    {
        $this->fail();
    }

    public function leaveRoom( string $roomId ): void
    {
        $this->fail();
    }

    public function kickRoomMember( string $roomId, string $userId, string $reason = '' ): void
    {
        $this->fail();
    }

    public function setRoomPowerLevel( string $roomId, string $userId, int $level ): void
    {
        $this->fail();
    }

    public function sendStateEvent( string $roomId, string $eventType, string $stateKey, array $content ): void
    {
        $this->fail();
    }

    public function getStateEvent( string $roomId, string $eventType, string $stateKey = '' ): ?array
    {
        $this->fail();
    }

    public function sendTombstone( string $roomId, string $replacementRoomId, string $body = 'This room has been replaced.' ): void
    {
        $this->fail();
    }

    public function deleteRoom( string $roomId, bool $purge = true ): string
    {
        $this->fail();
    }

    public function waitForRoomDeletion( string $deleteId, int $maxSeconds = 300 ): void
    {
        $this->fail();
    }

    public function purgeRemoteMedia( int $beforeTimestamp ): int
    {
        $this->fail();
    }

    public function listRegistrationTokens(): array
    {
        $this->fail();
    }

    public function getRegistrationToken( string $token ): array
    {
        $this->fail();
    }

    public function createRegistrationToken( ?string $token = null, ?int $usesAllowed = null, ?int $expiryTime = null ): array
    {
        $this->fail();
    }

    public function deleteRegistrationToken( string $token ): void
    {
        $this->fail();
    }

    public function listFederationDestinations( int $limit = 100, int $from = 0 ): array
    {
        $this->fail();
    }

    public function getFederationDestination( string $serverName ): array
    {
        $this->fail();
    }

    public function getServerVersion(): array
    {
        $this->fail();
    }
}
