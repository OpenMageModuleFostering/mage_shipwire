<?php

/**
* Our test shipping method module adapter
*/

class Shipwire_Shipping_Model_Carrier_ShippingMethod extends Mage_Shipping_Model_Carrier_Abstract
{

    /**
     * unique internal shipping method identifier
     *
     * @var string [a-z0-9_]
     */
    
    protected $_code = 'shipwire_shipping';

    /**
    * Collect rates for this shipping method based on information in $request
    *
    * @param Mage_Shipping_Model_Rate_Request $data
    * @return Mage_Shipping_Model_Rate_Result
    */
    public function collectRates(Mage_Shipping_Model_Rate_Request $request)
    {
      // skip if not enabled
      if (!Mage::getStoreConfig('carriers/'.$this->_code.'/active')) {
          return false;
      }
    
      /**
       * here we are retrieving shipping rates from external service
       * or using internal logic to calculate the rate from $request
       * you can see an example in Mage_Usa_Model_Shipping_Carrier_Ups::setRequest()
       */
      // get necessary configuration values
      $response = $this->_submitRequest($request);
      //$handling = Mage::getStoreConfig('carriers/'.$this->_code.'/handling');
    
      // this object will be returned as result of this method
      // containing all the shipping rates of this method
      $result = Mage::getModel('shipping/rate_result');
      // $response is an array that we have
      foreach ($response as $rMethod) {
        // create new instance of method rate
        $method = Mage::getModel('shipping/rate_result_method');
        // record carrier information
        $method->setCarrier($this->_code);
        $method->setCarrierTitle(Mage::getStoreConfig('carriers/'.$this->_code.'/title'));
        // record method information
        $method->setMethod($rMethod['code']);
        $method->setMethodTitle($rMethod['title']);
    
        // rate cost is optional property to record how much it costs to vendor to ship
        $method->setCost($rMethod['amount']);
    
        // in our example handling is fixed amount that is added to cost
        // to receive price the customer will pay for shipping method.
        // it could be as well percentage:
        /// $method->setPrice($rMethod['amount']*$handling/100);
        $method->setPrice($rMethod['amount']);
    
        // add this rate to the result
        $result->append($method);
      }
    
      return $result;
    }
    
    
    public function getAllowedMethods()
    {
        return array('shipwire_shipping'=>$this->getConfigData('name'));
    }
    
    private function _submitRequest($requestVar) 
    {
        
        $account_email = Mage::getStoreConfig('carriers/shipwire_shipping/shipwire_email');
        $account_password = Mage::getStoreConfig('carriers/shipwire_shipping/shipwire_password');
        $available_services = Mage::getStoreConfig('carriers/shipwire_shipping/availableservices');
        
        $address_street1 = $requestVar->dest_street;
        $address_street2 = '';
        $address_city = $requestVar->dest_city;
        $address_region = $requestVar->dest_region_code;
        $address_country = $requestVar->dest_country_id;
        $address_postcode = $requestVar->postcode;
    
        $items = $requestVar->all_items;
        
        $item_xml = '';
        $num = 1;
        if (count($items) > 0) {
          foreach ($items as $item) {
            $item_xml .= '<Item num="' . $num++ . '">';
              $item_xml .= '<Code>' . htmlentities($item->sku) . '</Code>';
              $item_xml .= '<Quantity>' . htmlentities($item->qty) . '</Quantity>';
            $item_xml .= '</Item>';
          }
        }
    
        $xml = '
        <RateRequest>
          <EmailAddress><![CDATA[' . $account_email . ']]></EmailAddress>
          <Password><![CDATA[' . $account_password . ']]></Password>
          <Order id="quote123">
            <Warehouse>00</Warehouse>
            <AddressInfo type="ship">
              <Address1><![CDATA[' . htmlentities($address_street1) . ']]></Address1>
              <Address2><![CDATA[' . htmlentities($address_street2) . ']]></Address2>
              <City><![CDATA[' . htmlentities($address_city) . ']]></City>
              <State><![CDATA[' . htmlentities($address_region) . ']]></State>
              <Country><![CDATA[' . htmlentities($address_country) . ']]></Country>
              <Zip><![CDATA[' . htmlentities($address_postcode) . ']]></Zip>
            </AddressInfo>
            ' . $item_xml . '
          </Order>
        </RateRequest>';
    
        $xml_request_encoded = ("RateRequestXML=" . $xml);
        
        $xml_submit_url = "https://api.shipwire.com/exec/RateServices.php";
        
        $session = curl_init();
        curl_setopt($session, CURLOPT_URL, $xml_submit_url);
        curl_setopt($session, CURLOPT_POST, true);
        curl_setopt($session, CURLOPT_HTTPHEADER, array("Content-type","application/x-www-form-urlencoded"));
        curl_setopt($session, CURLOPT_POSTFIELDS, $xml_request_encoded);
        curl_setopt($session, CURLOPT_HEADER, false);
        curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($session, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($session, CURLOPT_TIMEOUT, 360);
        $response = curl_exec($session);
/*                 
        $client = new Varien_Http_Client('https://api.shipwire.com/exec/RateServices.php');
        $client->setMethod(Zend_Http_Client::POST);
        $client->setParameterPost('RateRequestXML', $xml);
        $response = $client->request();
*/    
        $rateResult = array();
        if (FALSE === $response) {
            $rateResult; 
        }
       
        $parser = xml_parser_create();

        xml_parse_into_struct($parser, $response, $xmlVals, $xmlIndex);
       
        xml_parser_free($parser);        
        
        foreach($xmlVals as $key){
            if($key['tag'] == "STATUS"){
                if($key['value'] != "OK"){
                  return $rateResult;
                }
                
            }
        }
        
        $code = array();
        $method = array();
        $cost = array();
        $supportedServices = explode(",", $available_services);
        
        foreach($xmlVals as $key){
            if($key['tag'] == "QUOTE" && $key['type'] == "open" && $key['level'] == 4) { 
                $code[] =  $key['attributes']['METHOD']; 
            }
            if($key['tag'] == "SERVICE" && $key['type'] == "complete" && $key['level'] == 5) {
                $method[] =  $key['value'];
            }
            if($key['tag'] == "COST" && $key['type'] == "complete" && $key['level'] == 5) { 
                $cost[] =  $key['value']; 
            }
        }
        
        $la = count($code);
        $lb = count($method);
        $lc = count($cost); 
        
        if($la = $lb = $lc){
            foreach($code as $index => $value){
                if (in_array($value, $supportedServices)) { 
                    $rateResult[] = array("code" => $code[$index],
                                          "title" => $method[$index],
                                          "amount" =>$cost[$index]) ;
                }                
            }
        }
        return $rateResult;

    }

  protected function _formatResponse($response) {
      $values = array();
      $index = array();

      $p = xml_parser_create();
      xml_parse_into_struct($p, $response->getBody(), $values, $index);
      xml_parser_free($p);

      //print_r($values);
      //print_r($index);
      $exceptions = array();
      $warnings = array();
  }
        
}