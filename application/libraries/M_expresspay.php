<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$CI = &get_instance();

class M_expresspay {
	
	protected $payment_setting_array;
	
	
	
    public function __construct() {
		global $CI, $payment_setting_array;
		$payment_setting = $CI->db->get('setting', 1)->row()->payment_setting;
		$payment_setting_array = json_decode($payment_setting, 1);
    }
	
	

	protected function getRestURL($action, $params = '') {
		global $payment_setting_array;
		($payment_setting_array['expresspay_is_test']) ? $url = 'https://sandbox-api.express-pay.by/' : $url = 'https://api.express-pay.by/';
		switch ($action) {
			case 'checkoutInvoices' :
				$rest_url = $url . 'v1/web_cardinvoices';
				break;
			case 'checkoutRecurring' :
				$rest_url = $url . 'v1/recurringpayment/bind';
				break;
			case 'cancelSubscription' :
				$rest_url = $url . 'v1/recurringpayment/unbind/?';
				break;
		}
		return $rest_url . $params;
	}
	

	
	protected function headerBuilder() {
		$header = [
		  //'content-type:application/json'
		];
		return $header;
	}


	
	protected function subscriptionCycle($period) {
		switch ($period) {
			case 'year' :
			  $writeOffPeriod = 6;
			  break;
			case 'month' :
			  $writeOffPeriod = 3;
			  break;
			case 'week' :
			  $writeOffPeriod = 2;
			  break;
			case 'day' :
			  $writeOffPeriod = 1;
			  break;
		}
		return $writeOffPeriod;
	}



