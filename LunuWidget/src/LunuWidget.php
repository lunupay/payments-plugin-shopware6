<?php declare(strict_types=1);

namespace Lunu\Widget;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Lunu\Widget\Service\LunuPayment;

/**
 * Lunu Widget Plugin for Shopware 6
 * 
 * Integrates Lunu cryptocurrency payment gateway into Shopware 6.
 * Provides a payment method for accepting cryptocurrency payments.
 * 
 * @package Lunu\Widget
 */
class LunuWidget extends Plugin
{
    /**
     * Installs the plugin and registers the Lunu payment method
     * 
     * @param InstallContext $context
     * @return void
     */
    public function install(InstallContext $context): void
    {
        $this->addPaymentMethod($context->getContext());
    }

    /**
     * Uninstalls the plugin and deactivates the payment method
     * 
     * Note: The payment method is only deactivated, not removed, to prevent
     * data consistency issues with existing orders.
     * 
     * @param UninstallContext $context
     * @return void
     */
    public function uninstall(UninstallContext $context): void
    {
        // Only set the payment method to inactive when uninstalling. Removing the payment method would
        // cause data consistency issues, since the payment method might have been used in several orders
        $this->setPaymentMethodIsActive(false, $context->getContext());
    }

    /**
     * Activates the plugin and enables the payment method
     * 
     * @param ActivateContext $context
     * @return void
     */
    public function activate(ActivateContext $context): void
    {
        $this->setPaymentMethodIsActive(true, $context->getContext());
        parent::activate($context);
    }

    /**
     * Deactivates the plugin and disables the payment method
     * 
     * @param DeactivateContext $context
     * @return void
     */
    public function deactivate(DeactivateContext $context): void
    {
        $this->setPaymentMethodIsActive(false, $context->getContext());
        parent::deactivate($context);
    }

    /**
     * Adds the Lunu payment method to the system
     * 
     * @param Context $context
     * @return void
     */
    private function addPaymentMethod(Context $context): void
    {
        $paymentMethodExists = $this->getPaymentMethodId();

        // Payment method exists already, no need to continue here
        if ($paymentMethodExists) {
            return;
        }

        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        $pluginId = $pluginIdProvider->getPluginIdByBaseClass(get_class($this), $context);

        $lunuPaymentData = [
            // payment handler will be selected by the identifier
            'handlerIdentifier' => LunuPayment::class,
            'name' => 'Lunu Widget',
            'description' => 'Pay easily with a cryptocurrency of your choice.',
            'pluginId' => $pluginId,
            'afterOrderEnabled' => true,
        ];

        /** @var EntityRepository $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
        $paymentRepository->create([$lunuPaymentData], $context);
    }

    /**
     * Sets the active status of the Lunu payment method
     * 
     * @param bool $active Whether to activate or deactivate the payment method
     * @param Context $context
     * @return void
     */
    private function setPaymentMethodIsActive(bool $active, Context $context): void
    {
        /** @var EntityRepository $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');

        $paymentMethodId = $this->getPaymentMethodId();

        // Payment does not even exist, so nothing to (de-)activate here
        if (!$paymentMethodId) {
            return;
        }

        $paymentMethod = [
            'id' => $paymentMethodId,
            'active' => $active,
        ];

        $paymentRepository->update([$paymentMethod], $context);
    }

    /**
     * Retrieves the ID of the Lunu payment method if it exists
     * 
     * @return string|null The payment method ID or null if not found
     */
    private function getPaymentMethodId(): ?string
    {
        /** @var EntityRepository $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');

        // Fetch ID for update
        $paymentCriteria = (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', LunuPayment::class));
        return $paymentRepository->searchIds($paymentCriteria, Context::createDefaultContext())->firstId();
    }
}
