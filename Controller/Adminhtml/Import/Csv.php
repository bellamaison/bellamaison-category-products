<?php

namespace Bellamaison\CategoryProducts\Controller\Adminhtml\Import;

use Bellamaison\CategoryProducts\Model\UpdateCategoryProductsByCsvManual as CategoryProductsUpdater;
use Exception;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\File\Csv as CsvFile;
use Magento\Framework\Filesystem;
use Magento\MediaStorage\Model\File\UploaderFactory;
use Bellamaison\CategoryProducts\Model\UpdateCategoryProductsByCsvManual;

class Csv extends Action implements CsrfAwareActionInterface
{
    const UploadFolder = 'bellamaison/category-products/product-updater';
    const UploadFile = 'data.csv';
    const InputFileName = 'file';

    protected Context $context;
    protected PageFactory $resultPageFactory;
    protected CsvFile $csv;
    protected Filesystem $fileSystem;
    protected UploaderFactory $fileUploader;
    private Filesystem\Directory\WriteInterface $mediaDirectory;
    private CategoryProductsUpdater $categoryProductsUpdater;

    /**
     * Dependency Initialization
     *
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param CsvFile $csv
     * @param Filesystem $fileSystem
     * @param UploaderFactory $fileUploader
     * @param CategoryProductsUpdater $categoryProductsUpdater
     * @throws FileSystemException
     */
    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        CsvFile $csv,
        Filesystem $fileSystem,
        UploaderFactory $fileUploader,
        CategoryProductsUpdater  $categoryProductsUpdater
    ) {
        parent::__construct($context);

        $this->resultPageFactory = $resultPageFactory;
        $this->csv = $csv;
        $this->categoryProductsUpdater = $categoryProductsUpdater;

        $this->fileSystem = $fileSystem;
        $this->fileUploader = $fileUploader;
        $this->mediaDirectory = $fileSystem->getDirectoryWrite(DirectoryList::MEDIA);
    }

    /**
     *
     * @return Page
     * @throws Exception
     */
    public function execute(): Page
    {
        if($this->getRequest()->getParam('upload'))
        {
            $fileName = $this->uploadFile();
            if(!$fileName)
            {
                $this->_redirect('category_products/import/csv');
            }

            $message = __('Bu işlem sadece csv\'deki ürünleri ilgili kategoriye '.UpdateCategoryProductsByCsvManual::DEFAULT_POSITION.' sıra numarası ile bağlar, çıkarma yapmaz.');
            $message .= __('<br/><br/>Ürün sıralamalarını güncellemek için Bellamaison -> Advanced Sorting -> <a href="%1">Import Csv</a> menüsünü kullanabilirsiniz.', $this->getUrl('advanced_sorting/import/csv'));
            $message .= __('<br/><br/>Yüklediğiniz dosya işlenecek, onaylıyor musunuz?');

            $this->_redirect(
                'category_products/import/csv',
                [
                    'action' => 'confirm',
                    'file_name' => $fileName,
                    'message' => base64_encode($message),
                ]
            );
        }

        if($this->getRequest()->getParam('confirm'))
        {
            $fileName = $this->getRequest()->getParam('file_name');

            $result = $this->processFile($fileName);

            $this->_redirect(
                'category_products/import/csv',
                [
                    'action' => 'success',
                    'message' => base64_encode($result['message']),
                ]
            );
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Bellamaison_CategoryProducts::importCsv');
        $resultPage->getConfig()->getTitle()->prepend(__('Import Category Products Csv (sku,category_id)'));
        return $resultPage;

    }

    /**
     * Check Authorization
     *
     * @return bool
     */
    public function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('Bellamaison_CategoryProducts::importCsv');
    }

    /**
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ? InvalidRequestException
    {
        return null;
    }

    /**
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * @return bool|string
     */
    public function uploadFile(): bool|string
    {
        try{
            $file = $this->getRequest()->getFiles(self::InputFileName);

            if ($file)
            {
                $target = $this->mediaDirectory->getAbsolutePath(self::UploadFolder);
                @unlink($target.DIRECTORY_SEPARATOR.self::UploadFile);

                $uploader = $this->fileUploader->create(['fileId' => self::InputFileName]);
                $uploader->setAllowedExtensions(['csv']);
                $uploader->setAllowCreateFolders(true);
                $uploader->setAllowRenameFiles(true);

                $result = $uploader->save($target);

                if ($result['file']) {
                    $this->messageManager->addSuccessMessage(__('File has been successfully uploaded.'));
                }

                return $uploader->getUploadedFileName();
            }
        } catch (Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return false;
    }

    /**
     * @param string $fileName
     * @return array
     * @throws Exception
     */
    private function processFile(string $fileName): array
    {
        $oldFileName = $this->mediaDirectory->getAbsolutePath(self::UploadFolder).DIRECTORY_SEPARATOR.$fileName;
        $newFileName = $this->mediaDirectory->getAbsolutePath(self::UploadFolder).DIRECTORY_SEPARATOR.self::UploadFile;

        if($oldFileName != $newFileName)
        {
            @rename($oldFileName, $newFileName);
        }

        return $this->categoryProductsUpdater->execute();
    }

}
