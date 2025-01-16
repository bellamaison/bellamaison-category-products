<?php

namespace Bellamaison\CategoryProducts\Model;

use Exception;
use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\File\Csv;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use Magento\Indexer\Model\IndexerFactory;

class UpdateCategoryProducts
{
    const DATA_FILE_PATH = 'pub/media/bellamaison/category-products/product-updater/data.csv';
    const PRODUCT_TABLE = 'catalog_product_entity';
    const PRODUCT_CATEGORY_TABLE = 'catalog_category_product';
    const DEFAULT_POSITION = 99999;

    private DirectoryList $_directory;
    private Csv $_csv;
    private AdapterInterface $_connection;

    private array $productIdMapping = [];
    private TypeListInterface $_cacheTypeList;
    private Pool $_cacheFrontendPool;
    private IndexerFactory $_indexerFactory;
    private CollectionFactory $_indexerCollectionFactory;

    /**
     * @param DirectoryList $directory
     * @param Csv $csv
     * @param ResourceConnection $resource
     * @param TypeListInterface $cacheTypeList
     * @param Pool $cacheFrontendPool
     * @param IndexerFactory $indexerFactory
     * @param CollectionFactory $indexerCollectionFactory
     */
    public function __construct(
        DirectoryList $directory,
        Csv $csv,
        ResourceConnection $resource,
        TypeListInterface $cacheTypeList,
        Pool $cacheFrontendPool,
        IndexerFactory $indexerFactory,
        CollectionFactory $indexerCollectionFactory
    ) {
        $this->_directory = $directory;
        $this->_csv = $csv;
        $this->_connection = $resource->getConnection();
        $this->_cacheTypeList = $cacheTypeList;
        $this->_cacheFrontendPool = $cacheFrontendPool;
        $this->_indexerFactory = $indexerFactory;
        $this->_indexerCollectionFactory = $indexerCollectionFactory;
    }

    /**
     * @return array
     * @throws Exception
     */
    public function execute(): array
    {
        $fileFullPath = $this->_directory->getRoot(). DIRECTORY_SEPARATOR . self::DATA_FILE_PATH;
        $csvData = $this->_csv->getData($fileFullPath);

        $productCategoryMapping = $this->getProductCategoryMapping();

        foreach ($csvData as $row => $data) {
            if ($row > 0 && !empty($data[0]) && !empty($data[1]) && (int)$data[1] > 0) {
                $sku = $data[0];
                $categoryId = (int)$data[1];

                if($productId = $this->getProductIdBySku($sku)) {
                    $this->productIdMapping[$productId][] = $categoryId;
                }
            }
        }

        foreach ($this->productIdMapping as $productId => $categoryIds) {
            foreach ($categoryIds as $categoryId) {
                if(!in_array($categoryId, $productCategoryMapping[$productId]))
                {
                    $data = ['product_id' => $productId, 'category_id' => $categoryId, 'position' => self::DEFAULT_POSITION];
                    $this->_connection->insert(self::PRODUCT_CATEGORY_TABLE, $data);
                }
            }
        }

        $this->reIndex();
        $this->cleanCache();

        return ['result' => 'success', 'message' => count($this->productIdMapping).' products updated successfully.'];
    }

    /**
     * @param string $sku
     * @return int
     */
    private function getProductIdBySku(string $sku): int
    {
        $query = $this->_connection->select()
            ->from('catalog_product_entity', ['entity_id'])
            ->where('sku = ?', $sku);

        return (int)$this->_connection->fetchOne($query);
    }

    /**
     * @return void
     */
    private function cleanCache(): void
    {
        $types = array('block_html','collections','full_page');

        foreach ($types as $type)
        {
            $this->_cacheTypeList->cleanType($type);
        }

        foreach ($this->_cacheFrontendPool as $cacheFrontend)
        {
            $cacheFrontend->getBackend()->clean();
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    private function reIndex(): void
    {
        $allowedTypes = ['catalog_category_product','catalog_product_category','catalogsearch_fulltext'];

        $indexerCollection = $this->_indexerCollectionFactory->create();
        $allTypes = $indexerCollection->getAllIds();

        foreach ($allTypes as $type)
        {
            if(in_array($type, $allowedTypes))
            {
                $idx = $this->_indexerFactory->create()->load($type);
                $idx->reindexAll();
            }
        }
    }

    /**
     * @return array
     */
    private function getProductCategoryMapping(): array
    {
        $return = [];

        $query = $this->_connection->select()->from(self::PRODUCT_TABLE, ['product_id' => 'entity_id'])
            ->joinLeft(
                self::PRODUCT_CATEGORY_TABLE,
                self::PRODUCT_TABLE.'.entity_id = '.self::PRODUCT_CATEGORY_TABLE.'.product_id',
                ['category_id']
            );

        $result = $this->_connection->fetchAll($query);

        foreach ($result as $row)
        {
            $return[$row['product_id']][] = $row['category_id'];
        }

        return $return;
    }
}


