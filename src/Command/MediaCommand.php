<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright 2026 Joaquim Homrighausen; all rights reserved.

namespace Joho\Mtxctl\Command;

use Joho\Matrix\Contracts\SynapseAdminClientInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Manage Matrix media: purge cached remote media. */
final class MediaCommand extends Command
{
    public function __construct( private readonly SynapseAdminClientInterface $admin )
    {
        parent::__construct( 'media' );
    }

    protected function configure(): void
    {
        $this
            ->setDescription( 'Manage Matrix media' )
            ->setHelp( <<<'HELP'
                Actions:
                  purge  Delete cached remote media older than --days (default: 90)

                Only affects media fetched from other homeservers. Locally uploaded
                files and content marked as protected are never touched. The remote
                server still holds the original; your server re-fetches on demand.
                HELP )
            ->addArgument( 'action', InputArgument::REQUIRED, 'purge' )
            ->addOption( 'days',    'd', InputOption::VALUE_REQUIRED, 'Delete remote media cached more than N days ago', 90 )
            ->addOption( 'confirm', null, InputOption::VALUE_NONE,   'Skip confirmation prompt' );
    }

    protected function execute( InputInterface $input, OutputInterface $output ): int
    {
        return match ( $input->getArgument( 'action' ) ) {
            'purge' => $this->actionPurge( $input, $output ),
            default => $this->unknownAction( (string) $input->getArgument( 'action' ), $output ),
        };
    }

    private function actionPurge( InputInterface $input, OutputInterface $output ): int
    {
        $days      = max( 1, (int) $input->getOption( 'days' ) );
        $beforeMs  = (int) ( ( time() - $days * 86400 ) * 1000 );
        $beforeStr = date( 'Y-m-d', (int) ( $beforeMs / 1000 ) );

        if ( !(bool) $input->getOption( 'confirm' ) ) {
            $output->writeln( sprintf(
                'Will purge remote media cached before %s (%d days). Re-run with --confirm to proceed.',
                $beforeStr,
                $days,
            ) );
            return Command::SUCCESS;
        }

        $output->write( sprintf( 'Purging remote media cached before %s… ', $beforeStr ) );
        $deleted = $this->admin->purgeRemoteMedia( $beforeMs );
        $output->writeln( sprintf( '<info>%d file(s) deleted</info>.', $deleted ) );

        return Command::SUCCESS;
    }

    private function unknownAction( string $action, OutputInterface $output ): int
    {
        $output->writeln( '<error>Unknown action "' . $action . '". Valid: purge</error>' );
        return Command::FAILURE;
    }
}
