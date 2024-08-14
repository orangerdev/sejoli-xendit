<?php
use Carbon_Fields\Container;
use Carbon_Fields\Field;
use SejoliSA\Admin\Product as AdminProduct;
use SejoliSA\JSON\Product;
use SejoliSA\Model\Affiliate;
use Illuminate\Database\Capsule\Manager as Capsule;

final class SejoliXendit extends \SejoliSA\Payment{

    /**
     * Prevent double method calling
     * @since   1.0.0
     * @access  protected
     * @var     boolean
     */
    protected $is_called = false;

    /**
     * Redirect urls
     * @since   1.0.0
     * @var     array
     */
    public $base_url = array(
        'sandbox' => 'https://api.xendit.co/',
        'live'    => 'https://api.xendit.co/'
    );

    /**
     * Order price
     * @since 1.0.0
     * @var float
     */
    protected $order_price = 0.0;

    /**
     * Method options
     * @since   1.0.0
     * @var     array
     */
    protected $method_options = array();

    /**
     * Table name
     * @since 1.0.0
     * @var string
     */
    protected $table = 'sejolisa_xendit_transaction';

    /**
     * Construction
     */
    public function __construct() {
        
        global $wpdb;

        $this->id          = 'xendit';
        $this->name        = __( 'Xendit', 'sejoli-xendit' );
        $this->title       = __( 'Xendit', 'sejoli-xendit' );
        $this->description = __( 'Transaksi via Xendit Payment Gateway.', 'sejoli-xendit' );
        $this->table       = $wpdb->prefix . $this->table;

        $this->method_options = array(
            'BCA'               => __('Bank Central Asia (Virtual Account)', 'sejoli-xendit'),
            'BNI'               => __('Bank Negara Indonesia (Virtual Account)', 'sejoli-xendit'),
            'MANDIRI'           => __('Bank Mandiri (Virtual Account)', 'sejoli-xendit'),
            'PERMATA'           => __('Bank Permata (Virtual Account)', 'sejoli-xendit'),
            'SAHABAT_SAMPOERNA' => __('Bank Sahabat Sampoerna (Virtual Account)', 'sejoli-xendit'),
            'BRI'               => __('Bank Rakyat Indonesia (Virtual Account)', 'sejoli-xendit'),
            'BSI'               => __('Bank Syariah Indonesia (Virtual Account)', 'sejoli-xendit'),
            'ALFAMART'          => __('Alfamart (Retail)', 'sejoli-xendit'),
            'INDOMARET'         => __('Indomaret (Retail)', 'sejoli-xendit'),
            'OVO'               => __('OVO (eWallet)', 'sejoli-xendit'),
            'DANA'              => __('DANA (eWallet)', 'sejoli-xendit'),
            'SHOPEEPAY'         => __('Shopee Pay (eWallet)', 'sejoli-xendit'),
            'LINKAJA'           => __('LinkAja (eWallet)', 'sejoli-xendit'),
            'QRIS'              => __('QRIS Code', 'sejoli-xendit'),
            'CREDIT_CARD'       => __('Credit Card', 'sejoli-xendit')
        );

        add_action('admin_init',                     [$this, 'register_trx_table'],  1);
        add_filter('sejoli/payment/payment-options', [$this, 'add_payment_options']);
        add_filter('query_vars',                     [$this, 'set_query_vars'],     999);
        add_action('sejoli/thank-you/render',        [$this, 'check_for_redirect'], 1);
        add_action('init',                           [$this, 'set_endpoint'],       1);
        add_action('parse_query',                    [$this, 'check_parse_query'],  100);

    }

    /**
     * Register transaction table
     * Hooked via action admin_init, priority 1
     * @since   1.0.0
     * @return  void
     */
    public function register_trx_table() {

        if( !Capsule::schema()->hasTable( $this->table ) ):

            Capsule::schema()->create( $this->table, function( $table ) {
                $table->increments('ID');
                $table->datetime('created_at');
                $table->datetime('last_check')->default('0000-00-00 00:00:00');
                $table->integer('order_id');
                $table->string('status');
                $table->text('detail')->nullable();
            });

        endif;

    }

    /**
     * Get duitku order data
     * @since   1.0.0
     * @param   int $order_id
     * @return  false|object
     */
    protected function check_data_table( int $order_id ) {

        return Capsule::table($this->table)
            ->where(array(
                'order_id'  => $order_id
            ))
            ->first();

    }

