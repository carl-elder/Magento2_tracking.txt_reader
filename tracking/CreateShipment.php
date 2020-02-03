<?php

namespace XXXX;
use Mage;
use Mage_Core_Model_Store;

class CreateShipment
{
    protected $mage;
    protected $store;
    protected $orderId;
    protected $order;
    protected $orderAttributes;
    protected $shipmentTrackingNumber;
    protected $shipmentDate;
    protected $shipmentCarrierCode;
    protected $shipmentQuantities;

    /**
     * CreateShipment constructor.
     *
     * @param $trackingRow
     * @param $currentStore
     */
    public function __construct($trackingRow, $currentStore)
    {
        $this->orderId = $trackingRow[0];
        $this->order = Mage::getModel('sales/order')
            ->loadByIncrementId($this->orderId);
        $this->orderAttributes = Mage::getModel('amorderattr/attribute')
            ->load($this->order->getId(), 'order_id');
        $this->shipmentTrackingNumber = $trackingRow[2];
        $this->shipmentDate = $trackingRow[4];
        $this->shipmentCarrierCode = $trackingRow[3];
        $this->shipmentQuantities = $trackingRow[7];
        $this->mage = Mage::app();
        $this->store = $currentStore;
    }

    /**
     * Process text file record to completion, build the shipment, register it,
     * save it, save new order status
     *
     * @return bool
     */
    public function completeShipment()
    {
        try {
            $this->buildShipment();
            $this->setTrackingInfo();
            $this->saveShipment();
            $this->_saveOrder($this->order, $this->order->fullShipment);
            return true;
        } catch (\Exception $e) {
            Mage::logException($e);
            return false;
        }
    }

    /**
     * Check order can ship - per Mage - and begin process of sending to listrak
     *
     * @return bool
     */
    protected function buildShipment()
    {
        echo 'buildshipment hit <br />';
        try {
            if ($this->order->canShip()) {
                $this->sendToListrak();
            }
            return true;
        } catch (\Exception $e) {
            Mage::logException($e);
            return false;
        }
    }

    /**
     * Send completed soap object to listrak for processing
     *
     * @return bool
     */
    protected function sendToListrak()
    {
        echo 'sendtoListrak hit <br />';
        $sendVal = $this->buildForListrak();
        $authvalues = new \SoapVar($sendVal['sh_param'], SOAP_ENC_OBJECT);
        $headers[] = new \SoapHeader("http://webservices.listrak.com/v31/", 'WSUser', $sendVal['sh_param']);
        $soapClient = new \SoapClient(
            "https://webservices.listrak.com/v31/IntegrationService.asmx?WSDL",
            ['trace' => 1, 'exceptions' => true, 'cache_wsdl' => WSDL_CACHE_NONE, 'soap_version' => SOAP_1_2]
        );
        $soapClient->__setSoapHeaders($headers);
        try {
            $rest = $soapClient->SetContact($sendVal['params']);
            Mage::log($soapClient->__getLastRequest(), null, 'tracking_email.log');
            return true;
        } catch (\Exception $e) {
            Mage::logException($e);
            return false;
        }
    }

