<?php

namespace Bellamaison\CategoryProducts\Console;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Bellamaison\CategoryProducts\Cron\UpdateProductPositionsByCsvAuto as Cron;

class UpdateProductPositionsByCsvAuto extends Command
{
    const COMMAND_NAME = 'category_products:update_product_positions_by_csv_auto';
    const COMMAND_DESCRIPTION = 'Category Products -> Update Product positions by csv auto';

    protected State $state;
    private Cron $cron;

    /**
     * @param State $state
     * @param Cron $cron
     */
    public function __construct(
        State $state,
        Cron  $cron
    )
    {
        $this->state = $state;
        $this->cron = $cron;

        parent::__construct(self::COMMAND_NAME);
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME);
        $this->setDescription(__(self::COMMAND_DESCRIPTION));
        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws LocalizedException
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int
    {
        $this->state->setAreaCode(Area::AREA_ADMINHTML);
        $this->cron->execute();

        return 200;
    }
}
