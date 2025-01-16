<?php

namespace Bellamaison\CategoryProducts\Block\Adminhtml\Import;

use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class Csv extends Template
{
    private FormKey $formKey;

    /**
     * @param FormKey $formKey
     * @param Context $context
     * @param array $data
     */
    public function __construct(
        FormKey $formKey,
        Context $context,
        array   $data = []
    ) {
        parent::__construct($context, $data);

        $this->formKey = $formKey;
    }

    /**
     * @return string
     */
    public function getPostUrl(): string
    {
        return $this->_urlBuilder->getUrl('category_products/import/csv');
    }

    /**
     * @throws LocalizedException
     */
    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    /**
     * @return bool|string|null
     */
    public function getMessage(): bool|string|null
    {
        $message = $this->getRequest()->getParam('message');
        if($message)
        {
            return base64_decode($message);
        }

        return null;
    }

    /**
     * @return string|null
     */
    public function getAction(): ?string
    {
        return $this->getRequest()->getParam('action');
    }

    /**
     * @return string
     */
    public function getConfirmUrl(): string
    {
        return $this->_urlBuilder->getUrl(
            'category_products/import/csv',
            [
                'confirm' => true,
                'file_name' => $this->getRequest()->getParam('file_name')
            ]
        );
    }

    /**
     * @return string
     */
    public function getCancelUrl(): string
    {
        return $this->_urlBuilder->getUrl('category_products/import/csv');
    }
}
