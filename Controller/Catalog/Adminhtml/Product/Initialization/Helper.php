<?php
/**
 * @author Alan Barber <alan@cadence-labs.com>
 */
namespace Cadence\ScopeFix\Controller\Catalog\Adminhtml\Product\Initialization;

use Magento\Catalog\Api\Data\ProductCustomOptionInterfaceFactory as CustomOptionFactory;
use Magento\Catalog\Api\Data\ProductLinkInterfaceFactory as ProductLinkFactory;
use Magento\Catalog\Api\ProductRepositoryInterface\Proxy as ProductRepository;
use Magento\Catalog\Controller\Adminhtml\Product\Initialization\StockDataFilter;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Initialization\Helper\ProductLinks;
use Magento\Catalog\Model\Product\Link\Resolver as LinkResolver;
use Magento\Framework\App\ObjectManager;

class Helper extends \Magento\Catalog\Controller\Adminhtml\Product\Initialization\Helper
{
    /**
     * @var \Magento\Framework\Registry
     */
    protected $registry;

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $resource;

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    protected $request;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var StockDataFilter
     */
    protected $stockFilter;

    /**
     * @var \Magento\Backend\Helper\Js
     */
    protected $jsHelper;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\Filter\Date
     *
     * @deprecated
     */
    protected $dateFilter;

    /**
     * @var CustomOptionFactory
     */
    protected $customOptionFactory;

    /**
     * @var ProductLinkFactory
     */
    protected $productLinkFactory;

    /**
     * @var ProductRepository
     */
    protected $productRepository;

    /**
     * @var ProductLinks
     */
    protected $productLinks;

    /**
     * @var LinkResolver
     */
    private $linkResolver;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\Filter\DateTime
     */
    private $dateTimeFilter;

    /**
     * @var \Magento\Catalog\Model\Product\LinkTypeProvider
     */
    private $linkTypeProvider;

