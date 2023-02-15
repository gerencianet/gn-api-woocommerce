<?php

use GN_Includes\Gerencianet_I18n;

function init_gerencianet_pix() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	};

	class WC_Gerencianet_Pix extends WC_Payment_Gateway {

		// payment gateway plugin ID
		public $id;
		public $has_fields;
		public $method_title;
		public $method_description;
		public $supports;

		public function __construct() {
			$this->id                 = GERENCIANET_PIX_ID; // payment gateway plugin ID
			$this->has_fields         = true; // custom form
			$this->method_title       = __( 'Gerencianet - PIX', Gerencianet_I18n::getTextDomain() );
			$this->method_description = __( 'Com Gerencianet você pode receber pagamentos via Pix', Gerencianet_I18n::getTextDomain() );

			$this->supports = array(
				'products',
				'refunds',
			);

			$this->init_form_fields();

			$this->gerencianetSDK = new Gerencianet_Integration();

			$discountText = '';
			if ( $this->get_option( 'gn_pix_discount' ) != '' && $this->get_option( 'gn_pix_discount' ) != '0' ) {
				$discountText = ' - ' . $this->get_option( 'gn_pix_discount' ) . '% de Desconto';
			}

			// Load the settings.
			$this->init_settings();
			$this->title       = __( 'Pix', Gerencianet_I18n::getTextDomain() ) . $discountText;
			$this->description = __( 'Pagando por Pix, seu pagamento será confirmado em poucos segundos.', Gerencianet_I18n::getTextDomain() );
			$this->enabled     = $this->get_option( 'gn_pix' );

			$this->gn_pix_key               = sanitize_text_field( $this->get_option( 'gn_pix_key' ) );
			$this->gn_pix_file              = sanitize_text_field( $this->get_option( 'gn_pix_file' ) );
			$this->gn_pix_discount          = sanitize_text_field( $this->get_option( 'gn_pix_discount' ) );
			$this->gn_pix_discount_shipping = sanitize_text_field( $this->get_option( 'gn_pix_discount_shipping' ) );
			$this->gn_pix_number_hours      = sanitize_text_field( $this->get_option( 'gn_pix_number_hours' ) );
			$this->gn_pix_mtls              = sanitize_text_field( $this->get_option( 'gn_pix_mtls' ) );
			$this->gn_sandbox               = sanitize_text_field( $this->get_option( 'gn_sandbox' ) );

			// // This action hook saves the settings
			add_action( 'woocommerce_update_options_payment_gateways_' . GERENCIANET_PIX_ID, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_update_options_payment_gateways_' . GERENCIANET_PIX_ID, array( $this, 'savePixCertificate' ) );
			add_action( 'woocommerce_update_options_payment_gateways_' . GERENCIANET_PIX_ID, array( $this, 'registerWebhook' ) );

			add_action( 'woocommerce_api_' . strtolower( GERENCIANET_PIX_ID ), array( $this, 'webhook' ) );

			// This hook add the "view payment methods" button
			add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'add_view_payment_methods' ) );
		}

		public function process_refund( $order_id, $amount = null, $reason = '' ) {

			try {
				$res   = $this->gerencianetSDK->pix_refund( $order_id, $amount );
				$order = wc_get_order( $order_id );
				$order->update_status( 'refund' );
				return $res;
			} catch ( Error $e ) {
				throw $e;
			}

		}

		public function savePixCertificate() {
			
			$file_name = $_FILES['woocommerce_WC_Gerencianet_Pix_gn_pix_file']['name'];

			if ( $_FILES['woocommerce_WC_Gerencianet_Pix_gn_pix_file']['error'] != 0 ) {
				return;
			}

			// get the file extension
			$fileExt       = explode( '.', $file_name );
			$fileActualExt = strtolower( end( $fileExt ) );
			if ( $fileActualExt != 'pem' && $fileActualExt != 'p12' ) {
				echo '<div class="error"><p><strong> Certificado Pix inválido! </strong></div>';
				return;
			}
			if ( $fileActualExt == 'p12' ) {
				if ( ! $cert_file_p12 = file_get_contents( $_FILES['woocommerce_WC_Gerencianet_Pix_gn_pix_file']['tmp_name'] ) ) { // Pega o conteúdo do arquivo .p12
					echo '<div class="error"><p><strong> Falha ao ler arquivo o Certificado Pix! </strong></div>';
					return;
				}
				if ( ! openssl_pkcs12_read( $cert_file_p12, $cert_info_pem, '' ) ) { // Converte o conteúdo para .pem
					echo '<div class="error"><p><strong> Falha ao converter o arquivo .p12! </strong></div>';
					return;
				}
				$file_read  = "subject=/CN=271207/C=BR\n";
				$file_read .= "issuer=/C=BR/ST=Minas Gerais/O=Gerencianet Pagamentos do Brasil Ltda/OU=Infraestrutura/CN=api-pix.gerencianet.com.br/emailAddress=infra@gerencianet.com.br\n";
				$file_read .= $cert_info_pem['cert'];
				$file_read .= "Key Attributes: <No Attributes>\n";
				$file_read .= $cert_info_pem['pkey'];
			} else {
				// read the contents of the file
				if ( ! $file_read = file_get_contents( $_FILES['woocommerce_WC_Gerencianet_Pix_gn_pix_file']['tmp_name'] ) ) { // Pega o conteúdo do arquivo .p12
					echo '<div class="error"><p><strong> Falha ao ler arquivo o .pem! </strong></div>';
					return;
				}
			}
			if ( isset( $file_read ) ) {

				$dir_gerencianet = '/tmp/';
				
				$file = fopen( $dir_gerencianet . $file_name, 'w+' );
				if ( $file ) {
					$a = fwrite( $file, $file_read );
					$b = fclose( $file );
				}

				$this->update_option( 'gn_pix_file', $dir_gerencianet . $file_name );
			}

		}

		public function init_form_fields() {
			$certificateLabel = $this->get_option( 'gn_pix_file' ) != '' ? 'Certificado Pix salvo: <code>' . $this->get_option( 'gn_pix_file' ) . '</code>' : 'Nenhum certificado salvo';

			$this->form_fields = array(
				'gn_api_section'                => array(
					'title'       => __( 'Credenciais Gerencianet', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'title',
					'description' => __( "<a href='https://gerencianet.com.br/artigo/como-obter-chaves-client-id-e-client-secret-na-api/#versao-7' target='_blank'>Clique aqui para obter seu Client_id e Client_secret! </a>", Gerencianet_I18n::getTextDomain() ),
				),
				'gn_client_id_production'       => array(
					'title'       => __( 'Client_Id Produção', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'text',
					'description' => __( 'Por favor, insira seu Client_id. Isso é necessário para receber o pagamento.', Gerencianet_I18n::getTextDomain() ),
					'default'     => '',
				),
				'gn_client_secret_production'   => array(
					'title'       => __( 'Client_secret Produção', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'text',
					'description' => __( 'Por favor, insira seu Client_secret. Isso é necessário para receber o pagamento.', Gerencianet_I18n::getTextDomain() ),
					'default'     => '',
				),
				'gn_client_id_homologation'     => array(
					'title'       => __( 'Client_id Homologação', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'text',
					'description' => __( 'Por favor, insira seu Client_id de Homologação. Isso é necessário para testar os pagamentos.', Gerencianet_I18n::getTextDomain() ),
					'default'     => '',
				),
				'gn_client_secret_homologation' => array(
					'title'       => __( 'Client_secret Homologação', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'text',
					'description' => __( 'Por favor, insira seu Client_secret de Homologação. Isso é necessário para testar os pagamentos.', Gerencianet_I18n::getTextDomain() ),
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
				'gn_pix'                        => array(
					'title'   => __( 'Pix', Gerencianet_I18n::getTextDomain() ),
					'type'    => 'checkbox',
					'label'   => __( 'Habilitar Pix', Gerencianet_I18n::getTextDomain() ),
					'default' => 'no',
				),
				'gn_pix_key'                    => array(
					'title'       => __( 'Chave Pix', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'text',
					'description' => __( 'Insira sua chave Pix da Gerencianet', Gerencianet_I18n::getTextDomain() ),
					'desc_tip'    => false,
					'placeholder' => '',
					'default'     => '',
				),
				'gn_pix_file'                   => array(
					'title'       => __( 'Certificado Pix', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'file',
					'description' => $certificateLabel,
					'desc_tip'    => false,
				),
				'gn_pix_discount'               => array(
					'title'       => __( 'Desconto no Pix', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'number',
					'description' => __( 'Desconto a ser aplicado para pagamentos com Pix. (Deixe em branco ou como 0 para não aplicar desconto)', Gerencianet_I18n::getTextDomain() ),
					'placeholder' => '0',
					'default'     => '0',
				),
				'gn_pix_discount_shipping'      => array(
					'title'       => __( 'Modo de desconto', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'select',
					'description' => __( 'Escolha como aplicar o desconto definido na opção anterior.', Gerencianet_I18n::getTextDomain() ),
					'default'     => 'total',
					'options'     => array(
						'total'    => __( 'Aplicar desconto no valor total com Frete', Gerencianet_I18n::getTextDomain() ),
						'products' => __( 'Aplicar desconto apenas no preço dos produtos', Gerencianet_I18n::getTextDomain() ),
					),
				),
				'gn_pix_number_hours'           => array(
					'title'       => __( 'Expiração do pix', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'number',
					'description' => __( 'Em quantas horas o Pix expira depois de emitido', Gerencianet_I18n::getTextDomain() ),
					'placeholder' => '0',
					'default'     => '24',
				),
				'gn_pix_mtls'                   => array(
					'title'       => __( 'Validar mTLS', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'checkbox',
					'description' => __( 'Entenda os riscos de não configurar o mTLS <a href="https://dev.gerencianet.com.br/docs/api-pix-endpoints#webhooks" target="_blank">clicando aqui.</a>', Gerencianet_I18n::getTextDomain() ),
					'default'     => 'no',
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
			<div class="form-row form-row-wide" id="gn_field_pix">
				<label>CPF/CNPJ <span class="required">*</span></label>
				<input id="gn_pix_cpf_cnpj" name="gn_pix_cpf_cnpj" type="text" placeholder="___.___.___-__" autocomplete="off" onkeypress="return event.charCode >= 48 && event.charCode <= 57">
			</div>
			<div class="clear"></div>
			</fieldset>
			<?php
		}

		public function validate_fields() {
			if ( empty( sanitize_text_field( $_POST['gn_pix_cpf_cnpj'] ) ) ) {
				wc_add_notice( __( 'O CPF é obrigatório', Gerencianet_I18n::getTextDomain() ), 'error' );
				return false;
			} else {
				$cpf_cnpj = sanitize_text_field( $_POST['gn_pix_cpf_cnpj'] );
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

			$types         = array( 'line_item', 'fee', 'shipping', 'coupon' );
			$value         = 0;
			$shippingTotal = 0;
			// get the Items
			foreach ( $order->get_items( $types ) as $item_id => $item ) {
				switch ( $item->get_type() ) {
					case 'fee':
						$value += $item->get_subtotal();
						break;
					case 'shipping':
						$value         += $item->get_total();
						$shippingTotal += $item->get_total();
						break;
					case 'coupon':
						$value -= $item->get_discount();
						break;
					default:
						$product = $item->get_product();
						$value  += ( $item->get_quantity() * $product->get_price() );
						break;
				}
			}

			$value += $order->get_total_tax();

			if ( $this->get_option( 'gn_pix_discount' ) != '' && $this->get_option( 'gn_pix_discount' ) != '0' ) {

				$discount = 0;

				if ( $this->get_option( 'gn_pix_discount_shipping' ) == 'total' ) {
					$discount = ( ( $value ) * ( intval( $this->get_option( 'gn_pix_discount' ) ) / 100 ) );
					$discountMessage = ' no valor total da compra';
				} else {
					$discount = ( ( $value - $shippingTotal ) * ( intval( $this->get_option( 'gn_pix_discount' ) ) / 100 ) );
					$discountMessage = ' no valor total dos produtos (frete não incluso)';
				}
				
				$order_item_id = wc_add_order_item(
					$order_id,
					array(
						'order_item_name' => $this->get_option( 'gn_pix_discount' ) . __( '% de desconto no Pix' ).$discountMessage,
						'order_item_type' => 'fee',
					)
				);
				if ( $order_item_id ) {
					wc_add_order_item_meta( $order_item_id, '_fee_amount', -$discount, true );
					wc_add_order_item_meta( $order_item_id, '_line_total', -$discount, true );
					$order->set_total( $order->get_total() - ( $discount ) );
					$order->save();

				}
			}

			$cpf_cnpj = str_replace( '.', '', sanitize_text_field( $_POST['gn_pix_cpf_cnpj'] ) );
			$cpf_cnpj = str_replace( '-', '', $cpf_cnpj );
			$cpf_cnpj = str_replace( '/', '', $cpf_cnpj );
			if ( Gerencianet_Validate::cpf( $cpf_cnpj ) ) {
				$customer = array(
					'nome' => $order->get_formatted_billing_full_name(),
					'cpf'  => $cpf_cnpj,
				);
			} elseif ( Gerencianet_Validate::cnpj( $cpf_cnpj ) ) {
				$customer = array(
					'nome' => $order->get_billing_company() != '' ? $order->get_billing_company() : $order->get_formatted_billing_full_name(),
					'cnpj' => $cpf_cnpj,
				);
			} else {
				wc_add_notice( __( 'CPF/CNPJ inválido.', Gerencianet_I18n::getTextDomain() ), 'error' );
				return false;
			}

			$body = array(
				'calendario'     => array( 'expiracao' => intval( $this->get_option( 'gn_pix_number_hours' ) ) * 3600 ),
				'devedor'        => $customer,
				'valor'          => array( 'original' => sprintf( '%0.2f', $value ) ),
				'chave'          => $this->get_option( 'gn_pix_key' ),
				'infoAdicionais' => array(
					array(
						'nome'  => 'Pagamento em',
						'valor' => get_bloginfo(),
					),
					array(
						'nome'  => 'Numero do Pedido',
						'valor' => '#' . $order_id,
					),
				),
			);

			try {
				$chargeResponse = $this->gerencianetSDK->pay_pix( $body );
				$charge         = json_decode( $chargeResponse, true );

				$qrCodeResponse = $this->gerencianetSDK->generate_qrcode( $charge['loc']['id'] );
				$qrCode         = json_decode( $qrCodeResponse, true );

				$linkPix = str_replace( 'qrcodes-pix.gerencianet.com.br/v2/', 'https://pix.gerencianet.com.br/cob/pagar/', $charge['loc']['location'] );

				update_post_meta( $order_id, '_gn_pix_link', $linkPix );
				update_post_meta( $order_id, '_gn_pix_qrcode', $qrCode['imagemQrcode'] );
				update_post_meta( $order_id, '_gn_pix_copy', $qrCode['qrcode'] );
				update_post_meta( $order_id, '_gn_pix_txid', $charge['txid'] );

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
			$this->successful_webhook( file_get_contents( 'php://input' ) );
		}

		public function registerWebhook() {
			global $woocommerce;

			try {
				$pix_key  = $this->get_option( 'gn_pix_key' );
				$url      = strtolower( $woocommerce->api_request_url( GERENCIANET_PIX_ID ) . '?ignore=' );
				$response = $this->gerencianetSDK->update_webhook( $pix_key, $url );
			} catch ( \Throwable $th ) {
				gn_log( $th );
			}
		}

		public static function getMethodId() {
			return self::$id;
		}

		public function successful_webhook( $posted ) {
			$pix = json_decode( $posted, true )['pix'];
			// Percorre lista de notificações
			$args = array(
				'limit'        => -1,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'meta_key'     => '_gn_pix_txid',
				'meta_compare' => '=',
				'meta_value'   => sanitize_text_field( $pix[0]['txid'] ),
			);

			// Busca pedidos
			$orders = wc_get_orders( $args );

			// Atualiza status
			foreach ( $orders as $order ) {

				if ( isset( $pix[0]['txid'] ) && $pix[0]['txid'] != '' && ( get_post_meta( $order->get_id(), '_gn_pix_txid', true ) == $pix[0]['txid'] ) ) {
					add_post_meta( intval( $order->get_id() ), '_gn_pix_E2EID', $pix[0]['endToEndId'], true );

					gn_log( $pix[0] );
					if ( isset( $pix[0]['devolucoes'] ) && $pix[0]['devolucoes'][0]['status'] == 'DEVOLVIDO' ) {
						$order->update_status( 'refund' );
					} else {
						$order->update_status( 'processing' );
						$order->payment_complete();
					}
				}
			}

			exit();
		}

		public function add_view_payment_methods( $order ) {

			if ( $order->get_payment_method() != $this->id ) {
				return;
			}

			if ( get_post_meta( $order->get_id(), '_gn_pix_copy', true ) || get_post_meta( $order->get_id(), '_gn_pix_link', true ) ) {
				?>
					<style>

						.gngrid-container {
							display: grid;
							grid-template-columns: 50% 50%;
							overflow: hidden;
						}
						.gngrid-item {
							padding: 20px;
							font-size: 30px;
						}

						.gn-item-area {
							border-radius: 1.2rem;
							transition: transform 300ms ease;
						}

						.gn-item-area:hover {
							transform: scale(1.02);
						}

						.gn-btn{
							width: 100%;
							background: #EB6608 !important; 
							color:#ffff !important; 
							border:none;
							border-color: #EB6608 !important; 
							font-size: 1rem !important; 
							padding: 3px 5px 3px 5px !important;
						}
					</style>

			<script>

				function gncopy($field) {
					document.getElementById('gnpix').innerHTML = 'Copiado!';
					navigator.clipboard.writeText('<?php echo esc_html( get_post_meta( $order->get_id(), '_gn_pix_copy', true ) ); ?>');
					setTimeout(()=> {
							document.getElementById('gnpix').innerHTML = 'Copiar Pix Copia e Cola';
						},1000)
				}

				function viewGnInfos(params) {
					Swal.fire({
						title: 'Meios de Pagamento Disponíveis',
						icon: 'info',
						html: '<div class="gngrid-container"><div class="gngrid-item"><?php if ( get_post_meta( $order->get_id(), '_gn_pix_copy', true ) !== NULL ) { ?><div class="gn-item-area"><img style="width:150px;" src=" <?php echo esc_url(plugins_url( 'woo-gerencianet-official/assets/img/pix-copia.png' )); ?> " /><br> <a onclick="gncopy(2)" id="gnpix" class="button gn-btn">Copiar Pix Copia e Cola</a> </div><?php }?></div><div class="gn-item-area"><?php if ( get_post_meta( $order->get_id(), '_gn_pix_link', true ) !== NULL ) { ?> <div class="gngrid-item"><img style="width:150px;" src=" <?php echo esc_url(plugins_url( 'woo-gerencianet-official/assets/img/boleto-online.png' )); ?> " /><br><a href=" <?php echo esc_url(get_post_meta( $order->get_id(), '_gn_pix_link', true )); ?>  " target="_blank" class="button gn-btn">Acessar Pix Online</a></div> <?php }?> </div></div>',
						showCloseButton: true,
						showCancelButton: false,
						showConfirmButton: false
					})
				}
			</script>
			<div class="gn_payment_methods">
				<a onclick="viewGnInfos()" style="background: #EB6608; color:#ffff; border:none;" class="button">
					<span class="dashicons dashicons-visibility" style="margin-top:4px;"></span>
					<?php echo __( 'Visualizar Métodos de Pagamento', Gerencianet_I18n::getTextDomain() ); ?>
				</a>
			</div>

				<?php
			}
		}
	}
}
