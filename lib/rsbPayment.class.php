<?php

/**
 *
 * @author wa-plugins.ru
 * @name RSB
 * @description RSB Payments
 *
 * @property-read string $sandbox
 */
  
class rsbPayment extends waPayment implements waIPayment
{

    private $MerchantHandler = 'https://securepay.rsb.ru:9443/ecomm2/MerchantHandler';
    private $MerchantHandlerTest = 'https://testsecurepay.rsb.ru:9443/ecomm2/MerchantHandler';
    private $ClientHandler = 'https://securepay.rsb.ru/ecomm2/ClientHandler';
    private $ClientHandlerTest = 'https://testsecurepay.rsb.ru/ecomm2/ClientHandler';
    
    private $order_id;
    private $currency = array(
        '643' => 'RUB', 
        '840' => 'USD', 
        '978' => 'EUR', 
        '980' => 'UAH'
    );

    public function allowedCurrency()
    {
        return $this->currency;
    }
    
    public function saveSettings($settings = array()){
        parent::saveSettings();
        
        //$files = waRequest::file('pemFile');
        //print_r($files);exit;
    }

    public function payment($payment_form_data, $order_data, $auto_submit = false)
    {          
        if (!in_array($order_data['currency_id'], $this->allowedCurrency())) {
            throw new waException('Ошибка оплаты. Валюта не поддерживается');
        }

        $currency_id = $order_data['currency_id'];
        $currency = array_search($currency_id, $this->currency);


        $data = array(
            'command' => 'v', 
            'amount' => floor($order_data['amount'] * 100),
            'currency' => $currency, 
            'client_ip_addr' => waRequest::server('REMOTE_ADDR'),
            'description' => "Оплата заказа: №" . $order_data['order_id'], 
            'language' => 'ru', 
            'order_id' => $order_data['order_id']
            );
            
        if($this->sandbox) {
            $url = $this->MerchantHandlerTest;  
        } else {
            $url = $this->MerchantHandler;
        }
        
        $response = $this->sendData($url, $data); 
        
        if(!preg_match("#^TRANSACTION_ID: (.*?)$#is", $response, $matches)){
            throw new waException('Ошибка оплаты. Неверный ключ транзакции. '.$response);
        }
        $trans_id = $matches[1];


        $transaction_data = array(
            'native_id' => $trans_id,
            'state' => self::STATE_AUTH,
            'amount' => $data['amount'],
            'currency_id' => $data['currency'],
            'order_id' => $order_data['order_id'],
            'customer_id' => $order_data['customer_id'],
        );
        $this->saveTransaction($transaction_data);

        if($this->sandbox) {
            $formUrl = $this->ClientHandlerTest;  
        } else {
            $formUrl = $this->ClientHandler;
        }
        
        $formUrl .= '?trans_id=' . $trans_id;

        $view = wa()->getView();
        $view->assign('form_url', $formUrl);
        $view->assign('auto_submit', $auto_submit);
        return $view->fetch($this->path . '/templates/payment.html');
    }

    protected function callbackInit($request)
    {
        if (!empty($request['trans_id'])) {
            $transaction_model = new waTransactionModel();
            $trans = $transaction_model->getByField('native_id', $request['trans_id']);
            if($trans) {
                $this->app_id = $trans['app_id'];
                $this->merchant_id = $trans['merchant_id'];
                $this->order_id = $trans['order_id'];
            }
        }
        return parent::callbackInit($request);
    }

    protected function callbackHandler($request)
    {

        if (!$this->order_id) {
            throw new waPaymentException('Ошибка. Не верный номер заказа');
        }
        
        $data = array(
            'command' => 'c', 
            'trans_id' => $request['trans_id'],
            'client_ip_addr' => waRequest::server('REMOTE_ADDR'),
            );
            
        if($this->sandbox) {
            $url = $this->MerchantHandlerTest;  
        } else {
            $url = $this->MerchantHandler;
        }
        
        $response = $this->sendData($url, $data);
        $transaction_raw_data = $this->formalizeData($response);
        $transaction_data = array();
        $transaction_data['native_id'] = $request['trans_id'];
        $transaction_data['order_id'] = $this->order_id;

        if (isset($transaction_data['RESULT_CODE']) && $transaction_data['RESULT_CODE'] == '000') {
            $message = 'Оплата успешно произведена';
            $app_payment_method = self::CALLBACK_PAYMENT;
            $transaction_data['state'] = self::STATE_CAPTURED;
            $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data);
        } else {
            if (isset($transaction_data['RESULT_CODE'])) {
                $message = 'Отказ. Код ' . $transaction_data['RESULT_CODE'];
            } elseif (isset($transaction_data['error'])) {
                $message = 'Ошибка. ' . $transaction_data['error'];
            } else {
                $message = ' Неизвестная ошибка. ' . $response;
            }
            $app_payment_method = self::CALLBACK_DECLINE;
            $transaction_data['state'] = self::STATE_DECLINED;
            $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
        } 


        $transaction_data = $this->saveTransaction($transaction_data, $transaction_raw_data);
        $result = $this->execAppCallback($app_payment_method, $transaction_data);

        self::addTransactionData($transaction_data['id'], $result);

        return array('template' => $this->path . '/templates/callback.html', 'back_url' =>
            $url, 'message' => $message, );
    }

    private function sendData($url, $data)
    {

        if (!extension_loaded('curl') || !function_exists('curl_init')) {
            throw new waException('PHP расширение cURL не доступно');
        }

        if (!($ch = curl_init())) {
            throw new waException('curl init error');
        }

        if (curl_errno($ch) != 0) {
            throw new waException('Ошибка инициализации curl: ' . curl_errno($ch));
        }

        $postdata = array();

        foreach ($data as $name => $value) {
            $postdata[] = "$name=$value";
        }

        $post = implode('&', $postdata);
        
        $FILE_pem = $this->path . '/cert/9294039987.pem';
        $FILE_key = $this->path . '/cert/9294039987.key';
        $FILE_chain = $this->path . '/cert/chain-ecomm-ca-root-ca.crt';
        
        $ch = curl_init($url);
        @curl_setopt($ch, CURLOPT_HEADER, false);
        @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        @curl_setopt($ch, CURLOPT_TIMEOUT_MS, 3000);
        @curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 3000);
        @curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        @curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        @curl_setopt($ch, CURLOPT_SSLCERT, $FILE_pem);
        @curl_setopt($ch, CURLOPT_SSLKEY, $FILE_key);
        @curl_setopt($ch, CURLOPT_CAINFO, $FILE_chain);
        @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        @curl_setopt($ch, CURLOPT_POST, true);
        @curl_setopt($ch, CURLOPT_POSTFIELDS, $post);


        $response = @curl_exec($ch);
        $app_error = null;
        if (curl_errno($ch) != 0) {
            $app_error = 'Ошибка curl: ' . curl_error($ch);
        }
        curl_close($ch);
        if ($app_error) {
            throw new waException($app_error);
        }
        if (empty($response)) {
            throw new waException('Пустой ответ от сервера');
        }

        return $response;
    }

    protected function formalizeData($transaction_raw_data)
    {
        $transaction_data = parent::formalizeData($transaction_raw_data);
        $lines = explode("\n",$transaction_raw_data);
        $transaction_data = array();
        foreach($lines as $line) {
            if(trim($line)) {
                list($param, $value) = explode(": ",$line);
                $transaction_data[trim($param)] = trim($value);
            }
        }

        return $transaction_data;
    }

}
