<?php

use NormanHuth\ConsoleApp\LuraInstaller;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class Installer extends LuraInstaller
{
    /**
     * Execute the installer console command.
     *
     * @param mixed|\NormanHuth\ConsoleApp\LuraCommand $command
     * @return int
     */
    public function runLura(mixed $command): int
    {
        // Installer here

        return SymfonyCommand::SUCCESS;
    }
}
