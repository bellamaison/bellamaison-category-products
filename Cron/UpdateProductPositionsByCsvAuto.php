<?php

Namespace Bellamaison\CategoryProducts\Cron;

use Exception;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\File\Csv;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use Magento\Indexer\Model\IndexerFactory;
use Psr\Log\LoggerInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\App\ResourceConnection;

class UpdateProductPositionsByCsvAuto
{
    const DEFAULT_POSITION = 99999;
    const CSV_URL = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vRhPzfIxWult93MSxunxH9qRd0NWxL-LmBn_Hn_ytGuNeUKlKG7dSaQlFv53zI0LdcjBTzz8dMMPsWU/pub?output=csv';
    const CSV_FILENAME = 'update_product_positions_by_csv_auto.csv';
    const CATEGORY_PRODUCTS_TABLE = 'catalog_category_product';

    protected LoggerInterface $logger;
    private CategoryRepositoryInterface $categoryRepository;
    private AdapterInterface $connection;
    private array $categoryProducts = [];
    private array $productSkuId = [];
    private array $notFoundCategoryIds = [];
    private Csv $csv;
    private DirectoryList $directoryList;
    private ?string $fileName = null;
    private IndexerFactory $indexerFactory;
    private CollectionFactory $indexerCollectionFactory;

    /**
     * @param LoggerInterface $logger
     * @param CategoryRepositoryInterface $categoryRepository
     * @param ResourceConnection $resource
     * @param Csv $csv
     * @param DirectoryList $directoryList
     * @param IndexerFactory $indexerFactory
     * @param CollectionFactory $indexerCollectionFactory
     */
    public function __construct(
        LoggerInterface $logger,
        CategoryRepositoryInterface $categoryRepository,
        ResourceConnection $resource,
        Csv $csv,
        DirectoryList $directoryList,
        IndexerFactory $indexerFactory,
        CollectionFactory $indexerCollectionFactory
    ) {
        $this->logger = $logger;
        $this->categoryRepository = $categoryRepository;
        $this->connection = $resource->getConnection();
        $this->csv = $csv;
        $this->directoryList = $directoryList;
        $this->indexerFactory = $indexerFactory;
        $this->indexerCollectionFactory = $indexerCollectionFactory;
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        try {
            $this->init();
            $this->linkAllProductsToParentCategory();
            $this->updateProductPositions();
            $this->reIndex();
        } catch (NoSuchEntityException|FileSystemException|Exception $e) {
            $this->logger->error($e->getMessage());
            return;
        }
    }

    /**
     * @return void
     */
    private function init(): void
    {
        //category-products mapping
        $query = $this->connection->select()->from(self::CATEGORY_PRODUCTS_TABLE, ['category_id','product_id']);
        $result = $this->connection->fetchAll($query);
        foreach ($result as $row)
        {
            $this->categoryProducts[(int)$row['category_id']][] = (int)$row['product_id'];
        }

        //product id-sku mapping
        $query = $this->connection->select()->from('catalog_product_entity', ['entity_id','sku']);
        $result = $this->connection->fetchAll($query);
        foreach ($result as $row)
        {
            $this->productSkuId[$row['sku']] = (int)$row['entity_id'];
        }
    }

    /**
     * @param int $productId
     * @param int $categoryId
     * @return bool
     */
    private function productExistsInCategory(int $productId, int $categoryId): bool
    {
        if(!empty($this->categoryProducts[$categoryId]) && in_array($productId, $this->categoryProducts[$categoryId]))
        {
            return true;
        }

        return false;
    }

    /**
     * @return void
     */
    private function linkAllProductsToParentCategory(): void
    {
        $insertArray = [];

        foreach ($this->categoryProducts as $categoryId => $categoryProducts)
        {
            if(!empty($this->notFoundCategoryIds[$categoryId])) continue;

            try{
                $category = $this->categoryRepository->get($categoryId);
            }catch(NoSuchEntityException $e){
                $this->notFoundCategoryIds[$categoryId] = $categoryId;
                $this->logger->error($e->getMessage());
                continue;
            }

            $parentCategory = $category->getParentCategory();

            if($parentCategory->getLevel() < 2)
            {
                continue;
            }

            $parentCategoryId = (int)$parentCategory->getId();

            foreach ($categoryProducts as $productId)
            {
                if(!$this->productExistsInCategory($productId, $parentCategoryId))
                {
                    $insertArray[$parentCategoryId.'-'.$productId] = ['category_id' => $parentCategoryId, 'product_id' => $productId, 'position' => self::DEFAULT_POSITION];
                }
            }
        }

        if(!empty($insertArray))
        {
            $this->connection->insertMultiple(self::CATEGORY_PRODUCTS_TABLE, $insertArray);
        }
    }

    /**
     * @return void
     */
    private function updateProductPositions(): void
    {
        try{
            $result = $this->downloadCsv();
            if(!$result)
            {
                return;
            }

            $csvData = $this->csv->getData($this->fileName);

            $this->connection->update(
                self::CATEGORY_PRODUCTS_TABLE,
                ['position' => self::DEFAULT_POSITION]
            );

            foreach ($csvData as $row => $data)
            {
                if ($row > 0)
                {
                    $sku = $data[0];
                    $position = (int)$data[1];
                    $productId = $this->productSkuId[$sku] ?? null;

                    if($productId)
                    {
                        $this->connection->update(
                            self::CATEGORY_PRODUCTS_TABLE,
                            ['position' => $position],
                            ['product_id = ?' => $productId]
                        );
                    }
                }
            }
        }catch (Exception $e){
            $this->logger->error($e->getMessage());
            return;
        }
    }

    /**
     * @return bool
     */
    private function downloadCsv(): bool
    {
        try{
            $this->fileName = $this->directoryList->getPath('var').DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.self::CSV_FILENAME;
            @unlink($this->fileName);
            @file_put_contents($this->fileName, @file_get_contents(self::CSV_URL));

            return true;
        }catch (Exception $e){
            $this->logger->error($e->getMessage());
            return false;
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    private function reIndex(): void
    {
        $allowedTypes = ['catalog_category_product'];

        $indexerCollection = $this->indexerCollectionFactory->create();
        $allTypes = $indexerCollection->getAllIds();

        foreach ($allTypes as $type)
        {
            if(in_array($type, $allowedTypes))
            {
                $idx = $this->indexerFactory->create()->load($type);
                $idx->reindexAll();
            }
        }
    }
}