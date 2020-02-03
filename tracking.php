<?php
ini_set('memory_limit', '512M');
define('MAGENTO_ROOT', getcwd());
include_once(dirname(__FILE__).'XXXX/config.php');
require_once(dirname(__FILE__).'/XXXX/app/Mage.php');
Mage::app();

$dbcnx = mysql_connect($HOSTNAME, $USERNAME, $PASSWORD);
mysql_select_db($DATABASE);

if (isset($argv)) {
    foreach ($argv as $key => $value) {
        parse_str($argv[$key]);
    }
    $file = "/www/XXXX/tracking.txt"; // removing file directory for github public repo
} else {
    $file = "tracking.txt";
}
if (file_exists($file)) {
    $testVar = file_get_contents($file);
    $trackingList = preg_split('/[\n\r]+/', $testVar);
    /**
     * Completes the Shipment, followed by completing the Order life-cycle
     * It is assumed that the Invoice has already been generated
     * and the amount has been captured.
     */
    class createShipment
    {
        /**
         * @param $orderIncrementId
         * @param $shipmentTrackingNumber
         * @param $shipmentDate
         * @param $shipmentCarrierCode
         * @param $shipmentQuantities
         * @param $output
         * @param $currentstore
         */
        public function completeShipment($orderIncrementId, $shipmentTrackingNumber, $shipmentDate, $shipmentCarrierCode, $shipmentQuantities, &$output, $currentstore)
        {
            $customerEmailComments = 'Your order shipped ' . $shipmentDate . '';
            $output .= $orderIncrementId;
            $order = Mage::getModel('sales/order')->loadByIncrementId($orderIncrementId);

            if (!$order->getId()) {
                $output .= ' - Order does not exist<br>';
                return;
            }

            $query = "SELECT order_id, created_at FROM sales_flat_shipment WHERE order_id = ". $order->getId() ."";
            $result = mysql_query($query);

            if (mysql_num_rows($result) > 0) {
                while ($row = mysql_fetch_array($result)) {
                    $testDate = DateTime::createFromFormat('Y-m-d H:i:s', $row['created_at']);
                    $databaseDate = date_format($testDate, 'm/d/y');
                    /*$databaseDateMinusOne = date('m/d/y', strtotime($testDate . ' -1 day'));*/

                    if ($databaseDate === $shipmentDate /*|| $databaseDateMinusOne === $shipmentDate*/) {
                        $output .= " - Order already has shipment with same date<br>";
                        return;
                    }
                }
            }
            $output .= "<br>";

            if ($order->canShip()) {
                try {
                    $orderQty = $this->_getItemQtys($order);
                    $skuToId = $this->_getSkuToId($order);
                    $shippedQty = $this->_parseShippedQuantity($shipmentQuantities);
                    $matchedQtyArray = array();
                    $matchFailed = false;

                    //Look for matches between text file and skus from web order
                    $skippedSkus = array(
                        "22140",
                        "28855-3",
                        "22160",
                        "22170",
                        "22180",
                        "M821",
                        "STORE",
                        "UPS",
                        "CS.FREE",
                        "CS.GIFT",
                        "D.1962",
                        "AFTM",
                        "D.PROMOTION",
                        "RGC-2",
                    );

                    foreach ($shippedQty as $sku => $quantity) {
                        if ($sku == '') {
                            continue;
                        }

                        if (in_array(strtoupper($sku), $skippedSkus, true)) {
                            continue;
                        }

                        $matchedSku = $this->_matchSku($sku, $skuToId);
                        if (!$matchedSku) {
                            $failedSku = $sku;
                            $matchFailed = true;
                            break;
                        }

                        $orderItemId = $skuToId[strtolower($matchedSku)];

                        if (isset($matchedQtyArray[$orderItemId])) {
                            $matchedQtyArray[$orderItemId] = $matchedQtyArray[$orderItemId] + $quantity;
                        } else {
                            $matchedQtyArray[$orderItemId] = $quantity;
                        }
                    }

                    $fullShipment = true;

                    if ($matchFailed) {
                        //Could not find a match--run with $orderQty so that behavior is the same as previous version
                        $message = "Sku match failed on " . $failedSku . ". Using previous version email behavior on order: " . $order->getIncrementId();
                        mail('XXXX@XXXX.com', 'Mismatch Found', $message);
                        $setShipQty = $orderQty;
                    } else {
                        $misMatchDetected = false;
                        foreach ($matchedQtyArray as $id => $quantity) {
                            $difference = $orderQty[$id] - $quantity;

                            if ($difference > 0) {
                                $fullShipment = false;
                            }
                            if ($difference < 0) {
                                //Something has matched incorrectly--run with $orderQty so that behavior is the same as previous version
                                $misMatchDetected = true;
                                break;
                            }
                        }
                        if ($misMatchDetected) {
                            $message = "Mismatch detected or overshipped item in order: " . $order->getIncrementId();
                            mail('XXXX@XXXX.com', 'Mismatch Found', $message);
                            $setShipQty = $orderQty;
                        } else {
                            $setShipQty = $matchedQtyArray;
                        }
                    }

                    $shipment = Mage::getModel('sales/service_order', $order)->prepareShipment($setShipQty);
                    /**
                     * Carrier Codes can be like "ups" / "fedex" / "custom",
                     * but they need to be active from the System Configuration area.
                     * These variables can be provided custom-value, but it is always
                     * suggested to use Order values
                     */

                    //listrack
                    $tInfo = '';
                    $tNumbers = explode("|", $shipmentTrackingNumber);
                    $tCarriers = explode("|", $shipmentCarrierCode);
                    $i = 0;
                    $storeurl = Mage::app()->getStore($currentstore)->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK);
                    $spurl = "http://XXXX.com";
                    while ($i < count($tNumbers)) {
                        if ($tCarriers[$i] == 'UPS') {
                            $tInfo .= "<a href=\"". (($currentstore) ?  $storeurl : $spurl)  ."ups-tracking/?tracking=" . $tNumbers[$i] . "\">" . $tNumbers[$i] . "</a> ";
                        }
                        if ($tCarriers[$i] == 'USPS') {
                            $tInfo .= "<a href=\"". (($currentstore) ?  $storeurl : $spurl)  ."usps-tracking/?tracking=" . $tNumbers[$i] . "\">" . $tNumbers[$i] . "</a> ";
                        }
                        if ($tCarriers[$i] == 'FEDEX') {
                            $tInfo .= "<a href=\"". (($currentstore) ?  $storeurl : $spurl)  ."fedex-tracking/?tracking=" . $tNumbers[$i] . "\">" . $tNumbers[$i] . "</a> ";
                        }
                        $i++;
                    }

                    $_order = Mage::getModel('sales/order')->load($order->getId());
                    $shipping_address = Mage::getModel('sales/order_address')->load($_order->getShippingAddressId());
                    $testy3 = Mage::getModel('sales/order_address')->load($_order->getBillingAddressId());
                    $productGrid = '';
                    foreach ($_order->getAllItems() as $item) {
                        $productId = $item->getProductId();
                        $product = Mage::getModel('catalog/product')->load($productId);
                        $isVisibleProduct = $product->isVisibleInSiteVisibility();

                        if ($isVisibleProduct) {
                            $backorderedMessage = '';
                            $backordered = $item->getQtyOrdered() - ($setShipQty[$item->getId()] + $item->getQtyShipped());
                            if ($backordered >= 1) {
                                $expectedShipDate = $item->getProduct()->getExpectedShipDate();
                                $backorderedMessage .= '<span style="font-size: 10px;">Still On Backorder:</span> <span style="font-weight: 700;">' . round($backordered) . '</span><br>';
                                if ($product->getExpectedShipDate() && (new DateTime($product->getExpectedShipDate())) > (new DateTime())) {
                                    $shipdate = $product->getExpectedShipDate();
                                    $backorderedMessage .= '<span style="font-size: 10px;"> Expected Ship Date</span> <span style="font-weight: 700;">' . $shipdate . '</span><br>';
                                } else {
                                    $backorderedMessage .= '';
                                }
                            }
                            $shippedMessage = '';
                            $shipped = $item->getQtyShipped();
                            if ($shipped >= 1) {
                                $shippedMessage .= '<span style="font-size: 10px;">Previously Shipped:</span> <span style="font-weight: 700;">' . round($shipped) . '</span><br>';
                            }
                            $shippedQty = $setShipQty[$item->getId()] ? $setShipQty[$item->getId()] : '0';
                            $productGrid .= '
                                <!-- Single Product Starts -->
                                <table width="90%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse; mso-table-lspace:0pt; mso-table-rspace:0pt;">
                                    <tr>
                                        <td style="border-bottom: 1px solid #d7d7d7;">
                                            <table width="90%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse; mso-table-lspace:0pt; mso-table-rspace:0pt;">
                                                <tr>
                                                    <td class="fullWidthImg imgWidth" width="25%" align="right" style="font-family:Arial, Helvetica, sans-serif;font-size:14px; color: #6D6E71; font-weight: 700; padding: 10px 0 10px 0;"><img src="' . Mage::helper('catalog/image')->init($item->getProduct(), 'thumbnail') . '" alt="Product" border="0" width="99" style="display: block;"> </td>
                                                    <td class="fullWidthImg textWidth" align="left" style="font-family:Arial, Helvetica, sans-serif;font-size:14px; color: #6D6E71; font-weight: 400; mso-line-heigh-rule: exactly; line-height:19px; padding: 0 0 0 20px;">
                                                        <span style="font-weight: 700;">' . $product->getName() . '</span><br>
                                                        <span style="font-size: 10px;">Ordered:</span> <span style="font-weight:700;">' . round($item->getQtyOrdered()) . '</span><br>
                                                        <span style="font-size: 10px;">Shipped:</span> <span style="font-weight:700;">' . $shippedQty . '</span><br>' . $shippedMessage . $backorderedMessage . '
                                                        <span style="font-size: 10px;">PRICE:</span> <span style="font-weight:

                                                    700;">$' . number_format((float)$product->getFinalPrice() * $item->getQtyOrdered(), 2, '.', '') . '</span><br>
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </table>
                                <!-- Single Product Ends -->';
                        }
                    }

                    $output .= $productGrid;

                    Mage::log('the tracking email has been triggered', 6, 'tracking_email.log');
                    Mage::log($_order->getId(), null, 'tracking_email.log');
                    Mage::log('Template' . $productGrid, null, 'tracking_email.log');

                    $sh_param = array(
                        'UserName' => "XXXX",
                        'Password' => "XXXX"
                    );

                    $authvalues = new SoapVar($sh_param, SOAP_ENC_OBJECT);
                    $headers[] = new SoapHeader("http://webservices.listrak.com/v31/", 'WSUser', $sh_param);
                    $soapClient = new SoapClient(
                        "https://webservices.listrak.com/v31/IntegrationService.asmx?WSDL",
                        array(
                            'trace' => 1,
                            'exceptions' => true,
                            'cache_wsdl' => WSDL_CACHE_NONE,
                            'soap_version' => SOAP_1_2
                        )
                    );

                    $soapClient->__setSoapHeaders($headers);
                    $shiptype = str_replace("Sporty's Weight Carrier -", "", $_order->getShippingDescription());
                    $orderAttributes = Mage::getModel('amorderattr/attribute');
                    $orderAttributes->load($_order->getId(), 'order_id');

                    //Get Payment
                    /*$payment = $_order->getPayment();
                    $ptype = str_replace("Credit Card Type:", "", $payment->getMethodInstance()->getTitle());*/
                    $params = array(

                        'WSContact' => array(

                            'EmailAddress' => $_order->getCustomerEmail(),
                            'ListID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/listid', Mage::app()->getStore()),
                            'ContactProfileAttribute' => array(
                                array(//First Name

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/firstname', Mage::app()->getStore()),
                                    'Value' => $testy3->getFirstname()

                                ),
                                array(//Last Name

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/lastname', Mage::app()->getStore()),
                                    'Value' => $testy3->getLastname()

                                ),
                                array(//Order#

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/ordernumber', Mage::app()->getStore()),
                                    'Value' => $_order->getIncrementId()

                                ),
                                array(//Order date

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/orderdate', Mage::app()->getStore()),
                                    'Value' => date("m/d/Y", strtotime($_order->getCreatedAt()))

                                ),
                                array(//Address1

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/addressone', Mage::app()->getStore()),
                                    'Value' => $testy3->getStreet(1)

                                ),
                                array(//Address2

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/addresstwo', Mage::app()->getStore()),
                                    'Value' => $testy3->getStreet(2)

                                ),
                                array(//City

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/city', Mage::app()->getStore()),
                                    'Value' => $testy3->getCity()

                                ),
                                array(//State

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/state', Mage::app()->getStore()),
                                    'Value' => $testy3->getRegion()

                                ),
                                array(//Zipcode

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/zipcode', Mage::app()->getStore()),
                                    'Value' => $testy3->getPostcode()

                                ),
                                array(//Email

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/email', Mage::app()->getStore()),
                                    'Value' => $testy3->getEmail()

                                ),
                                array(//Phone

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/phone', Mage::app()->getStore()),
                                    'Value' => $testy3->getTelephone()

                                ),

                                //shipping info
                                array(//First Name

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/shippingfirstname', Mage::app()->getStore()),
                                    'Value' => $shipping_address->getFirstname()

                                ),
                                array(//Last Name

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/shippinglastname', Mage::app()->getStore()),
                                    'Value' => $shipping_address->getLastname()

                                ),
                                array(//Address1

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/shippingaddressone', Mage::app()->getStore()),
                                    'Value' => $shipping_address->getStreet(1)

                                ),
                                array(//Address2

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/shippingaddresstwo', Mage::app()->getStore()),
                                    'Value' => $shipping_address->getStreet(2)

                                ),
                                array(//City

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/shippingcity', Mage::app()->getStore()),
                                    'Value' => $shipping_address->getCity()

                                ),
                                array(//State

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/shippingstate', Mage::app()->getStore()),
                                    'Value' => $shipping_address->getRegion()

                                ),
                                array(//Zipcode

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/shippingzipcode', Mage::app()->getStore()),

                                    'Value' => $shipping_address->getPostcode()

                                ),
                                array(//Phone

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/shippingphone', Mage::app()->getStore()),
                                    'Value' => $shipping_address->getTelephone()

                                ),
                                array(//Email

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/shippingemail', Mage::app()->getStore()),
                                    'Value' => $testy3->getEmail()

                                ),
                                array(//Shipping Method

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/shippingmethod', Mage::app()->getStore()),
                                    'Value' => $shiptype

                                ),
                                array(//Grand Total

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/grandtotal', Mage::app()->getStore()),
                                    'Value' => '$' . number_format((float)$_order->getGrandTotal(), 2, '.', '')

                                ),
                                array(//Tax

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/tax', Mage::app()->getStore()),
                                    'Value' => '$' . number_format((float)$_order->getTaxAmount(), 2, '.', '')

                                ),
                                array(//Customer Comments

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/comments', Mage::app()->getStore()),
                                    'Value' => $orderAttributes->getData('ordermessage')

                                ),
                                /*array(//Payment Type

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/paymenttype', Mage::app()->getStore()),
                                    'Value' => '.'

                                ),*/
                                array(//Shipping Amount

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/shippingamount', Mage::app()->getStore()),
                                    'Value' => '$' . number_format((float)$_order->getShippingAmount(), 2, '.', '')

                                ),
                                array(//Tracking Number

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/trackingnumber', Mage::app()->getStore()),
                                    'Value' => $tInfo

                                ),
                                //$orderAttributes->getData('ordermessage')
                                array(//OrderSummary

                                    'AttributeID' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/productgrid', Mage::app()->getStore()),
                                    'Value' => $productGrid

                                )
                            )
                        ),
                        'ProfileUpdateType' => 'Overwrite',
                        'ExternalEventIDs' => Mage::getStoreConfig('XXXX_theme_options/listrak_options/shippingeventid', Mage::app()->getStore()),
                        'OverrideUnsubscribe' => true
                    );

                    Mage::log($params, null, 'tracking_email.log');

                    try {
                        $rest = $soapClient->SetContact($params);
                    } catch (SoapFault $e) {
                        $output .= '<pre>';
                        $output .= ($e->getMessage());
                        $output .= '</pre>';
                    }
                    Mage::log($soapClient->__getLastRequest(), null, 'tracking_email.log');

                    //listrak
                    $tNumbers = explode("|", $shipmentTrackingNumber);
                    $tCarriers = explode("|", $shipmentCarrierCode);
                    $i = 0;
                    while ($i < count($tNumbers)) {
                        $shipmentCarrierCode = $tCarriers[$i];
                        $shipmentCarrierTitle = $shipmentCarrierCode;
                        $shipmentTrackingNumber = $tNumbers[$i];

                        $arrTracking = array(
                            'carrier_code' => isset($shipmentCarrierCode) ? $shipmentCarrierCode : $order->getShippingCarrier()->getCarrierCode(),
                            'title' => isset($shipmentCarrierTitle) ? $shipmentCarrierTitle : $order->getShippingCarrier()->getConfigData('title'),
                            'number' => $shipmentTrackingNumber,
                        );

                        $track = Mage::getModel('sales/order_shipment_track')->addData($arrTracking);
                        $shipment->addTrack($track);
                        $i++;
                    }

                    // Register Shipment
                    $shipment->register();
                    // Save the Shipment
                    $this->_saveShipment($shipment, $order, $customerEmailComments);
                    // Finally, Save the Order
                    $this->_saveOrder($order, $fullShipment);
                } catch (Exception $e) {
                    $output .= $e->getMessage();
                }
            }
        }

        /**
         * Get the Quantities shipped for the Order, based on an item-level
         * This method can also be modified, to have the Partial Shipment functionality in place
         *
         * @param $order Mage_Sales_Model_Order
         * @return array
         */
        protected function _getItemQtys(Mage_Sales_Model_Order $order)
        {
            $qty = array();

            foreach ($order->getAllItems() as $_eachItem) {
                if ($_eachItem->getParentItemId()) {
                    $difference = $_eachItem->getQtyOrdered() - $_eachItem->getQtyShipped();

                    if (!isset($qty[$_eachItem->getParentItemId()]) || $qty[$_eachItem->getParentItemId()] > $difference) {
                        $qty[$_eachItem->getParentItemId()] = $_eachItem->getQtyOrdered() - $_eachItem->getQtyShipped();
                    }
                } else {
                    $difference = $_eachItem->getQtyOrdered() - $_eachItem->getQtyShipped();

                    if (!isset($qty[$_eachItem->getId()]) || $qty[$_eachItem->getId()] > $difference) {
                        $qty[$_eachItem->getId()] = $_eachItem->getQtyOrdered() - $_eachItem->getQtyShipped();
                    }
                }
            }

            return $qty;
        }

        /**
         * Saves the Shipment changes in the Order
         *
         * @param $shipment Mage_Sales_Model_Order_Shipment
         * @param $order Mage_Sales_Model_Order
         * @param $customerEmailComments string
         */
        protected function _saveShipment(Mage_Sales_Model_Order_Shipment $shipment, Mage_Sales_Model_Order $order, $customerEmailComments = '')
        {
            $shipment->getOrder()->setIsInProcess(true);
            $transactionSave = Mage::getModel('core/resource_transaction')
                ->addObject($shipment)
                ->addObject($order)
                ->save();
            $emailSentStatus = $shipment->getData('email_sent');
            if (!$emailSentStatus) {
                ;
                $shipment->setEmailSent(true)->save();
            }
            return $this;
        }

        /**
         * Saves the Order, to complete the full life-cycle of the Order
         * Order status will now show as Complete
         *
         * @param $order Mage_Sales_Model_Order
         */
        protected function _saveOrder(Mage_Sales_Model_Order $order, $complete)
        {
            if ($complete) {
                $order->setData('state', Mage_Sales_Model_Order::STATE_COMPLETE);
                $order->setData('status', Mage_Sales_Model_Order::STATE_COMPLETE);
            } else {
                $order->setStatus('partial', true);
            }
            $order->save();
            return $this;
        }

        //Create sku to order item id dictionary from order information
        protected function _getSkuToId(Mage_Sales_Model_Order $order)
        {
            $skuToId = array();

            foreach ($order->getAllItems() as $_eachItem) {
                if ($_eachItem->getParentItemId()) {
                    $skuToId[strtolower($_eachItem->getSku())] = $_eachItem->getParentItemId();
                } else {
                    $skuToId[strtolower($_eachItem->getSku())] = $_eachItem->getId();
                }
            }

            return $skuToId;
        }

        //Parse item quantity shipping information from text file
        protected function _parseShippedQuantity($textFileQuantity)
        {
            $productList = explode('*', $textFileQuantity);
            $quantityArray = array();
            foreach ($productList as $product) {
                $skuToQuantity = explode('|', $product);
                $quantityArray[strtolower($skuToQuantity[0])] = $skuToQuantity[1];
            }

            return $quantityArray;
        }

        //Match skus from text file to dictionary created from order information
        public function _matchSku($sku, $skuToIdDict)
        {
            if (isset($skuToIdDict[strtolower($sku)])) {
                return $sku;
            }

            $skuStoreArray = array(
                $sku . "A",
                $sku . "W",
                $sku . "L",
                $sku . "T",
            );

            foreach ($skuStoreArray as $storeSku) {
                if (isset($skuToIdDict[strtolower($storeSku)])) {
                    return $storeSku;
                }
            }

            $cleanSku = $sku;
            $cleanSku = str_replace('-1', '', $cleanSku);
            $cleanSku = str_replace('-2', '', $cleanSku);
            $cleanSku = str_replace('-00', '', $cleanSku);
            $cleanSku = str_replace('-0', '', $cleanSku);

            if (isset($skuToIdDict[strtolower($cleanSku)])) {
                return $cleanSku;
            }

            $cleanSkuStoreArray = array(
                $cleanSku . "A",
                $cleanSku . "W",
                $cleanSku . "L",
                $cleanSku . "T",
            );

            foreach ($cleanSkuStoreArray as $storeSku) {
                if (isset($skuToIdDict[strtolower($storeSku)])) {
                    return $storeSku;
                }
            }

            $cleanSku = str_replace('-3', '', $cleanSku);
            $cleanSku = str_replace('-4', '', $cleanSku);
            $cleanSku = str_replace('-6', '', $cleanSku);
            $cleanSku = str_replace('-8', '', $cleanSku);
            $cleanSku = str_replace('-9', '', $cleanSku);

            $cleanSkuStoreArray = array(
                $cleanSku . "A",
                $cleanSku . "W",
                $cleanSku . "L",
                $cleanSku . "T",
            );

            foreach ($cleanSkuStoreArray as $storeSku) {
                if (isset($skuToIdDict[strtolower($storeSku)])) {
                    return $storeSku;
                }
            }

            switch ($cleanSku) {
                case "20170":
                    if (isset($skuToIdDict[strtolower("20170-1A")])) {
                        return "20170-1A";
                    }
                    break;
                case "4100":
                    if (isset($skuToIdDict[strtolower("4100A-4100-00")])) {
                        return "4100A-4100-00";
                    }
                    break;
                case "b5000":
                    if (isset($skuToIdDict[strtolower("B5000A-b5000-6")])) {
                        return "B5000A-b5000-6";
                    }
                    break;
                case "d.circuit":
                    if (isset($skuToIdDict[strtolower("B1023A")])) {
                        return "B1023A";
                    }
                    break;
                case "d.toggle":
                    if (isset($skuToIdDict[strtolower("B5057A")])) {
                        return "B5057A";
                    }
                    break;
                case "d.fuel":
                    if (isset($skuToIdDict[strtolower("B2087A")])) {
                        return "B2087A";
                    }
                    break;
                case "d.sea":
                    if (isset($skuToIdDict[strtolower("B1976A")])) {
                        return "B1976A";
                    }
                    break;
                case "m319":
                    if (isset($skuToIdDict[strtolower("M319-1A")])) {
                        return "M319-1A";
                    }
                    break;
                case "m467":
                    if (isset($skuToIdDict[strtolower("M467-1A")])) {
                        return "M467-1A";
                    }
                    break;
                case "2546":
                    if (isset($skuToIdDict[strtolower("2546-BKW")])) {
                        return "2546-BKW";
                    }
                    break;

            }

            return false;
        }
    }


    $firstOrder = null;
    $lastOrder = null;
    $output = '';

    foreach ($trackingList as &$value) {
        $trackingRow = explode(",", $value);

        if ($trackingRow[1] != 'X') {
            $newshipment = new createShipment();

            if (is_null($firstOrder) && $trackingRow[0] != "ORDER_NO" && $trackingRow[0] != '') {
                $firstOrder = $trackingRow[0];
            }

            if ($trackingRow[0] != '') {
                $lastOrder = $trackingRow[0];
            }

            switch ($trackingRow[1]) {
                case "A":
                    $currentStore = 2;
                    break;
                case "T":
                    $currentStore = 5;
                    break;
                case "L":
                    $currentStore = 4;
                    break;
                case "W":
                    $currentStore = 3;
                    break;
            }
            Mage::app()->setCurrentStore($currentStore);

            if ($trackingRow[0] != "ORDER_NO" && $trackingRow[0] != '') {
                $newshipment->completeShipment($trackingRow[0], $trackingRow[2], $trackingRow[4], $trackingRow[3], $trackingRow[7], $output, $currentStore);
            }
        } else {
            $tInfo = '';

            $tInfo .= "Sporty\'s Shipping Confirmation Order number " . $trackingRow[0] . " was shipped on " . $trackingRow[4] . ".\r\n\r\n";

            $tNumbers = explode("|", $trackingRow[2]);
            $tCarriers = explode("|", $trackingRow[3]);
            $i = 0;

            while ($i < count($tNumbers)) {
                $tInfo .= "Carrier: " . $tCarriers[$i] . "\r\nTracking Number: " . $tNumbers[$i] . "\r\n";
                if ($tCarriers[$i] == 'UPS') {
                    $tInfo .= "Link: http://wwwapps.ups.com/WebTracking/processInputRequest?TypeOfInquiryNumber=T&loc=en_US&InquiryNumber1=" . $tNumbers[$i] . "\r\n";
                }
                if ($tCarriers[$i] == 'USPS') {
                    $tInfo .= "Link: http://trkcnfrm1.smi.usps.com/PTSInternetWeb/InterLabelInquiry.do?origTrackNum=" . $tNumbers[$i] . "\r\n";
                }
                if ($tCarriers[$i] == 'FEDEX') {
                    $tInfo .= "Link: http://fedex.com/Tracking?action=track&tracknumber_list=" . $tNumbers[$i] . "&cntry_code=us\r\n";
                }
                $i++;
            }

            $tInfo .= "\r\nThis is an automated email message.  Please do not reply to tracking@XXXX.com.\r\n\r\n
                Sporty's Shops\r\n
                Clermont County/Sporty's Airport \r\n
                2001 Sporty's Dr.\r\n
                Batavia, Ohio, 45103\r\n
                Phone: 1.800.SPORTYS (1.800.776.7897), Fax: 1.800.LIFTOFF (1.800.543.8633)";

            $result = mysql_query("Select * from tracking where phoneOrderId = $trackingRow[0]");
            $theaders = 'From: tracking@XXXX.com' . "\r\n" .
                'Reply-to: No-Reply@XXXX.com' . "\r\n" .
                'X-Mailer: PHP/' . phpversion();
            if (mysql_num_rows($result) == 0) {
                if ($trackingRow[6] != 'X') {
                    mail($trackingRow[6], 'Your Sporty\'s Order Has Shipped', $tInfo, $theaders);
                }

                $result = mysql_query("Insert into tracking (phoneOrderId,carrier,zipcode,shipdate,uploaddate,tracking_number) Values (" . $trackingRow[0] . ",'" . $trackingRow[3] . "','" . $trackingRow[5] . "','" . $trackingRow[4] . "','" . NOW() . "','" . $trackingRow[2] . "')");
                print mysql_error();
            }
        }
    }

    $firstOrderText = "<br>First order: " . $firstOrder;
    $lastOrderText = "<br>Last Order: " . $lastOrder;

    $output .= $firstOrderText;
    $output .= $lastOrderText;

    mail("XXXX@XXXX.com", "Tracking Finished", $output);
    mail('XXXX@XXXX.com', "Tracking Finished", $output);

    print "Finished";
    print $firstOrderText;
    print $lastOrderText;
}
else {
    echo 'file not found';
}