    /**
     * Add transaction data
     * @since   1.0.0
     * @param   integer $order_id Order ID
     * @return  void
     */
    protected function add_to_table( int $order_id ) {

        Capsule::table($this->table)
            ->insert([
                'created_at' => current_time('mysql'),
                'last_check' => '0000-00-00 00:00:00',
                'order_id'   => $order_id,
                'status'     => 'pending'
            ]);
    
    }

    /**
     * Update data status
     * @since   1.0.0
     * @param   integer $order_id [description]
     * @param   string $status [description]
     * @return  void
     */
    protected function update_status( $order_id, $status ) {
        
        Capsule::table($this->table)
            ->where(array(
                'order_id' => $order_id
            ))
            ->update(array(
                'status'    => $status,
                'last_check'=> current_time('mysql')
            ));

    }

    /**
     * Update data detail payload
     * @since   1.0.0
     * @param   integer $order_id [description]
     * @param   array $detail [description]
     * @return  void
     */
    protected function update_detail( $order_id, $detail ) {
        
        Capsule::table($this->table)
            ->where(array(
                'order_id' => $order_id
            ))
            ->update(array(
                'detail' => serialize($detail),
            ));

    }

    /**
     *  Set end point custom menu
     *  Hooked via action init, priority 999
     *  @since   1.0.0
     *  @access  public
     *  @return  void
     */
    public function set_endpoint() {
        
        add_rewrite_rule( '^xendit/([^/]*)/?', 'index.php?xendit-method=1&action=$matches[1]', 'top' );

        flush_rewrite_rules();
    
    }

    /**
     * Set custom query vars
     * Hooked via filter query_vars, priority 100
     * @since   1.0.0
     * @access  public
     * @param   array $vars
     * @return  array
     */
    public function set_query_vars( $vars ) {

        $vars[] = 'xendit-method';

        return $vars;
    
    }

    /**
     * Check parse query and if duitku-method exists and process
     * Hooked via action parse_query, priority 999
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function check_parse_query() {

        global $wp_query;

        if( is_admin() || $this->is_called ) :

            return;

        endif;

        if(
            isset( $wp_query->query_vars['xendit-method'] ) &&
            isset( $wp_query->query_vars['action'] ) && !empty( $wp_query->query_vars['action'] )
        ) :

            if( 'process' === $wp_query->query_vars['action'] ) :

                $this->is_called = true;
                $this->process_callback();

            elseif( 'return' === $wp_query->query_vars['action'] ) :

                $this->is_called = true;
                $this->receive_return();

            endif;

        endif;

    }

    /**
     * Set option in Sejoli payment options, we use CARBONFIELDS for plugin options
     * Called from parent method
     * @since   1.0.0
     * @return  array
     */
    public function get_setup_fields() {

        return array(

            Field::make('separator', 'sep_xendit_transaction_setting', __('Pengaturan Xendit', 'sejoli-xendit')),

            Field::make('checkbox', 'xendit_active', __('Aktifkan pembayaran melalui Xendit', 'sejoli-xendit')),
            
            Field::make('select', 'xendit_mode', __('Payment Mode', 'sejoli-xendit'))
            ->set_options(array(
                'sandbox' => __('Sandbox', 'sejoli-xendit'),
                'live'    => __('Live', 'sejoli-xendit'),
            ))
            ->set_conditional_logic(array(
                array(
                    'field' => 'xendit_active',
                    'value' => true
                )
            )),

            Field::make('text', 'xendit_public_key_sandbox', __('Xendit Public API Key (Sandbox)', 'sejoli-xendit'))
            ->set_required(true)
            ->set_help_text(__('Obtain your public key from your Xendit dashboard <a href="https://dashboard.xendit.co/settings/developers#api-keys" target="blank"> here</a>.', 'sejoli-xendit'))
            ->set_conditional_logic(array(
                array(
                    'field' => 'xendit_active',
                    'value' => true
                ),array(
                    'field' => 'xendit_mode',
                    'value' => 'sandbox'
                )
            )),

            Field::make('text', 'xendit_secret_key_sandbox', __('Xendit Secret API Key (Sandbox)', 'sejoli-xendit'))
            ->set_required(true)
            ->set_help_text(__('Obtain your secret key from your Xendit dashboard <a href="https://dashboard.xendit.co/settings/developers#api-keys" target="blank"> here</a>.', 'sejoli-xendit'))
            ->set_conditional_logic(array(
                array(
                    'field' => 'xendit_active',
                    'value' => true
                ),array(
                    'field' => 'xendit_mode',
                    'value' => 'sandbox'
                )
            )),

            Field::make('text', 'xendit_public_key_live', __('Xendit Public API Key (Live)', 'sejoli-xendit'))
            ->set_required(true)
            ->set_help_text(__('Obtain your public key from your Xendit dashboard <a href="https://dashboard.xendit.co/settings/developers#api-keys" target="blank"> here</a>.', 'sejoli-xendit'))
            ->set_conditional_logic(array(
                array(
                    'field' => 'xendit_active',
                    'value' => true
                ),array(
                    'field' => 'xendit_mode',
                    'value' => 'live'
                )
            )),

            Field::make('text', 'xendit_secret_key_live', __('Xendit Secret API Key (Live)', 'sejoli-xendit'))
            ->set_required(true)
            ->set_help_text(__('Obtain your secret key from your Xendit dashboard <a href="https://dashboard.xendit.co/settings/developers#api-keys" target="blank"> here</a>.', 'sejoli-xendit'))
            ->set_conditional_logic(array(
                array(
                    'field' => 'xendit_active',
                    'value' => true
                ),array(
                    'field' => 'xendit_mode',
                    'value' => 'live'
                )
            )),

            Field::make('set', 'xendit_payment_method', __('Metode Pembayaran', 'sejoli-xendit'))
            ->set_required(true)  
            ->set_options($this->method_options)
            ->set_help_text(
                __('Wajib memilih minimal satu metode pembayaran dan PASTIKAN metode tersebut sudah aktif di pengaturan dashboard xendit.co', 'sejoli-xendit')
            )
            ->set_conditional_logic(array(
                array(
                    'field' => 'xendit_active',
                    'value' => true
                )
            )),

            Field::make('text', 'xendit_inv_prefix', __('Invoice Prefix', 'sejoli-xendit'))
            ->set_required(true)
            ->set_default_value('sjl1')
            ->set_help_text(__('Maksimal 6 Karakter', 'sejoli-xendit'))
            ->set_conditional_logic(array(
                array(
                    'field' => 'xendit_active',
                    'value' => true
                )
            )),

        );

    }

