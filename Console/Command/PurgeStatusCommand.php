<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Console\Command;

use Qoliber\TridentCache\Cron\DrainPurgeOutbox;
use Qoliber\TridentCache\Model\Outbox\PurgeOutboxInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento trident:purge:status` — purges committed but not yet
 * acknowledged by Trident. Exits 1 when the oldest has waited longer than
 * the stale threshold, so a monitoring check can alert on it.
 */
class PurgeStatusCommand extends Command
{
    /**
     * @param PurgeOutboxInterface $outbox
     */
    public function __construct(
        private readonly PurgeOutboxInterface $outbox
    ) {
        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('trident:purge:status')
            ->setDescription('Purges not yet acknowledged by Trident (exit 1 when stuck)');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stats = $this->outbox->stats();
        $output->writeln(sprintf('pending:      %d', $stats['pending']));
        $output->writeln(sprintf(
            'oldest age:   %s',
            $stats['oldest_age'] === null ? '-' : $stats['oldest_age'] . 's'
        ));
        $output->writeln(sprintf(
            'last failure: %s',
            $stats['last_error'] === null ? '-' : $stats['last_error'] . ' (' . $stats['last_error_at'] . ')'
        ));
        if (($stats['oldest_age'] ?? 0) > DrainPurgeOutbox::STALE_AFTER) {
            $output->writeln(
                '<error>Purges have waited longer than ' . DrainPurgeOutbox::STALE_AFTER . 's. '
                . 'Check that cron runs and that Trident accepts the api_token.</error>'
            );
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }
}
