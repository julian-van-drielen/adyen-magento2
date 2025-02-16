<?php
/**
 *
 * Adyen Payment Module
 *
 * Copyright (c) 2021 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Model\Api;

use Adyen\Payment\Api\AdyenDonationsInterface;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Model\Ui\AdyenCcConfigProvider;
use Adyen\Payment\Model\Ui\AdyenHppConfigProvider;
use Adyen\Util\Uuid;
use Magento\Checkout\Model\Session;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Payment\Gateway\Command\CommandException;
use Magento\Payment\Gateway\Command\CommandPoolInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;


class AdyenDonations implements AdyenDonationsInterface
{
    /**
     * @var CommandPoolInterface
     */
    private $commandPool;

    /**
     * @var Session
     */
    private $checkoutSession;

    /**
     * @var OrderFactory
     */
    private $orderFactory;

    /**
     * @var Json
     */
    private $jsonSerializer;

    /**
     * @var
     */
    private $donationResult;

    /**
     * @var
     */
    private $donationTryCount;

    /**
     * @var Data
     */
    protected $dataHelper;

    public function __construct(
        CommandPoolInterface $commandPool,
        OrderFactory $orderFactory,
        Session $checkoutSession,
        Json $jsonSerializer,
        Data $dataHelper
    ) {
        $this->commandPool = $commandPool;
        $this->orderFactory = $orderFactory;
        $this->checkoutSession = $checkoutSession;
        $this->jsonSerializer = $jsonSerializer;
        $this->dataHelper = $dataHelper;
    }

    /**
     * @inheritDoc
     *
     * @throws CommandException|NotFoundException|LocalizedException|\InvalidArgumentException
     */
    public function donate($payload)
    {
        $payload = $this->jsonSerializer->unserialize($payload);
        /** @var Order */
        $order = $this->orderFactory->create()->load($this->checkoutSession->getLastOrderId());
        $donationToken = $order->getPayment()->getAdditionalInformation('donationToken');

        if (!$donationToken) {
            throw new LocalizedException(__('Donation failed!'));
        }

        $payload['donationToken'] = $donationToken;
        $payload['donationOriginalPspReference'] = $order->getPayment()->getAdditionalInformation('pspReference');

        if ($order->getPayment()->getMethod() === AdyenCcConfigProvider::CODE) {
            $payload['paymentMethod'] = 'scheme';
        } elseif ($order->getPayment()->getMethod() === AdyenHppConfigProvider::CODE) {
            $payload['paymentMethod'] = $order->getPayment()->getAdditionalInformation('brand_code');
        } else {
            throw new LocalizedException(__('Donation failed!'));
        }

        $customerId = $order->getCustomerId();
        if ($customerId) {
            $payload['shopperReference'] = $this->dataHelper->padShopperReference($customerId);
        } else {
            $guestCustomerId = $order->getIncrementId() . Uuid::generateV4();
            $payload['shopperReference'] = $guestCustomerId;
        }

        try {
            $donationsCaptureCommand = $this->commandPool->get('capture');
            $this->donationResult = $donationsCaptureCommand->execute(['payment' => $payload]);

            // Remove donation token after a successfull donation.
            $this->removeDonationToken($order);
        }
        catch (LocalizedException $e) {
            $this->donationTryCount = $order->getPayment()->getAdditionalInformation('donationTryCount');

            if ($this->donationTryCount >= 5) {
                // Remove donation token after 5 try and throw a exception.
                $this->removeDonationToken($order);
            }

            $this->incrementTryCount($order);
            throw new LocalizedException(__('Donation failed!'));
        }

        return $this->donationResult;
    }

    /**
     * @param $order
     */
    private function incrementTryCount($order)
    {
        if (!$this->donationTryCount) {
            $order->getPayment()->setAdditionalInformation('donationTryCount', 1);
        }
        else {
            $this->donationTryCount += 1;
            $order->getPayment()->setAdditionalInformation('donationTryCount', $this->donationTryCount);
        }

        $order->save();
    }

    /**
     * @param $order
     */
    private function removeDonationToken($order)
    {
        $order->getPayment()->unsAdditionalInformation('donationToken');
        $order->save();
    }
}