    /**
     * Build array for listrak
     *
     * @return mixed
     */
    private function buildForListrak()
    {
        echo 'buildForListrak hit <br />';
        $order = $this->order;
        $sA = Mage::getModel('sales/order_address')->load($order->getShippingAddressId());
        $bA = Mage::getModel('sales/order_address')->load($order->getBillingAddressId());
        $soapy['sh_param'] = ['UserName' => "XXXX", 'Password' => "XXXX"];
        $soapy['params'] = [
            'WSContact' => [
                'EmailAddress' => $order->getCustomerEmail(),
                'ListID' => $this->getListrakOption('listid'),
                'ContactProfileAttribute' => [
                    ['AttributeID' => $this->getListrakOption('firstname'), 'Value' => $order->getFirstname()],
                    ['AttributeID' => $this->getListrakOption('lastname'), 'Value' => $order->getLastname()],
                    ['AttributeID' => $this->getListrakOption('ordernumber'), 'Value' => $this->orderId],
                    ['AttributeID' => $this->getListrakOption('orderdate'),
                        'Value' => date("m/d/Y", strtotime($order->getCreatedAt()))
                    ],
                    ['AttributeID' => $this->getListrakOption('addressone'), 'Value' => $bA->getStreet(1)],
                    ['AttributeID' => $this->getListrakOption('addresstwo'), 'Value' => $bA->getStreet(2)],
                    ['AttributeID' => $this->getListrakOption('city'), 'Value' => $bA->getCity()],
                    ['AttributeID' => $this->getListrakOption('state'), 'Value' => $bA->getRegion()],
                    ['AttributeID' => $this->getListrakOption('zipcode'), 'Value' => $bA->getPostcode()],
                    ['AttributeID' => $this->getListrakOption('email'), 'Value' => $this->order->getEmail()],
                    ['AttributeID' => $this->getListrakOption('phone'), 'Value' => $bA->getTelephone()],
                    ['AttributeID' => $this->getListrakOption('shippingfirstname'), 'Value' => $bA->getFirstname()],
                    ['AttributeID' => $this->getListrakOption('shippinglastname'), 'Value' => $sA->getLastname()],
                    ['AttributeID' => $this->getListrakOption('shippingaddressone'), 'Value' => $sA->getStreet(1)],
                    ['AttributeID' => $this->getListrakOption('shippingaddresstwo'), 'Value' => $sA->getStreet(2)],
                    ['AttributeID' => $this->getListrakOption('shippingcity'), 'Value' => $sA->getCity()],
                    ['AttributeID' => $this->getListrakOption('shippingstate'), 'Value' => $sA->getRegion()],
                    ['AttributeID' => $this->getListrakOption('shippingzipcode'), 'Value' => $sA->getPostcode()],
                    ['AttributeID' => $this->getListrakOption('shippingphone'), 'Value' => $sA->getTelephone()],
                    ['AttributeID' => $this->getListrakOption('shippingemail'), 'Value' => $sA->getEmail()],
                    ['AttributeID' => $this->getListrakOption('shippingmethod'), 'Value' => $order->getShipType()],
                    ['AttributeID' => $this->getListrakOption('grandtotal'),
                        'Value' => '$' . number_format((float)$order->getGrandTotal(),2,'.','')
                    ],
                    ['AttributeID' => $this->getListrakOption('tax'),
                        'Value' => '$'.number_format((float)$order->getTaxAmount(),2,'.','')
                    ],
                    ['AttributeID' => $this->getListrakOption('comments'),'Value' => $this->orderAttributes->getData('ordermessage')],
                    ['AttributeID' => $this->getListrakOption('shippingamount'),
                        'Value' => '$'.number_format((float)$order->getShippingAmount(),2,'.','')
                    ],
                    ['AttributeID' => $this->getListrakOption('trackingnumber'), 'Value' => $this->setTrackingUrl()],
                    ['AttributeID' => $this->getListrakOption('productgrid'), 'Value' => $this->setProductGrid()]
                ]
            ],
            'ProfileUpdateType' => 'Overwrite',
            'ExternalEventIDs' => $this->getListrakOption('shippingeventid'),
            'OverrideUnsubscribe' => true
        ];
        return $soapy;
    }

