<?php

use GN_Includes\Gerencianet_I18n;

function init_gerencianet_cartao() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	};

	class WC_Gerencianet_Cartao extends WC_Payment_Gateway {

		// payment gateway plugin ID
		public $id;
		public $has_fields;
		public $method_title;
		public $method_description;
		public $supports;

		public function __construct() {

			$this->id                 = GERENCIANET_CARTAO_ID; // payment gateway plugin ID
			$this->has_fields         = true; // custom form
			$this->method_title       = __( 'Gerencianet - Cartão de Crédito', Gerencianet_I18n::getTextDomain() );
			$this->method_description = __( 'Com a Gerencianet você pode receber pagamentos via Cartão de Crédito', Gerencianet_I18n::getTextDomain() );

			$this->supports = array(
				'products',
			);

			$this->init_form_fields();

			$this->gerencianetSDK = new Gerencianet_Integration();

			// Load the settings.
			$this->init_settings();
			$this->title                         = __( 'Cartão de Crédito', Gerencianet_I18n::getTextDomain() );
			$this->description                   = __( 'Pagando com Cartão de Crédito, seu pagamento será confirmado em até um dia útil.', Gerencianet_I18n::getTextDomain() );
			$this->enabled                       = sanitize_text_field( $this->get_option( 'gn_credit_card' ) );
			$this->gn_payee_code                 = sanitize_text_field( $this->get_option( 'gn_payee_code' ) );
			$this->gn_client_id_production       = sanitize_text_field( $this->get_option( 'gn_client_id_production' ) );
			$this->gn_client_secret_production   = sanitize_text_field( $this->get_option( 'gn_client_secret_production' ) );
			$this->gn_client_id_homologation     = sanitize_text_field( $this->get_option( 'gn_client_id_homologation' ) );
			$this->gn_client_secret_homologation = sanitize_text_field( $this->get_option( 'gn_client_secret_homologation' ) );
			$this->gn_sandbox                    = sanitize_text_field( $this->get_option( 'gn_sandbox' ) );

			// // This action hook saves the settings
			add_action( 'woocommerce_update_options_payment_gateways_' . GERENCIANET_CARTAO_ID, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_api_' . strtolower( GERENCIANET_CARTAO_ID ), array( $this, 'webhook' ) );
			// This Hook add the payment token script on checkout
			add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
		}

		public function init_form_fields() {

			$this->form_fields = array(
				'gn_api_section'                => array(
					'title'       => __( 'Credenciais Gerencianet', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'title',
					'description' => __( "<a href='https://gerencianet.com.br/artigo/como-obter-chaves-client-id-e-client-secret-na-api/#versao-7' target='_blank'>Clique aqui para obter seu Client_id e Client_secret! </a>", Gerencianet_I18n::getTextDomain() ),
				),
				'gn_client_id_production'       => array(
					'title'       => __( 'Client_id Produção', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'text',
					'description' => __( 'Por favor, insira seu Client_id. Isso é necessário para receber o pagamento.', Gerencianet_I18n::getTextDomain() ),
					'desc_tip'    => false,
					'default'     => '',
				),
				'gn_client_secret_production'   => array(
					'title'       => __( 'Client_secret Produção', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'text',
					'description' => __( 'Por favor, insira seu Client_secret. Isso é necessário para receber o pagamento.', Gerencianet_I18n::getTextDomain() ),
					'desc_tip'    => false,
					'default'     => '',
				),
				'gn_client_id_homologation'     => array(
					'title'       => __( 'Client_id Homologação', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'text',
					'description' => __( 'Por favor, insira seu Client_id de Homologação. Isso é necessário para testar os pagamentos.', Gerencianet_I18n::getTextDomain() ),
					'desc_tip'    => false,
					'default'     => '',
				),
				'gn_client_secret_homologation' => array(
					'title'       => __( 'Client_secret Homologação', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'text',
					'description' => __( 'Por favor, insira seu Client_secret de Homologação. Isso é necessário para testar os pagamentos.', Gerencianet_I18n::getTextDomain() ),
					'desc_tip'    => false,
					'default'     => '',
				),
				'gn_sandbox_section'            => array(
					'title'       => __( 'Ambiente Sandbox', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'title',
					'description' => 'Habilite para usar o ambiente de testes da Gerencianet. Nenhuma cobrança emitida nesse modo poderá ser paga.',
				),
				'gn_sandbox'                    => array(
					'title'   => __( 'Sandbox', Gerencianet_I18n::getTextDomain() ),
					'type'    => 'checkbox',
					'label'   => __( 'Habilitar o ambiente sandbox', Gerencianet_I18n::getTextDomain() ),
					'default' => 'no',
				),
				'gn_one_payment_section'        => array(
					'title' => __( 'Configurações de recebimento', Gerencianet_I18n::getTextDomain() ),
					'type'  => 'title',
				),
				'gn_credit_card'                => array(
					'title'   => __( 'Cartão de Crédito', Gerencianet_I18n::getTextDomain() ),
					'type'    => 'checkbox',
					'label'   => __( 'Habilitar Cartão de Crédito', Gerencianet_I18n::getTextDomain() ),
					'default' => 'no',
				),
				'gn_payee_code'                 => array(
					'title'       => __( 'Identificador de Conta', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'text',
					'description' => __( 'Por favor, insira seu identificador de conta. Isso é necessário para receber os pagamentos.', Gerencianet_I18n::getTextDomain() ),
					'desc_tip'    => false,
					'default'     => '',
				),
			);
		}

		public function payment_fields() {

			if ( $this->description ) {
				echo wpautop( wp_kses_post( $this->description ) );
			}

			$is_sandbox = $this->get_option( 'gn_sandbox' ) == 'yes' ? true : false;
			if ( $is_sandbox ) {
				$sandboxWarn = '<div class="warning-payment" id="wc-gerencianet-messages-sandbox">
                                    <div class="woocommerce-error">' . __( 'O modo Sandbox está ativo. As cobranças emitidas não serão válidas.', Gerencianet_I18n::getTextDomain() ) . '</div>
                                </div>';
				echo wpautop( wp_kses_post( $sandboxWarn ) );
			}

			echo '<fieldset id="wc-' . esc_attr( $this->id ) . '-cc-form" class="wc-credit-card-form wc-payment-form" style="background:transparent; border: none;">';

			?>
				<div id="gn_row_cpf_birth">
					<div class="form-row form-row-first" id="gn_field_cpf_cnpj">
						<label>CPF/CNPJ <span class="required">*</span></label>
						<input id="gn_cartao_cpf_cnpj" name="gn_cartao_cpf_cnpj" type="text" placeholder="___.___.___-__" autocomplete="off" onkeypress="return event.charCode >= 48 && event.charCode <= 57">
					</div>
					<div class="form-row form-row-last" id="gn_field_birth">
						<label><?php echo __( 'Data de Nasc.', Gerencianet_I18n::getTextDomain() ); ?><span class="required">*</span></label>
						<input id="gn_cartao_birth" name="gn_cartao_birth" placeholder="__/__/____" type="text" autocomplete="off">
					</div>
				</div>
				<div class="form-row form-row-wide"><label><?php echo __( 'Número do Cartão', Gerencianet_I18n::getTextDomain() ); ?><span class="required">*</span></label>
					<input id="gn_cartao_number"  name="gn_cartao_number" type="text" autocomplete="off" style="width: 100%;">
				</div>
				<div class="form-row form-row-first"><label>CVV<span class="required">*</span></label>
					<input id="gn_cartao_cvv" name="gn_cartao_cvv" type="text" autocomplete="off">
				</div>
				<div class="form-row form-row-last"><label><?php echo __( 'Expiração', Gerencianet_I18n::getTextDomain() ); ?><span class="required">*</span></label>
					<input id="gn_cartao_expiration" placeholder="__/____" name="gn_cartao_expiration" type="text" autocomplete="off">
				</div>
				<div class="clear"></div>
				<div class="form-row form-row-wide"><label><?php echo __( 'Parcelas', Gerencianet_I18n::getTextDomain() ); ?><span class="required">*</span></label>
					<input id="gn_cartao_no_installments" name="gn_cartao_no_installments" type="text" value="<?php echo __( 'Por favor, insira o número do seu cartão', Gerencianet_I18n::getTextDomain() ); ?>" style="text-align: center; width: 100%;" disabled>
					<select id="gn_cartao_installments" name="gn_cartao_installments" style="display:none;width: 100%;border-color: #dcd7ca;"></select>
				</div>
				<input id="gn_payment_token" name="gn_payment_token" type="hidden">
			</fieldset>
			<?php

		}

		public function payment_scripts() {

			if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) ) {
				return;
			}

			if ( $this->enabled != 'yes' ) {
				return;
			}

			if ( $this->get_option( 'gn_sandbox' ) == 'yes' ) {
				echo html_entity_decode( "<script type='text/javascript'>var s=document.createElement('script');s.type='text/javascript';var v=parseInt(Math.random()*1000000);s.src='https://sandbox.gerencianet.com.br/v1/cdn/" . esc_html($this->get_option( 'gn_payee_code' )) . "/'+v;s.async=false;s.id='" . esc_html($this->get_option( 'gn_payee_code' )) . "';if(!document.getElementById('" . esc_html($this->get_option( 'gn_payee_code' )) . "')){document.getElementsByTagName('head')[0].appendChild(s);};&#36;gn={validForm:true,processed:false,done:{},ready:function(fn){&#36;gn.done=fn;}};</script>" );
			} else {
				echo html_entity_decode( "<script type='text/javascript'>var s=document.createElement('script');s.type='text/javascript';var v=parseInt(Math.random()*1000000);s.src='https://api.gerencianet.com.br/v1/cdn/" . esc_html($this->get_option( 'gn_payee_code' )) . "/'+v;s.async=false;s.id='" . esc_html($this->get_option( 'gn_payee_code' )) . "';if(!document.getElementById('" . esc_html($this->get_option( 'gn_payee_code' )) . "')){document.getElementsByTagName('head')[0].appendChild(s);};&#36;gn={validForm:true,processed:false,done:{},ready:function(fn){&#36;gn.done=fn;}};</script>" );
			}
			wp_enqueue_script( 'gn-sweetalert', plugins_url( '../assets/js/sweetalert.js', plugin_dir_path( __FILE__ ) ), '11.0.0', true );
			wp_enqueue_script( 'gn-payment-token', plugins_url( '../assets/js/gn-payment-token.js', plugin_dir_path( __FILE__ ) ), '1.0.0', true );

		}

		public function validate_fields() {

			if ( empty( sanitize_text_field( $_POST['gn_cartao_cpf_cnpj'] ) ) ) {
				wc_add_notice( __( 'O CPF é obrigatório', Gerencianet_I18n::getTextDomain() ), 'error' );
				return false;
			} else {
				$cpf_cnpj = preg_replace( '/[^0-9]/', '', sanitize_text_field( $_POST['gn_cartao_cpf_cnpj'] ) );
				if ( strlen( $cpf_cnpj ) == 11 ) {
					// cpf
					return Gerencianet_Validate::cpf( $cpf_cnpj );
				} else {
					// cnpj
					return Gerencianet_Validate::cnpj( $cpf_cnpj );
				}
			}
			return true;
		}

		public function process_payment( $order_id ) {

			global $woocommerce;

			$order = wc_get_order( $order_id );

			$types    = array( 'line_item', 'fee', 'shipping', 'coupon' );
			$items    = array();
			$shipping = array();
			$discount = false;
			// get the Items
			foreach ( $order->get_items( $types ) as $item_id => $item ) {

				switch ( $item->get_type() ) {
					case 'fee':
						$newFee      = array(
							'name'   => __( 'Taxas', Gerencianet_I18n::getTextDomain() ),
							'amount' => 1,
							'value'  => $item->get_subtotal() * 100,
						);
						$orderTotal += $item->get_subtotal() * 100;
						$items[]     = $newFee;
						break;
					case 'shipping':
						if ( $item->get_total() > 0 ) {
							$shipping[] = array(
								'name'  => __( 'Frete', Gerencianet_I18n::getTextDomain() ),
								'value' => $item->get_total() * 100,
							);
						}
						break;
					case 'coupon':
						$newDiscount = array(
							'type'  => 'currency',
							'value' => $item->get_total() * 100,
						);
						$discount    = $newDiscount;
						break;
					default:
						$product     = $item->get_product();
						$newItem     = array(
							'name'   => $item->get_name(),
							'amount' => $item->get_quantity(),
							'value'  => $product->get_price() * 100,
						);
						$orderTotal += $product->get_price() * 100 * $item->get_quantity();
						$items[]     = $newItem;
						break;
				}
			}

			if($order->get_total_tax()>0){
				$newItem     = array(
					'name'   => 'Taxas',
					'amount' => 1,
					'value'  => $order->get_total_tax() * 100,
				);
				array_push($items, $newItem);
			}

			$cpf_cnpj = preg_replace( '/[^0-9]/', '', sanitize_text_field( $_POST['gn_cartao_cpf_cnpj'] ) );
			if ( Gerencianet_Validate::cpf( $cpf_cnpj ) ) {
				$customer = array(
					'name'         => $order->get_formatted_billing_full_name(),
					'cpf'          => $cpf_cnpj,
					'phone_number' => preg_replace( '/[^0-9]/', '', $order->get_billing_phone() ),
				);
			} else {
				$customer = array(
					'juridical_person' => array(
						'corporate_name' => $order->get_billing_company() != '' ? $order->get_billing_company() : $order->get_formatted_billing_full_name(),
						'cnpj'           => $cpf_cnpj,
					),
					'phone_number'     => preg_replace( '/[^0-9]/', '', $order->get_billing_phone() ),
				);
			}
			$customer['email'] = $order->get_billing_email();
			$birth             = explode( '/', sanitize_text_field( $_POST['gn_cartao_birth'] ) );
			$customer['birth'] = $birth[2] . '-' . $birth[1] . '-' . $birth[0];

			$paymentToken = sanitize_text_field( $_POST['gn_payment_token'] );

			$installments = intval( sanitize_text_field( $_POST['gn_cartao_installments'] ) );

			$number = sanitize_text_field( $_POST['gn_billing_number'] ) != null ? sanitize_text_field( $_POST['gn_billing_number'] ) : sanitize_text_field( $_POST['billing_number'] );

			$billingAddress = array(
				'street'       => $order->get_billing_address_1(),
				'number'       => $number,
				'neighborhood' => sanitize_text_field( $_POST['billing_neighborhood'] ) != null ? sanitize_text_field( $_POST['billing_neighborhood'] ) : $order->get_billing_address_2(),
				'zipcode'      => str_replace( '-', '', $order->get_billing_postcode() ),
				'city'         => $order->get_billing_city(),
				'state'        => $order->get_billing_state(),
			);

			try {
				$response = $this->gerencianetSDK->one_step_card( $order_id, $items, $shipping, strtolower( $woocommerce->api_request_url( GERENCIANET_CARTAO_ID ) ), $customer, $paymentToken, $installments, $billingAddress );
				$charge   = json_decode( $response, true );

				$order->update_status( 'pending-payment' );
				wc_reduce_stock_levels( $order_id );
				$woocommerce->cart->empty_cart();

				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);

			} catch ( Exception $e ) {
				wc_add_notice( $e->getMessage(), 'error' );
				return;
			}
		}

		public function webhook() {
			header( 'HTTP/1.0 200 OK' );
			$post_notification = sanitize_text_field( $_POST['notification'] );
			if ( isset( $post_notification ) && ! empty( $post_notification ) ) {

				$notification = json_decode( $this->gerencianetSDK->getNotification( GERENCIANET_CARTAO_ID, $post_notification ) );
				if ( $notification->code == 200 ) {

					foreach ( $notification->data as $notification_data ) {
						$orderIdFromNotification     = sanitize_text_field( $notification_data->custom_id );
						$orderStatusFromNotification = sanitize_text_field( $notification_data->status->current );
						$gerencianetChargeId         = sanitize_text_field( $notification_data->identifiers->charge_id );
					}

					$order = wc_get_order( $orderIdFromNotification );

					switch ( $orderStatusFromNotification ) {
						case 'paid':
							$order->update_status( 'processing' );
							$order->payment_complete();
							break;
						case 'unpaid':
							$order->update_status( 'failed' );

							if ( $this->get_option( 'billet_unpaid' ) == 'yes' ) {
								$this->gerencianetSDK->cancel_charge( $gerencianetChargeId );
							}

							break;
						case 'refunded':
							$order->update_status( 'refund' );
							break;
						case 'contested':
							$order->update_status( 'failed' );
							break;
						case 'canceled':
							$order->update_status( 'cancelled' );
							break;
						default:
							// no action
							break;
					}
				} else {
					gn_log( 'Notification Request : FAIL ' );
				}

				exit();

			} else {
				wp_die( __( 'Request Failure', Gerencianet_I18n::getTextDomain() ) );
			}
		}

		public static function getMethodId() {
			return self::$id;
		}

	}
}
