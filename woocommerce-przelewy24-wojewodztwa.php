<?php
/**
 * Plugin Name: WooCommerce Przelewy24 – Multi‑Account (województwa)
 * Plugin URI:  https://github.com/wojtaszek104/wc-przelewy24-voivodeship
 * Description: Przełącza Merchant ID / CRC / API Key według województwa działa z woocomerce i przelewy24.
 * Version:     1.3.6
 * Author:      Wojciech Wiercioch
 * 
 * 
 * License:     GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * 
 * Text Domain: wc-p24-voivodeship
 * 
 *  * © 2024-2025 Wojciech Wiercioch.  Distributed under the GPL v3 license – see LICENSE file.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', function () {
    if (!class_exists('\WC_P24\Gateways\Online_Payments\Gateway')) {
        return;
    }

    if (!class_exists('P24_Base_Gateway', false)) {
        class_alias('\WC_P24\Gateways\Online_Payments\Gateway', 'P24_Base_Gateway');
    }

    class WC_Gateway_Przelewy24_Voivodeship extends P24_Base_Gateway
    {
        public static $current_credentials = null;

        public function __construct()
        {
            parent::__construct();

            $this->id = defined('\WC_P24\Core::MAIN_METHOD')
                ? \WC_P24\Core::MAIN_METHOD
                : 'przelewy24_online_payments';

            $this->method_title = __('Przelewy24 (województwa)', 'wc-p24-voivodeship');
            $this->method_description = __('Osobne Merchant ID / CRC / API Key dla każdego województwa.', 'wc-p24-voivodeship');

            $this->init_form_fields();
            $this->init_settings();
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action( 'woocommerce_api_przelewy24',             [ $this, 'before_callback' ], 1 );
            add_action( 'woocommerce_api_przelewy24_transaction', [ $this, 'before_callback' ], 1 );
        }

        public function set_current_credentials($order_id)
        {
            $log = wc_get_logger();
            $order = wc_get_order($order_id);
            if (!$order) {
                $log->warning("Nie można pobrać zamówienia dla order_id: $order_id", ['source' => 'wc-p24-voivodeship']);
                return;
            }

            $state = strtoupper(preg_replace('/^PL-/', '', $order->get_billing_state() ?: $order->get_shipping_state()));
            $merchant_id = $this->get_option("merchant_id_$state", $this->merchant_id);
            $api_key = $this->get_option("api_key_$state", $this->settings['rest_api_key'] ?? get_option('p24_rest_api_key', ''));

            self::$current_credentials = [
                'merchant_id' => $merchant_id,
                'api_key' => $api_key,
            ];

            //$log->debug("Ustawiono dane uwierzytelniające dla województwa $state: MID=$merchant_id, API=" . substr($api_key, 0, 4) . '...', ['source' => 'wc-p24-voivodeship']);
        }

        public function init_form_fields()
        {
            parent::init_form_fields();

            $this->form_fields['debug'] = [
                'title'   => __('Loguj żądania', 'wc-p24-voivodeship'),
                'type'    => 'checkbox',
                'label'   => __('Pisz logi do WooCommerce → Status → Logi', 'wc-p24-voivodeship'),
                'default' => 'no',
            ];
            $this->form_fields['header'] = [
                'type'  => 'title',
                'title' => __('Konta przypisane do województw', 'wc-p24-voivodeship'),
            ];

            foreach ($this->get_polish_states() as $c => $l) {
                $this->form_fields["title_$c"] = [
                    'type'  => 'title',
                    'title' => sprintf('%s [%s]', $l, $c),
                ];

                $pref = "[$c] $l — ";
                $this->form_fields["merchant_id_$c"] = [
                    'title' => $pref . __('ID Sprzedawcy', 'wc-p24-voivodeship'),
                    'type'  => 'text',
                ];
                $this->form_fields["crc_key_$c"] = [
                    'title' => $pref . 'CRC',
                    'type'  => 'text',
                ];
                $this->form_fields["api_key_$c"] = [
                    'title' => $pref . 'API Key',
                    'type'  => 'text',
                ];
            }
        }

        public function generate_settings_html($form_fields = [], $echo = true)
        {
            if (empty($form_fields)) {
                $form_fields = $this->get_form_fields();
            }

            ob_start();

            $parent_fields = array_filter($form_fields, function ($key) {
                return !in_array($key, ['debug', 'header']) && strpos($key, 'title_') !== 0 && strpos($key, 'merchant_id_') !== 0 && strpos($key, 'crc_key_') !== 0 && strpos($key, 'api_key_') !== 0;
            }, ARRAY_FILTER_USE_KEY);
            if (!empty($parent_fields)) {
                woocommerce_admin_fields($parent_fields);
            }

            $custom_fields = array_filter($form_fields, function ($key) {
                return in_array($key, ['debug', 'header']) || strpos($key, 'title_') === 0 || strpos($key, 'merchant_id_') === 0 || strpos($key, 'crc_key_') === 0 || strpos($key, 'api_key_') === 0;
            }, ARRAY_FILTER_USE_KEY);
            if (!empty($custom_fields)) {
                woocommerce_admin_fields($custom_fields);
            }

            $html = ob_get_clean();

            if ($echo) {
                echo $html;
                return '';
            }

            return $html;
        }

        private function get_polish_states(): array
        {
            return [
                'DS' => 'Dolnośląskie', 'KP' => 'Kujawsko‑Pomorskie', 'LU' => 'Lubelskie',
                'LB' => 'Lubuskie', 'LD' => 'Łódzkie', 'MA' => 'Małopolskie', 'MZ' => 'Mazowieckie',
                'OP' => 'Opolskie', 'PK' => 'Podkarpackie', 'PD' => 'Podlaskie', 'PM' => 'Pomorskie',
                'SL' => 'Śląskie', 'SK' => 'Świętokrzyskie', 'WN' => 'Warmińsko‑Mazurskie',
                'WP' => 'Wielkopolskie', 'ZP' => 'Zachodniopomorskie'
            ];
        }

        public function process_payment( $order_id ): array {

            $log   = wc_get_logger();
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                $log->error( "Nie można pobrać zamówienia $order_id", [ 'source' => 'wc-p24-voivodeship' ] );
                return [ 'result' => 'failure', 'redirect' => '' ];
            }

            $cfg = [
                'mid' => trim( $this->merchant_id ),
                'crc' => trim( $this->crc ),
                'api' => trim( $this->settings['rest_api_key'] ?? get_option( 'p24_rest_api_key', '' ) ),
            ];

            $state = strtoupper( preg_replace( '/^PL-/', '', $order->get_billing_state() ?: $order->get_shipping_state() ) );
            //$log->debug( "Voivodeship state: $state", [ 'source' => 'wc-p24-voivodeship' ] );

            if ( array_key_exists( $state, $this->get_polish_states() ) ) {
                $mid = trim( $this->get_option( "merchant_id_$state" ) );
                $crc = trim( $this->get_option( "crc_key_$state" ) );
                $api = trim( $this->get_option( "api_key_$state" ) );

                if ( $mid && $crc && $api ) {
                    $cfg = [ 'mid' => $mid, 'crc' => $crc, 'api' => $api ];
                    // $log->debug( "Config for $state: MID=$mid, CRC=" . substr( $crc, 0, 4 ) . '…, API=' .
                    //             substr( $api, 0, 4 ) . '…', [ 'source' => 'wc-p24-voivodeship' ] );
                } else {
                    wc_add_notice( __( 'Błąd: Brak konfiguracji płatności dla wybranego województwa.', 'wc-p24-voivodeship' ), 'error' );
                    return [ 'result' => 'failure', 'redirect' => '' ];
                }
            }

            self::$current_credentials = [
                'merchant_id' => $cfg['mid'],
                'api_key'     => $cfg['api'],
            ];

            update_post_meta( $order_id, '_p24_mid',  $cfg['mid'] );
            update_post_meta( $order_id, '_p24_crc',  $cfg['crc'] );
            update_post_meta( $order_id, '_p24_api',  $cfg['api'] );
            // $log->debug( "Meta saved for $order_id: MID={$cfg['mid']}, CRC=" . substr( $cfg['crc'], 0, 4 ) .
            //             '…, API=' . substr( $cfg['api'], 0, 4 ) . '…', [ 'source' => 'wc-p24-voivodeship' ] );

            $this->sandbox      = false;
            $this->merchant_id  = $this->merchantId = $cfg['mid'];
            $this->crc          = $cfg['crc'];
            $this->rest_api_key = $cfg['api'];
            $this->pos_id = $this->posId = $cfg['mid'];

            $this->settings = array_merge( $this->settings, [
                'merchantId'   => $cfg['mid'],
                'posId'        => $cfg['mid'],
                'crc'          => $cfg['crc'],
                'rest_api_key' => $cfg['api'],
                'report_key'   => $cfg['api'],
            ] );

            try {
                $conf = new \WC_P24\Models\Configuration( false ); // false = produkcja
                $conf->set_config(
                    (int) $cfg['mid'],   // merchantId
                    $cfg['crc'],         // crc
                    (int) $cfg['mid'],   // posId
                    $cfg['api']          // API key
                );
                \WC_P24\Config::get_instance()->set_config( $conf );
            } catch ( \Throwable $e ) {
                $log->error( 'SDK set_config() exception: ' . $e->getMessage(), [ 'source' => 'wc-p24-voivodeship' ] );
                wc_add_notice( __( 'Błąd inicjalizacji Przelewy24: ', 'wc-p24-voivodeship' ) . $e->getMessage(), 'error' );
                return [ 'result' => 'failure', 'redirect' => '' ];
            }

            if ( property_exists( $this, 'p24_api' ) && method_exists( $this->p24_api, 'set_credentials' ) ) {
                try {
                    $this->p24_api->set_credentials( (int) $cfg['mid'], (int) $cfg['mid'], $cfg['api'], $cfg['crc'] );
                } catch ( \Throwable $e ) {
                    $log->error( 'set_credentials() exception: ' . $e->getMessage(), [ 'source' => 'wc-p24-voivodeship' ] );
                    wc_add_notice( __( 'Błąd inicjalizacji Przelewy24: ', 'wc-p24-voivodeship' ) . $e->getMessage(), 'error' );
                    return [ 'result' => 'failure', 'redirect' => '' ];
                }
            }

            // $log->debug( 'Gateway credentials in use: MID=' . $this->merchant_id .
            //             ', CRC=' . substr( $this->crc, 0, 4 ) . '…, API=' . substr( $this->rest_api_key, 0, 4 ) . '…',
            //             [ 'source' => 'wc-p24-voivodeship' ] );

            try {
                $result = $this->process_on_paywall( $order_id, null, isset( $_POST['regulation'] ) );
                //$log->debug( 'Payment result: ' . print_r( $result, true ), [ 'source' => 'wc-p24-voivodeship' ] );
                return $result;
            } catch ( \Throwable $e ) {
                $log->error( 'Payment processing failed: ' . $e->getMessage(), [ 'source' => 'wc-p24-voivodeship' ] );
                wc_add_notice( __( 'Błąd podczas przetwarzania płatności: ', 'wc-p24-voivodeship' ) . $e->getMessage(), 'error' );
                return [ 'result' => 'failure', 'redirect' => '' ];
            }
        }

        public function process_on_paywall($order_id, ?int $method = null, $accept_rules = false): array
        {
            //$log = wc_get_logger();

            $transaction = new \WC_P24\Models\Transaction(
                $order_id,
                $method ?? $this->method,
                $accept_rules
            );

            try {
                $cfgObj = \WC_P24\Config::get_instance();
                // $log->debug(
                //     'Config snapshot: MID=' . $cfgObj->get_merchant_id() .
                //     ', CRC=' . $cfgObj->get_crc_key(),
                //     ['source' => 'wc-p24-voivodeship']
                // );

                if (method_exists($transaction, 'get_transaction_data')) {
                    $payload = $transaction->get_transaction_data();
                    // $log->debug(
                    //     'Request body preview: ' . json_encode($payload, JSON_UNESCAPED_UNICODE),
                    //     ['source' => 'wc-p24-voivodeship']
                    // );
                } else {
                    //$log->debug('Request body preview: (brak metody get_transaction_data)', ['source' => 'wc-p24-voivodeship']);
                }
            } catch (\Throwable $e) {
                $log->warning('Podgląd payloadu nieudany: ' . $e->getMessage(), ['source' => 'wc-p24-voivodeship']);
            }
            //$log->debug("test: 12340", ['source' => 'wc-p24-voivodeship']);
            //$log->debug('transaction: ', ['source' => 'wc-p24-voivodeship']);
            $transaction->register();
            if ( method_exists( $transaction, 'get_session_id' ) ) {
                update_post_meta( $order_id, '_p24_session', $transaction->get_session_id() );
            }
            return ['result' => 'success', 'redirect' => $transaction->get_paywall_url()];
        }

    public function load_order_credentials($order_id) {
        //$log = wc_get_logger();
        
        $mid = get_post_meta($order_id, '_p24_mid', true);
        $crc = get_post_meta($order_id, '_p24_crc', true);
        $api = get_post_meta($order_id, '_p24_api', true);
        
        // $log->debug(
        //     "Odczytano meta dane dla zamówienia $order_id: MID=" . ($mid ?: 'brak') . 
        //     ", CRC=" . ($crc ? substr($crc, 0, 4) . '...' : 'brak') . 
        //     ", API=" . ($api ? substr($api, 0, 4) . '...' : 'brak'),
        //     ['source' => 'wc-p24-voivodeship']
        // );
        
        if (!$mid || !$crc || !$api) {
           // $log->error("Brak kompletnych danych uwierzytelniających dla zamówienia $order_id", ['source' => 'wc-p24-voivodeship']);
            return false;
        }

        $this->merchant_id = $this->merchantId = $mid;
        $this->crc = $crc;
        $this->rest_api_key = $api;
        $this->pos_id = $this->posId = $mid;

        $sdk_config = \WC_P24\Config::get_instance();
        $new_config = new \WC_P24\Models\Configuration($this->sandbox);
        $new_config->set_config((int) $mid, $crc, (int) $mid, $api);
        $sdk_config->set_config($new_config);

        self::$current_credentials = [
            'merchant_id' => $mid,
            'api_key' => $api,
        ];

        // $log->debug(
        //     "Załadowno dane uwierzytelniające dla zamówienia $order_id: MID=$mid, API=" . substr($api, 0, 4) . '...',
        //     ['source' => 'wc-p24-voivodeship']
        // );
        return true;
    }

    public function before_callback() {
        //$log = wc_get_logger();
        $order_id = 0;

        if ( isset( $_GET['order-id'] ) ) {
            $order_id = (int) $_GET['order-id'];
        } elseif ( isset( $_GET['session_id'] ) ) {
            $order_id = (int) $_GET['session_id'];
        } else {
            $body = file_get_contents( 'php://input' );
            if ( $body ) {
                $json = json_decode( $body, true );
                if ( isset( $json['sessionId'] ) ) {
                    $orders = wc_get_orders( [
                        'limit'      => 1,
                        'meta_key'   => '_p24_session',
                        'meta_value' => $json['sessionId'],
                        'return'     => 'ids',
                    ] );
                    $order_id = $orders ? (int) $orders[0] : 0;
                }
            }
        }

        // if ( $order_id && $this->load_order_credentials( $order_id ) ) {
        //     $log->debug( "before_callback: załadowano creds dla order $order_id", [ 'source' => 'wc-p24-voivodeship' ] );
        // } else {
        //     $log->warning( 'before_callback: nie znaleziono order_id', [ 'source' => 'wc-p24-voivodeship' ] );
        // }
    }

    }

    add_filter('http_request_args', function ($args, $url) {
        if (strpos($url, 'przelewy24.pl/api') !== false) {
            //$log = wc_get_logger();
            //$log->debug('Original headers: ' . print_r($args['headers'], true), ['source' => 'wc-p24-voivodeship']);

            if (WC_Gateway_Przelewy24_Voivodeship::$current_credentials) {
                $merchant_id = WC_Gateway_Przelewy24_Voivodeship::$current_credentials['merchant_id'];
                $api_key = WC_Gateway_Przelewy24_Voivodeship::$current_credentials['api_key'];
                $args['headers']['Authorization'] = 'Basic ' . base64_encode($merchant_id . ':' . $api_key);
                //$log->debug('Modified Authorization header: ' . $args['headers']['Authorization'], ['source' => 'wc-p24-voivodeship']);
            } else {
                //$log->warning('Brak danych uwierzytelniających w filtrze dla URL: ' . $url, ['source' => 'wc-p24-voivodeship']);
            }
        }
        return $args;
    }, 10, 2);

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'WC_Gateway_Przelewy24_Voivodeship';
        return $gateways;
    });
});