    /**
     * Display xendit payment options in checkout page
     * Hooked via filter sejoli/payment/payment-options, priority 100
     * @since   1.0.0
     * @param   array $options
     * @return  array
     */
    public function add_payment_options( array $options ) {
        
        $active = boolval( carbon_get_theme_option('xendit_active') );

        if( true === $active ) :

            $methods          = carbon_get_theme_option('xendit_payment_method');
            $image_source_url = plugin_dir_url(__FILE__);

            foreach( (array) $methods as $_method ) :

                $key = 'xendit:::'.$_method;

                switch($_method) :

                    case 'BCA' :
                        $options[$key] = [
                            'label' => $this->method_options[$_method],
                            'image' => $image_source_url.'img/bca-logo.svg'
                        ];
                        break;

                    case 'BNI' :
                        $options[$key] = [
                            'label' => $this->method_options[$_method],
                            'image' => $image_source_url.'img/bni-logo.svg'
                        ];
                        break;

                    case 'MANDIRI' :
                        $options[$key] = [
                            'label' => $this->method_options[$_method],
                            'image' => $image_source_url.'img/mandiri-logo.svg'
                        ];
                        break;

                    case 'PERMATA' :
                        $options[$key] = [
                            'label' => $this->method_options[$_method],
                            'image' => $image_source_url.'img/permata-logo.svg'
                        ];
                        break;

                    case 'SAHABAT_SAMPOERNA' :
                        $options[$key] = [
                            'label' => $this->method_options[$_method],
                            'image' => $image_source_url.'img/bss-logo.svg'
                        ];
                        break;

                    case 'BRI' :
                        $options[$key] = [
                            'label' => $this->method_options[$_method],
                            'image' => $image_source_url.'img/bri-logo.svg'
                        ];
                        break;

                    case 'BSI' :
                        $options[$key] = [
                            'label' => $this->method_options[$_method],
                            'image' => $image_source_url.'img/bsi-logo.svg'
                        ];
                        break;

                    case 'ALFAMART' :
                        $options[$key] = [
                            'label' => $this->method_options[$_method],
                            'image' => $image_source_url.'img/alfamart-logo.svg'
                        ];
                        break;

                    case 'INDOMARET' :
                        $options[$key] = [
                            'label' => $this->method_options[$_method],
                            'image' => $image_source_url.'img/indomaret-logo.svg'
                        ];
                        break;

                    case 'OVO' :
                        $options[$key] = [
                            'label' => $this->method_options[$_method],
                            'image' => $image_source_url.'img/ovo-logo.svg'
                        ];
                        break;

                    case 'DANA' :
                        $options[$key] = [
                            'label' => $this->method_options[$_method],
                            'image' => $image_source_url.'img/dana-logo.svg'
                        ];
                        break;

                    case 'SHOPEEPAY' :
                        $options[$key] = [
                            'label' => $this->method_options[$_method],
                            'image' => $image_source_url.'img/shopeepay-logo.svg'
                        ];
                        break;

                    case 'LINKAJA' :
                        $options[$key] = [
                            'label' => $this->method_options[$_method],
                            'image' => $image_source_url.'img/linkaja-logo.svg'
                        ];
                        break;

                    case 'QRIS' :
                        $options[$key] = [
                            'label' => $this->method_options[$_method],
                            'image' => $image_source_url.'img/qris-logo.svg'
                        ];
                        break;

                    case 'CREDIT_CARD' :
                        $options[$key] = [
                            'label' => $this->method_options[$_method],
                            'image' => $image_source_url.'img/cc.png'
                        ];
                        break;

                endswitch;

            endforeach;

        endif;

        return $options;

    }

