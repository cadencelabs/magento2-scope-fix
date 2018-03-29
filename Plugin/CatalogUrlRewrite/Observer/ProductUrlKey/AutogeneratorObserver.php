<?php
/**
 * @author Alan Barber <alan@cadence-labs.com>
 */
namespace Cadence\ScopeFix\Plugin\CatalogUrlRewrite\Observer\ProductUrlKey;

use Magento\CatalogUrlRewrite\Observer\ProductUrlKeyAutogeneratorObserver as Subject;

class AutogeneratorObserver
{
    /**
     * @var \Magento\Framework\Registry
     */
    protected $registry;

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    protected $request;

    /**
     * Helper constructor.
     * @param \Magento\Framework\App\RequestInterface $request
     */
    public function __construct(
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\RequestInterface $request
    ) {
        $this->registry = $registry;
        $this->request = $request;
    }

    /**
     * Ensure the `use_default` checkbox can be used with URL Keys
     * @param Subject $subject
     * @param callable $proceed
     * @param \Magento\Framework\Event\Observer $observer
     */
    public function aroundExecute(Subject $subject, callable $proceed, \Magento\Framework\Event\Observer $observer)
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $product = $observer->getEvent()->getProduct();

        /**
         * Check "Use Default Value" checkboxes values
         */
        $useDefaults = (array)$this->request->getPost('use_default', []);

        if ($this->registry->registry('cadence_force_url_default')
            || (isset($useDefaults['url_key']) && $useDefaults['url_key'])) {
            $product->setData('url_key', null);
            return;
        }

        $proceed($observer);
    }
}