    /**
     * Helper constructor.
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param StockDataFilter $stockFilter
     * @param ProductLinks $productLinks
     * @param \Magento\Backend\Helper\Js $jsHelper
     * @param \Magento\Framework\Stdlib\DateTime\Filter\Date $dateFilter
     * @param \Magento\Catalog\Model\Product\LinkTypeProvider $linkTypeProvider
     */
    public function __construct(
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        ProductRepository $productRepository,
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        StockDataFilter $stockFilter,
        \Magento\Catalog\Model\Product\Initialization\Helper\ProductLinks $productLinks,
        \Magento\Backend\Helper\Js $jsHelper,
        \Magento\Framework\Stdlib\DateTime\Filter\Date $dateFilter,
        \Magento\Catalog\Model\Product\LinkTypeProvider $linkTypeProvider = null
    ) {
        $this->registry = $registry;
        $this->resource = $resourceConnection;
        $this->productRepository = $productRepository;
        $this->request = $request;
        $this->storeManager = $storeManager;
        $this->stockFilter = $stockFilter;
        $this->productLinks = $productLinks;
        $this->jsHelper = $jsHelper;
        $this->dateFilter = $dateFilter;
        $this->linkTypeProvider = $linkTypeProvider ?: \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\Magento\Catalog\Model\Product\LinkTypeProvider::class);
    }

    /**
     * Initialize product from data
     *
     * @param Product $product
     * @param array $productData
     * @return Product
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function initializeFromData(Product $product, array $productData)
    {
        unset($productData['custom_attributes']);
        unset($productData['extension_attributes']);

        if ($productData) {
            $stockData = isset($productData['stock_data']) ? $productData['stock_data'] : [];
            $productData['stock_data'] = $this->stockFilter->filter($stockData);
        }

        $productData = $this->normalize($productData);

        if (!empty($productData['is_downloadable'])) {
            $productData['product_has_weight'] = 0;
        }

        foreach (['category_ids', 'website_ids'] as $field) {
            if (!isset($productData[$field])) {
                $productData[$field] = [];
            }
        }

        foreach ($productData['website_ids'] as $websiteId => $checkboxValue) {
            if (!$checkboxValue) {
                unset($productData['website_ids'][$websiteId]);
            }
        }

        $wasLockedMedia = false;
        if ($product->isLockedAttribute('media')) {
            $product->unlockAttribute('media');
            $wasLockedMedia = true;
        }

        $dateFieldFilters = [];
        $attributes = $product->getAttributes();
        foreach ($attributes as $attrKey => $attribute) {
            if ($attribute->getBackend()->getType() == 'datetime') {
                if (array_key_exists($attrKey, $productData) && $productData[$attrKey] != '') {
                    $dateFieldFilters[$attrKey] = $this->getDateTimeFilter();
                }
            }
        }

        $inputFilter = new \Zend_Filter_Input($dateFieldFilters, [], $productData);
        $productData = $inputFilter->getUnescaped();

        if (isset($productData['options'])) {
            $productOptions = $productData['options'];
            unset($productData['options']);
        } else {
            $productOptions = [];
        }

        $product->addData($productData);

        if ($wasLockedMedia) {
            $product->lockAttribute('media');
        }

        /**
         * Check "Use Default Value" checkboxes values
         */
        $useDefaults = (array)$this->request->getPost('use_default', []);
        foreach ($useDefaults as $attributeCode => $useDefaultState) {
            if ($useDefaultState) {
                $product->setData($attributeCode, null);
                // UI component sends value even if field is disabled, so 'Use Config Settings' must be reset to false
                if ($product->hasData('use_config_' . $attributeCode)) {
                    $product->setData('use_config_' . $attributeCode, false);
                }
            }
        }

        $currentStoreId = $_POST['product']['current_store_id'] ?? 0;

        if (intval($currentStoreId) != 0 && $product->getId()) {

            $rootProduct = $this->productRepository->getById($product->getId());
            $resource = $this->resource;
            $connection = $resource->getConnection();

            foreach ($productData as $productKey => $productValue) {

                $attribute = $product->getResource()->getAttribute($productKey);

                if (!$attribute) {
                    // Not an EAV attribute
                    continue;
                }

                if (isset($useDefaults[$productKey])) {
                    continue;
                }

                $forceDefault = false;

                if ($attribute->getBackendType() != 'static' && $attribute->getScope() != \Magento\Catalog\Api\Data\EavAttributeInterface::SCOPE_GLOBAL_TEXT && $rootProduct->getData($productKey) == $productValue) {
                    $tableName = $attribute->getBackendTable();

                    $sql = "SELECT COUNT(*) FROM {$tableName} WHERE entity_id = :entity_id AND attribute_id = :attribute_id and store_id = :store_id";

                    $existingStoreLevelValue = intval($connection->fetchOne($sql, [
                        'attribute_id' => $attribute->getId(),
                        'entity_id' => $product->getId(),
                        'store_id' => $currentStoreId
                    ])) ? true : false;

                    if (!$existingStoreLevelValue) {
                        $forceDefault = true;
                    }

                }

                if ($forceDefault) {
                    if ($productKey == 'url_key') {
                        $this->registry->register('cadence_force_url_default', true, true);
                    }
                    /*
                     * Force the use of the default if:
                     * (1) Empty string and use_default wasn't sent to the server (bug since the checkboxes don't render if the tab isn't open)
                     * (2) Identical value to parent and there isn't an existing store-level override (bug since the checkboxes don't render if the tab isn't open)
                     */
                    $product->setData($productKey, null);
                    // UI component sends value even if field is disabled, so 'Use Config Settings' must be reset to false
                    if ($product->hasData('use_config_' . $productKey)) {
                        $product->setData('use_config_' . $productKey, false);
                    }
                }

            }
        }

        $product = $this->setProductLinks($product);
        $product = $this->fillProductOptions($product, $productOptions);

        $product->setCanSaveCustomOptions(
            !empty($productData['affect_product_custom_options']) && !$product->getOptionsReadonly()
        );

        return $product;
    }

    /**
     * Initialize product before saving
     *
     * @param Product $product
     * @return Product
     */
    public function initialize(Product $product)
    {
        $productData = $this->request->getPost('product', []);
        return $this->initializeFromData($product, $productData);
    }

    /**
     * Setting product links
     *
     * @param Product $product
     * @return Product
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function setProductLinks(Product $product)
    {
        $links = $this->getLinkResolver()->getLinks();

        $product->setProductLinks([]);

        $product = $this->productLinks->initializeLinks($product, $links);
        $productLinks = $product->getProductLinks();
        $linkTypes = [];

        /** @var \Magento\Catalog\Api\Data\ProductLinkTypeInterface $linkTypeObject */
        foreach ($this->linkTypeProvider->getItems() as $linkTypeObject) {
            $linkTypes[$linkTypeObject->getName()] = $product->getData($linkTypeObject->getName() . '_readonly');
        }

        // skip linkTypes that were already processed on initializeLinks plugins
        foreach ($productLinks as $productLink) {
            unset($linkTypes[$productLink->getLinkType()]);
        }

        foreach ($linkTypes as $linkType => $readonly) {
            if (isset($links[$linkType]) && !$readonly) {
                foreach ((array) $links[$linkType] as $linkData) {
                    if (empty($linkData['id'])) {
                        continue;
                    }

                    $linkProduct = $this->getProductRepository()->getById($linkData['id']);
                    $link = $this->getProductLinkFactory()->create();
                    $link->setSku($product->getSku())
                        ->setLinkedProductSku($linkProduct->getSku())
                        ->setLinkType($linkType)
                        ->setPosition(isset($linkData['position']) ? (int)$linkData['position'] : 0);
                    $productLinks[] = $link;
                }
            }
        }

        return $product->setProductLinks($productLinks);
    }

    /**
     * Internal normalization
     * TODO: Remove this method
     *
     * @param array $productData
     * @return array
     */
    protected function normalize(array $productData)
    {
        foreach ($productData as $key => $value) {
            if (is_scalar($value)) {
                if ($value === 'true') {
                    $productData[$key] = '1';
                } elseif ($value === 'false') {
                    $productData[$key] = '0';
                }
            } elseif (is_array($value)) {
                $productData[$key] = $this->normalize($value);
            }
        }

        return $productData;
    }

    /**
     * Merge product and default options for product
     *
     * @param array $productOptions product options
     * @param array $overwriteOptions default value options
     * @return array
     */
    public function mergeProductOptions($productOptions, $overwriteOptions)
    {
        if (!is_array($productOptions)) {
            return [];
        }

        if (!is_array($overwriteOptions)) {
            return $productOptions;
        }

        foreach ($productOptions as $optionIndex => $option) {
            $optionId = $option['option_id'];
            $option = $this->overwriteValue(
                $optionId,
                $option,
                $overwriteOptions
            );

            if (isset($option['values']) && isset($overwriteOptions[$optionId]['values'])) {
                foreach ($option['values'] as $valueIndex => $value) {
                    if (isset($value['option_type_id'])) {
                        $valueId = $value['option_type_id'];
                        $value = $this->overwriteValue(
                            $valueId,
                            $value,
                            $overwriteOptions[$optionId]['values']
                        );

                        $option['values'][$valueIndex] = $value;
                    }
                }
            }

            $productOptions[$optionIndex] = $option;
        }

        return $productOptions;
    }

    /**
     * Overwrite values of fields to default, if there are option id and field name in array overwriteOptions.
     *
     * @param int   $optionId
     * @param array $option
     * @param array $overwriteOptions
     *
     * @return array
     */
    private function overwriteValue($optionId, $option, $overwriteOptions)
    {
        if (isset($overwriteOptions[$optionId])) {
            foreach ($overwriteOptions[$optionId] as $fieldName => $overwrite) {
                if ($overwrite && isset($option[$fieldName]) && isset($option['default_' . $fieldName])) {
                    $option[$fieldName] = $option['default_' . $fieldName];
                    if ('title' == $fieldName) {
                        $option['is_delete_store_title'] = 1;
                    }
                }
            }
        }

        return $option;
    }

    /**
     * @return CustomOptionFactory
     */
    private function getCustomOptionFactory()
    {
        if (null === $this->customOptionFactory) {
            $this->customOptionFactory = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Catalog\Api\Data\ProductCustomOptionInterfaceFactory::class);
        }

        return $this->customOptionFactory;
    }

    /**
     * @return ProductLinkFactory
     */
    private function getProductLinkFactory()
    {
        if (null === $this->productLinkFactory) {
            $this->productLinkFactory = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Catalog\Api\Data\ProductLinkInterfaceFactory::class);
        }

        return $this->productLinkFactory;
    }

    /**
     * @return ProductRepository
     */
    private function getProductRepository()
    {
        if (null === $this->productRepository) {
            $this->productRepository = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Catalog\Api\ProductRepositoryInterface\Proxy::class);
        }

        return $this->productRepository;
    }

    /**
     * @deprecated
     * @return LinkResolver
     */
    private function getLinkResolver()
    {
        if (!is_object($this->linkResolver)) {
            $this->linkResolver = ObjectManager::getInstance()->get(LinkResolver::class);
        }

        return $this->linkResolver;
    }

    /**
     * @return \Magento\Framework\Stdlib\DateTime\Filter\DateTime
     *
     * @deprecated
     */
    private function getDateTimeFilter()
    {
        if ($this->dateTimeFilter === null) {
            $this->dateTimeFilter = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Framework\Stdlib\DateTime\Filter\DateTime::class);
        }

        return $this->dateTimeFilter;
    }

    /**
     * Fills $product with options from $productOptions array
     *
     * @param Product $product
     * @param array $productOptions
     * @return Product
     */
    private function fillProductOptions(Product $product, array $productOptions)
    {
        if ($product->getOptionsReadonly()) {
            return $product;
        }
        if (empty($productOptions)) {
            return $product->setOptions([]);
        }
        // mark custom options that should to fall back to default value
        $options = $this->mergeProductOptions(
            $productOptions,
            $this->request->getPost('options_use_default')
        );
        $customOptions = [];
        foreach ($options as $customOptionData) {
            if (!empty($customOptionData['is_delete'])) {
                continue;
            }

            if (empty($customOptionData['option_id'])) {
                $customOptionData['option_id'] = null;
            }
            if (isset($customOptionData['values'])) {
                $customOptionData['values'] = array_filter(
                    $customOptionData['values'],
                    function ($valueData) {
                        return empty($valueData['is_delete']);
                    }
                );
            }
            $customOption = $this->getCustomOptionFactory()->create(
                ['data' => $customOptionData]
            );
            $customOption->setProductSku($product->getSku());
            $customOptions[] = $customOption;
        }

        return $product->setOptions($customOptions);
    }
}