    /**
     * Set order price if there is any fee need to be added
     * @since   1.0.0
     * @param   float $price
     * @param   array $order_data
     * @return  float
     */
    public function set_price( float $price, array $order_data ) {

        if( 0.0 !== $price ) :

            $this->order_price = $price;

            return floatval( $this->order_price );

        endif;

        return $price;

    }

    /**
     * Get setup values
     * @return array
     */
    protected function get_setup_values() {

        $mode            = carbon_get_theme_option('xendit_mode');
        $secret_key      = trim( carbon_get_theme_option('xendit_secret_key_'.$mode) );
        $public_key      = trim( carbon_get_theme_option('xendit_public_key_'.$mode) );
        $payment_method  = carbon_get_theme_option('xendit_payment_method');
        $base_url        = $this->base_url[$mode];

        return array(
            'mode'           => $mode,
            'secret_key'     => $secret_key,
            'public_key'     => $public_key,
            'payment_method' => $payment_method,
            'base_url'       => $base_url
        );

    }

    /**
     * Set order meta data
     * @since   1.0.0
     * @param   array $meta_data
     * @param   array $order_data
     * @param   array $payment_subtype
     * @return  array
     */
    public function set_meta_data( array $meta_data, array $order_data, $payment_subtype ) {

        $trans_id = $order_data['user_id'].$order_data['grand_total'];

        $meta_data['xendit'] = [
            'trans_id'   => substr( md5( $trans_id ), 0, 20 ),
            'unique_key' => substr( md5( rand( 0,1000 ) ), 0, 16 ),
            'method'     => $payment_subtype
        ];

        return $meta_data;

    }

