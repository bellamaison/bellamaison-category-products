<?php

namespace Bellamaison\CategoryProducts\Console;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Magento\Framework\Exception\LocalizedException;
use Bellamaison\CategoryProducts\Model\UpdateCategoryProducts as CategoryProductsUpdater;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class UpdateProducts extends Command
{
    const COMMAND_NAME = 'category_products:update_products';
    const COMMAND_DESCRIPTION = 'Category Products -> Update Products';

    protected State $state;
    private CategoryProductsUpdater $categoryProductsUpdater;

    /**
     * @param State $state
     * @param CategoryProductsUpdater $categoryProductsUpdater
     */
    public function __construct(
        State $state,
        CategoryProductsUpdater  $categoryProductsUpdater
    )
    {
        $this->state = $state;
        $this->categoryProductsUpdater = $categoryProductsUpdater;

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

        try
        {
            $output->writeln('<info>Start</info>');

            $response = $this->categoryProductsUpdater->execute();

            if ($response['result'] == 'success') {
                $output->writeln('<info>' . $response['message'] . '</info>');
            } else {
                $output->writeln('<error>' . $response['message'] . '</error>');
            }

            $output->writeln('<info>Finish</info>');

        } catch (LocalizedException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

        } catch (Throwable $e) {
            $output->writeln('<error>' .  __('Something went wrong while processing: %1',$e->getMessage()) .'</error>');
        }

        return 200;
    }
}
