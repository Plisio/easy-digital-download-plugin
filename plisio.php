<?php

/**
 * Plugin Name:     Easy Digital Downloads Payment Gateway - Plisio
 * Plugin URI:      https://plisio.net
 * Description:     Accept cryptocurrencies via Plisio in your EDD store
 * Version:         1.1.1
 * Author:          Plisio
 * Author URI:      plugins@plisio.net
 * License: MIT License
 **/

namespace PlisioGate;

///Exit if accessed directly

if (!defined('ABSPATH')) {
    exit;
}


final class EDD_Plisio_Payments
{
    private static $instance;
    public $gateway_id = 'plisio';
    public $client = null;
    public $doing_ipn = false;

    private function __construct()
    {
        $this->register();
	    $this->includes();
        $this->filters();
        $this->actions();
    }

    public static function getInstance()
    {
        if (!isset(self::$instance) && !(self::$instance instanceof EDD_Plisio_Payments)) {
            self::$instance = new EDD_Plisio_Payments;
        }

        return self::$instance;
    }

    private function register()
    {
        add_filter('edd_payment_gateways', array($this, 'register_gateway'), 1, 1);
    }

	private function includes()
	{
		require_once __DIR__. '/lib/PlisioClientEdd.php';
	}

    private function filters()
    {
        add_filter('edd_accepted_payment_icons', array($this, 'register_payment_icon'), 10, 1);

        if (is_admin()) {
            add_filter('edd_settings_sections_gateways', array($this, 'register_gateway_section'), 1, 1);
            add_filter('edd_settings_gateways', array($this, 'register_gateway_settings'), 1, 1);
        }
    }

    private function actions()
    {
        add_action('init', array($this, 'process_ipn'));
        add_action('edd_plisio_cc_form', '__return_false');
        add_action('edd_gateway_plisio', array($this, 'process_purchase'));
    }

    public function check_config()
    {
        $is_enabled = edd_is_gateway_active($this->gateway_id);
        if ((!$is_enabled || false === $this->is_setup()) && 'plisio' == edd_get_chosen_gateway()) {
            edd_set_error('plisio_gateway_not_configured',
                __('There is an error with the Plisio Payments configuration.', 'easy-digital-downloads'));
        }
    }

    public function register_gateway($gateways)
    {
        $default_plisio_info = array(
            $this->gateway_id => array(
                'admin_label' => __('Plisio', 'easy-digital-downloads'),
                'checkout_label' => __('Accept cryptocurrencies via Plisio',
                    'easy-digital-downloads'),
                'supports' => array(),
            ),
        );

        $default_plisio_info = apply_filters('edd_register_plisio_gateway', $default_plisio_info);
        $gateways = array_merge($gateways, $default_plisio_info);

        return $gateways;
    }

    public function register_payment_icon($payment_icons)
    {
        $payment_icons[plugins_url('lib/plisio_icon.png', __FILE__)] = "Plisio";

        return $payment_icons;
    }

    public function register_gateway_section($gateway_sections)
    {
        $gateway_sections['plisio'] = __('Plisio Payments', 'easy-digital-downloads');

        return $gateway_sections;
    }

    public function register_gateway_settings($gateway_settings)
    {
        $default_plisio_settings = array(
            'plisio' => array(
                'id' => 'plisio',
                'name' => '<strong>' . __('Plisio Payment Gateway Settings', 'easy-digital-downloads') . '</strong>',
                'type' => 'header',
            ),
            'plisio_secret_key' => array(
                'id' => 'plisio_secret_key',
                'name' => __('Secret Key', 'easy-digital-downloads'),
                'desc' => __('Plisio Secret Key received upon creating store at <a href="https://plisio.net/" target="_blank"> Plisio. </a>',
                    'easy-digital-downloads'),
                'type' => 'text',
                'size' => 'large',
            )
        );

        $default_plisio_settings = apply_filters('edd_default_plisio_settings', $default_plisio_settings);
        $gateway_settings['plisio'] = $default_plisio_settings;

        return $gateway_settings;
    }

    public function process_purchase($purchase_data)
    {
        global $edd_options;

        $api_key = edd_get_option('plisio_secret_key', '');
        $ipn_url = trailingslashit(home_url()) . '?edd-listener=CPIPN';
        $success_url = add_query_arg('payment-confirmation', $this->gateway_id,
            get_permalink($edd_options['success_page']));

        $payment_id = edd_insert_payment($purchase_data);

        $client = new \PlisioClientEdd($api_key);

        $currency = edd_get_currency();
        if ($currency === 'RIAL') {
            $currency = 'IRR';
        }

        $params = [
            'order_name' => 'Order #' . $payment_id,
            'order_number' => $payment_id,
            'source_amount' => $purchase_data['price'],
            'source_currency' => $currency,
            'cancel_url' => edd_get_failed_transaction_uri(),
            'callback_url' => $ipn_url,
            'success_url' => $success_url,
            'email' => $purchase_data['user_email'],
            'plugin' => 'EasyDigitalDownloads',
            'version' => '1.1.0'
        ];

        $response = $client->createTransaction($params);

        if ($response && $response['status'] !== 'error' && !empty($response['data'])) {
            wp_redirect($response['data']['invoice_url']);
            edd_empty_cart();
        } else {
            edd_set_error('plisio_error', implode(',', json_decode($response['data']['message'], true)), 'easy-digital-downloads');
            edd_send_back_to_checkout('?payment-mode=plisio');
        }
    }

    function verifyCallbackData($post, $apiKey): bool
    {
        if (!isset($post['verify_hash'])) {
            return false;
        }

        $verifyHash = $post['verify_hash'];
        unset($post['verify_hash']);
        ksort($post);
        if (isset($post['expire_utc'])){
            $post['expire_utc'] = (string)$post['expire_utc'];
        }
        if (isset($post['tx_urls'])){
            $post['tx_urls'] = html_entity_decode($post['tx_urls']);
        }
        $postString = serialize($post);
        $checkKey = hash_hmac('sha1', $postString, $apiKey);
        if ($checkKey != $verifyHash) {
            return false;
        }

        return true;
    }

    public function process_ipn()
    {
        if (!isset($_POST['status'])) {
            return;
        }

        $api_key = edd_get_option('plisio_secret_key', '');
        $order_id = absint($_POST['order_number']);
        $order_status = sanitize_text_field($_POST['status']);

        if ($this->verifyCallbackData($_POST, $api_key)) {

            $this->doing_ipn = true;

            switch ($order_status) {
                case 'new':
                    edd_update_payment_status($order_id, "pending");
                    break;
                case 'completed':
                case 'mismatch':
                    edd_update_payment_status($order_id, "complete");
                    break;
                case 'expired':
                    edd_update_payment_status($order_id, "abandoned");
                    break;
                case 'cancelled':
                    edd_update_payment_status($order_id, "cancelled");
                    break;
            }
        }
    }
}

function EDD_Plisio()
{
    return EDD_Plisio_Payments::getInstance();
}

EDD_Plisio();

