<?php

/**
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade the MultiSafepay plugin
 * to newer versions in the future. If you wish to customize the plugin for your
 * needs please document your changes and make backups before you update.
 *
 * @category    MultiSafepay
 * @package     Connect
 * @author      MultiSafepay <techsupport@multisafepay.com>
 * @copyright   Copyright (c) 2019 MultiSafepay, Inc. (https://www.multisafepay.com)
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED,
 * INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR
 * PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
 * HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN
 * ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
 * WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

use MltisafeMultiSafepayPayment\Components\API\MspClient;
use MltisafeMultiSafepayPayment\Components\Gateways;
use MltisafeMultiSafepayPayment\Components\Helper;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Shopware\Components\CSRFWhitelistAware;
use Shopware\Components\OptinServiceInterface;
use Shopware\Models\Order\Order;
use Shopware\Models\Order\Status;
use Shopware\Models\Payment\Payment;

class Shopware_Controllers_Frontend_MultiSafepayPayment extends Shopware_Controllers_Frontend_Payment implements CSRFWhitelistAware
{
    const MAX_LOG_FILES = 7;
    private $shopwareConfig;
    private $pluginConfig;
    private $quoteNumber;
    private $shop;
    private $logger;

    /**
     * {@inheritdoc}
     */
    public function preDispatch()
    {
        $this->shop = $this->get('shop');
        $this->shopwareConfig = $this->get('config');
        $this->pluginConfig = $this->get('shopware.plugin.cached_config_reader')->getByPluginName('MltisafeMultiSafepayPayment', $this->shop);
        $this->quoteNumber = $this->get('multi_safepay_payment.components.quotenumber');

        $this->logger = new Logger('multisafepay');
        $rotatingFileHandler = new RotatingFileHandler(
            $this->get('kernel')->getLogDir() . '/multisafepay.log',
            self::MAX_LOG_FILES,
            $this->pluginConfig['multisafepay_debug_mode'] ? Logger::DEBUG : Logger::ERROR
        );

        $this->logger->pushHandler($rotatingFileHandler);
    }

    /**
     * {@inheritdoc}
     */
    public function getWhitelistedCSRFActions()
    {
        return [
            'index',
            'gateway',
            'notify',
            'return',
            'cancel',
        ];
    }

    /**
     * Index action method.
     *
     * Forwards to the correct action.
     *
     * @return void
     */
    public function indexAction()
    {
        if (preg_match('/multisafepay_(.+)/', $this->getPaymentShortName(), $matches)) {
            return $this->redirect(array('action' => 'gateway', 'payment' => $matches[1], 'forceSecure' => true));
        } else {
            return $this->redirect(['controller' => 'checkout']);
        }
    }

    /**
     * Gateway action method.
     *
     * Collects the payment information and transmit it to MultiSafepay.
     *
     * @return void
     */
    public function gatewayAction()
    {
        $router = $this->Front()->Router();
        $userinfo = $this->getUser();
        $basket = $this->getBasket();
        $hash = $this->createHashFromSession();

        $msp = new MspClient();
        $msp->setApiKey($this->pluginConfig['msp_api_key']);
        if (!$this->pluginConfig['msp_environment']) {
            $msp->setApiUrl('https://testapi.multisafepay.com/v1/json/');
        } else {
            $msp->setApiUrl('https://api.multisafepay.com/v1/json/');
        }

        $checkoutData = $this->getCheckoutData($basket, $userinfo['additional']['charge_vat']);
        $shoppingCart = $checkoutData["shopping_cart"];
        $checkoutData = $checkoutData["checkout_options"];

        list($street, $housenumber) = Helper::parseAddress($userinfo["billingaddress"]["street"], $userinfo["billingaddress"]["additionalAddressLine1"]);
        list($shipping_street, $shipping_housenumber) = Helper::parseAddress($userinfo["shippingaddress"]["street"], $userinfo["shippingaddress"]["additionalAddressLine1"]);

        $billing_data = array(
            "locale" => Shopware()->Container()->get('shop')->getLocale()->getLocale(),
            "ip_address" => Helper::getRemoteIP(),
            "forwarded_ip" => Helper::getForwardedIP(),
            "first_name" => $userinfo["billingaddress"]["firstname"],
            "last_name" => $userinfo["billingaddress"]["lastname"],
            "address1" => $street,
            "address2" => $userinfo["billingaddress"]["additionalAddressLine1"],
            "house_number" => $housenumber,
            "zip_code" => $userinfo["billingaddress"]["zipcode"],
            "city" => $userinfo["billingaddress"]["city"],
            "state" => $userinfo["billingaddress"]["state"],
            "country" => $userinfo["additional"]["country"]["countryiso"],
            "phone" => $userinfo["billingaddress"]["phone"],
            "email" => $userinfo["additional"]["user"]["email"],
        );

        $delivery_data = array(
            "first_name" => $userinfo["shippingaddress"]["firstname"],
            "last_name" => $userinfo["shippingaddress"]["lastname"],
            "address1" => $shipping_street,
            "address2" => $userinfo["shippingaddress"]["additionalAddressLine1"],
            "house_number" => $shipping_housenumber,
            "zip_code" => $userinfo["shippingaddress"]["zipcode"],
            "city" => $userinfo["shippingaddress"]["city"],
            "state" => $userinfo["shippingaddress"]["state"],
            "country" => $userinfo["additional"]["countryShipping"]["countryiso"],
            "phone" => $userinfo["shippingaddress"]["phone"],
            "email" => $userinfo["additional"]["user"]["email"],
        );

        $order_id = $this->quoteNumber->getNextQuotenumber();

        $items = "<ul>\n";
        foreach ($basket['content'] as $data) {
            $items .= "<li>" . ($data['quantity'] * 1) . " x : " . $data['articlename'] . "</li>\n";
        }
        $items .= "</ul>\n";

        $paymentOptions = [
            "notification_url" => $router->assemble(['action' => 'notify', 'forceSecure' => true, 'hash' => $hash]),
            "redirect_url" => $router->assemble(['action' => 'return', 'forceSecure' => true, 'hash' => $hash]),
            "cancel_url" => $router->assemble(['action' => 'cancel', 'forceSecure' => true, 'hash' => $hash]),
            "close_window" => "true",
        ];


        $order_data = array(
            "type" => Gateways::getGatewayType($this->Request()->payment),
            "order_id" => $order_id,
            "currency" => $this->getCurrencyShortName(),
            "amount" => round($this->getAmount() * 100),
            "description" => "Order #" . $order_id,
            "items" => $items,
            "manual" => "false",
            "var1" => $this->getSignature(),
            "gateway" => Gateways::getGatewayCode($this->Request()->payment),
            "seconds_active" => Helper::getSecondsActive($this->pluginConfig["msp_time_label"], $this->pluginConfig["msp_time_active"]),
            "payment_options" => $paymentOptions,
            "customer" => $billing_data,
            "delivery" => $delivery_data,
            "plugin" => array(
                "shop" => "Shopware" . ' ' . $this->shopwareConfig->get('version'),
                "shop_version" => $this->shopwareConfig->get('version'),
                "plugin_version" => ' - Plugin ' . Helper::getPluginVersion(),
                "partner" => "MultiSafepay",
            ),
            "gateway_info" => array(
                "issuer_id" => $this->get('session')->get('ideal_issuer'),
            ),
            "shopping_cart" => $shoppingCart,
            "checkout_options" => $checkoutData,
        );

        if ($order_data['gateway'] == 'IDEAL' && !$order_data['gateway_info']['issuer_id']) {
            $order_data['type'] = 'redirect';
        }

        try {
            $msp->orders->post($order_data);
        } catch (\Exception $e) {
            $this->redirect(['controller' => 'checkout', 'action' => 'shippingPayment', 'multisafepay_error_message' => $e->getMessage()]);
            return;
        }

        $result = $msp->orders->getResult();

        if (!$result->success) {
            $message = "There was an error processing your transaction request, please try again with another payment method.<br />";
            $message .= "Error: " . "{$result->error_code} : {$result->error_info}";
            $this->redirect([
                'controller' => 'checkout',
                'action' => 'shippingPayment',
                'multisafepay_error_message' => urlencode($message)
            ]);
            return;
        }

        $this->redirect($msp->orders->getPaymentLink());
    }

    /**
     * @throws Exception
     */
    public function notifyAction()
    {
        $this->Front()->Plugins()->ViewRenderer()->setNoRender(true);
        $transactionid = $this->Request()->getParam('transactionid');
        $hash = $this->Request()->getParam('hash');
        $this->fillMissingSessionData($hash);

        $helper = new Helper();
        $msp = new MspClient();
        $msp->setApiKey($this->pluginConfig['msp_api_key']);
        if (!$this->pluginConfig['msp_environment']) {
            $msp->setApiUrl('https://testapi.multisafepay.com/v1/json/');
        } else {
            $msp->setApiUrl('https://api.multisafepay.com/v1/json/');
        }

        $msporder = $msp->orders->get($endpoint = 'orders', $transactionid);
        $status = $msporder->status;
        $signature = $msporder->var1;

        $order = Shopware()->Models()->getRepository('Shopware\Models\Order\Order')->findOneBy(['transactionId' => $transactionid]);

        $create_order = false;
        $update_order = false;
        switch ($status) {
            case "initialized":
                $create_order = false;
                $update_order = false;
                break;
            case "expired":
                $create_order = false;
                $update_order = true;
                $payment_status = $helper->getPaymentStatus('expired', $this->shop);
                break;
            case "cancelled":
            case "void":
                $create_order = false;
                $update_order = true;
                $payment_status = $helper->getPaymentStatus('cancelled', $this->shop);
                break;
            case "chargedback":
                $update_order = true;
                $payment_status = $helper->getPaymentStatus('chargedback', $this->shop);
                break;
            case "completed":
                if (is_null($order)) {
                    $create_order = true;
                } elseif (Helper::orderHasClearedDate($order) === false) {
                    $update_order = true;
                }
                $payment_status = $helper->getPaymentStatus('completed', $this->shop);
                break;
            case "uncleared":
                $create_order = true;
                $update_order = false;
                $payment_status = $helper->getPaymentStatus('uncleared', $this->shop);
                break;
            case "declined":
                $create_order = false;
                $update_order = true;
                $payment_status = $helper->getPaymentStatus('declined', $this->shop);
                break;
            case "refunded":
                if ($this->pluginConfig['msp_update_refund_active'] &&
                    is_int($this->pluginConfig['msp_update_refund']) &&
                    $this->pluginConfig['msp_update_refund'] > 0
                ) {
                    $payment_status = $helper->getPaymentStatus('refund', $this->shop);
                    $update_order = true;
                }
                break;
        }

        if ($create_order) {
            $basket = $this->getBasketBasedOnSignature($signature);
            if ($basket) {
                $this->saveOrder($transactionid, $transactionid, null, true);
                $this->savePaymentStatus($transactionid, $transactionid, $payment_status, $helper->isAllowedToSendStatusMail($status, $this->shop));
            } elseif (!Helper::isValidOrder($order)) {
                $this->saveOrder($transactionid, $transactionid, Status::PAYMENT_STATE_REVIEW_NECESSARY, true);
            }
        }

        if ($update_order && Helper::isOrderAllowedToChangePaymentStatus($order)) {
            $this->savePaymentStatus($transactionid, $transactionid, $payment_status, $helper->isAllowedToSendStatusMail($status, $this->shop));
        }

        if ($status === 'completed' && !Helper::orderHasClearedDate($order)) {
            $this->setClearedDate($transactionid);
        }

        if (Helper::isValidOrder($order)) {
            $this->changePaymentMethod($order, $msporder->payment_details->type);
        }

        $this->Response()
            ->setBody('OK')
            ->setHttpResponseCode(200);
    }

    /**
     * Return action method
     */
    public function returnAction()
    {
        $request = $this->Request();
        $transactionId = $request->getParam('transactionid');

        $hash = $request->getParam('hash');
        $this->fillMissingSessionData($hash);

        // Setup the request
        $msp = new MspClient();
        $msp->setApiKey($this->pluginConfig['msp_api_key']);
        if (!$this->pluginConfig['msp_environment']) {
            $msp->setApiUrl('https://testapi.multisafepay.com/v1/json/');
        } else {
            $msp->setApiUrl('https://api.multisafepay.com/v1/json/');
        }

        $mspOrder = $msp->orders->get($endpoint = 'orders', $transactionId);

        $signature = $mspOrder->var1;

        for ($i = 0; $i <= 5; $i++) {
            $orderExist = false;
            $order = Shopware()->Models()
                ->getRepository('Shopware\Models\Order\Order')
                ->findOneBy(['transactionId' => $transactionId]);

            if (Helper::isValidOrder($order)) {
                $orderExist = true;
                break;
            }
            sleep(1);
        }

        if ($orderExist) {
            $this->redirect(['controller' => 'checkout', 'action' => 'finish', 'sUniqueID' => $this->Request()->transactionid]);
            return;
        }

        $basket = $this->getBasketBasedOnSignature($signature);

        if ($basket) {
            $this->saveOrder($transactionId, $transactionId, null, true);
        } elseif (!$orderExist) {
            $this->saveOrder($transactionId, $transactionId, Status::PAYMENT_STATE_REVIEW_NECESSARY, true);
        }
        $this->redirect(['controller' => 'checkout', 'action' => 'finish', 'sUniqueID' => $this->Request()->transactionid]);
    }

    /**
     * Cancel action method
     */
    public function cancelAction()
    {
        $this->redirect(['controller' => 'checkout']);
    }

    /**
     * @param array $hashData
     */
    private function restoreSession($hashData)
    {
        $sessionId = $hashData['sessionId'];

        if ($sessionId == session_id()) {
            $this->logger->info(
                'Session Id is the same, no further actions required',
                [
                    'TransactionId' => $this->Request()->getParam('transactionid'),
                    'CurrentSessionId' => isset($_SESSION['Shopware']['sessionId']) ? session_id() : 'session_id_not_found',
                    'OrderSessionId' => $sessionId,
                    'Action' => $this->Request()->getActionName()
                ]
            );
            return;
        }
        $this->logger->info(
            'Start session restore',
            [
                'TransactionId' => $this->Request()->getParam('transactionid'),
                'CurrentSessionId' => isset($_SESSION['Shopware']['sessionId']) ? session_id() : 'session_id_not_found',
                'OrderSessionId' => $sessionId,
                'Action' => $this->Request()->getActionName()
            ]
        );

        if (class_exists(\Enlight_Components_Session::class)) {
            \Enlight_Components_Session::writeClose();
            \Enlight_Components_Session::setId($sessionId);
            \Enlight_Components_Session::start();
            return;
        }

        $this->logger->info(
            'Finding session in database',
            [
                'TransactionId' => $this->Request()->getParam('transactionid'),
                'CurrentSessionId' => isset($_SESSION['Shopware']['sessionId']) ? session_id() : 'session_id_not_found',
                'OrderSessionId' => $sessionId,
                'Action' => $this->Request()->getActionName()
            ]
        );


        $session = Shopware()->Container()->get('db')->fetchRow(
            'SELECT *
            FROM s_core_sessions
            WHERE id = :sessionId',
            [
                'sessionId' => $sessionId,
            ]
        );

        if ($session) {
            $this->logger->info(
                'Session found in database, trying to restore it',
                [
                    'TransactionId' => $this->Request()->getParam('transactionid'),
                    'CurrentSessionId' => isset($_SESSION['Shopware']['sessionId']) ? session_id() : 'session_id_not_found',
                    'OrderSessionId' => $sessionId,
                    'DatabaseData' => $session,
                    'Action' => $this->Request()->getActionName()
                ]
            );

            try {
                Shopware()->Session()->save();
                session_id($sessionId);
                $this->logger->info(
                    'Successfully restored session',
                    [
                        'TransactionId' => $this->Request()->getParam('transactionid'),
                        'CurrentSessionId' => isset($_SESSION['Shopware']['sessionId']) ? session_id() : 'session_id_not_found',
                        'OrderSessionId' => $sessionId,
                        'Action' => $this->Request()->getActionName()
                    ]
                );
            } catch (Exception $exception) {
                $this->logger->warning(
                    'Could not restore session',
                    [
                        'TransactionId' => $this->Request()->getParam('transactionid'),
                        'CurrentSessionId' => isset($_SESSION['Shopware']['sessionId']) ? session_id() : 'session_id_not_found',
                        'OrderSessionId' => $sessionId,
                        'Action' => $this->Request()->getActionName(),
                        'exception' => $exception->getMessage()
                    ]
                );
            }
            return;
        }

        if ($hashData['sessionData']) {
            $this->logger->info(
                'Trying to restore session using optin service ',
                [
                    'TransactionId' => $this->Request()->getParam('transactionid'),
                    'CurrentSessionId' => isset($_SESSION['Shopware']['sessionId']) ? session_id() : 'session_id_not_found',
                    'OrderSessionId' => $sessionId,
                    'Action' => $this->Request()->getActionName()
                ]
            );

            $sessionData = json_decode($hashData['sessionData'], true);

            foreach ($sessionData as $key => $sessionDatum) {
                if (!Shopware()->Session()->get($key)) {
                    Shopware()->Session()->offsetSet($key, $sessionDatum);
                }
            }
            return;
        }
        $this->logger->warning(
            'Cannot restore session, no data found in the database',
            [
                'TransactionId' => $this->Request()->getParam('transactionid'),
                'CurrentSessionId' => isset($_SESSION['Shopware']['sessionId']) ? session_id() : 'session_id_not_found',
                'OrderSessionId' => $sessionId,
                'Action' => $this->Request()->getActionName()
            ]
        );
    }

    /**
     * @param $basket
     * @param bool $taxIncluded
     * @return mixed
     */
    private function getCheckoutData($basket, $taxIncluded = true)
    {
        $alternateTaxRates = array();
        $shoppingCart = array();
        $rates = array();
        $items = $basket['content'];

        foreach ($items as $data) {
            $rate = $data['tax_rate'] + 0;
            $rates[$rate] = $rate;

            $shoppingCart['shopping_cart']['items'][] = array(
                "name" => $data['articlename'],
                "description" => $data['additional_details']['description'],
                "unit_price" => $data['netprice'],
                "quantity" => $data['quantity'],
                "merchant_item_id" => $data['ordernumber'],
                "tax_table_selector" => $taxIncluded ? (string) number_format($rate, 2) : 'BTW0',
                "weight" => array(
                    "unit" => $data['additional_details']['sUnit']['unit'],
                    "value" => $data['additional_details']['weight'],
                )
            );
        }

        //Add shipping line item
        $shipping_rate = $basket['sShippingcostsTax'] + 0;
        $rates[$shipping_rate] = $shipping_rate;
        $shipping_info = $this->get('session')->sOrderVariables->sDispatch;
        $shipping_name = !empty($shipping_info['name']) ? $shipping_info['name'] : 'Shipping';
        $shipping_descr = !empty($shipping_info['description']) ? $shipping_info['description'] : 'Shipping';

        $shoppingCart['shopping_cart']['items'][] = array(
            "name" => $shipping_name,
            "description" => $shipping_descr,
            "unit_price" => $this->getShippingExclTax($basket, $taxIncluded),
            "quantity" => "1",
            "merchant_item_id" => "msp-shipping",
            "tax_table_selector" => $taxIncluded ? (string) number_format($shipping_rate, 2) : 'BTW0',
            "weight" => array(
                "unit" => "KG",
                "value" => "0",
            )
        );

        //Add alternate tax rates
        foreach ($rates as $rate) {
            $alternateTaxRates['tax_tables']['alternate'][] = array(
                 "standalone" => "true",
                 "name" => (string) number_format($rate, 2),
                 "rules" => array(
                     array("rate" => $rate / 100)
                 ),
             );
        }

        if (!$taxIncluded) {
            $alternateTaxRates['tax_tables']['alternate'][] = [
                'standalone' => 'true',
                'name' => 'BTW0',
                'rules' => [
                    [
                        'rate' => 0
                    ]
                ]
            ];
        }

        $checkoutData["shopping_cart"] = $shoppingCart['shopping_cart'];
        $checkoutData["checkout_options"] = $alternateTaxRates;
        return $checkoutData;
    }

    /**
     * @param $transactionid
     * @throws Exception
     */
    private function setClearedDate($transactionid)
    {
        $order = Shopware()->Models()->getRepository('Shopware\Models\Order\Order')->findOneBy(['transactionId' => $transactionid]);

        //Check if date has not been set yet
        if (!Helper::orderHasClearedDate($order)) {
            $order->setClearedDate(new \DateTime());
            $this->container->get('models')->flush($order);
        }
    }

    /**
     * @return mixed
     */
    private function getSessionId()
    {
        if ($this->container->has('shopware.components.optin_service') &&
            !empty($this->Request()->getParam('hash'))
        ) {
            $optinService = $this->container->get('shopware.components.optin_service');
            $hashArray = $optinService->get(
                OptinServiceInterface::TYPE_CUSTOMER_LOGIN_FROM_BACKEND,
                $this->Request()->getParam('hash')
            );
            return $hashArray['sessionId'];
        }

        $shop = $this->Request()->getParam('__shop');
        return $this->Request()->getParam('session-' . $shop);
    }

    /**
     * @return mixed
     */
    private function getSignature()
    {
        return $this->persistBasket();
    }

    /**
     * @param $signature
     * @return bool|ArrayObject
     */
    private function getBasketBasedOnSignature($signature)
    {
        $this->logger->info(
            'Start signature check',
            [
                'transactionId' => $this->Request()->getParam('transactionid'),
                'sessionId' => isset($_SESSION['Shopware']['sessionId']) ? session_id() : 'session_id_not_found',
                'signature' => $signature,
                'action' => $this->Request()->getActionName()
            ]
        );

        // To prevent race conditions. if the basket cannot be found. we will NOT set the order to review necessary
        try {
            $basket = $this->loadBasketFromSignature($signature);
        } catch (RuntimeException $runtimeException) {
            $this->logger->warning(
                RuntimeException::class . ': Could not verify the signature: ' . $runtimeException->getMessage(),
                [
                    'exception' => $runtimeException,
                    'transactionId' => $this->Request()->getParam('transactionid'),
                    'signature' => $signature,
                    'sessionId' => isset($_SESSION['Shopware']['sessionId']) ? session_id() : 'session_id_not_found',
                    'basket' => null,
                    'action' => $this->Request()->getActionName()
                ]
            );
            return true;
        }
        $this->logger->info(
            'Successfully loaded the basket',
            [
                'transactionId' => $this->Request()->getParam('transactionid'),
                'signature' => $signature,
                'sessionId' => isset($_SESSION['Shopware']['sessionId']) ? session_id() : 'session_id_not_found',
                'basket' => $basket,
                'action' => $this->Request()->getActionName()
            ]
        );

        try {
            $this->verifyBasketSignature($signature, $basket);
        } catch (RuntimeException $runtimeException) {
            $this->logger->warning(
                RuntimeException::class .': Could not verify the signature: '. $runtimeException->getMessage(),
                [
                    'exception' => $runtimeException,
                    'transactionId' => $this->Request()->getParam('transactionid'),
                    'signature' => $signature,
                    'sessionId' => isset($_SESSION['Shopware']['sessionId']) ? session_id() : 'session_id_not_found',
                    'basket' => $basket ?: null,
                    'action' => $this->Request()->getActionName()
                ]
            );
            return false;
        }
        $this->logger->info(
            'Successfully verified the basket',
            [
                'transactionId' => $this->Request()->getParam('transactionid'),
                'signature' => $signature,
                'sessionId' => isset($_SESSION['Shopware']['sessionId']) ? $_SESSION['Shopware']['sessionId'] : 'session_id_not_found',
                'basket' => $basket,
                'action' => $this->Request()->getActionName()
            ]
        );

        return $basket;
    }

    /**
     * @return string
     * @throws Exception
     */
    private function createHashFromSession()
    {
        if (!$this->container->has('shopware.components.optin_service')) {
            throw new \Exception('MultiSafepay requires optin service to work');
        }

        /** @var  $optinService \Shopware\Components\OptinService */
        $optinService = $this->container->get('shopware.components.optin_service');

        return $optinService->add(
            OptinServiceInterface::TYPE_CUSTOMER_LOGIN_FROM_BACKEND,
            Helper::getSecondsActive($this->pluginConfig["msp_time_label"], $this->pluginConfig["msp_time_active"]),
            [
                'sessionId' => Shopware()->Session()->get('sessionId'),
                'sessionData' => json_encode($_SESSION['Shopware'])
            ]
        );
    }

    /**
     * @param $hash
     * @return null
     */
    private function fillMissingSessionData($hash)
    {
        //Backend order
        if ($hash === null) {
            return null;
        }
        /** @var \Shopware\Components\OptinService $optinService */
        $optinService = $this->container->get('shopware.components.optin_service');
        $data = $optinService->get(
            OptinServiceInterface::TYPE_CUSTOMER_LOGIN_FROM_BACKEND,
            $hash
        );

        if (null === $data) {
            return null;
        }

        $this->restoreSession($data);
    }

    /**
     * @param $basket
     * @param $taxIncluded
     * @return float
     */
    private function getShippingExclTax($basket, $taxIncluded)
    {
        if (!$taxIncluded) {
            return $basket['sShippingcostsNet'];
        }

        $shippingTaxRate = 1 + ($basket['sShippingcostsTax'] / 100);
        return round($basket['sShippingcostsWithTax'] / $shippingTaxRate, 10);
    }

    /**
     * @return void
     */
    private function changePaymentMethod(Order $order, $gatewayCode)
    {
        $paymentMethodId = Shopware()->Models()->getRepository(Payment::class)
            ->getActivePaymentsQuery(['name' => 'multisafepay_'.$gatewayCode])
            ->getResult()[0]['id'];

        //If payment method is the same, don't change it
        if ($order->getPayment()->getId() === $paymentMethodId) {
            return;
        }

        $paymentMethod = Shopware()->Models()->find(Payment::class, $paymentMethodId);
        $order->setPayment($paymentMethod);
        Shopware()->Models()->persist($order);
        Shopware()->Models()->flush($order);
    }
}
