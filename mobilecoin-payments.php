<?php
// Copyright (c) 2021 MobileCoin Inc.

/*
 * Plugin Name: MobileCoin Payments
 * Plugin URI: https://mobilecoin.com/
 * Description: This plugin adds the MobileCoin Payment Method on WooCommerce Checkouts.
 * Author: MobileCoin
 * Version: 1.0.0
 * Author https://mobilecoin.com/
 * Text Domain: mobilecoin_payments
*/

/** Check if the WooCommerce plugin is active */
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

add_filter('woocommerce_payment_gateways', function ( $methods ) {
    $mob_payments_gateway = new MobileCoin_Payments_Gateway_WC;

    /**
     * Required fields for front-end display
     * Public API Key, Endpoint URL, Title
     */
    if(
        (
                !empty( $mob_payments_gateway->public_api_key ) &&
                !empty( $mob_payments_gateway->endpoint_url ) &&
                !empty( $mob_payments_gateway->title ) &&
                !is_admin()
        ) || (
                is_admin()
        )
    ) {
        $methods[] = $mob_payments_gateway;
    }
    return $methods;
});

add_action('plugins_loaded', function () {
    if (class_exists('WC_Payment_Gateway')) {
        class MobileCoin_Payments_Gateway_WC extends WC_Payment_Gateway
        {
            public function __construct()
            {
                $this->id = 'mobilecoin_payments';
                $this->icon = apply_filters('woocommerce_mobilecoin_url', plugins_url('/images/mobilecoin-symbol.svg', __FILE__));
                $this->has_fields = FALSE;

                $this->method_title = __('MobileCoin Payments', 'mobilecoin_payments');
                $this->method_description = __('Pay via MobileCoin Payments', 'mobilecoin_payments');

                $this->title = $this->get_option('title');
                $this->description = $this->get_option('description');
                $this->instructions = $this->get_option('instructions');
                $this->public_api_key = $this->get_option('public_api_key');
                $this->secret_api_key = $this->get_option('secret_api_key');
                $this->endpoint_url = $this->get_option('endpoint_url');

                /**
                 * Add Admin Fields
                 * and initialize them
                 */
                $this->init_form_fields();
                $this->init_settings();

                // Actions and Hooks
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
                add_action('woocommerce_thank_you_' . $this->id, array($this, 'thank_you_page'));

                /**
                 * Register Webhook for Completed Payment
                 * {webhook name} is mobilecoin-payment-complete
                 */
                add_action('woocommerce_api_mobilecoin-payment-complete', array($this, 'mobilecoin_payment_complete_cb'));
                
                /**
                 * Add Order Custom Details
                 * https://rudrastyh.com/woocommerce/customize-order-details.html
                 */
                add_action('woocommerce_admin_order_data_after_order_details', array($this, 'editable_order_meta_general'));
            }

            public function init_form_fields()
            {
                $this->form_fields = apply_filters('woo_mobile_payments_fields',
                    array(
                        'enabled' => array(
                            'title' => __('Enable/Disable', 'mobilecoin_payments'),
                            'type' => 'checkbox',
                            'label' => __('Enable or Disable MobileCoin Payments', 'mobilecoin_payments'),
                            'default' => 'no'
                        ),
                        'public_api_key' => array(
                            'title' => __('Public Store API key', 'mobilecoin_payments'),
                            'type' => 'password',
                            'description' => __('Associated with a specific Store, this API key can be used to perform non-sensitive operations such as creating new Payment Intents', 'mobilecoin_payments'),
                            'default' => '',
                            'desc_tip' => true
                        ),
                        'secret_api_key' => array(
                            'title' => __('Secret Store API key', 'mobilecoin_payments'),
                            'type' => 'password',
                            'description' => __('Associated with a specific Store and limited to a list of specific scopes, this API key can be used to perform sensitive operations such as listing payment intents.', 'mobilecoin_payments'),
                            'default' => '',
                            'desc_tip' => true
                        ),
                        'endpoint_url' => array(
                            'title' => __('Endpoint URL', 'mobilecoin_payments'),
                            'type' => 'text',
                            'description' => __('Insert here the endpoint URL where the API will make calls', 'mobilecoin_payments'),
                            'default' => 'https://payments.mobilecoin.com/api/hosted-payments-page/',
                            'desc_tip' => true
                        ),
                        'title' => array(
                            'title' => __('MobileCoin Payments Gateway', 'mobilecoin_payments'),
                            'type' => 'text',
                            'description' => __('Add a new title for the Mobile Coin Payments Gateway that customers will see in the checkout', 'mobilecoin_payments'),
                            'default' => 'MobileCoin Payments Gateway', 'mobilecoin_payments',
                            'desc_tip' => true
                        ),
                        'description' => array(
                            'title' => __('MobileCoin Payments Gateway Description', 'mobilecoin_payments'),
                            'type' => 'textarea',
                            'description' => __('Add a new description for the Mobile Coin Payments Gateway that customers will see in the checkout', 'mobilecoin_payments'),
                            'default' => 'Please remit your payment to the shop to allow for the delivery to be made', 'mobilecoin_payments',
                            'desc_tip' => true
                        ),
                        'instructions' => array(
                            'title' => __('Instructions', 'mobilecoin_payments'),
                            'type' => 'textarea',
                            'description' => __('Instructions that will be added to the thank you page and order email', 'mobilecoin_payments'),
                            'default' => '',
                            'desc_tip' => true
                        ),

                    ));
            }

            public function editable_order_meta_general($order)
            {
                /*
                 * get all the meta data values we need
                 */
                $api_response = get_post_meta($order->get_id(), 'api_response', true);
                ?>
                <br class="clear"/>

                <div class="">
                    <h3><strong><?php echo __('Meta: API JSON Response:', ''); ?></strong></h3>
                    <?php
                    // we show the rest fields in this column only if this order is marked as a gift
                    if ($api_response) :
                        ?>
                        <pre style="word-break: break-all; white-space: pre-wrap; max-height: 500px; overflow-y: scroll;">
							<?php echo $api_response ?>
						</pre>
                    <?php
                    endif;
                    ?>
                </div>

                <?php
            }

            public function process_payment($order_id)
            {
                /**
                 * Order Methods
                 * https://www.businessbloomer.com/woocommerce-easily-get-order-info-total-items-etc-from-order-object/
                 */
                $site_url = get_site_url();
                $order = wc_get_order($order_id);

                $this->order_id = $order_id;

                /** API callback for Fetching the MobileCoin Payment Page */
                $data_array = array(
                    "payment" => array(
                        "description" => $site_url . " Order #" . $order_id,
                        "fiat_amount" => $order->get_total(),
                        "fiat_amount_currency" => get_woocommerce_currency(),
                        "expires_at" => time() + (10 * 60) // 10 mins; 60 secs
                    ),
                    "success_url" => $site_url . "/wc-api/mobilecoin-payment-complete?order_id=" . $order_id . '&order_key=' . $order->get_order_key(),
                    "cancel_url" => $site_url . "/checkout/"
                );

                $public_api_key = $this->public_api_key;
                $secret_api_key = $this->secret_api_key;
                $endpoint_url = $this->endpoint_url;

                $data_json = json_encode($data_array);

                $curl = curl_init();

                curl_setopt_array($curl, array(
                    CURLOPT_URL => $endpoint_url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => $data_json,
                    CURLOPT_HTTPHEADER => array(
                        'Authorization: Api-Key ' . $public_api_key,
                        'Content-Type: application/json'
                    ),
                ));

                $response = curl_exec($curl);

                curl_close($curl);

                $response_decoded = json_decode($response);

                if (isset($response_decoded->urls->payment_page)) {
                    $payment_page_url = $response_decoded->urls->payment_page;

                    update_post_meta($order_id, 'api_response', json_encode($response_decoded, JSON_PRETTY_PRINT));

                    return array(
                        'result' => 'success',
                        'redirect' => $payment_page_url
                    );
                } else {
                    wc_add_notice(__('Payment error:', 'woothemes') . '<p>' . $response . '</p>', 'error');
                }

                return null;
            }

            public function mobilecoin_payment_complete_cb()
            {
                $order = wc_get_order($_GET['order_id']);
                $order_key_return = $_GET['order_key'];

                if ($order_key_return == $order->get_order_key()) {
                    $order->update_status('wc-completed', __('MobileCoin Payment Completed', 'mobilecoin_payments'));

                    // This also takes care of reducing the stock
                    $order->payment_complete();

                    WC()->cart->empty_cart();

                    return wp_redirect($this->get_return_url($order));
                }

                return null;
            }

            public function thank_you_page()
            {
                if ($this->instructions) {
                    echo wpautop($this->instructions);
                }
            }
        }
    }
});
