<?php

/**
 *
 * @author dixite.ru
 * @name AlfaBank
 * @description AlfaBank Payments
 *
 * @property-read string $userName
 * @property-read string $password
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
            throw new waException('Ошибка оплаты. Неверный ключ транзакции.');
        }
        $trans_id = $matches [1];
        
        require_once($this->path.'/lib/models/rsbPlugin.model.php');
        $model = new rsbPluginModel();
        $trans_data = array(
            'trans_id' => $model->escape($trans_id),
            'app_id' => $model->escape($trans_id),//$this->app_id,
            'merchant_id' => $this->merchant_id,
            'order_id' => $order_data['order_id']
        );
        print_r($trans_data);
        $model->insert($trans_data);
        
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
            try {
                $wamodel = new waModel();
                $sql = "SELECT * FROM `shop_plugin` WHERE `plugin`='rsb'";
                $plugin = $wamodel->query($sql)->fetch();
                if ($plugin) {
                    $this->app_id = 'shop';
                    $this->merchant_id = $plugin['id'];
                }
            } catch (Exception $e) {
                //$error = $e->getMessage();
            }
            $this->trans_id = $request['trans_id'];
        } elseif (!empty($request['app_id'])) {
            $this->app_id = $request['app_id'];
        }

        return parent::callbackInit($request);
    }

    protected function callbackHandler($request)
    {

        if (!$this->order_id) {
            throw new waPaymentException('Ошибка. Не верный номер заказа');
        }

        if ($this->sandbox) {
            $url = $this->test_url . 'getOrderStatus.do';
        } else {
            $url = $this->url . 'getOrderStatus.do';
        }


        $params = array('userName' => $this->userName, 'password' => $this->password,
            'orderId' => $this->order_id, );
        $request = $this->sendData($url, $params);
        $transaction_data = $this->formalizeData($request);


        if ($request['ErrorCode'] == 0 && $request['OrderStatus'] == 2) {
            $message = $request['ErrorMessage'];
            $app_payment_method = self::CALLBACK_PAYMENT;
            $transaction_data['state'] = self::STATE_CAPTURED;
            $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data);
        } else {
            switch ($request['ErrorCode']) {

                case 2:
                    $message = 'Заказ отклонен по причине ошибки в реквизитах платежа.';
                    $app_payment_method = self::CALLBACK_DECLINE;
                    $transaction_data['state'] = self::STATE_DECLINED;
                    $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
                    break;
                case 5:
                    $message = 'Ошибка значения параметра запроса.';
                    $app_payment_method = self::CALLBACK_DECLINE;
                    $transaction_data['state'] = self::STATE_DECLINED;
                    $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
                    break;
                case 6:
                    $message = 'Незарегистрированный OrderId.';
                    $app_payment_method = self::CALLBACK_DECLINE;
                    $transaction_data['state'] = self::STATE_DECLINED;
                    $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
                    break;
                default:
                    $message = $request['ErrorMessage'];
                    $app_payment_method = self::CALLBACK_DECLINE;
                    $transaction_data['state'] = self::STATE_DECLINED;
                    $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
                    break;
            }
        }


        $transaction_data = $this->saveTransaction($transaction_data, $request);
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
        $currency_id = $transaction_raw_data['currency'];

        $transaction_data = parent::formalizeData($transaction_raw_data);
        $transaction_data['native_id'] = $this->order_id;
        $transaction_data['order_id'] = $transaction_raw_data['OrderNumber'];
        $transaction_data['currency_id'] = $this->currency[$currency_id];
        $transaction_data['amount'] = $transaction_raw_data['Amount'];
        //$transaction_data['view_data'] = 'view_data';


        return $transaction_data;
    }

}