	protected function exeCurl($method, $url, $header, $sendData = '') {
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $sendData);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		try {
			$response_body = curl_exec($curl);
			$http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
			if ($http_code == 200 || $http_code == 201) {
				$resp_arr = array(
				  'success' => TRUE,
				  'response_body' => $response_body
				);
			}
			else {
				log_message('error', 'HTTP_CODE: ' . $http_code . ', RESPONSE_BODY: ' .$response_body);  //log it for debug
				$resp_arr = array(
				  'success' => FALSE,
				  'message' => 'Processing error, please contact the administrator.'
				);
			}
		}
		catch (Exception $e) {  //it's the network error
			log_message('error', $e->getMessage());  //log it for debug
			$resp_arr = array(
			  'success' => FALSE,
			  'message' => 'Connection error, please contact the administrator.'
			);
		}
		return $resp_arr;
	}

	
	
	public function verifyPayment($payloadArray) {
		global $CI, $payment_setting_array;

		if ($payment_setting_array['expresspay_is_use_signature_for_notification'] || 
			$payloadArray['signature'] == self::computeSignature(array("data" => $payloadArray['data']), 
				$payment_setting_array['expresspay_secret_word_notification'], 'notification')) 
		{
			$resp_array['success'] = TRUE;
			$resp_array['data'] = json_decode($payloadArray['data']);
			
			if($resp_array['data']->CmdType == 7){
				$resp_array['event'] = 'RecurringPaid';
				$resp_array['gateway_identifier'] = 'R'.$resp_array['data']->CustomerId;
			}
			if($resp_array['data']->CmdType == 3){
				if($resp_array['data']->Status == 3 || $resp_array['data']->Status == 6)
				{
					$resp_array['event'] = 'Paid';
					$resp_array['gateway_identifier'] = 'P'.$resp_array['data']->InvoiceNo;
				}
			}
		} 
		else {
			$resp_array['success'] = FALSE;
		}
		return $resp_array;
	}


	
	public function retrieveSubscription($subscriptionID) {
		global $CI;
		$query_subscription = $CI->db->where('gateway_identifier', $subscriptionID)->get('payment_subscription', 1);
		
		if ($query_subscription->num_rows()) { //subscription exists
			$rs_subscription = $query_subscription->row();
			$resp_array['success'] = TRUE;
			$resp_array['status'] = 'active';
			$resp_array['identifier'] = $subscriptionID;
			$resp_array['currentStartTime'] = $rs_subscription->start_time;
			$resp_array['currentEndTime'] = date('Y-m-d H:i:s', strtotime(my_get_payment_item($rs_subscription->item_ids, 'recurring_interval_count') . ' ' . my_get_payment_item($rs_subscription->item_ids, 'recurring_interval'), strtotime($rs_subscription->end_time)));
			$resp_array['cycleStartTime'] = $rs_subscription->created_time;
		}
		else { //new subscription
			$query_log = $CI->db->where('gateway_identifier', $subscriptionID)->get('payment_log', 1);
			
			if ($query_log->num_rows()) {
				$rs_log = $query_log->row(); 
				$resp_array['success'] = TRUE;
				$resp_array['status'] = 'active';
				$resp_array['identifier'] = $subscriptionID;
				$resp_array['currentStartTime'] = my_server_time();
				$resp_array['currentEndTime'] = date('Y-m-d H:i:s', strtotime(my_get_payment_item($rs_log->item_ids, 'recurring_interval_count') . ' ' . my_get_payment_item($rs_log->item_ids, 'recurring_interval'), strtotime(my_server_time())));
				$resp_array['cycleStartTime'] = my_server_time();
			}
			else {
				$resp_array['success'] = FALSE;
			}
		}
		return $resp_array;
	}
	
	
	
	public function cancelSubscription($subscriptionArray) { 
		global $CI, $payment_setting_array;

		$customerId;
		$signatureParams = array(
            "Token" => $payment_setting_array['expresspay_token'],
            "ServiceId" => $payment_setting_array['expresspay_service_id'],
        );
        $signatureParams['Signature'] = self::computeSignature($signatureParams, $payment_setting_array['expresspay_secret_word'], "recurringpayment-unbind");
		unset($signatureParams["Token"]);
		$resp_arr = array();
		$resultsArray = $this->exeCurl('POST', $this->getRestURL('cancelSubscription', $customerId), $this->headerBuilder(), http_build_query($signatureParams));
		if($resultsArray['success'])
		{
			return array('success'=>TRUE);
		}
	}
	
	
	
	public function resumeSubscription($subscriptionArray) {
		global $CI;
		$query = $CI->db->where('gateway_identifier', $subscriptionArray['subscriptionID'])->where('status', 'pending_cancellation')->get('payment_subscription', 1);
		if ($query->num_rows()) {
			$resp_array['success'] = TRUE;
		}
		else {
			$resp_array['success'] = FALSE;
		}
		return $resp_array;
	}
	
	
	
	public function checkoutProcessing($checkoutArray, $itemRS) {

		global $CI, $payment_setting_array;

		if ($checkoutArray['type'] == 'recurring') {  //it's a recurring payment, handle the plan

			$signatureParams = array(
				"Token" => $payment_setting_array['expresspay_token'],
				"ServiceId" => $payment_setting_array['expresspay_service_id'],
				"WriteOffPeriod" => $this->subscriptionCycle($itemRS->recurring_interval),
				"Amount" => $checkoutArray['amount'],
				"Currency" => 933,
				"Info" => $checkoutArray['name'],

				"ReturnUrl" => base_url('webhook/authorized/expresspay/'),
				"FailUrl" => base_url('webhook/authorized/expresspay/'),
				"ReturnType" => "json",
				"Language" => "ru"
			);

			$signatureParams['Signature'] = self::computeSignature($signatureParams, $payment_setting_array['expresspay_secret_word'], "recurringpayment-bind");
			unset($signatureParams["Token"]);
			$resp_arr = array();
			$returnArray = $this->exeCurl('POST', $this->getRestURL('checkoutRecurring'), $this->headerBuilder(), http_build_query($signatureParams));
			if ($returnArray['success']) {
				$paymentDetailArray = json_decode($returnArray['response_body'], 1);
	
				if(!empty($paymentDetailArray["CustomerId"]) && !empty($paymentDetailArray["FormUrl"])){
					$resp_arr = array(
						'success' => TRUE,
						'processingID' => 'R'.$paymentDetailArray["CustomerId"],
						'redirectURL' => $paymentDetailArray["FormUrl"]
					  );
				}
				else{
					log_message('error', 'response_body: ' .$returnArray['response_body']);  //log it for debug
					$resp_arr = array(
						'success' => FALSE,
						'message' => 'Processing error, please contact the administrator.'
					);
				}
				
			}
			else {
				$resp_arr = $returnArray;
			}
			return $resp_arr;
		}
		else {
			$insert_data = array(
				'type' => $checkoutArray['type'],
				'amount' => $checkoutArray['amount'],
				'currency' => $checkoutArray['currency'],
				'description' => $checkoutArray['name'],
				'created_time' => my_server_time()
			);
	
			$CI->db->insert('expresspay_invoice', $insert_data);
			$expresspayInvoice = $CI->db->select_max('id')->get('expresspay_invoice',1)->row();
			$signatureParams = array(
				"Token" => $payment_setting_array['expresspay_token'],
				"ServiceId" => $payment_setting_array['expresspay_service_id'],
				"AccountNo" => $expresspayInvoice->id,
				"Amount" => $checkoutArray['amount'],
				"Currency" => 933,
				"Info" => $checkoutArray['name'],

				"ReturnUrl" => base_url('webhook/authorized/expresspay/'),
				"FailUrl" => base_url('webhook/authorized/expresspay/'),
				"ReturnType" => "json",
				"Language" => "ru"
			);
			if(strlen($checkoutArray['phone']) == 12) $signatureParams['SmsPhone'] = $checkoutArray['phone'];
	
			$signatureParams['Signature'] = self::computeSignature($signatureParams, $payment_setting_array['expresspay_secret_word'], "add-webcard-invoice");
			unset($signatureParams["Token"]);
			$resp_arr = array();
			$returnArray = $this->exeCurl('POST', $this->getRestURL('checkoutInvoices'), $this->headerBuilder(), http_build_query($signatureParams));
			if ($returnArray['success']) {
				$paymentDetailArray = json_decode($returnArray['response_body'], 1);
				if(!empty($paymentDetailArray["ExpressPayInvoiceNo"]) && !empty($paymentDetailArray["InvoiceUrl"])){
					$resp_arr = array(
						'success' => TRUE,
						'processingID' => 'P'.$paymentDetailArray["ExpressPayInvoiceNo"],
						'redirectURL' => $paymentDetailArray["InvoiceUrl"]
					  );
				}
				else{
					log_message('error', 'response_body: ' .$returnArray['response_body']);  //log it for debug
					$resp_arr = array(
						'success' => FALSE,
						'message' => 'Processing error, please contact the administrator.'
					);
				}
			}
			else {
				$resp_arr = $returnArray;
			}
			return $resp_arr;
		}
	}

	

	/**
     * 
     * Формирование цифровой подписи
     * 
     * @param array  $signatureParams Список передаваемых параметров
     * @param string $secretWord      Секретное слово
     * @param string $method          Метод формирования цифровой подписи
     * 
     * @return string $hash           Сформированная цифровая подпись
     * 
     */
    private function computeSignature($signatureParams, $secretWord, $method)
    {
        $normalizedParams = array_change_key_case($signatureParams, CASE_LOWER);
        $mapping = array(
            "add-webcard-invoice" => array(
                "token",
                "serviceid",
                "accountno",
                "expiration",
                "amount",
                "currency",
                "info",
                "returnurl",
                "failurl",
                "language",
                "sessiontimeoutsecs",
                "expirationdate",
                "returntype",
                "returninvoiceurl"
            ),
            "recurringpayment-unbind"         => array(
                "token",
                "serviceid"
            ),
            "recurringpayment-bind"         => array(
				"token",
				"serviceid",
				"writeoffperiod",
				"amount",
				"currency",
				"info",
				"returnurl",
				"failurl",
				"language",
				"returntype"
            ),
            "notification"         => array(
                "data"
            )
        );
        $apiMethod = $mapping[$method];
        $result = "";
        foreach ($apiMethod as $item) {
            $result .= (isset($normalizedParams[$item])) ? $normalizedParams[$item] : '';
        }
        $hash = strtoupper(hash_hmac('sha1', $result, $secretWord));
        return $hash;
    }

}