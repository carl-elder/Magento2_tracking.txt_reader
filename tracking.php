<?php
ini_set('memory_limit', '512M');
define('MAGENTO_ROOT', getcwd());
include_once(dirname(__FILE__).'XXXX/config.php');
require_once(dirname(__FILE__).'/XXXX/app/Mage.php');
Mage::app();
$dbcnx = mysqli_connect($HOSTNAME, $USERNAME, $PASSWORD);
mysqli_select_db($dbcnx, $DATABASE);
if (isset($argv)) {
    foreach ($argv as $key => $value) {
        parse_str($argv[$key]);
    }
    $file = "/www/sites/XXXX/files/html/XXXX/tracking.txt";
} else {
    $file = "XXXX/tracking.txt";
}
if (file_exists($file)) {
    include('tracking/CreateShipment.php');
    $testVar = file_get_contents($file);
    $trackingList = preg_split('/[\n\r]+/', $testVar);
    $output = '';
    foreach ($trackingList as &$value) {
        $trackingRow = explode(",", $value);
        if ($trackingRow[0] !== "ORDER_NO" && $trackingRow[0] !== '' && $trackingRow[1] !== 'X') {
            echo 'switch hit';
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
            $newshipment = new \XXXX\CreateShipment($trackingRow, $currentStore);
            if(!$newshipment->completeShipment()){
                mail("xxxx@xxxx.com, xxxx@xxxx.com", "Tracking Failure", 'Order No: '.$trackingRow[0]);
            }
        } elseif ($trackingRow[0] !== "ORDER_NO" && $trackingRow[0] !== '') {
            $tInfo = '';
            $tInfo .= "xxxx\'s Shipping Confirmation Order number " . $trackingRow[0] . " was shipped on " . $trackingRow[4] . ".\r\n\r\n";
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
                XXXX's Shops\r\n
                XXXX\r\n
                2001 XXXX's Dr.\r\n
                XXXX, XXXX, XXXX\r\n
                Phone: 1.800.XXXX (1.800.XXX.XXXX), Fax: 1.XXX.XXXX (1.XXX.XXX.XXXX)";
            $result = mysqli_query($dbcnx, "SELECT * FROM tracking WHERE phoneOrderId = $trackingRow[0]");
            $theaders = 'From: XXXX@XXXX.com' . "\r\n" .
                'Reply-to: No-Reply@XXXX.com' . "\r\n" .
                'X-Mailer: PHP/' . phpversion();
            if (mysqli_num_rows($result) == 0) {
                if ($trackingRow[6] != 'X') {
                    mail($trackingRow[6], 'Your XXXX\'s Order Has Shipped', $tInfo, $theaders);
                }
                $result = mysqli_query(mysqli_connect($HOSTNAME, $USERNAME, $PASSWORD),
                    "INSERT INTO XXXX (phoneOrderId,carrier,zipcode,shipdate,uploaddate,tracking_number) 
                          VALUES (".$trackingRow[0].",'".$trackingRow[3]."','".$trackingRow[5]."','".$trackingRow[4]."','".NOW()."','".$trackingRow[2]."')");
                echo 'Error? -'. mysqli_error($dbcnx);
            }
        }
    }
    mail("XXXX@XXXX.com, XXXX@XXXX.com", "Tracking Finished", $output);
    echo "Finished";
} else {
    echo 'file not found';
}
