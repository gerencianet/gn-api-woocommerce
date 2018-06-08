<?php

/**
 * WC gerencianet oficial Gateway Class.
 *
 * Built the gerencianet method.
 */
class WC_Gerencianet_Oficial_Gateway extends WC_Payment_Gateway {

	public $gnIntegration;

	/**
	 * Constructor for the Gerencianet gateway.
	 *
	 */
	public function __construct() {

		$this->id           = 'gerencianet_oficial';
		$this->icon         = apply_filters( 'woocommerce_gerencianet_oficial_icon', plugins_url( 'assets/images/gn-payment.png', plugin_dir_path( __FILE__ ) ) );
		$this->has_fields   = false;
		$this->method_title = __( 'Gerencianet', WCGerencianetOficial::getTextDomain() );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Payment methods.
		$this->billet_banking = $this->get_option( 'billet_banking' );
		$this->credit_card    = $this->get_option( 'credit_card' );

		// OSC option
		$this->osc = $this->get_option( 'osc_option', 'no' );

		// Display payment options.
		if ( $this->credit_card == 'no' ) {
			$this->title = __( "Billet", WCGerencianetOficial::getTextDomain() );
		} else if ( $this->billet_banking == 'no' ) {
			$this->title = __( "Credit Card", WCGerencianetOficial::getTextDomain() );
		} else if ( $this->billet_banking == 'yes' && $this->credit_card == 'yes' ) {
			$this->title = __( "Billet or Credit Card", WCGerencianetOficial::getTextDomain() );
		} else {
			$this->title = __( "Gerencianet", WCGerencianetOficial::getTextDomain() );
		}

		if ( $this->osc == 'no' ) {
			$this->checkout_type = "checkout_page";
		} else {
			$this->checkout_type = "OSC";
		}


		if ( $this->checkout_type == "OSC" ) {
			$this->description = get_template( plugin_dir_path( dirname( __FILE__ ) ) . 'templates/transparent-osc.php' );
		} else {
			$this->description = $this->get_option( 'description' );
		}

		// Gateway options.
		$this->invoice_prefix = $this->get_option( 'invoice_prefix', 'WC-' );

		// Billet options.
		$this->billet                   = $this->get_option( 'billet', 'no' );
		$this->billet_number_days       = $this->get_option( 'billet_number_days', '5' );
		$this->discountBillet           = floatval( preg_replace( '/[^0-9.]/', '', str_replace( ",", ".", $this->get_option( 'billet_discount', '0' ) ) ) );
		$this->billet_discount_shipping = $this->get_option( 'billet_discount_shipping', 'total' );
		$this->instructions_line_1      = substr( $this->get_option( 'billet_instructions_line_1', '' ), 0, 90 );
		$this->instructions_line_2      = substr( $this->get_option( 'billet_instructions_line_2', '' ), 0, 90 );
		$this->instructions_line_3      = substr( $this->get_option( 'billet_instructions_line_3', '' ), 0, 90 );
		$this->instructions_line_4      = substr( $this->get_option( 'billet_instructions_line_4', '' ), 0, 90 );

		$this->client_id_production      = $this->get_option( 'client_id_production' );
		$this->client_secret_production  = $this->get_option( 'client_secret_production' );
		$this->client_id_development     = $this->get_option( 'client_id_development' );
		$this->client_secret_development = $this->get_option( 'client_secret_development' );
		$this->payee_code                = $this->get_option( 'payee_code', '' );

		// Debug options.
		$this->sandbox  = $this->get_option( 'sandbox' );
		$this->debug    = $this->get_option( 'debug' );
		$this->callback = $this->get_option( 'callback' );

		if ( 'yes' == $this->debug ) {
			if ( ! function_exists( 'write_log' ) ) {
				function write_log( $log ) {
					if ( is_array( $log ) || is_object( $log ) ) {
						error_log( print_r( $log, true ) );
					} else {
						error_log( $log );
					}
				}
			}
		}

		$this->gnIntegration = new GerencianetIntegration( $this->client_id_production, $this->client_secret_production, $this->client_id_development, $this->client_secret_development, $this->sandbox, $this->payee_code );

		// Actions.
		add_action( 'woocommerce_api_WC_Gerencianet_Oficial_Gateway', array( $this, 'validate_notification' ) );
		add_action( 'validate_notification_request', array( $this, 'successful_request' ) );

		if ( $this->osc == 'no' ){
            wp_deregister_script('jquery');
            add_action( 'woocommerce_receipt_gerencianet_oficial', array($this, 'generate_gn_script'), 1);
        }

		add_action( 'woocommerce_receipt_gerencianet_oficial', array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
			$this,
			'process_admin_options'
		) );
		add_action( 'woocommerce_thankyou_gerencianet_oficial', array( $this, 'thankyou_page' ) );

