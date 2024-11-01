<?php
/*
Plugin Name: XPAY Payment GateWay
Description: XPAY Payment Gateway for ecommerce.
Tags: payment, payment gateway, woocommerce, ecommerce, xpay, acquiring, merchant
Version: 1.2
WC requires at least: 3.0 or higher
Tested up to: 6.0.
Stable tag: 1.2
Author: xpay
Author URI: http://xpay.com.ua/
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

define("WC_XPAY_DIR", __DIR__);

add_action('plugins_loaded', 'woocommerce_xpay_init', 0);

function woocommerce_xpay_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    if (isset($_GET['msg']) && !empty($_GET['msg'])) {
        add_action('the_content', 'showXPAYMessage');
    }

    function showXPAYMessage($content)
    {
        return '<div class="' . htmlentities(esc_attr($_GET['type'])) . '">' . htmlentities(urldecode(esc_html($_GET['msg']))) . '</div>' . esc_html($content);
    }

    /**
     * Gateway class
     */
    class WC_xpay extends WC_Payment_Gateway
    {
        protected $url = 'https://mapi.xpay.com.ua/uk/frame/widget/banner-payment';

        const ORDER_APPROVED = 'Approved';
        const ORDER_REFUNDED = 'Refunded';
        const ORDER_SUFFIX = '_woo_xpay_';

        private static $instance = null;

        /**
         * gets the instance via lazy initialization (created on first usage)
         */
        public static function getInstance()
        {
            if (static::$instance === null) {
                static::$instance = new static();
            }

            return static::$instance;
        }


        private function __construct()
        {
            $this->id = 'xpay-gateway';
            $this->method_title = 'XPAY';
            $this->method_description = "Payment Gateway";
            $this->has_fields = false;
            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->settings['title'];
            $this->redirect_page_id = $this->settings['returnUrl'];

            $this->serviceUrl = $this->settings['returnUrl'];

            $this->pid = $this->settings['pid'];
            $this->description = $this->settings['description'];

            $this->msg['message'] = "";
            $this->msg['class'] = "";

            load_plugin_textdomain('wc-xpay-gateway', false, basename(WC_XPAY_DIR) . '/languages/');

            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                /* 2.0.0 */
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            } else {
                /* 1.6.6 */
                add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
            }


            add_action('woocommerce_receipt_' . $this->id, array(&$this, 'receipt_page'));
        }

        public function init_helper_routes()
        {
            // route url: domain.com/wp-json/$namespace/$route
            $namespace = 'xpay-gateway/v1';

            $routePayLink = 'pay-link';
            register_rest_route($namespace, $routePayLink, array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'generate_pay_link']
            ));

            $routePayCallback = 'process-pay';
            register_rest_route($namespace, $routePayCallback, array(
                'methods' => WP_REST_Server::ALLMETHODS,
                'callback' => [$this, 'process_pay']
            ));
        }

        public function generate_pay_link()
        {
            $request = json_decode(file_get_contents("php://input"), true);

            $clientIP = sanitize_text_field($_SERVER['REMOTE_ADDR']);

            $order = new WC_Order($request['order_id']);

            $orderData = $order->get_data();

            $orderSum = intval($order->get_total()) * 100;

            $orderItems = $order->get_items();

            $paymentInfo = [];

            if (isset($this->settings['showPaymentInfo']) && $this->settings['showPaymentInfo'] === 'yes') {
                foreach ($orderItems as $orderItem) {
                    $product = wc_get_product($orderItem->get_data()['product_id']);
                    $paymentInfo[] = ['Caption' => $this->settings['paymentInfoCaption'] === 'short_description' ? $product->get_short_description() : $orderItem->get_data()['name'], 'Value' => $orderItem->get_data()['total']];
                }
            }

            $paymentData = [
                'Email' => $orderData["billing"]["email"],
                'Phone' => str_replace(['+', '-', '(', ')'], '', $orderData["billing"]["phone"]),
                'FirstName' => $orderData["billing"]["first_name"],
                'LastName' => $orderData["billing"]["last_name"],
                'ClientIP' => $clientIP,
                'BrowserFingerprint' => $request['fingerprint'],
                'txn_id' => strval($order->id),
                'Currency' => $order->get_currency(),
                'PaymentInfo' => $paymentInfo,
                'CallBackURL' => $this->settings['serviceUrlForXpay'],
                'Callback' => [
                    'PaySuccess' => [
                        'URL' => $this->getCallbackUrl()
                    ]
                ]
            ];

            $userAcc = isset($this->settings['identifiedBy']) ? ($this->settings['identifiedBy'] === 'phone' ? $paymentData['Phone'] : $paymentData['Email']) : $paymentData['Phone'];

            $jsonData = wp_json_encode($paymentData);

            $gzdata = gzencode($jsonData);
            $data = urlencode(base64_encode($gzdata));
            $url = $this->settings['xpay_url'] . '?pid=' . $this->settings['pid'] . '&acc=' . $userAcc . '&sum=' . $orderSum . '&data=' . $data;

            return new WP_REST_Response(['url' => $url]);
        }

        private function validate_response($stringData, $signature)
        {
            return openssl_verify($stringData, base64_decode($signature), $this->settings['xpay_public_key'], OPENSSL_ALGO_SHA256);
        }

        public function process_pay()
        {
            if (!isset($this->settings['processPay']) || $this->settings['processPay'] !== 'yes') {
                return new WP_REST_Response(['result' => "21", 'txn_id' => null, 'message' => 'Processing pay callback disabled.', 'date_time' => date('YmdHis')]);
            }

            try {
                $requestDataTemp = json_decode(file_get_contents("php://input"), true);


                if (!$requestDataTemp && is_array($_GET)) {
                    $requestDataTemp = $_GET;
                } else {
                    throw new \Exception("Income data not array");
                }

                $requestData = [
                    'txn_id' => !empty($requestDataTemp['txn_id']) ? sanitize_text_field($requestDataTemp['txn_id']) : null,
                    'uuid' => !empty($requestDataTemp['uuid']) ? sanitize_text_field($requestDataTemp['uuid']) : null,
                    'txn_date' => !empty($requestDataTemp['txn_date']) ? sanitize_text_field($requestDataTemp['txn_date']) : null,
                    'sum' => !empty($requestDataTemp['sum']) ? sanitize_text_field($requestDataTemp['sum']) : null,
                    'sign' => !empty($requestDataTemp['sign']) ? sanitize_text_field($requestDataTemp['sign']) : null,
                ];
            } catch (\Exception $exception) {
                return new WP_REST_Response(['result' => "21", 'txn_id' => null, 'message' => 'Not JSON', 'date_time' => date('YmdHis')]);
            }

            foreach ($requestData as $key => $requestDataItem) {
                $requestData[$key] = sanitize_text_field($requestDataItem);
            }

            $isSignValid = $this->validate_response($requestData['txn_id'] . $requestData['uuid'] . $requestData['txn_date'] . $requestData['sum'], $requestData['sign']);

            if ($isSignValid !== 1) {
                return new WP_REST_Response(['result' => "21", 'txn_id' => $requestData['txn_id'], 'message' => 'Signature not valid!', 'date_time' => date('YmdHis')]);
            }

            if ($requestData['command'] !== 'pay') {
                return new WP_REST_Response(['result' => "21", 'txn_id' => $requestData['txn_id'], 'message' => 'Only pay command supported!', 'date_time' => date('YmdHis')]);
            }

            if (!$requestData['txn_id']) {
                return new WP_REST_Response(['result' => "21", 'txn_id' => $requestData['txn_id'], 'message' => 'txn_id is required!', 'date_time' => date('YmdHis')]);
            }


            try {
                $order = new WC_Order($requestData['txn_id']);
            } catch (\Exception $exception) {
                return new WP_REST_Response(['result' => "21", 'txn_id' => $requestData['txn_id'], 'message' => 'Error while getting order by txn_id!', 'date_time' => date('YmdHis')]);
            }


            if (!$order) {
                return new WP_REST_Response(['result' => "21", 'txn_id' => $requestData['txn_id'], 'message' => 'No Orders found for this txn_id!', 'date_time' => date('YmdHis')]);
            }

            $orderStatus = $order->get_status();

            if (!in_array(strtolower($orderStatus), ['processing', 'failed', 'canceled', 'refunded'])) {
                $order->set_status('processing');
                $order->save();

                return new WP_REST_Response(['result' => "10", 'txn_id' => $order->id, 'message' => 'Ok', 'date_time' => date('YmdHis')]);
            }

            return new WP_REST_Response(['result' => "21", 'txn_id' => $requestData['txn_id'], 'message' => 'Something wend wrong...', 'date_time' => date('YmdHis')]);
        }

        function init_form_fields()
        {
            $serviceUrl = home_url() . '/wp-json/xpay-gateway/v1/process-pay';

            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'wc-xpay-gateway'),
                    'type' => 'checkbox',
                    'label' => __('Enable XPAY Payment Module.', 'wc-xpay-gateway'),
                    'default' => 'no',
                    'description' => 'Show in the Payment List as a payment option'),
                'title' => array(
                    'title' => __('Title:', 'wc-xpay-gateway'),
                    'type' => 'text',
                    'default' => __('XPAY Payments', 'wc-xpay-gateway'),
                    'description' => __('This controls the title which the user sees during checkout.', 'wc-xpay-gateway'),
                    'desc_tip' => true
                ),
                'description' => array(
                    'title' => __('Description:', 'wc-xpay-gateway'),
                    'type' => 'textarea',
                    'default' => __('Pay securely through XPAY.', 'wc-xpay-gateway'),
                    'description' => __('This controls the description which the user sees during checkout.', 'wc-xpay-gateway'),
                    'desc_tip' => true
                ),
                'xpay_url' => array(
                    'title' => __('XPAY Service URL', 'wc-xpay-gateway'),
                    'type' => 'text',
                    'description' => __('Given to Partner by XPAY'),
                    'default' => $this->url,
                    'desc_tip' => true
                ),
                'xpay_public_key' => array(
                    'title' => __('XPAY Public Key', 'wc-xpay-gateway'),
                    'type' => 'textarea',
                    'description' => __('Given to Partner by XPAY'),
                    'default' => "",
                    'desc_tip' => true
                ),
                'in_new_window' => array(
                    'title' => __('Open in new window', 'wc-xpay-gateway'),
                    'type' => 'checkbox',
                    'description' => __('Open payment page in new window'),
                    'default' => 'no',
                    'desc_tip' => true
                ),
                'pid' => array(
                    'title' => __('Partner ID', 'wc-xpay-gateway'),
                    'type' => 'text',
                    'description' => __('Given to Partner by XPAY'),
                    'default' => '12345',
                    'desc_tip' => true
                ),
                'identifiedBy' => array(
                    'title' => __('User identified by'),
                    'type' => 'select',
                    'options' => ['phone' => 'Phone', 'email' => 'E-mail'],
                    'default' => 'phone',
                    'description' => __('Which user info will used for identification', 'wc-xpay-gateway'),
                    'desc_tip' => true
                ),
                'returnUrl' => array(
                    'title' => __('Return URL'),
                    'type' => 'select',
                    'options' => $this->xpay_get_pages('Select Page'),
                    'description' => __('URL of success page', 'wc-xpay-gateway'),
                    'desc_tip' => true
                ),
                'returnUrl_m' => array(
                    'title' => __('or manual link'),
                    'type' => 'text',
                    'description' => __('URL of success page', 'wc-xpay-gateway'),
                    'default' => '',
                    'desc_tip' => true
                ),
                'showPaymentInfo' => array(
                    'title' => __('Show payment info', 'wc-xpay-gateway'),
                    'type' => 'checkbox',
                    'default' => 'yes',
                    'description' => 'Show payment details in widget'
                ),
                'paymentInfoCaption' => array(
                    'title' => __('Payment item caption', 'wc-xpay-gateway'),
                    'type' => 'select',
                    'options' => ['name' => 'Product name', 'short_description' => 'Product short description'],
                    'default' => 'name',
                    'description' => 'Order item caption at payment details in widget'
                ),
                'serviceUrlForXpay' => array(
                    'title' => __('Service URL for Pay request', 'wc-xpay-gateway'),
                    'description' => __('Pay request url for XPAY. Default: ' . $serviceUrl, 'wc-xpay-gateway'),
                    'type' => 'text',
                    'default' => $serviceUrl,
                    'label' => $serviceUrl,
                    'desc_tip' => true,
                ),
                'processPay' => array(
                    'title' => __('Should process pay callback from X-pay?', 'wc-xpay-gateway'),
                    'type' => 'checkbox',
                    'default' => 'yes',
                    'description' => 'Activate order processing through pay callback'
                ),
            );
        }

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         **/
        public function admin_options()
        {
            echo '<h3>' . __('XPAY.com', 'wc-xpay-gateway') . '</h3>';
            echo '<p>' . __('Payment gateway') . '</p>';
            echo '<table class="form-table">';
            // Generate the HTML For the settings form.
            $this->generate_settings_html();
            echo '</table>';
        }

        /**
         *  There are no payment fields, but we want to show the description if set.
         **/
        function payment_fields()
        {
            if ($this->description) {
                echo wpautop(wptexturize(esc_html($this->description)));
            }
        }

        /**
         * Receipt Page
         **/
        function receipt_page($order)
        {
            global $woocommerce;

            echo '<p class="x-pay-title">' . esc_html(__('Спасибо за ваш заказ, нажмите на кнопку ниже для перехода к оплате.', 'wc-xpay-gateway')) . '</p>';
            echo $this->generate_xpay_form($order);

            $woocommerce->cart->empty_cart();
        }


        /**
         * @return $this
         */
        public function fillPayForm($data)
        {
            return $this->generateForm($data);
        }


        /**
         * Generate form with fields
         *
         * @param $data
         * @return string
         */
        protected function generateForm($data)
        {
            $form = '<form method="POST" id="form_xpay" action="' . home_url() . '/wp-json/xpay-gateway/v1/pay-link" accept-charset="utf-8">';
            foreach ($data as $k => $v) $form .= $this->printInput($k, $v);
            $button = "
                <div class='xpay-pay-link-wrapper'>
                    <a href='' " . ($this->settings['in_new_window'] === 'yes' ? " target='_blank' " : "") . " id='xpay-pay-link' class='xpay-pay-link loading'>" . __('Завантаження...', 'wc-xpay-gateway') . "</a>
                </div>
                <style>
                    .xpay-pay-link {
                        display: inline-flex !important;
                        padding: 15px 20px;
                        text-decoration: none !important;
                        background: #5F63DE;
                        color: #fff;
                        font-weight: 500;
                    }
                    
                    .xpay-pay-link:hover {
                        opacity: .7;
                    }
                    
                    .xpay-pay-link.loading {
                        opacity: .6;
                        pointer-events: none;
                    }
                    
                    .xpay-pay-link.active {
                        opacity: 1;
                    }
                    
                    .xpay-pay-link-wrapper {
                        display: flex;
                        align-items: center;
                        justify-content: center;
                    }
                </style>
                <script>
                    const script = document.createElement('script');
                    script.onload = function () {
                        FingerprintJS.load().then(function (fp) {
                            fp.get().then(function (result) {
                                window.visitorId = result.visitorId;
                                 setTimeout( getPaymentLink, 200 );
                            })
                        });
                    }
                    script.async = true;
                    script.src = 'https://cdn.jsdelivr.net/npm/'
                      + '@fingerprintjs/fingerprintjs@3/dist/fp.min.js';
                    document.head.appendChild(script);
               
                
                   function getPaymentLink()
                    {
                        let form = document.getElementById('form_xpay'),
                            method = form.getAttribute('method'),
                            url = form.getAttribute('action'),
                            orderId = form.querySelector('input[name=\"order_id\"]').value;
                        
                        let xhr = new XMLHttpRequest();
                        xhr.open(method, url);
                        xhr.setRequestHeader('Content-type', 'application/json; charset=utf-8');
                        
                        xhr.onload = function () {
                            let response = JSON.parse(xhr.response);
                            let xpayLink = document.getElementById('xpay-pay-link');
                               
                            xpayLink.setAttribute('href', response.url);
                            xpayLink.classList.add('active');
                            xpayLink.classList.remove('loading');
                            xpayLink.innerText = '" . __('Сплатити з XPAY', 'wc-xpay-gateway') . "';
                        }
                        
                        let json = JSON.stringify({
                          order_id: orderId,
                          fingerprint: window.visitorId
                        });
                        
                        xhr.send(json);
                    }
                </script>
                ";

            return $form .
                "</form>"
                . $button;
        }

        /**
         * Print inputs in form
         *
         * @param $name
         * @param $val
         * @return string
         */
        protected function printInput($name, $val)
        {
            $str = "";
            if (!is_array($val)) return '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr(htmlspecialchars($val)) . '">' . "\n<br />";
            foreach ($val as $v) $str .= $this->printInput($name . '[]', $v);
            return $str;
        }


        /**
         * Generate xpay button link
         **/
        function generate_xpay_form($order_id)
        {
            $order = new WC_Order($order_id);

            $args = [
                'order_id' => $order->id,
            ];

            return $this->fillPayForm($args);
        }

        /**
         * Process the payment and return the result
         **/
        function process_payment($order_id)
        {
            $order = new WC_Order($order_id);

            if (version_compare(WOOCOMMERCE_VERSION, '2.1.0', '>=')) {
                /* 2.1.0 */
                $checkout_payment_url = $order->get_checkout_payment_url(true);
            } else {
                /* 2.0.0 */
                $checkout_payment_url = get_permalink(get_option('woocommerce_pay_page_id'));
            }

            return array('result' => 'success',
                'redirect' => add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, $checkout_payment_url)));
        }

        /**
         * @param bool $service
         * @return bool|string
         */
        private function getCallbackUrl($service = false)
        {

            $redirect_url = ($this->redirect_page_id == "" || $this->redirect_page_id == 0) ? get_site_url() . "/" : get_permalink($this->redirect_page_id);
            if (!$service) {
                if (
                    isset($this->settings['returnUrl_m']) &&
                    trim($this->settings['returnUrl_m']) !== ''
                ) {
                    return trim($this->settings['returnUrl_m']);
                }
                return $redirect_url;
            }

            return add_query_arg('wc-api', get_class($this), $redirect_url);
        }

        private function getLanguage()
        {
            return substr(get_bloginfo('language'), 0, 2);
        }


        // get all pages
        function xpay_get_pages($title = false, $indent = true)
        {
            $wp_pages = get_pages('sort_column=menu_order');
            $page_list = array();
            if ($title) {
                $page_list[] = $title;
            }
            foreach ($wp_pages as $page) {
                $prefix = '';
                // show indented child pages?
                if ($indent) {
                    $has_parent = $page->post_parent;
                    while ($has_parent) {
                        $prefix .= ' - ';
                        $next_page = get_page($has_parent);
                        $has_parent = $next_page->post_parent;
                    }
                }
                // add to page list array array
                $page_list[$page->ID] = $prefix . $page->post_title;
            }
            return $page_list;
        }
    }


    function woocommerce_gateway_xpay()
    {
        return WC_xpay::getInstance();
    }

    /**
     * Add the Gateway to WooCommerce
     **/
    function woocommerce_add_xpay_gateway($methods)
    {
        $methods[] = woocommerce_gateway_xpay();
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_xpay_gateway');
    add_action('rest_api_init', array(WC_xpay::getInstance(), 'init_helper_routes'));

}
