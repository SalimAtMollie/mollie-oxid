<?php

namespace Mollie\Payment\extend\Application\Model;

use Mollie\Api\Exceptions\ApiException;
use OxidEsales\Eshop\Application\Model\Order as CoreOrder;
use OxidEsales\Eshop\Core\Registry;
use Mollie\Payment\Application\Helper\Payment as PaymentHelper;

class PaymentGateway extends PaymentGateway_parent
{
    /**
     * OXID URL parameters to copy from initial order execute request
     *
     * @var array
     */
    protected $aMollieUrlCopyParameters = [
        'stoken',
        'sDeliveryAddressMD5',
        'oxdownloadableproductsagreement',
        'oxserviceproductsagreement',
        'ord_agb',
    ];

    /**
     * Overrides standard oxid finalizeOrder method if the used payment method belongs to PAYONE.
     * Return parent's return if payment method is no PAYONE method
     *
     * Executes payment, returns true on success.
     *
     * @param double $dAmount Goods amount
     * @param object $oOrder  User ordering object
     *
     * @extend executePayment
     * @return bool
     */
    public function executePayment($dAmount, &$oOrder)
    {
        if(!PaymentHelper::getInstance()->isMolliePaymentMethod($oOrder->oxorder__oxpaymenttype->value)) {
            return parent::executePayment($dAmount, $oOrder);
        }

        $this->handleMolliePayment($oOrder, $dAmount);

        return false; // return false for for trying faster
    }

    /**
     * Collect parameters from the current order execute call and add them to the return URL
     * Also add parameters needed for the return process
     *
     * @return string
     */
    protected function mollieGetAdditionalParameters()
    {
        $oRequest = Registry::getRequest();
        $oSession = Registry::getSession();

        $sAddParams = '';

        foreach ($this->aMollieUrlCopyParameters as $sParamName) {
            $sValue = $oRequest->getRequestEscapedParameter($sParamName);
            if (!empty($sValue)) {
                $sAddParams .= '&'.$sParamName.'='.$sValue;
            }
        }

        $sSid = $oSession->sid(true);
        if ($sSid != '') {
            $sAddParams .= '&'.$sSid;
        }

        $sAddParams .= '&rtoken='.$oSession->getRemoteAccessToken();

        return $sAddParams;
    }

    /**
     * Generate a return url with all necessary return flags
     *
     * @return string
     */
    protected function getRedirectUrl()
    {
        $sBaseUrl = Registry::getConfig()->getCurrentShopUrl().'index.php?cl=order&fnc=handleMollieReturn';

        return $sBaseUrl.$this->mollieGetAdditionalParameters();
    }

    /**
     * Execute Mollie API request and redirect to Mollie for payment
     *
     * @param CoreOrder $oOrder
     * @param double $dAmount
     * @return bool
     */
    protected function handleMolliePayment(CoreOrder &$oOrder, $dAmount)
    {
        $oOrder->mollieSetOrderNumber();
        
        try {
            $sApiMethod = $oOrder->mollieGetPaymentModel()->getApiMethod();
            if ($sApiMethod == 'payment') {
                $oApiRequest = oxNew(\Mollie\Payment\Application\Model\Request\Payment::class);
            } else {
                $oApiRequest = oxNew(\Mollie\Payment\Application\Model\Request\Order::class);
            }

            $oResponse = $oApiRequest->sendRequest($oOrder, $dAmount, $this->getRedirectUrl());

            $sPaymentUrl = $oResponse->getCheckoutUrl();

            $oOrder->mollieSetTransactionId($oResponse->id);

            if (!empty($sPaymentUrl)) {
                Registry::getUtils()->redirect($sPaymentUrl);
            }
        } catch(ApiException $exc) {
            $this->_iLastErrorNo = $exc->getCode();
            $this->_sLastError = $exc->getMessage();

            $sApiMethod = $oOrder->mollieGetPaymentModel()->getApiMethod();
            if($oApiRequest) {
                $oApiRequest->logExceptionResponse(
                    $exc->getCode(),
                    $exc->getMessage(),
                    !empty($sApiMethod) ? $sApiMethod : ''
                );
            }
        }

        return true;
    }
}