		// Display admin notices.
		$this->admin_notices();
	}

	/**
	 * Backwards compatibility with version prior to 2.1.
	 *
	 * @return object Returns the main instance of WooCommerce class.
	 */
	protected function woocommerce_instance() {
		if ( function_exists( 'WC' ) ) {
			return WC();
		} else {
			global $woocommerce;

			return $woocommerce;
		}
	}

	/**
	 * Displays notifications when the admin has something wrong with the configuration.
	 *
	 * @return void
	 */
	protected function admin_notices() {
		if ( is_admin() ) {
			// Valid for use.
			if ( empty( $this->payee_code ) ) {
				add_action( 'admin_notices', array( $this, 'payee_code_missing_message' ) );
			}

			if ( $this->sandbox == "yes" ) {
				add_action( 'admin_notices', array( $this, 'sandbox_active_message' ) );
			}

			if ( 'no' == $this->billet_banking && 'no' == $this->credit_card ) {
				add_action( 'admin_notices', array( $this, 'payment_option_missing_message' ) );
			}

			if ( empty( $this->client_id_production ) || empty( $this->client_secret_production ) || empty( $this->client_id_development ) || empty( $this->client_secret_development ) ) {
				add_action( 'admin_notices', array( $this, 'credentials_missing_message' ) );
			}

			// Checks that the currency is supported
			if ( ! $this->using_supported_currency() ) {
				add_action( 'admin_notices', array( $this, 'currency_not_supported_message' ) );
			}
		}
	}

	/**
	 * Returns a bool that indicates if currency is amongst the supported ones.
	 *
	 * @return bool
	 */
	public function using_supported_currency() {
		return ( 'BRL' == get_woocommerce_currency() );
	}

	/**
	 * Returns a value indicating the the Gateway is available or not. It's called
	 * automatically by WooCommerce before allowing customers to use the gateway
	 * for payment.
	 *
	 * @return bool
	 */
	public function is_available() {
		// Check if all required credentials and configurations are defined to sucessfully active the module
		$available = ( 'yes' == $this->settings['enabled'] ) && $this->using_supported_currency() && ! empty( $this->payee_code ) && ! empty( $this->client_id_production ) && ! empty( $this->client_secret_production ) && ! empty( $this->client_id_development ) && ! empty( $this->client_secret_development );

		return $available;
	}

	/**
	 * Convert value to gn format
	 *
	 * @param float $number Product price
	 *
	 * @return int Formated price
	 */
	public function gn_price_format( $value ) {

		$value = number_format( $value, 2, "", "" );

		return $value;
	}

	/**
	 * Call plugin scripts in front-end.
	 *
	 * @return void
	 */
	public function scripts() {
		wp_register_script( 'jquery-wp', '/wp-includes/js/jquery/jquery.js', false );
		wp_enqueue_script( 'jquery-wp' );
		wp_enqueue_script( 'wc-gerencianet-checkout', plugins_url( 'assets/js/checkout.js', plugin_dir_path( __FILE__ ) ), array( 'jquery-wp' ), '', true );
		wp_enqueue_script( 'jquery-mask-gn', plugins_url( 'assets/js/jquery.maskedinput.js', plugin_dir_path( __FILE__ ) ), array( 'jquery-wp' ), '', true );
		wp_localize_script(
			'wc-gerencianet-checkout',
			'woocommerce_gerencianet_api',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'security' => wp_create_nonce( 'woocommerce_gerencianet' ),
			)
		);
	}

	/**
	 * Call plugin styles in front-end.
	 *
	 * @return void
	 */
	public function styles() {
		wp_enqueue_style( 'wc-gerencianet-checkout', plugins_url( 'assets/css/checkout.css', plugin_dir_path( __FILE__ ) ), array(), '', 'all' );
	}

	/**
	 * Admin Panel Options.
	 */
	public function admin_options() {
		wp_enqueue_style( 'wc-gerencianet-checkout', plugins_url( 'assets/css/admin.css', plugin_dir_path( __FILE__ ) ), array(), '', 'all' );
		wp_enqueue_script( 'wc-gerencianet', plugins_url( 'assets/js/admin.min.js', plugin_dir_path( __FILE__ ) ), array( 'jquery' ), '', true );
		wp_enqueue_script( 'jquery' );
		wp_enqueue_script( 'jquery-mask', plugins_url( 'assets/js/jquery.mask.min.js', plugin_dir_path( __FILE__ ) ), array( 'jquery' ), '', true );

		echo '<h3>' . __( 'Gerencianet Payments - Official Module', WCGerencianetOficial::getTextDomain() ) . ' v' . WCGerencianetOficial::VERSION . '</h3>';
		echo '<p>' . __( 'This module is the official module of Gerencianet Payment Gateway. If you have any doubths or suggestions, contact us at <a href="http://www.gerencianet.com.br">www.gerencianet.com.br</a>', WCGerencianetOficial::getTextDomain() ) . '</p>';

		// Generate the HTML For the settings form.
		echo '<table class="form-table">';
		$this->generate_settings_html();
		echo '</table>';

		echo '<script>var plugin_images_url = "' . plugins_url( 'assets/images/', plugin_dir_path( __FILE__ ) ) . '";
		var ajax_url = "' . admin_url( 'admin-ajax.php' ) . '";</script>
			<div id="tutorialGnBox" class="gn-admin-tutorial-box">
				<div class="gn-admin-tutorial-row">
				    <div class="gn-admin-tutorial-line">
				      <div class="pull-right gn-admin-tutorial-close">
				        Fechar <b>X</b>
				      </div>
				    </div>
				    <img id="imgTutorial" />
				 </div>
			</div>';

	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 *
	 * @return void
	 */
	public function init_form_fields() {

		$this->form_fields = array(
			'enabled'                    => array(
				'title'   => __( 'Enable/Disable', WCGerencianetOficial::getTextDomain() ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Gerencianet Payments', WCGerencianetOficial::getTextDomain() ),
				'default' => 'yes'
			),
			'title'                      => array(
				'title'       => __( 'Title', WCGerencianetOficial::getTextDomain() ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', WCGerencianetOficial::getTextDomain() ),
				'desc_tip'    => true,
				'default'     => __( 'Gerencianet', WCGerencianetOficial::getTextDomain() )
			),
			'description'                => array(
				'title'       => __( 'Description', WCGerencianetOficial::getTextDomain() ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', WCGerencianetOficial::getTextDomain() ),
				'default'     => __( 'Pay via gerencianet', WCGerencianetOficial::getTextDomain() )
			),
			'invoice_prefix'             => array(
				'title'       => __( 'Invoice Prefix', WCGerencianetOficial::getTextDomain() ),
				'type'        => 'text',
				'description' => __( 'Please enter a prefix for your invoice numbers. If you use your gerencianet account for multiple stores ensure this prefix is unqiue as gerencianet will not allow orders with the same invoice number.', WCGerencianetOficial::getTextDomain() ),
				'desc_tip'    => true,
				'default'     => 'WC-'
			),
			'api_section'                => array(
				'title'       => __( 'Gerencianet Credentials', WCGerencianetOficial::getTextDomain() ),
				'type'        => 'title',
				'description' => __( "Where will you find your credentials: <a id='showKeysProductionTutorial' class='gn-admin-cursor-pointer'>Client ID/Secret Production</a>, <a id='showKeysDevelopmentTutorial' class='gn-admin-cursor-pointer'>Client ID/Secret Development</a> and <a id='showPayeeCodeTutorial' class='gn-admin-cursor-pointer'>Payee Code</a>", WCGerencianetOficial::getTextDomain() ),
			),
			'client_id_production'       => array(
				'title'       => __( 'Client Id Production', WCGerencianetOficial::getTextDomain() ),
				'type'        => 'text',
				'description' => __( 'Please enter your Client Id Production; this is needed in order to take payment.', WCGerencianetOficial::getTextDomain() ),
				'desc_tip'    => true,
				'default'     => ''
			),
			'client_secret_production'   => array(
				'title'       => __( 'Client Secret Production', WCGerencianetOficial::getTextDomain() ),
				'type'        => 'text',
				'description' => __( 'Please enter your Client Secret Production; this is needed in order to take payment.', WCGerencianetOficial::getTextDomain() ),
				'desc_tip'    => true,
				'default'     => ''
			),
			'client_id_development'      => array(
				'title'       => __( 'Client ID Development', WCGerencianetOficial::getTextDomain() ),
				'type'        => 'text',
				'description' => __( 'Please enter your Client Id Development; this is needed to test payment.', WCGerencianetOficial::getTextDomain() ),
				'desc_tip'    => true,
				'default'     => ''
			),
			'client_secret_development'  => array(
				'title'       => __( 'Client Secret Development', WCGerencianetOficial::getTextDomain() ),
				'type'        => 'text',
				'description' => __( 'Please enter your Client Secret Development; this is needed to test payment.', WCGerencianetOficial::getTextDomain() ),
				'desc_tip'    => true,
				'default'     => ''
			),
			'payee_code'                 => array(
				'title'       => __( 'Payee Code', WCGerencianetOficial::getTextDomain() ),
				'type'        => 'text',
				'description' => __( 'Please enter your account payee code; this is needed in order to take payment.', WCGerencianetOficial::getTextDomain() ),
				'desc_tip'    => true,
				'default'     => ''
			),
			'osc_section'                => array(
				'title'       => __( 'One Step Checkout', WCGerencianetOficial::getTextDomain() ),
				'type'        => 'title',
				'description' => __( 'This option allow the payment direct on checkout page. Before use on production, please do some test payments in sandbox mode to verify if it is compatible with your store.', WCGerencianetOficial::getTextDomain() ),
			),
			'osc_option'                 => array(
				'title'   => __( 'One Step Checkout', WCGerencianetOficial::getTextDomain() ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable One Step Checkout', WCGerencianetOficial::getTextDomain() ),
				'default' => 'no'
			),
			'payment_section'            => array(
				'title'       => __( 'Payment Settings', WCGerencianetOficial::getTextDomain() ),
				'type'        => 'title',
				'description' => __( 'These options need to be available to you in your gerencianet account.', WCGerencianetOficial::getTextDomain() ),
			),
			'billet_banking'             => array(
				'title'   => __( 'Billet Banking', WCGerencianetOficial::getTextDomain() ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Billet Banking', WCGerencianetOficial::getTextDomain() ),
				'default' => 'yes'
			),
			'credit_card'                => array(
				'title'   => __( 'Credit Card', WCGerencianetOficial::getTextDomain() ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Credit Card', WCGerencianetOficial::getTextDomain() ),
				'default' => 'yes'
			),
			'billet_section'             => array(
				'title'       => __( 'Billet Settings', WCGerencianetOficial::getTextDomain() ),
				'type'        => 'title',
				'description' => '',
			),
			'billet_discount'            => array(
				'title'       => __( 'Billet discount', WCGerencianetOficial::getTextDomain() ),
				'type'        => 'text',
				'description' => __( 'Discount for payment with billet.', WCGerencianetOficial::getTextDomain() ),
				'desc_tip'    => true,
				'placeholder' => '0%',
				'default'     => '0%'
			),
			'billet_discount_shipping'   => array(
				'title'       => __( 'Apply discount mode', WCGerencianetOficial::getTextDomain() ),
				'type'        => 'select',
				'description' => __( 'Choose mode of discount.', WCGerencianetOficial::getTextDomain() ),
				'default'     => 'total',
				'options'     => array(
					'total'    => __( 'Apply discount on total value with Shipping', WCGerencianetOficial::getTextDomain() ),
					'products' => __( 'Apply discount just on products price', WCGerencianetOficial::getTextDomain() ),
				)
			),
			'billet_number_days'         => array(
				'title'       => __( 'Number of Days', WCGerencianetOficial::getTextDomain() ),
				'type'        => 'text',
				'description' => __( 'Days to expire the billet after printed.', WCGerencianetOficial::getTextDomain() ),
				'desc_tip'    => true,
				'placeholder' => '5',
				'default'     => '5'
			),
			'billet_instructions_title'  => array(
				'title'       => __( 'Billet Instructions (not required)', WCGerencianetOficial::getTextDomain() ),
				'type'        => 'title',
				'description' => '',
			),
			'billet_instructions_line_1' => array(
				'title'       => __( 'Instructions line 1', WCGerencianetOficial::getTextDomain() ),
				'type'        => 'text',
				'description' => __( 'Text of billet instructions. Maximum of 90 characters per line.', WCGerencianetOficial::getTextDomain() ),
				'desc_tip'    => true,
				'placeholder' => '',
				'default'     => ''
			),
			'billet_instructions_line_2' => array(
				'title'       => __( 'Instructions line 2', WCGerencianetOficial::getTextDomain() ),
				'type'        => 'text',
				'description' => __( 'Text of billet instructions. Maximum of 90 characters per line.', WCGerencianetOficial::getTextDomain() ),
				'desc_tip'    => true,
				'placeholder' => '',
				'default'     => ''
			),
			'billet_instructions_line_3' => array(
				'title'       => __( 'Instructions line 3', WCGerencianetOficial::getTextDomain() ),
				'type'        => 'text',
				'description' => __( 'Text of billet instructions. Maximum of 90 characters per line.', WCGerencianetOficial::getTextDomain() ),
				'desc_tip'    => true,
				'placeholder' => '',
				'default'     => ''
			),
			'billet_instructions_line_4' => array(
				'title'       => __( 'Instructions line 4', WCGerencianetOficial::getTextDomain() ),
				'type'        => 'text',
				'description' => __( 'Text of billet instructions. Maximum of 90 characters per line.', WCGerencianetOficial::getTextDomain() ),
				'desc_tip'    => true,
				'placeholder' => '',
				'default'     => ''
			),
			'callback_section'           => array(
				'title'       => __( 'Callback', WCGerencianetOficial::getTextDomain() ),
				'type'        => 'title',
				'description' => '',
			),
			'callback'                   => array(
				'title'   => __( 'Auto order status update', WCGerencianetOficial::getTextDomain() ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable order auto update', WCGerencianetOficial::getTextDomain() ),
				'default' => 'yes'
			),
			'sandbox_section'            => array(
				'title'       => __( 'Tests', WCGerencianetOficial::getTextDomain() ),
				'type'        => 'title',
				'description' => '',
			),
			'sandbox'                    => array(
				'title'   => __( 'Sandbox', WCGerencianetOficial::getTextDomain() ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable gerencianet sandbox', WCGerencianetOficial::getTextDomain() ),
				'default' => 'no'
			),
			'debug'                      => array(
				'title'   => __( 'Debug', WCGerencianetOficial::getTextDomain() ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable debug for Gerencianet', WCGerencianetOficial::getTextDomain() ),
				'default' => 'no'
			)
		);
	}

	/**
	 * Add error message in checkout.
	 *
	 * @param string $message Error message.
	 *
	 * @return string
	 */
	public function add_error( $message ) {
		wc_add_notice( $message, 'error' );
	}

	/**
	 * Request Gerencianet API Installments.
	 *
	 * @return string
	 */
	public function gerencianet_validate_credentials() {

		$gnApiResult = $this->gnIntegration->validate_credentials( $_POST['client_id'], $_POST['client_secret'], $_POST['mode'] );

		return $gnApiResult;
	}

	/**
	 * Request Gerencianet API Installments.
	 *
	 * @return string
	 */
	public function gerencianet_get_installments() {

		$post_order_id = sanitize_text_field( $_POST['order_id'] );
		$post_brand    = sanitize_text_field( $_POST['brand'] );

		if ( $post_order_id == "" ) {
			$value    = WC()->cart->get_cart_total();
			$shipping = WC()->cart->get_cart_shipping_total();
			$tax      = WC()->cart->get_cart_tax();
			$total    = ( (int) preg_replace( "/[^0-9]/", "", html_entity_decode( $value ) ) + (int) preg_replace( "/[^0-9]/", "", html_entity_decode( $shipping ) ) + (int) preg_replace( "/[^0-9]/", "", html_entity_decode( $tax ) ) );
		} else {
			$meta_discount_value_array = get_post_meta( intval( $post_order_id ), 'billet_discount_value' );
			if ( isset( $meta_discount_value_array[0] ) ) {
				$meta_discount_value = $meta_discount_value_array[0];
			} else {
				$meta_discount_value = 0;
			}
			$order = wc_get_order( $post_order_id );
			$total = $this->gn_price_format( $order->get_total() ) + $meta_discount_value;
		}


		$brand       = esc_attr( $post_brand );
		$gnApiResult = $this->gnIntegration->get_installments( $total, $brand );

		$resultCheck = array();
		$resultCheck = json_decode( $gnApiResult, true );

		if ( isset( $resultCheck["code"] ) ) {
			if ( $resultCheck["code"] == 200 ) {
				if ( 'yes' == $this->debug ) {
					write_log( 'GERENCIANET :: gerencianet_get_installments Request : SUCCESS' );
				}
			} else {
				if ( 'yes' == $this->debug ) {
					write_log( 'GERENCIANET :: gerencianet_get_installments Request : ERROR' );
				}
			}
		}

		return $gnApiResult;
	}

	/**
	 * Request Gerencianet API Create Charge.
	 *
	 * @return string
	 */
	public function gerencianet_create_charge( $checkout_type, $order_id ) {

		if ( $checkout_type == "OSC" ) {
			$post_order_id = $order_id;
		} else {
			if ( count( $_POST ) < 1 ) {
				$errorResponse = array(
					"message" => __( "An error occurred during your request. Please, try again.", WCGerencianetOficial::getTextDomain() )
				);

				return json_encode( $errorResponse );
			}

			$arrayDadosPost = array();
			foreach ( $_POST as $key => $value ) {
				$arrayDadosPost[ $key ] = sanitize_text_field( $value );
			}

			$post_order_id = $arrayDadosPost['order_id'];
		}

		$order = wc_get_order( $post_order_id );

		$order_items = $order->get_items();

		$items = array();
		foreach ( $order_items as $items_key => $item ) {
			$item_name = $item->get_name();
			$item_meta = strip_tags( wc_display_item_meta( $item, array(
				'before'    => "",
				'separator' => ", ",
				'after'     => "",
				'echo'      => false,
				'autop'     => false,
			) ) );

			if ( $item_meta ) {
				$item_name .= ' (' . $item_meta . ')';
			}
			$items [] = array(
				"name"   => $item_name,
				"value"  => (int) $this->gn_price_format( $item ['line_subtotal'] / $item ['qty'] ),
				"amount" => (int) $item ['qty']
			);
		}

		$total_taxes = $order->get_total_tax();
		if ( $total_taxes > 0 ) {
			array_push( $items, array(
					"name"   => "Impostos Gerais",
					"value"  => (int) $this->gn_price_format( $total_taxes ),
					"amount" => 1
				)
			);
		}

		if ( $this->gn_price_format( $order->get_total_shipping() ) > 0 ) {
			$shipping = array(
				array(
					'name'  => $order->get_shipping_method(),
					'value' => (int) $this->gn_price_format( $order->get_total_shipping() )
				)
			);
		} else {
			$shipping = null;
		}

		$gnApiResult = $this->gnIntegration->create_charge( $post_order_id, $items, $shipping, WC()->api_request_url( 'WC_Gerencianet_Oficial_Gateway' ) );

		$resultCheck = array();
		$resultCheck = json_decode( $gnApiResult, true );

		if ( isset( $resultCheck["code"] ) ) {
			if ( $resultCheck["code"] == 200 ) {
				if ( 'yes' == $this->debug ) {
					write_log( 'GERENCIANET :: gerencianet_create_charge Request : SUCCESS' );
				}
			} else {
				if ( 'yes' == $this->debug ) {
					write_log( 'GERENCIANET :: gerencianet_create_charge Request : ERROR : ' . $resultCheck["code"] );
				}
			}
		} else {
			if ( 'yes' == $this->debug ) {
				write_log( 'GERENCIANET :: gerencianet_create_charge Request : ERROR : Ajax Request Fail' );
			}
		}

		return $gnApiResult;
	}

	/**
	 * Request Gerencianet API Pay Charge with billet.
	 *
	 * @return string
	 */
	public function gerencianet_pay_billet( $checkout_type, $order_id, $charge_id ) {

		$billetExpireDays = $this->billet_number_days;

		$expirationDate = date( "Y-m-d", mktime( 0, 0, 0, date( "m" ), date( "d" ) + intval( $billetExpireDays ), date( "Y" ) ) );

		if ( $checkout_type == "OSC" ) {
			if ( count( $_POST ) < 1 ) {
				$errorResponse = array(
					"message" => __( "An error occurred during your request. Please, try again.", WCGerencianetOficial::getTextDomain() )
				);

				return json_encode( $errorResponse );
			}

			$arrayDadosPost = array();
			foreach ( $_POST as $key => $value ) {
				if ( $key != "email" ) {
					$arrayDadosPost[ $key ] = sanitize_text_field( $value );
				} else {
					$arrayDadosPost[ $key ] = $value;
				}
			}

			$post_order_id       = $order_id;
			$post_name_corporate = $arrayDadosPost['gn_billet_name_corporate'];
			$post_cpf_cnpj       = preg_replace( '/[^0-9]/', '', $arrayDadosPost['gn_billet_cpf_cnpj'] );
			$post_email          = sanitize_email( $arrayDadosPost['gn_billet_email'] );
			$post_phone_number   = preg_replace( '/[^0-9]/', '', $arrayDadosPost['gn_billet_phone_number'] );
			$post_charge_id      = $charge_id;
		} else {
			if ( count( $_POST ) < 1 ) {
				$errorResponse = array(
					"message" => __( "An error occurred during your request. Please, try again.", WCGerencianetOficial::getTextDomain() )
				);

				return json_encode( $errorResponse );
			}

			$arrayDadosPost = array();
			foreach ( $_POST as $key => $value ) {
				if ( $key != "email" ) {
					$arrayDadosPost[ $key ] = sanitize_text_field( $value );
				} else {
					$arrayDadosPost[ $key ] = $value;
				}
			}

			$post_order_id       = $arrayDadosPost['order_id'];
			$post_name_corporate = $arrayDadosPost['name_corporate'];
			$post_cpf_cnpj       = $arrayDadosPost['cpf_cnpj'];
			$post_email          = sanitize_email( $arrayDadosPost['email'] );
			$post_phone_number   = $arrayDadosPost['phone_number'];
			$post_charge_id      = $arrayDadosPost['charge_id'];

		}

		if ( strlen( $post_cpf_cnpj ) > 11 ) {
			$juridical_data = array(
				'corporate_name' => $post_name_corporate,
				'cnpj'           => $post_cpf_cnpj
			);

			$customer = array(
				'phone_number'     => $post_phone_number,
				'email'            => $post_email,
				'juridical_person' => $juridical_data
			);
		} else {
			$customer = array(
				'name'         => $post_name_corporate,
				'cpf'          => $post_cpf_cnpj,
				'phone_number' => $post_phone_number,
				'email'        => $post_email
			);
		}

		$order = wc_get_order( $post_order_id );

		if ( $this->gn_price_format( $order->get_total_shipping() ) > 0 ) {
			$totalShipping = (int) $this->gn_price_format( $order->get_total_shipping() );
		} else {
			$totalShipping = 0;
		}

		if ( $this->gn_price_format( $order->get_total_tax() ) > 0 ) {
			$totalTax = (int) $this->gn_price_format( $order->get_total_tax() );
		} else {
			$totalTax = 0;
		}

		$meta_discount_value_array = get_post_meta( intval( $post_order_id ), 'billet_discount_value' );

		if ( $order->get_status() == "failed" || isset( $meta_discount_value_array[0] ) ) {
			$discountBillet     = $this->discountBillet;
			$discountTotalValue = (int) ( $this->gn_price_format( $order->get_total_discount() ) );
			if ( $this->billet_discount_shipping == "products" ) {
				$discountBilletTotal = (int) floor( ( $this->gn_price_format( $order->get_total() ) - $totalShipping - $totalTax ) * ( ( (float) $discountBillet / 100 ) ) );
			} else {
				$discountBilletTotal = (int) floor( ( $this->gn_price_format( $order->get_total() ) ) * ( ( (float) $discountBillet / 100 ) ) );
			}

		} else {
			$discountBillet = $this->discountBillet;
			if ( $this->billet_discount_shipping == "products" ) {
				$discountTotalValue  = (int) ( $this->gn_price_format( $order->get_total_discount() ) + floor( ( $this->gn_price_format( $order->get_total() ) - $totalShipping - $totalTax ) * ( ( (float) $discountBillet / 100 ) ) ) );
				$discountBilletTotal = (int) floor( ( $this->gn_price_format( $order->get_total() ) - $totalShipping - $totalTax ) * ( ( (float) $discountBillet / 100 ) ) );
			} else {
				$discountTotalValue  = (int) ( $this->gn_price_format( $order->get_total_discount() ) + floor( ( $this->gn_price_format( $order->get_total() ) ) * ( ( (float) $discountBillet / 100 ) ) ) );
				$discountBilletTotal = (int) floor( ( $this->gn_price_format( $order->get_total() ) ) * ( ( (float) $discountBillet / 100 ) ) );
			}

		}


		if ( $discountTotalValue > 0 ) {
			$discount = array(
				'type'  => 'currency',
				'value' => $discountTotalValue
			);
		} else {
			$discount = null;
		}

		$instructionsList = array(
			$this->instructions_line_1,
			$this->instructions_line_2,
			$this->instructions_line_3,
			$this->instructions_line_4
		);
		$instructions     = array();
		foreach ( $instructionsList as $instruction ) {
			if ( $instruction != '' && $instruction != null ) {
				array_push( $instructions, $instruction );
			}
		}

		$gnApiResult = $this->gnIntegration->pay_billet( $post_charge_id, $expirationDate, $customer, $instructions, $discount );

		$resultCheck = array();
		$resultCheck = json_decode( $gnApiResult, true );

		if ( isset( $resultCheck["code"] ) ) {
			if ( $resultCheck["code"] == 200 ) {
				if ( 'yes' == $this->debug ) {
					write_log( 'GERENCIANET :: gerencianet_pay_billet Request : SUCCESS ' );
				}
				global $wpdb;

				if ( $order->get_status() != "failed" && ! isset( $meta_discount_value_array[0] ) ) {
					$wpdb->insert( $wpdb->prefix . "woocommerce_order_items", array(
						'order_item_name' => __( 'Discount of ', WCGerencianetOficial::getTextDomain() ) . str_replace( ".", ",", $discountBillet ) . __( '% Billet', WCGerencianetOficial::getTextDomain() ),
						'order_item_type' => 'fee',
						'order_id'        => intval( $post_order_id )
					) );
					$lastid = $wpdb->insert_id;
					$wpdb->insert( $wpdb->prefix . "woocommerce_order_itemmeta", array(
						'order_item_id' => $lastid,
						'meta_key'      => '_tax_class',
						'meta_value'    => '0'
					) );

					if ( $this->billet_discount_shipping == "products" ) {
						$wpdb->insert( $wpdb->prefix . "woocommerce_order_itemmeta", array(
							'order_item_id' => $lastid,
							'meta_key'      => '_line_total',
							'meta_value'    => '-' . number_format( intval( floor( ( $this->gn_price_format( $order->get_total() ) - $totalShipping - $totalTax ) * ( ( (float) $discountBillet / 100 ) ) ) ) / 100, 2, '.', '' )
						) );
					} else {
						$wpdb->insert( $wpdb->prefix . "woocommerce_order_itemmeta", array(
							'order_item_id' => $lastid,
							'meta_key'      => '_line_total',
							'meta_value'    => '-' . number_format( intval( floor( ( $this->gn_price_format( $order->get_total() ) ) * ( ( (float) $discountBillet / 100 ) ) ) ) / 100, 2, '.', '' )
						) );
					}

					$wpdb->insert( $wpdb->prefix . "woocommerce_order_itemmeta", array(
						'order_item_id' => $lastid,
						'meta_key'      => '_line_tax',
						'meta_value'    => '0'
					) );
					$wpdb->insert( $wpdb->prefix . "woocommerce_order_itemmeta", array(
						'order_item_id' => $lastid,
						'meta_key'      => '_line_tax_data',
						'meta_value'    => '0'
					) );

					update_post_meta( intval( $post_order_id ), '_order_total', number_format( intval( ceil( $this->gn_price_format( $order->get_total() ) - ( $discountBilletTotal ) ) ) / 100, 2, '.', '' ) );

					update_post_meta( intval( $post_order_id ), '_payment_method_title', sanitize_text_field( __( 'Billet Banking - Gerencianet', WCGerencianetOficial::getTextDomain() ) ) );
					add_post_meta( intval( $post_order_id ), 'billet', $resultCheck['data']['link'], true );
					add_post_meta( intval( $post_order_id ), 'billet_discount_value', $discountBilletTotal, true );
				}
				$order->update_status( 'on-hold', __( 'Waiting' ) );
				$order->reduce_order_stock();
				WC()->cart->empty_cart();
			} else {
				if ( 'yes' == $this->debug ) {
					write_log( 'GERENCIANET :: gerencianet_pay_billet Request : ERROR : ' . $resultCheck["code"] );
				}
			}
		} else {
			if ( 'yes' == $this->debug ) {
				write_log( 'GERENCIANET :: gerencianet_pay_billet Request : ERROR : Ajax request fail' );
			}
		}

		return $gnApiResult;
	}

	/**
	 * Request Gerencianet API pay charge with Card.
	 *
	 * @return string
	 */
	public function gerencianet_pay_card( $checkout_type, $order_id, $charge_id ) {

		if ( $checkout_type == "OSC" ) {
			if ( count( $_POST ) < 1 ) {
				$errorResponse = array(
					"message" => __( "An error occurred during your request. Please, try again.", WCGerencianetOficial::getTextDomain() )
				);

				return json_encode( $errorResponse );
			}

			$arrayDadosPost = array();
			foreach ( $_POST as $key => $value ) {
				if ( $key != "email" ) {
					$arrayDadosPost[ $key ] = sanitize_text_field( $value );
				} else {
					$arrayDadosPost[ $key ] = $value;
				}
			}

			$post_order_id = $order_id;

			$birth = explode( "/", $arrayDadosPost['gn_card_birth'] );
			$birth = $birth[2] . "-" . $birth[1] . "-" . $birth[0];

			$post_name_corporate = $arrayDadosPost['gn_card_name_corporate'];
			$post_cpf_cnpj       = preg_replace( '/[^0-9]/', '', $arrayDadosPost['gn_card_cpf_cnpj'] );
			$post_phone_number   = preg_replace( '/[^0-9]/', '', $arrayDadosPost['gn_card_phone_number'] );
			$post_email          = sanitize_email( $arrayDadosPost['gn_card_email'] );
			$post_birth          = $birth;
			$post_street         = $arrayDadosPost['gn_card_street'];
			$post_number         = $arrayDadosPost['gn_card_street_number'];
			$post_neighborhood   = $arrayDadosPost['gn_card_neighborhood'];
			$post_zipcode        = preg_replace( '/[^0-9]/', '', $arrayDadosPost['gn_card_zipcode'] );
			$post_city           = $arrayDadosPost['gn_card_city'];
			$post_state          = $arrayDadosPost['gn_card_state'];
			$post_complement     = $arrayDadosPost['gn_card_complement'];
			$post_payment_token  = $arrayDadosPost['gn_card_payment_token'];
			$post_installments   = $arrayDadosPost['gn_card_installments'];
			$post_charge_id      = $charge_id;
		} else {
			if ( count( $_POST ) < 1 ) {
				$errorResponse = array(
					"message" => __( "An error occurred during your request. Please, try again.", WCGerencianetOficial::getTextDomain() )
				);

				return json_encode( $errorResponse );
			}

			$arrayDadosPost = array();
			foreach ( $_POST as $key => $value ) {
				if ( $key != "email" ) {
					$arrayDadosPost[ $key ] = sanitize_text_field( $value );
				} else {
					$arrayDadosPost[ $key ] = $value;
				}
			}

			$post_order_id = $arrayDadosPost['order_id'];

			$post_name_corporate = $arrayDadosPost['name_corporate'];
			$post_cpf_cnpj       = $arrayDadosPost['cpf_cnpj'];
			$post_phone_number   = $arrayDadosPost['phone_number'];
			$post_email          = sanitize_email( $arrayDadosPost['email'] );
			$post_birth          = $arrayDadosPost['birth'];
			$post_street         = $arrayDadosPost['street'];
			$post_number         = $arrayDadosPost['number'];
			$post_neighborhood   = $arrayDadosPost['neighborhood'];
			$post_zipcode        = preg_replace( '/[^0-9]/', '', $arrayDadosPost['zipcode'] );
			$post_city           = $arrayDadosPost['city'];
			$post_state          = $arrayDadosPost['state'];
			$post_complement     = $arrayDadosPost['complement'];
			$post_payment_token  = $arrayDadosPost['payment_token'];
			$post_installments   = $arrayDadosPost['installments'];
			$post_charge_id      = $arrayDadosPost['charge_id'];
		}

		if ( strlen( $post_cpf_cnpj ) > 11 ) {
			$juridical_data = array(
				'corporate_name' => $post_name_corporate,
				'cnpj'           => $post_cpf_cnpj
			);

			$customer = array(
				'phone_number'     => $post_phone_number,
				'juridical_person' => $juridical_data,
				'email'            => $post_email,
				'birth'            => $post_birth
			);
		} else {
			$customer = array(
				'name'         => $post_name_corporate,
				'cpf'          => $post_cpf_cnpj,
				'phone_number' => $post_phone_number,
				'email'        => $post_email,
				'birth'        => $post_birth
			);
		}

		$billingAddress = array(
			'street'       => $post_street,
			'number'       => $post_number,
			'neighborhood' => $post_neighborhood,
			'zipcode'      => $post_zipcode,
			'city'         => $post_city,
			'state'        => $post_state,
			'complement'   => $post_complement
		);

		$order = wc_get_order( $post_order_id );

		$discountTotalValue = (int) ( $this->gn_price_format( $order->get_total_discount() ) );

		if ( $discountTotalValue > 0 ) {
			$discount = array(
				'type'  => 'currency',
				'value' => $discountTotalValue
			);
		} else {
			$discount = null;
		}

		$gnApiResult = $this->gnIntegration->pay_card( (int) $post_charge_id, $post_payment_token, (int) $post_installments, $billingAddress, $customer, $discount );

		$resultCheck = array();
		$resultCheck = json_decode( $gnApiResult, true );

		if ( isset( $resultCheck["code"] ) ) {
			if ( $resultCheck["code"] == 200 ) {
				if ( 'yes' == $this->debug ) {
					write_log( 'GERENCIANET :: gerencianet_pay_card Request : SUCCESS ' );
				}
				update_post_meta( intval( $post_order_id ), '_payment_method_title', sanitize_text_field( __( 'Credit Card - Gerencianet', WCGerencianetOficial::getTextDomain() ) ) );

				$meta_discount_value_array = get_post_meta( intval( $post_order_id ), 'billet_discount_value' );

				if ( isset( $meta_discount_value_array[0] ) ) {
					if ( (int) $meta_discount_value_array[0] > 0 ) {
						update_post_meta( intval( $post_order_id ), '_order_total', number_format( intval( ceil( $this->gn_price_format( $order->get_total() ) + ( $meta_discount_value_array[0] ) ) ) / 100, 2, '.', '' ) );

						add_post_meta( intval( $post_order_id ), 'billet', '', true );
						add_post_meta( intval( $post_order_id ), 'billet_discount_value', '0', true );
					}
				}

				$order->update_status( 'on-hold', __( 'Waiting' ) );

				WC()->cart->empty_cart();
			} else {
				if ( 'yes' == $this->debug ) {
					write_log( 'GERENCIANET :: gerencianet_pay_card Request : ERROR : ' . $resultCheck["code"] );
				}
			}
		} else {
			if ( 'yes' == $this->debug ) {
				write_log( 'GERENCIANET :: gerencianet_pay_card Request : ERROR : Ajax Request Fail' );
			}
		}

		return $gnApiResult;
	}

	/**
	 * Generate Gerencianet script to get payment token 
	 */
	public function generate_gn_script(){
        $script = $this->gnIntegration->get_gn_script();
        echo $script;
    }

	/**
	 * Generate the form for payment
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return string           Payment form.
	 */
	protected function generate_payment_page_options( $order_id ) {
		$this->styles();
		$this->scripts();

		if ( $this->sandbox == "yes" ) {
			$script = htmlentities( html_entity_decode( "var s=document.createElement('script');s.type='text/javascript';var v=parseInt(Math.random()*1000000);s.src='https://sandbox.gerencianet.com.br/v1/cdn/" . $this->payee_code . "/'+v;s.async=false;s.id='" . $this->payee_code . "';if(!document.getElementById('" . $this->payee_code . "')){document.getElementsByTagName('head')[0].appendChild(s);};&#36;gn={validForm:true,processed:false,done:{},ready:function(fn){&#36;gn.done=fn;}};" ) );
		} else {
			$script = htmlentities( html_entity_decode( "var s=document.createElement('script');s.type='text/javascript';var v=parseInt(Math.random()*1000000);s.src='https://api.gerencianet.com.br/v1/cdn/" . $this->payee_code . "/'+v;s.async=false;s.id='" . $this->payee_code . "';if(!document.getElementById('" . $this->payee_code . "')){document.getElementsByTagName('head')[0].appendChild(s);};&#36;gn={validForm:true,processed:false,done:{},ready:function(fn){&#36;gn.done=fn;}};" ) );
		}

		$sandbox = $this->sandbox;

		$order = wc_get_order( $order_id );

		if ( $this->gn_price_format( $order->get_total_shipping() ) > 0 ) {
			$totalShipping = (int) $this->gn_price_format( $order->get_total_shipping() );
		} else {
			$totalShipping = 0;
		}

		if ( $this->gn_price_format( $order->get_total_tax() ) > 0 ) {
			$totalTax = (int) $this->gn_price_format( $order->get_total_tax() );
		} else {
			$totalTax = 0;
		}

		$discount                  = $this->discountBillet;
		$meta_discount_value_array = get_post_meta( intval( $order_id ), 'billet_discount_value' );

		if ( isset( $meta_discount_value_array[0] ) ) {
			$discount            = 0;
			$meta_discount_value = (int) $meta_discount_value_array[0];
		} else {
			$meta_discount_value = 0;
		}

		$billet_option = $this->billet_banking;
		$card_option   = $this->credit_card;

		$order_received_url = $order->get_checkout_order_received_url();

		$max_installments = $this->gnIntegration->max_installments( $this->gn_price_format( $order->get_total() ) + $meta_discount_value );


		//alterei
		if ( $this->billet_discount_shipping == "products" ) {
			$order_with_billet_discount = $this->gnIntegration->formatCurrencyBRL( ceil( ( $this->gn_price_format( $order->get_total() ) - $totalShipping - $totalTax ) * ( 1 - ( (float) $discount / 100 ) ) + $totalShipping ) + $totalTax );
		} else {
			$order_with_billet_discount = $this->gnIntegration->formatCurrencyBRL( ceil( ( $this->gn_price_format( $order->get_total() ) ) * ( 1 - ( (float) $discount / 100 ) ) ) );
		}


		$order_total = $this->gnIntegration->formatCurrencyBRL( $this->gn_price_format( $order->get_total() ) + $meta_discount_value );

		$order_total_card = $this->gn_price_format( $order->get_total() ) + $meta_discount_value;

		//alterei
		if ( $this->billet_discount_shipping == "products" ) {
			$order_total_billet = ceil( ( $this->gn_price_format( $order->get_total() ) - $totalShipping - $totalTax ) * ( 1 - ( (float) $discount / 100 ) ) + $meta_discount_value );
		} else {
			$order_total_billet = ceil( ( $this->gn_price_format( $order->get_total() ) ) * ( 1 - ( (float) $discount / 100 ) ) + $meta_discount_value );
		}


		$gn_card_payment_comments          = __( "Opting to pay by credit card, the payment is processed and the confirmation will take place within 48 hours.", WCGerencianetOficial::getTextDomain() );
		$gn_billet_payment_method_comments = __( "Opting to pay by Banking Billet, the confirmation will be performed on the next business day after payment.", WCGerencianetOficial::getTextDomain() );
		$gn_name_corporate                 = __( "Name/Company:", WCGerencianetOficial::getTextDomain() );
		$gn_name                           = __( "Name:", WCGerencianetOficial::getTextDomain() ); //novo
		$gn_corporate                      = __( "Company:", WCGerencianetOficial::getTextDomain() ); //novo
		$gn_cpf_cnpj                       = __( "CPF/CNPJ: ", WCGerencianetOficial::getTextDomain() );
		$gn_cpf                            = __( "CPF: ", WCGerencianetOficial::getTextDomain() ); //novo
		$gn_cnpj                           = __( "CNPJ: ", WCGerencianetOficial::getTextDomain() ); //novo
		$gn_phone                          = __( "Phone: ", WCGerencianetOficial::getTextDomain() );
		$gn_birth                          = __( "Birth Date: ", WCGerencianetOficial::getTextDomain() );
		$gn_email                          = __( "E-mail: ", WCGerencianetOficial::getTextDomain() );
		$gn_street                         = __( "Address: ", WCGerencianetOficial::getTextDomain() );
		$gn_street_number                  = __( "Number: ", WCGerencianetOficial::getTextDomain() );
		$gn_neighborhood                   = __( "Neighborhood: ", WCGerencianetOficial::getTextDomain() );
		$gn_address_complement             = __( "Complement: ", WCGerencianetOficial::getTextDomain() );
		$gn_cep                            = __( "Zipcode: ", WCGerencianetOficial::getTextDomain() );
		$gn_city                           = __( "City: ", WCGerencianetOficial::getTextDomain() );
		$gn_state                          = __( "State: ", WCGerencianetOficial::getTextDomain() );
		$gn_card_title                     = __( "Billing Data", WCGerencianetOficial::getTextDomain() );
		$gn_card_number                    = __( "Card Number: ", WCGerencianetOficial::getTextDomain() );
		$gn_card_expiration                = __( "Expiration date: ", WCGerencianetOficial::getTextDomain() );
		$gn_card_cvv                       = __( "Security Code: ", WCGerencianetOficial::getTextDomain() );
		$gn_card_installments_options      = __( "Installments: ", WCGerencianetOficial::getTextDomain() );
		$gn_card_brand                     = __( "Select the card brand", WCGerencianetOficial::getTextDomain() );

		$gn_mininum_gn_charge_price = __( "You can not pay this order with Gerencianet because the total value is less than R$5,00.", WCGerencianetOficial::getTextDomain() );
		$gn_pay_billet_option       = __( "Pay with Billet Banking", WCGerencianetOficial::getTextDomain() );
		$gn_discount_billet         = __( "Discount of ", WCGerencianetOficial::getTextDomain() );
		$gn_pay_card_option         = __( "Pay with Credit Card", WCGerencianetOficial::getTextDomain() );
		$gn_installments_pay        = __( "Pay in", WCGerencianetOficial::getTextDomain() );
		$gn_billing_address_title   = __( "Billing Address", WCGerencianetOficial::getTextDomain() );
		$gn_billing_state_select    = __( "Select the state", WCGerencianetOficial::getTextDomain() );
		$gn_card_cvv_tip            = __( "Are the last three digits<br>on the back of the card.", WCGerencianetOficial::getTextDomain() );
		$gn_card_brand_select       = __( "Select the Card Brand", WCGerencianetOficial::getTextDomain() );
		$gn_loading_payment_request = __( "Please, wait...", WCGerencianetOficial::getTextDomain() );

		$gn_warning_sandbox_message = __( "Sandbox mode is active. The payments will not be valid.", WCGerencianetOficial::getTextDomain() );


		$gn_billing_cpf_cnpj_validate       = false;
		$gn_billing_name_corporate_validate = false;
		$gn_billing_phone_number_validate   = false;
		$gn_billing_email_validate          = false;
		$gn_billing_birthdate_validate      = false;
		$gn_billing_street_validate         = false;
		$gn_billing_number_validate         = false;
		$gn_billing_neighborhood_validate   = false;
		$gn_billing_complement_validate     = false;
		$gn_billing_state_validate          = false;
		$gn_billing_city_validate           = false;
		$gn_billing_zipcode_validate        = false;

		$validate = new GerencianetValidation();

		$gn_order_cpf_cnpj       = '';
		$gn_order_name_corporate = '';

		if ( isset( $order->billing_persontype ) && $order->billing_persontype == "1" ) {
			if ( isset( $order->billing_cpf ) ) {
				if ( $validate->_cpf( $order->billing_cpf ) ) {
					$gn_billing_cpf_cnpj_validate = true;
					$gn_order_cpf_cnpj            = $order->billing_cpf;
				}
			}

			if ( $validate->_name( $order->get_formatted_billing_full_name() ) ) {
				$gn_billing_name_corporate_validate = true;
				$gn_order_name_corporate            = $order->get_formatted_billing_full_name();
			}
		} else {
			if ( isset( $order->billing_cnpj ) ) {
				if ( $validate->_cnpj( $order->billing_cnpj ) ) {
					$gn_billing_cpf_cnpj_validate = true;
					$gn_order_cpf_cnpj            = $order->billing_cnpj;
				}
			}

			if ( $validate->_corporate( $order->billing_company ) ) {
				$gn_billing_name_corporate_validate = true;
				$gn_order_name_corporate            = $order->billing_company;
			}
		}

		if ( isset( $order->billing_email ) ) {
			if ( $validate->_email( $order->billing_email ) ) {
				$gn_billing_email_validate = true;
			}
		}

		if ( isset( $order->billing_phone ) ) {
			if ( $validate->_phone_number( $order->billing_phone ) ) {
				$gn_billing_phone_number_validate = true;
			}
		}

		if ( isset( $order->billing_birthdate ) ) {
			if ( $validate->_birthdate( $order->billing_birthdate ) ) {
				$gn_billing_birthdate_validate = true;
			}
		}

		if ( isset( $order->billing_address_1 ) ) {
			if ( $validate->_street( $order->billing_address_1 ) ) {
				$gn_billing_street_validate = true;
			}
		}

		if ( isset( $order->billing_number ) ) {
			if ( $validate->_number( $order->billing_number ) ) {
				$gn_billing_number_validate = true;
			}
		}

		if ( isset( $order->billing_neighborhood ) ) {
			if ( $validate->_neighborhood( $order->billing_neighborhood ) ) {
				$gn_billing_neighborhood_validate = true;
			}
		}

		if ( isset( $order->billing_city ) ) {
			if ( $validate->_city( $order->billing_city ) ) {
				$gn_billing_city_validate = true;
			}
		}

		if ( isset( $order->billing_city ) ) {
			if ( $validate->_city( $order->billing_city ) ) {
				$gn_billing_city_validate = true;
			}
		}

		if ( isset( $order->billing_postcode ) ) {
			if ( $validate->_zipcode( $order->billing_postcode ) ) {
				$gn_billing_zipcode_validate = true;
			}
		}

		if ( isset( $order->billing_state ) ) {
			if ( $validate->_state( $order->billing_state ) ) {
				$gn_billing_state_validate = true;
			}
		}


		ob_start();
		include plugin_dir_path( dirname( __FILE__ ) ) . 'templates/transparent-checkout.php';
		$html = ob_get_clean();

		return $html;
	}

	/**
	 * Payment fields.
	 */
	public function payment_fields() {

		global $woocommerce, $post, $order_id;

		if ( $this->checkout_type == "OSC" ) {

			$this->styles();
			wp_enqueue_script( 'jquery' );
			wp_enqueue_script( 'jquery-mask-gn', plugins_url( 'assets/js/jquery.maskedinput.js', plugin_dir_path( __FILE__ ) ), array( 'jquery' ), '', true );
			//wp_enqueue_script( 'wc-gerencianet-checkout-osc', plugins_url( 'assets/js/checkout-osc.js', plugin_dir_path( __FILE__ ) ), array( 'jquery' ), '', true );
			wp_localize_script(
				'wc-gerencianet-checkout',
				'woocommerce_gerencianet_api',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'security' => wp_create_nonce( 'woocommerce_gerencianet' ),
				)
			);

			if ( $this->sandbox == "yes" ) {
				$script = htmlentities( html_entity_decode( "var s=document.createElement('script');s.type='text/javascript';var v=parseInt(Math.random()*1000000);s.src='https://sandbox.gerencianet.com.br/v1/cdn/" . $this->payee_code . "/'+v;s.async=false;s.id='" . $this->payee_code . "';if(!document.getElementById('" . $this->payee_code . "')){document.getElementsByTagName('head')[0].appendChild(s);};&#36;gn={validForm:true,processed:false,done:{},ready:function(fn){&#36;gn.done=fn;}};" ) );
			} else {
				$script = htmlentities( html_entity_decode( "var s=document.createElement('script');s.type='text/javascript';var v=parseInt(Math.random()*1000000);s.src='https://api.gerencianet.com.br/v1/cdn/" . $this->payee_code . "/'+v;s.async=false;s.id='" . $this->payee_code . "';if(!document.getElementById('" . $this->payee_code . "')){document.getElementsByTagName('head')[0].appendChild(s);};&#36;gn={validForm:true,processed:false,done:{},ready:function(fn){&#36;gn.done=fn;}};" ) );
			}

			if ( ! $order_id && isset( $_GET[ key ] ) ) {
				$order_id = wc_get_order_id_by_order_key( sanitize_text_field( $_GET[ key ] ) );
			}
			if ( $order_id ) {
				$order = wc_get_order( $order_id );
			} else {
				$order = null;
			}
			if ( $order ) {
				$meta_discount_value_array = get_post_meta( intval( $order_id ), 'billet_discount_value' );
				/* **************************** Verify if billet discount exists ********************************* */

				if ( isset( $meta_discount_value_array[0] ) ) {
					$meta_discount_value = $meta_discount_value_array[0];
				} else {
					$meta_discount_value = 0;
				}

				/* ***************************** Verify if exists shipping **************************************** */

				if ( $this->gn_price_format( $order->get_total_shipping() ) > 0 ) {
					$totalShipping = (int) $this->gn_price_format( $order->get_total_shipping() );
				} else {
					$totalShipping = 0;
				}

				/* ***************************** Verify if exists tax ******************************************** */

				if ( $this->gn_price_format( $order->get_total_tax() ) > 0 ) {
					$totalTax = (int) $this->gn_price_format( $order->get_total_tax() );
				} else {
					$totalTax = 0;
				}

				/* *********************************************************************************************** */

				$order_total_value_without_shipping_and_tax = $this->gn_price_format( $order->get_total() ) - $totalShipping - $totalTax;
				$order_total_tax_value                      = $totalTax;
				$order_total_shipping_value                 = $totalShipping;
			} else {
				$meta_discount_value                        = 0;
				$order_total_tax_value                      = WC()->cart->get_cart_tax();
				$order_total_value_without_shipping_and_tax = WC()->cart->get_cart_total();
				$order_total_shipping_value                 = WC()->cart->get_cart_shipping_total();
			}

			$order_total = ( (int) preg_replace( "/[^0-9]/", "", html_entity_decode( $order_total_value_without_shipping_and_tax ) ) ) / 100 + ( (int) preg_replace( "/[^0-9]/", "", html_entity_decode( $order_total_shipping_value ) ) ) / 100 + ( (int) preg_replace( "/[^0-9]/", "", html_entity_decode( $order_total_tax_value ) ) ) / 100;

			$order_total_without_shipping = ( (int) preg_replace( "/[^0-9]/", "", html_entity_decode( $order_total_value_without_shipping_and_tax ) ) ) / 100;

			if ( $meta_discount_value > 0 ) {
				$discount = 0;
			} else {
				$discount = $this->discountBillet;
			}

			$discountBilletFormatted = str_replace( ".", ",", $discount );

			if ( $this->billet_discount_shipping == "products" ) {
				$total_order_pay_by_billet = ceil( $this->gn_price_format( $order_total_without_shipping ) * ( 1 - ( (float) $discount / 100 ) ) + ( (int) preg_replace( "/[^0-9]/", "", html_entity_decode( $order_total_shipping_value ) ) ) + ( (int) preg_replace( "/[^0-9]/", "", html_entity_decode( $order_total_tax_value ) ) ) );
			} else {
				$total_order_pay_by_billet = ceil( $this->gn_price_format( $order_total ) * ( 1 - ( (float) $discount / 100 ) ) );
			}

			$total_order_pay_by_card = $this->gn_price_format( $order_total ) + $meta_discount_value;
			$discount_value          = $total_order_pay_by_card - $total_order_pay_by_billet;

			if(is_ajax())
				wc_get_template( 'transparent-osc.php', array(
					'order_id'                          => $order_id,
					'ajax_url'                          => admin_url( 'admin-ajax.php' ),
					'script_load'                       => $script,
					'discount'                          => $discount,
					'discount_formatted'                => $discountBilletFormatted,
					'billet_option'                     => $this->billet_banking,
					'card_option'                       => $this->credit_card,
					'max_installments'                  => $this->gnIntegration->max_installments( $total_order_pay_by_card ),
					'order_with_billet_discount'        => $this->gnIntegration->formatCurrencyBRL( $total_order_pay_by_billet ),
					'order_billet_discount'             => $this->gnIntegration->formatCurrencyBRL( $discount_value ),
					'order_total'                       => $this->gnIntegration->formatCurrencyBRL( $total_order_pay_by_card ),
					'order_total_card'                  => $total_order_pay_by_card,
					'order_total_billet'                => $total_order_pay_by_billet,
					'sandbox'                           => $this->sandbox,
					'gn_card_payment_comments'          => __( "Opting to pay by credit card, the payment is processed and the confirmation will take place within 48 hours.", WCGerencianetOficial::getTextDomain() ),
					'gn_billet_payment_method_comments' => __( "Opting to pay by Banking Billet, the confirmation will be performed on the next business day after payment.", WCGerencianetOficial::getTextDomain() ),
					'gn_name_corporate'                 => __( "Name/Company:", WCGerencianetOficial::getTextDomain() ),
					'gn_name'                           => __( "Name:", WCGerencianetOficial::getTextDomain() ), //novo
					'gn_corporate'                      => __( "Company:", WCGerencianetOficial::getTextDomain() ), //novo
					'gn_cpf_cnpj'                       => __( "CPF/CNPJ: ", WCGerencianetOficial::getTextDomain() ),
					'gn_cpf'                            => __( "CPF: ", WCGerencianetOficial::getTextDomain() ), //novo
					'gn_cnpj'                           => __( "CNPJ: ", WCGerencianetOficial::getTextDomain() ), //novo
					'gn_phone'                          => __( "Phone: ", WCGerencianetOficial::getTextDomain() ),
					'gn_birth'                          => __( "Birth Date: ", WCGerencianetOficial::getTextDomain() ),
					'gn_email'                          => __( "E-mail: ", WCGerencianetOficial::getTextDomain() ),
					'gn_street'                         => __( "Address: ", WCGerencianetOficial::getTextDomain() ),
					'gn_street_number'                  => __( "Number: ", WCGerencianetOficial::getTextDomain() ),
					'gn_neighborhood'                   => __( "Neighborhood: ", WCGerencianetOficial::getTextDomain() ),
					'gn_address_complement'             => __( "Complement: ", WCGerencianetOficial::getTextDomain() ),
					'gn_cep'                            => __( "Zipcode: ", WCGerencianetOficial::getTextDomain() ),
					'gn_city'                           => __( "City: ", WCGerencianetOficial::getTextDomain() ),
					'gn_state'                          => __( "State: ", WCGerencianetOficial::getTextDomain() ),
					'gn_card_title'                     => __( "Billing Data", WCGerencianetOficial::getTextDomain() ),
					'gn_card_number'                    => __( "Card Number: ", WCGerencianetOficial::getTextDomain() ),
					'gn_card_expiration'                => __( "Expiration date: ", WCGerencianetOficial::getTextDomain() ),
					'gn_card_cvv'                       => __( "Security Code: ", WCGerencianetOficial::getTextDomain() ),
					'gn_card_installments_options'      => __( "Installments: ", WCGerencianetOficial::getTextDomain() ),
					'gn_card_brand'                     => __( "Select the card brand", WCGerencianetOficial::getTextDomain() ),

					'gn_mininum_gn_charge_price' => __( "You can not pay this order with Gerencianet because the total value is less than R$5,00.", WCGerencianetOficial::getTextDomain() ),
					'gn_pay_billet_option'       => __( "Pay with Billet Banking", WCGerencianetOficial::getTextDomain() ),
					'gn_discount_billet'         => __( "Discount of ", WCGerencianetOficial::getTextDomain() ),
					'gn_pay_card_option'         => __( "Pay with Credit Card", WCGerencianetOficial::getTextDomain() ),
					'gn_installments_pay'        => __( "Pay in", WCGerencianetOficial::getTextDomain() ),
					'gn_billing_address_title'   => __( "Billing Address", WCGerencianetOficial::getTextDomain() ),
					'gn_billing_state_select'    => __( "Select the state", WCGerencianetOficial::getTextDomain() ),
					'gn_card_cvv_tip'            => __( "Are the last three digits<br>on the back of the card.", WCGerencianetOficial::getTextDomain() ),
					'gn_card_brand_select'       => __( "Select the Card Brand", WCGerencianetOficial::getTextDomain() ),
					'gn_loading_payment_request' => __( "Please, wait...", WCGerencianetOficial::getTextDomain() ),

					'gn_warning_sandbox_message' => __( "Sandbox mode is active. The payments will not be valid.", WCGerencianetOficial::getTextDomain() )
				), 'woocommerce/woo-gerencianet-official/', plugin_dir_path( dirname( __FILE__ ) ) . 'templates/' );

		} else {
			echo $this->description;
		}
	}


	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return array           Redirect.
	 */
	public function process_payment( $order_id ) {

		$order = wc_get_order( $order_id );

		$charge_id = json_encode( $_POST );

		if ( $this->checkout_type == "OSC" ) {

			if ( ( $_POST['gn_card_payment_token'] == "" || $_POST['gn_card_installments'] == "0" ) && $_POST['paymentMethodRadio'] == "card" ) {
				wc_add_notice( 'Não foi possível validar os dados do cartão. Por favor, digite os dados novamente.', 'error' );

				return array(
					'result'   => 'fail',
					'redirect' => '',
				);
			}

			$create_charge = $this->gerencianet_create_charge( 'OSC', $order_id );

			$resultCheck = array();
			$resultCheck = json_decode( $create_charge, true );

			if ( isset( $resultCheck["code"] ) ) {
				if ( $resultCheck["code"] == 200 ) {
					if ( $_POST['paymentMethodRadio'] == "billet" ) {
						$pay_charge     = $this->gerencianet_pay_billet( 'OSC', $order_id, (int) $resultCheck["data"]["charge_id"] );
						$resultCheckPay = array();
						$resultCheckPay = json_decode( $pay_charge, true );
						if ( isset( $resultCheckPay["code"] ) ) {
							if ( $resultCheckPay["code"] == 200 ) {
								return array(
									'result'   => 'success',
									'redirect' => $order->get_checkout_order_received_url() . '&method=billet&charge_id=' . (int) $resultCheck["data"]["charge_id"] . '&'
								);
							}
						}
					} else {
						$pay_charge     = $this->gerencianet_pay_card( 'OSC', $order_id, (int) $resultCheck["data"]["charge_id"] );
						$resultCheckPay = array();
						$resultCheckPay = json_decode( $pay_charge, true );
						if ( isset( $resultCheckPay["code"] ) ) {
							if ( $resultCheckPay["code"] == 200 ) {
								return array(
									'result'   => 'success',
									'redirect' => $order->get_checkout_order_received_url() . '&method=card&charge_id=' . (int) $resultCheck["data"]["charge_id"] . '&'
								);
							}
						}
					}


					wc_add_notice( $resultCheckPay['message'], 'error' );

					return array(
						'result'   => 'fail',
						'redirect' => '',
					);

				} else {
					wc_add_notice( 'Ocorreu um erro ao tentar realizar o pagamento. Tente novamente.', 'error' );

					return array(
						'result'   => 'fail',
						'redirect' => '',
					);
				}
			} else {
				wc_add_notice( $resultCheck['message'], 'error' );

				return array(
					'result'   => 'fail',
					'redirect' => '',
				);
			}

		} else {
			return array(
				'result'   => 'success',
				'redirect' => $order->get_checkout_payment_url( true )
			);
		}

	}

	/**
	 * Output for the order received page.
	 *
	 * @param  object $order Order data.
	 *
	 * @return void
	 */
	public function receipt_page( $order ) {

		echo $this->generate_payment_page_options( $order );

	}

	/**
	 * Output for the order received page.
	 */
	public function thankyou_page( $order_id ) {

		echo wpautop( wptexturize( $this->generate_gn_thankyou_page( $order_id ) ) );

	}

	/**
	 * Generate the thank you page.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return string           Payment form.
	 */
	protected function generate_gn_thankyou_page( $order_id ) {
		$this->styles();

		$order = wc_get_order( $order_id );

		$email = $order->billing_email;

		$billet_url = get_post_meta( $order_id, 'billet', true );

		$generated_payment_type = sanitize_text_field( $_GET['method'] );
		$charge_id              = sanitize_text_field( $_GET['charge_id'] );

		$gn_success_payment_box_title_billet        = __( "Billet emitted by Gerencianet", WCGerencianetOficial::getTextDomain() );
		$gn_success_payment_box_title_card          = __( "Your order was successful and your payment is being processed. Wait until you receive confirmation of payment by email.", WCGerencianetOficial::getTextDomain() );
		$gn_success_payment_box_comments_billet     = __( 'The Banking Billet was successfully generated. Make payment in bank, lottery, post office or bankline. Stay tuned to the expiration date of the banking billet.', WCGerencianetOficial::getTextDomain() );
		$gn_success_payment_box_comments_card_part1 = __( "The charge on your card is being processed. Soon as it is confirmed, we will send an e-mail to <b>", WCGerencianetOficial::getTextDomain() );
		$gn_success_payment_box_comments_card_part2 = __( '</b>, informed in your registration. If you do not receive the product or service purchased, you have <b>14 days from the payment confirmation</b> date to open a dispute.<br>Get informed in <a href="http://www.gerencianet.com.br/contestacao" target="_blank">www.gerencianet.com.br/contestacao</a>.', WCGerencianetOficial::getTextDomain() );
		$gn_success_payment_charge_number           = __( "Charge number:", WCGerencianetOficial::getTextDomain() );
		$gn_success_payment_open_billet             = __( "Show Billet", WCGerencianetOficial::getTextDomain() );

		ob_start();
		include plugin_dir_path( dirname( __FILE__ ) ) . 'templates/order-received.php';
		$html = ob_get_clean();

		return $html;
	}

	/**
	 * Check API Notification response.
	 *
	 * @return void
	 */
	public function validate_notification() {
		@ob_clean();
		$post_notification = sanitize_text_field( $_POST['notification'] );
		if ( isset( $post_notification ) ) {
			header( 'HTTP/1.0 200 OK' );
			do_action( 'validate_notification_request', stripslashes_deep( $_POST ) );
		} else {
			wp_die( __( 'Request Failure', WCGerencianetOficial::getTextDomain() ) );
		}
	}

	/**
	 * Check if notification request is valid and make changes in order status
	 *
	 * @param array $posted gerencianet post data.
	 *
	 * @return void
	 */
	public function successful_request( $posted ) {

		//If the IPN request is about notification
		if ( ! empty( $posted['notification'] ) ) {


			$notification = json_decode( $this->gnIntegration->notificationCheck( $posted['notification'] ) );
			if ( $notification->code == 200 ) {

				if ( 'yes' == $this->debug ) {
					write_log( 'GERENCIANET :: notification Request : SUCCESS ' );
				}

				foreach ( $notification->data as $notification_data ) {
					$orderIdFromNotification     = $notification_data->custom_id;
					$orderStatusFromNotification = $notification_data->status->current;
				}

				$order = wc_get_order( $orderIdFromNotification );

				if ( $this->callback == 'yes' ) {
					switch ( $orderStatusFromNotification ) {
						case 'paid':
							$order->update_status( 'processing', __( 'Paid ' ) );
							$order->payment_complete();
							break;
						case 'unpaid':
							$order->update_status( 'failed', __( 'Unpaid  ' ) );
							break;
						case 'refunded':
							$order->update_status( 'failed', __( 'Refunded ' ) );
							break;
						case 'contested':
							$order->update_status( 'failed', __( 'Contested ' ) );
							break;
						case 'canceled':
							$order->update_status( 'cancelled', __( 'Canceled ' ) );
							break;
						default:
							//no action
							break;
					}
				}

			} else {
				if ( 'yes' == $this->debug ) {
					$this->log->add( 'gerencianet-oficial', 'GERENCIANET :: notification Request : FAIL ' );
				}
			}

			exit();
		}

	}

	/**
	 * Gets the admin url.
	 *
	 * @return string
	 */
	protected function admin_url() {
		return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=WC_Gerencianet_Oficial_Gateway' );
	}

	/**
	 * Adds error message when not configured correctly the payee code.
	 *
	 * @return string Error Message.
	 */
	public function sandbox_active_message() {
		echo '<div class="error"><p><strong>' . __( 'Gerencianet Disabled', WCGerencianetOficial::getTextDomain() ) . '</strong>: ' . sprintf( __( 'Sandbox mode is active. The payments will not be valid. %s', WCGerencianetOficial::getTextDomain() ), '<a href="' . $this->admin_url() . '">' . __( 'Click here to configure', WCGerencianetOficial::getTextDomain() ) . '</a>' ) . '</p></div>';
	}


	/**
	 * Adds error message when not configured correctly the payee code.
	 *
	 * @return string Error Mensage.
	 */
	public function payee_code_missing_message() {
		echo '<div class="error"><p><strong>' . __( 'Gerencianet Disabled', WCGerencianetOficial::getTextDomain() ) . '</strong>: ' . sprintf( __( 'You should inform your Gerencianet Account Payee Code. %s', WCGerencianetOficial::getTextDomain() ), '<a href="' . $this->admin_url() . '">' . __( 'Click here to configure', WCGerencianetOficial::getTextDomain() ) . '</a>' ) . '</p></div>';
	}

	/**
	 * Adds error message when not configured a one payment option.
	 *
	 * @return string Error Message.
	 */
	public function payment_option_missing_message() {
		echo '<div class="error"><p><strong>' . __( 'Gerencianet Disabled', WCGerencianetOficial::getTextDomain() ) . '</strong>: ' . sprintf( __( 'You should activate at least one payment option to activate the module. %s', WCGerencianetOficial::getTextDomain() ), '<a href="' . $this->admin_url() . '">' . __( 'Click here to configure', WCGerencianetOficial::getTextDomain() ) . '</a>' ) . '</p></div>';
	}


	/**
	 * Adds error message when not configured correctly the credentials.
	 *
	 * @return string Error Message.
	 */
	public function credentials_missing_message() {
		echo '<div class="error"><p><strong>' . __( 'Gerencianet Disabled', WCGerencianetOficial::getTextDomain() ) . '</strong>: ' . sprintf( __( 'You should inform your Gerencianet Application Credentials. %s', WCGerencianetOficial::getTextDomain() ), '<a href="' . $this->admin_url() . '">' . __( 'Click here to configure', WCGerencianetOficial::getTextDomain() ) . '</a>' ) . '</p></div>';
	}

	/**
	 * Adds error message when an unsupported currency is used.
	 *
	 * @return string Error Message.
	 */
	public function currency_not_supported_message() {
		echo '<div class="error"><p><strong>' . __( 'Gerencianet Disabled', WCGerencianetOficial::getTextDomain() ) . '</strong>: ' . sprintf( __( 'Currency <code>%s</code> is not supported by Gerencianet. Works only with <code>BRL</code> (Brazilian Real).', WCGerencianetOficial::getTextDomain() ), get_woocommerce_currency() ) . '</p></div>';
	}

}

/**
 * Adds billet link to order details.
 *
 * @return string Billet link button.
 */
add_action( 'woocommerce_order_details_after_order_table', 'gn_order_view_billet_link', 10, 1 );
function gn_order_view_billet_link( $order ) {

	$billet = get_post_meta( $order->id, 'billet', true );
	if ( ! empty( $billet ) ) {
		if ( $order->get_status() == "on-hold" || $order->get_status() == "pending" ) {
			echo "
			<div style='text-align: right;'><a class='button' href='" . $billet . "' target='_blank'>" . __( 'Show Billet', WCGerencianetOficial::getTextDomain() ) . "</a></div>";
		}
	}
}

/**
 * Adds billet link to order email.
 *
 * @return string Billet link button.
 */
add_action( 'woocommerce_email_after_order_table', 'gn_email_billet_link' );
function gn_email_billet_link( $order ) {

	$billet = get_post_meta( $order->id, 'billet', true );
	if ( ! empty( $billet ) ) {
		echo "
		<div>
			<center><a class='button' href='" . $billet . "' target='_blank'>" . __( 'Click here to see the Billet', WCGerencianetOficial::getTextDomain() ) . "</a></center>
		</div>";
	}
}