    /**
     * Prepare Xendit Data
     * @since   1.0.0
     * @return  array
     */
    public function prepare_xendit_data( array $order ) {

        extract( $this->get_setup_values() );

        $redirect_link     = '';
        $request_to_xendit = false;
        $data_order        = $this->check_data_table( $order['ID'] );
        $request_url       = $base_url.'v2/invoices';
        $payment_amount    = (int) $order['grand_total'];
        $merchant_order_ID = $order['ID'];
        $signature         = md5( $order['ID'] . $merchant_order_ID . $payment_amount . $secret_key );

        if ( isset( $order['meta_data']['shipping_data'] ) ) {

            $grand_total               = $order['grand_total'];
            $subtotal                  = $order['grand_total'] - $order['meta_data']['shipping_data']['cost'];
            $product_price             = $order['grand_total'] - $order['meta_data']['shipping_data']['cost']; 
            $receiver_destination_id   = $order['meta_data']['shipping_data']['district_id'];
            $receiver_destination_city = sejolise_get_subdistrict_detail( $receiver_destination_id );
            $receiver_city             = $receiver_destination_city['type'].' '.$receiver_destination_city['city'];
            $receiver_province         = $receiver_destination_city['province'];
            $shipping_cost             = $order['meta_data']['shipping_data']['cost'];
            $recipient_name            = $order['meta_data']['shipping_data']['receiver'];
            $recipient_address         = $order['address'];
            $recipient_phone           = $order['meta_data']['shipping_data']['phone'];
        
        } else {
            
            if ( isset( $order['product']->subscription ) ){
                $grand_total   = $order['grand_total'];
                $product_price = $grand_total;
            } else {
                $grand_total   = $order['grand_total'];
                $product_price = $grand_total;
            }
            
            $receiver_destination_id   = $order['user']->data->meta->destination;
            $receiver_destination_city = sejolise_get_subdistrict_detail( $receiver_destination_id );
            if (isset($receiver_destination_city) && is_array($receiver_destination_city)) :
                $receiver_city         = $receiver_destination_city['type'].' '.$receiver_destination_city['city'];
                $receiver_province     = $receiver_destination_city['province'];
            else:
                $receiver_city         = '';
                $receiver_province     = '';
            endif;
            $shipping_cost             = 0.00;
            $recipient_name            = $order['user']->data->display_name;
            $recipient_address         = $order['user']->data->meta->address;
            $recipient_phone           = $order['user']->data->meta->phone;
            $subtotal                  = $product_price; 
        
        }

        if( NULL === $data_order ) :
            
            $request_to_xendit = true;
        
        else :

            $detail = unserialize( $data_order->detail );
            if( !isset( $detail['invoice_url'] ) || empty( $detail['invoice_url'] ) ) :
                $request_to_xendit = true;
            else :
                $redirect_link = $detail['invoice_url'];
            endif;

        endif;

        $previx_refference = carbon_get_theme_option('xendit_inv_prefix');

        if( true === $request_to_xendit ) :

            $this->add_to_table( $order['ID'] );

            if ( !empty( $secret_key ) ) {

                $set_params = [ 
                    'external_id'      => $previx_refference.$signature,
                    'amount'           => $payment_amount,
                    'description'      => __('Payment for Order No ', 'sejoli-xendit') . $order['ID'],
                    'payer_email'      => $order['user']->user_email,
                    'invoice_duration' => 86400,
                    'customer' => [
                        'given_names'   => $recipient_name,
                        'surname'       => $recipient_name,
                        'email'         => $order['user']->user_email,
                        'mobile_number' => $order['user']->meta->phone,
                        'address' => [
                            [
                                'city'         => $receiver_city,
                                'country'      => 'Indonesia',
                                'postal_code'  => '',
                                'state'        => $receiver_province,
                                'street_line1' => preg_replace("/<br\W*?\/>/", ", ", $recipient_address),
                                'street_line2' => preg_replace("/<br\W*?\/>/", ", ", $recipient_address),
                            ]
                        ]
                    ],
                    'customer_notification_preference' => [
                        'invoice_created' => [
                            'whatsapp',
                            'sms',
                            'email',
                            'viber'
                        ],
                        'invoice_reminder' => [
                            'whatsapp',
                            'sms',
                            'email',
                            'viber'
                        ],
                        'invoice_paid' => [
                            'whatsapp',
                            'sms',
                            'email',
                            'viber'
                        ],
                        'invoice_expired' => [
                            'whatsapp',
                            'sms',
                            'email',
                            'viber'
                        ]
                    ],
                    'success_redirect_url' => add_query_arg(array(
                                                    'order_id'   => $order['ID'],
                                                    'unique_key' => $order['meta_data']['xendit']['unique_key']
                                            ), site_url('/xendit/process')),
                    'failure_redirect_url' => add_query_arg(array(
                                                    'order_id'   => $order['ID'],
                                                    'unique_key' => $order['meta_data']['xendit']['unique_key']
                                            ), site_url('/xendit/return')),
                    'should_send_email' => "true",
                    "payment_methods"   => ["".$order['meta_data']['xendit']['method'].""],
                    'currency'          => 'IDR',
                    'items' => [
                        [
                            'name'     => $order['product']->post_title,
                            'quantity' => $order['quantity'],
                            'price'    => $payment_amount,
                            'category' => $order['product']->post_title,
                            'url'      => get_home_url().'/product/'.$order['product']->post_name,
                        ]
                    ]
                ];

                $params             = json_encode($set_params);
                $executeTransaction = $this->executeTransaction( $request_url, $params, $secret_key, $public_key );
                $inv_url            = array_key_exists('invoice_url', $executeTransaction) ? $executeTransaction['invoice_url'] : null;
                $invoice_url        = isset($executeTransaction) ? $inv_url : '';

                if ( $invoice_url ) {

                    $http_code = 200;

                } else {
                    
                    $http_code = 400;
                
                }

                if( 200 === $http_code ) :

                    do_action( 'sejoli/log/write', 'success-xendit', $executeTransaction );

                    $this->update_detail( $order['ID'], $executeTransaction );
                    $redirect_link = $invoice_url;

                else :

                    do_action( 'sejoli/log/write', 'error-xendit', array( $executeTransaction, $http_code, $params ) );
                        
                    wp_die(
                        __('Error!<br> Please check the following : ' . '<pre>' . var_export( $executeTransaction, true ) . '</pre>', 'sejoli-xendit'),
                        __('Error!', 'sejoli-xendit')
                    );

                    exit;
            
                endif;

            }

        endif;

        wp_redirect( $redirect_link );

        exit;

    }