    /**
     * Set tracking url
     *
     * @return string
     */
    protected function setTrackingUrl()
    {
        echo 'setTrackingUrl hit <br />';

        $tNumbers = explode("|", $this->shipmentTrackingNumber);
        $tCarriers = explode("|", $this->shipmentCarrierCode);
        $i = 0;
        $output = '';
        $storeUrl = Mage::app()->getStore($this->store)->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK);
        $spUrl = "http://sportys.com";
        while ($i < count($tNumbers)) {
            $output .= "<a href=\"".(($storeUrl) ? $storeUrl : $spUrl).strtolower($tCarriers[$i])."-tracking/?tracking=".$tNumbers[$i]."\">".$tNumbers[$i]."</a> ";
            $i++;
        }
        return $output;
    }

    /**
     * Set tracking number and carrier info for tracking email
     *
     * @param $setShipQty
     */
    protected function setTrackingInfo()
    {
        echo 'setTrackingInfo hit <br />';

        try {
            $shipment = Mage::getModel('sales/service_order', $this->order)
                ->prepareShipment($this->shipmentQuantities);
            $tNumbers = explode("|", $this->shipmentTrackingNumber);
            $tCarriers = explode("|", $this->shipmentCarrierCode);
            $i = 0;
            while ($i < count($tNumbers)) {
                $shipmentCarrierCode = $tCarriers[$i];
                $shipmentCarrierTitle = $shipmentCarrierCode;
                $shipmentTrackingNumber = $tNumbers[$i];
                $arrTracking = [
                    'carrier_code' => isset($shipmentCarrierCode) ? $shipmentCarrierCode : $this->order->getShippingCarrier()->getCarrierCode(),
                    'title' => isset($shipmentCarrierTitle) ? $shipmentCarrierTitle : $this->order->getShippingCarrier()->getConfigData('title'),
                    'number' => $shipmentTrackingNumber,
                ];
                $track = Mage::getModel('sales/order_shipment_track')->addData($arrTracking);
                $shipment->addTrack($track);
                $i++;
            }
            $shipment->register();
        } catch (\Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * Set the product grid for tracking email template
     *
     * @return bool|string
     */
    protected function setProductGrid()
    {
        try {
            $productGrid = '';
            if (strpos($this->shipmentQuantities, '*')) {
                $chunky = array_chunk(preg_split('/(\||*)/', $this->shipmentQuantities), 2);
                $items = array_combine(array_column($chunky, 0), array_column($chunky, 1));
            } else {
                $chunky = array_chunk(preg_split('/(\|)/', $this->shipmentQuantities), 2);
                $items = array_combine(array_column($chunky, 0), array_column($chunky, 1));
            }
            foreach ($items as $sku => $quantity) {
                $productId = Mage::getModel('catalog/product')->getIdBySku($sku);
                $product = Mage::getModel('catalog/product')->load($productId);
                $orderedProducts = $this->order->getAllVisibleItems();
                foreach ($orderedProducts as $o) {
                    if ($o->getSku() == $sku) {
                        $orderedProduct = $o;
                    }
                }
                $productGrid .= '<table width="90%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse; mso-table-lspace:0pt; mso-table-rspace:0pt;">
                    <tr>
                        <td style="border-bottom: 1px solid #d7d7d7;">
                            <table width="90%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse; mso-table-lspace:0pt; mso-table-rspace:0pt;">
                                <tr>
                                    <td class="fullWidthImg imgWidth" width="25%" align="right" style="font-family:Arial, Helvetica, sans-serif;font-size:14px; color: #6D6E71; font-weight: 700; padding: 10px 0 10px 0;"><img src="' . Mage::helper('catalog/image')->init($product, 'thumbnail') . '" alt="Product" border="0" width="99" style="display:block;"> </td>
                                    <td class="fullWidthImg textWidth" align="left" style="font-family:Arial, Helvetica, sans-serif;font-size:14px; color: #6D6E71; font-weight: 400; mso-line-heigh-rule: exactly; line-height:19px; padding: 0 0 0 20px;">
                                        <span style="font-weight: 700;">' . $product->getName() . '</span><br>
                                        <span style="font-size: 10px;">Ordered:</span> <span style="font-weight:700;">'.round($orderedProduct->getQtyOrdered()).'</span><br>
                                        <span style="font-size: 10px;">Shipped:</span> <span style="font-weight:700;">'.$quantity.'</span><br>
                                        <span style="font-size: 10px;">PRICE:</span> <span style="font-weight:700;">$' . number_format((float)$product->getFinalPrice() * $orderedProduct->getQtyOrdered(), 2, '.', '') . '</span><br>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table><!-- Single Product Ends -->';
            }
            return $productGrid;
        } catch (\Exception $e) {
            Mage::logException($e);
            return false;
        }
    }

    /**
     * Wrapper method. Save me from writing the full
     * getStoreConfig call 5000000 times...
     *
     * @param $option
     *
     * @return mixed
     */
    private function getListrakOption($option)
    {
        return Mage::getStoreConfig('sportys_theme_options/listrak_options/'.$option, Mage::app()->getStore());
    }

    /**
     * @param $shipment
     * @param $itemsQty
     * @return bool
     */
    private function saveShipment()
    {
        $shipmentCollection = Mage::getResourceModel('sales/order_shipment_collection');
        $shipmentCollection->addAttributeToFilter('order_id', $this->orderId);
        foreach ($shipmentCollection as $sc) {
            $shipment = Mage::getModel('sales/order_shipment');
            $shipment->load($sc->getId());
            if ($shipment->getId() != '') {
                $track = Mage::getModel('sales/order_shipment_track')
                    ->setShipment($shipment)
                    ->setData('title', 'ShippingMethodName')
                    ->setData('number', $this->shipmentTrackingNumber)
                    ->setData('carrier_code', 'ShippingCarrierCode')
                    ->setData('order_id', $shipment->getData('order_id'))
                    ->save();
            }
        }
    }
    /**
     * Saves the Order, to complete the full life-cycle of the Order
     * Order status will now show as Complete
     *
     * @param $order Mage_Sales_Model_Order
     */
    protected function _saveOrder($order, $complete)
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
}