    /**
     * Update order status based on product type ( digital or physic)
     * It's fired when payment module confirm the order payment
     *
     * @since   1.0.0
     * @param   int     $order_id
     * @return  void
     */
    protected function update_order_status_by_payment($order_id, $status) {

        $respond = sejolisa_get_order(['ID' => $order_id]);

        if(false !== $respond['valid']) :
            
            $order   = $respond['orders'];
            $product = sejolisa_get_product($order['product_id']);

            do_action('sejoli/order/update-status',[
                'ID'       => $order['ID'],
                'status'   => $status
            ]);

        endif;

    }

    /**
     * Receive return process
       @since   1.0.0
     * @return  void
     */
    protected function receive_return() {

        extract( $this->get_setup_values() );

        $args = wp_parse_args($_GET, array(
            'order_id' => NULL,
        ));

        if(
            !empty( $args['order_id'] )
        ) :

            $is_callback = isset( $args['order_id'] ) ? true : false;

            if( true === $is_callback ) :

                $data_order  = $this->check_data_table( $args['order_id'] );
                $detail      = unserialize( $data_order->detail );
                $request_url = $base_url.'v2/invoices/'.$detail['id'];

                $set_params = [];
                $params     = $set_params;

                $public_key = '';
                $getTransaction = $this->getTransaction( $request_url, $params, $secret_key, $public_key );

                if ($getTransaction['status'] === 'PAID' || $getTransaction['status'] === 'COMPLETED' || $getTransaction['status'] === 'SETTLED') {

                    $order_id = intval( $args['order_id'] );
                    $response = sejolisa_get_order( array( 'ID' => $order_id ) );

                    if( false !== $response['valid'] ) :

                        $order   = $response['orders'];
                        $product = $order['product'];

                        // if product is need of shipment
                        if( 'physical' === $product->type ) :
                            $status = 'in-progress';
                        else :
                            $status = 'completed';
                        endif;

                        sejolisa_update_order_meta_data($order_id, array(
                            'xendit' => array(
                                'status' => esc_attr($status)
                            )
                        ));

                        $this->update_order_status_by_payment( $order_id, $status );

                        wp_redirect(add_query_arg(array(
                            'order_id' => $order_id,
                            'status'   => "success"
                        ), site_url('checkout/thank-you')));
                        
                        do_action( 'sejoli/log/write', 'xendit-update-order', $args );

                        exit;

                    else :

                        do_action( 'sejoli/log/write', 'xendit-wrong-order', $args );
                    
                    endif;

                } elseif ( $getTransaction['status'] === 'EXPIRED' ) {
                    
                    $order_id = intval($args['order_id']);

                    $status = 'cancelled';

                    sejolisa_update_order_meta_data($order_id, array(
                        'xendit' => array(
                            'status' => esc_attr($status)
                        )
                    ));

                    $this->update_order_status_by_payment( $order_id, $status );

                    wp_redirect(add_query_arg(array(
                        'order_id' => $order_id,
                        'status'   => "failed"
                    ), site_url('checkout/thank-you')));

                    exit;
                    
                } else {
                    
                    $order_id   = intval($args['order_id']);
                    $status = 'on-hold';

                    sejolisa_update_order_meta_data($order_id, array(
                        'xendit' => array(
                            'status' => esc_attr($status)
                        )
                    ));

                    $this->update_order_status_by_payment( $order_id, $status );

                    wp_redirect(add_query_arg(array(
                        'order_id' => $order_id,
                        'status'   => "pending"
                    ), site_url('checkout/thank-you')));

                    exit;
                        
                }

            endif;
        
        endif;

    }
 
    /**
     * Process callback from xendit
     * @since   1.0.0
     * @return  void
     */
    protected function process_callback() {

        extract( $this->get_setup_values() );

        $args = wp_parse_args($_GET, array(
            'order_id' => NULL
        ));

        if(
            !empty( $args['order_id'] )
        ) :

            $is_callback = isset( $args['order_id'] ) ? true : false;

            if( true === $is_callback ) :

                $data_order  = $this->check_data_table( $args['order_id'] );
                $detail      = unserialize( $data_order->detail );
                $request_url = $base_url.'v2/invoices/'.$detail['id'];

                $set_params = [];
                $params     = $set_params;

                $public_key = '';
                $getTransaction = $this->getTransaction( $request_url, $params, $secret_key, $public_key );


                if ($getTransaction['status'] === 'PAID' || $getTransaction['status'] === 'COMPLETED' || $getTransaction['status'] === 'SETTLED') :

                    $order_id = intval( $args['order_id'] );
                    $response = sejolisa_get_order( array( 'ID' => $order_id ) );

                    if( false !== $response['valid'] ) :

                        $order   = $response['orders'];
                        $product = $order['product'];

                        // if product is need of shipment
                        if( 'physical' === $product->type ) :
                            $status = 'in-progress';
                        else :
                            $status = 'completed';
                        endif;

                        sejolisa_update_order_meta_data($order_id, array(
                            'xendit' => array(
                                'status' => esc_attr($status)
                            )
                        ));

                        $this->update_order_status_by_payment( $order_id, $status );

                        wp_redirect(add_query_arg(array(
                            'order_id' => $order_id,
                            'status'   => "success"
                        ), site_url('checkout/thank-you')));
                            
                        do_action( 'sejoli/log/write', 'xendit-update-order', $args );

                        exit;

                    else :

                        do_action( 'sejoli/log/write', 'xendit-wrong-order', $args );
                    
                    endif;

                elseif ( $getTransaction['status'] === 'EXPIRED' ) :

                    $order_id = intval( $args['order_id'] );
                    $response = sejolisa_get_order( array( 'ID' => $order_id ) );

                    if( false !== $response['valid'] ) :

                        $order   = $response['orders'];
                        $product = $order['product'];
                        $status  = 'cancelled';

                        sejolisa_update_order_meta_data($order_id, array(
                            'xendit' => array(
                                'status' => esc_attr($status)
                            )
                        ));

                        $this->update_order_status_by_payment( $order_id, $status );

                        wp_redirect(add_query_arg(array(
                            'order_id' => $order_id,
                            'status'   => "failed"
                        ), site_url('checkout/thank-you')));

                        do_action( 'sejoli/log/write', 'xendit-update-order', $args );

                        exit;

                    else :

                        do_action( 'sejoli/log/write', 'xendit-wrong-order', $args );
                    
                    endif;

                else:

                    $order_id = intval( $args['order_id'] );
                    $response = sejolisa_get_order( array( 'ID' => $order_id ) );

                    if( false !== $response['valid'] ) :

                        $order   = $response['orders'];
                        $product = $order['product'];
                        $status  = 'on-hold';

                        sejolisa_update_order_meta_data($order_id, array(
                            'xendit' => array(
                                'status' => esc_attr($status)
                            )
                        ));
                        
                        $this->update_order_status_by_payment( $order_id, $status );

                        wp_redirect(add_query_arg(array(
                            'order_id' => $order_id,
                            'status'   => "pending"
                        ), site_url('checkout/thank-you')));
                            
                        do_action( 'sejoli/log/write', 'xendit-update-order', $args );

                        exit;

                    else :

                        do_action( 'sejoli/log/write', 'xendit-wrong-order', $args );
                    
                    endif;

                endif;

            endif;

        else :

            wp_die(
                __('You don\'t have permission to access this page', 'sejoli-xendit'),
                __('Forbidden access by SEJOLI', 'sejoli-xendit')
            );
        
        endif;

        exit;

    }

    /**
     * Check if current order is using xendit and will be redirected to xendit payment channel options
     * Hooked via action sejoli/thank-you/render, priority 100
     * @since   1.0.0
     * @param   array  $order Order data
     * @return  void
     */
    public function check_for_redirect( array $order ) {

        extract( $this->get_setup_values() );

        if(
            isset( $order['payment_info']['bank'] ) &&
            'XENDIT' === strtoupper( $order['payment_info']['bank'] )
        ) :

            if( 'on-hold' === $order['status'] ) :
                 
                $this->prepare_xendit_data( $order );

            elseif( in_array( $order['status'], array( 'refunded', 'cancelled' ) ) ) :

                $title = __('Order telah dibatalkan', 'sejoli-xendit');
                require 'template/checkout/order-cancelled.php';

            elseif( in_array( $order['status'], array( 'completed' ) ) ) :

                $title = __('Order selesai', 'sejoli-xendit');
                require 'template/checkout/order-completed.php';

            else :

                $title = __('Order sudah diproses', 'sejoli-xendit');
                require 'template/checkout/order-processed.php';

            endif;

            exit;

        endif;
    
    }

    /**
     * Get email content from given template
     * @since   1.0.0
     * @param   string      $filename   The filename of notification
     * @param   string      $media      Notification media, default will be email
     * @param   null|array  $args       Parsing variables
     * @return  null|string
     */
    function sejoli_xendit_get_notification_content( $filename, $media = 'email', $vars = NULL ) {
        
        $content    = NULL;
        $email_file = plugin_dir_path( __FILE__ ) . '/template/'.$media.'/' . $filename . '.php';

        if( file_exists( $email_file ) ) :

            if( is_array( $vars ) ) :
                extract( $vars );
            endif;

            ob_start();
           
            require $email_file;
            $content = ob_get_contents();
           
            ob_end_clean();
            
        endif;

        return $content;

    }

    /**
     * Display payment instruction in notification
     * @since   1.0.0
     * @param   array    $invoice_data
     * @param   string   $media email,whatsapp,sms
     * @return  string
     */
    public function display_payment_instruction( $invoice_data, $media = 'email' ) {
        
        if( 'on-hold' !== $invoice_data['order_data']['status'] ) :

            return;

        endif;

        $content = $this->sejoli_xendit_get_notification_content(
                        'xendit',
                        $media,
                        array(
                            'order' => $invoice_data['order_data']
                        )
                    );

        return $content;
    
    }

    /**
     * Display simple payment instruction in notification
     * @since   1.0.0
     * @param   array    $invoice_data
     * @param   string   $media
     * @return  string
     */
    public function display_simple_payment_instruction( $invoice_data, $media = 'email' ) {

        if( 'on-hold' !== $invoice_data['order_data']['status'] ) :

            return;

        endif;

        $content = __('via Xendit', 'sejoli-xendit');

        return $content;

    }

    /**
     * Set payment info to order data
     * @since   1.0.0
     * @param   array $order_data
     * @return  array
     */
    public function set_payment_info( array $order_data ) {

        $trans_data = [
            'bank' => 'Xendit'
        ];

        return $trans_data;

    }

    /**
     * Excecute Transaction
     * @since   1.0.0
     * @return  array
     */
    private function executeTransaction( $request_url, $params, $secret_key, $public_key ) {
        
        $result = wp_remote_post($request_url, array(
            'headers' => array(
                            'Authorization' => 'Basic ' . base64_encode( $secret_key . ':' . $public_key ),
                            'Content-Type'  => 'application/json',
                            'Accept'        => '*/*',
                        ),
            'body'    => $params,
            'timeout' => 300
        ));

        if( is_wp_error( $result ) ){
            
            return [
                'success' => 0
            ];
        
        }

        $resBody = wp_remote_retrieve_body( $result );

        $resBody = json_decode( ( $resBody ), true );

        return $resBody;

    }

    /**
     * Excecute Transaction
     * @since   1.0.0
     * @return  array
     */
    private function getTransaction( $request_url, $params, $secret_key, $public_key ) {
        
        $result = wp_remote_get($request_url, array(
            'headers' => array(
                            'Authorization' => 'Basic ' . base64_encode( $secret_key . ':' . $public_key ),
                            // 'Content-Type'  => 'application/json',
                            'Accept'        => '*/*',
                        ),
            'body'    => $params,
            'timeout' => 300
        ));

        if( is_wp_error( $result ) ){
            
            return [
                'success' => 0
            ];
        
        }

        $resBody = wp_remote_retrieve_body( $result );

        $resBody = json_decode( ( $resBody ), true );

        return $resBody;

    }

    /**
     * Paypal Generate Iso Time
     * @since   1.0.0
     * @return  time
     */
    private function xendit_generate_isotime() {
        
        date_default_timezone_set("Asia/Jakarta");

        $fmt  = date( 'Y-m-d\TH:i:s' );
        $time = sprintf( "$fmt.%s%s", substr( microtime(), 2, 3 ), date( 'P' ) );

        return $time;

    }

}