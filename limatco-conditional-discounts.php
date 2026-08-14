<?php
/**
 * Plugin Name: Limatco - Descuentos Condicionales WooCommerce
 * Description: Descuentos individuales por categoría/producto + descuentos "combo" cuando se compran juntos productos de dos listas distintas (ej: Cerámica + Adhesivos = 20% en vez de 5%+5%).
 * Version: 1.0.0
 * Author: Limatco
 * Text Domain: lcd
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Seguridad: no acceso directo.
}

// Verificar que WooCommerce esté activo.
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>Descuentos Condicionales:</strong> este plugin requiere que WooCommerce esté activo.</p></div>';
		} );
		return;
	}
	LCD_Conditional_Discounts::instance();
} );

class LCD_Conditional_Discounts {

	private static $instance = null;

	const OPT_INDIVIDUAL = 'lcd_individual_rules';
	const OPT_COMBO       = 'lcd_combo_rules';

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Admin.
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_save_settings' ) );

		// Front-end: recalcular precios en el carrito.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_discounts' ), 20, 1 );

		// Mostrar aviso en el carrito/checkout cuando se activa un combo.
		add_action( 'woocommerce_before_cart_table', array( $this, 'maybe_show_combo_notice' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'maybe_show_combo_notice' ) );
	}

	/* -----------------------------------------------------------------
	 *  UTILIDADES DE CONFIGURACIÓN
	 * --------------------------------------------------------------- */

	private function get_individual_rules() {
		$rules = get_option( self::OPT_INDIVIDUAL, array() );
		return is_array( $rules ) ? $rules : array();
	}

	private function get_combo_rules() {
		$rules = get_option( self::OPT_COMBO, array() );
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Revisa si un producto pertenece a una lista de condiciones (categorías y/o identificadores de producto).
	 * Los identificadores de producto pueden ser un ID numérico o un SKU (texto).
	 *
	 * @param WC_Product $product
	 * @param array      $category_ids
	 * @param array      $product_identifiers  Mezcla de IDs numéricos y/o SKUs (strings).
	 * @return bool
	 */
	private function product_matches( $product, $category_ids, $product_identifiers ) {
		$product_id = $product->get_id();

		if ( ! empty( $product_identifiers ) ) {
			$sku = $product->get_sku();
			// Si es una variación sin SKU propio, usar el SKU del producto padre.
			if ( '' === $sku && $product->is_type( 'variation' ) ) {
				$parent = wc_get_product( $product->get_parent_id() );
				if ( $parent ) {
					$sku = $parent->get_sku();
				}
			}
			$sku = strtolower( trim( (string) $sku ) );

			foreach ( $product_identifiers as $identifier ) {
				$identifier = trim( (string) $identifier );
				if ( '' === $identifier ) {
					continue;
				}
				// Coincidencia por ID (incluye el ID del producto padre si es una variación).
				if ( is_numeric( $identifier ) ) {
					$id_to_check = (int) $identifier;
					if ( $id_to_check === $product_id ) {
						return true;
					}
					if ( $product->is_type( 'variation' ) && $id_to_check === (int) $product->get_parent_id() ) {
						return true;
					}
					continue;
				}
				// Coincidencia por SKU (no numérico).
				if ( '' !== $sku && strtolower( $identifier ) === $sku ) {
					return true;
				}
			}
		}

		if ( ! empty( $category_ids ) ) {
			$terms = wc_get_product_term_ids( $product_id, 'product_cat' );
			if ( array_intersect( $terms, $category_ids ) ) {
				return true;
			}
		}

		return false;
	}

	/* -----------------------------------------------------------------
	 *  LÓGICA DE DESCUENTOS (FRONT-END)
	 * --------------------------------------------------------------- */

	/**
	 * Calcula, para el carrito actual, qué combos están activos.
	 * Devuelve un arreglo de reglas combo activas.
	 */
	private function get_active_combos( $cart ) {
		$combo_rules   = $this->get_combo_rules();
		$active_combos = array();

		if ( empty( $combo_rules ) ) {
			return $active_combos;
		}

		foreach ( $combo_rules as $rule ) {
			$has_a = false;
			$has_b = false;

			foreach ( $cart->get_cart() as $cart_item ) {
				$product = $cart_item['data'];

				if ( ! $has_a && $this->product_matches( $product, $rule['group_a_cats'], $rule['group_a_products'] ) ) {
					$has_a = true;
				}
				if ( ! $has_b && $this->product_matches( $product, $rule['group_b_cats'], $rule['group_b_products'] ) ) {
					$has_b = true;
				}
			}

			if ( $has_a && $has_b ) {
				$active_combos[] = $rule;
			}
		}

		return $active_combos;
	}

	/**
	 * Hook principal: recalcula el precio de cada línea del carrito.
	 */
	public function apply_discounts( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		$individual_rules = $this->get_individual_rules();
		$active_combos     = $this->get_active_combos( $cart );

		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'];

			// Importante: leemos el precio regular desde una copia "limpia" del producto (recién
			// obtenida de la base de datos), en vez de $product->get_regular_price(). Esto evita que,
			// si este hook se ejecuta varias veces en el mismo request (WooCommerce lo hace seguido:
			// al agregar al carrito, al actualizar cantidades, al calcular envío, etc.), el descuento
			// se vaya aplicando de forma acumulativa sobre un precio ya rebajado.
			$clean_product = wc_get_product( $product->get_id() );
			$base_price    = $clean_product ? (float) $clean_product->get_regular_price() : (float) $product->get_regular_price();

			if ( $base_price <= 0 ) {
				continue;
			}

			$percent = $this->get_percent_for_product( $product, $individual_rules, $active_combos );

			$new_price = $percent > 0
				? round( $base_price * ( 1 - ( $percent / 100 ) ), 2 )
				: $base_price;

			$product->set_price( $new_price );

			// Clave del fix: WooCommerce calcula el "Subtotal" del carrito (el que se ve en el
			// mini-carrito y en la página de carrito) usando el PRECIO REGULAR del producto, no el
			// precio ya descontado. Si no igualamos también el precio regular, el Subtotal queda
			// calculado con el valor sin descuento aunque el precio de línea sí lo muestre, generando
			// el desfase reportado (línea en $4.750 pero Subtotal en $5.000).
			// Esto es solo para el cálculo del carrito en este request — no se guarda en la base de
			// datos ni afecta el precio real del producto en la tienda.
			$product->set_regular_price( $new_price );
		}
	}

	/**
	 * Determina el % de descuento final para un producto:
	 * - Si el producto está en un combo activo, se usa el % del combo (reemplaza al individual).
	 * - Si no, se usa el % de la regla individual que le corresponda (la primera que matchee).
	 */
	private function get_percent_for_product( $product, $individual_rules, $active_combos ) {
		// 1. ¿Está en algún combo activo?
		foreach ( $active_combos as $combo ) {
			$in_a = $this->product_matches( $product, $combo['group_a_cats'], $combo['group_a_products'] );
			$in_b = $this->product_matches( $product, $combo['group_b_cats'], $combo['group_b_products'] );
			if ( $in_a || $in_b ) {
				return (float) $combo['percent'];
			}
		}

		// 2. Si no, buscar regla individual.
		foreach ( $individual_rules as $rule ) {
			if ( $this->product_matches( $product, $rule['cats'], $rule['products'] ) ) {
				return (float) $rule['percent'];
			}
		}

		return 0;
	}

	/**
	 * Aviso visual simple en carrito/checkout cuando un combo está activo.
	 */
	public function maybe_show_combo_notice() {
		if ( ! WC()->cart ) {
			return;
		}
		$active_combos = $this->get_active_combos( WC()->cart );
		foreach ( $active_combos as $combo ) {
			$label = ! empty( $combo['label'] ) ? $combo['label'] : __( 'Descuento combo', 'lcd' );
			echo '<div class="woocommerce-info">' . esc_html( sprintf(
				__( '¡Descuento combo activado! "%1$s": %2$s%% de descuento aplicado a los productos correspondientes.', 'lcd' ),
				$label,
				$combo['percent']
			) ) . '</div>';
		}
	}

	/* -----------------------------------------------------------------
	 *  PANEL DE ADMINISTRACIÓN
	 * --------------------------------------------------------------- */

	public function admin_menu() {
		add_menu_page(
			'Descuentos Condicionales',
			'Descuentos Condicionales',
			'manage_woocommerce',
			'lcd-descuentos',
			array( $this, 'render_settings_page' ),
			'dashicons-tag',
			56
		);
	}

	public function maybe_save_settings() {
		if ( ! isset( $_POST['lcd_save'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( 'lcd_save_settings', 'lcd_nonce' );

		// --- Reglas individuales ---
		$individual = array();
		if ( ! empty( $_POST['ind_cats'] ) && is_array( $_POST['ind_cats'] ) ) {
			$count = count( $_POST['ind_cats'] );
			for ( $i = 0; $i < $count; $i++ ) {
				$cats     = isset( $_POST['ind_cats'][ $i ] ) ? array_map( 'intval', (array) $_POST['ind_cats'][ $i ] ) : array();
				$products = isset( $_POST['ind_products'][ $i ] ) ? $this->parse_id_list( $_POST['ind_products'][ $i ] ) : array();
				$percent  = isset( $_POST['ind_percent'][ $i ] ) ? floatval( $_POST['ind_percent'][ $i ] ) : 0;

				if ( ( ! empty( $cats ) || ! empty( $products ) ) && $percent > 0 ) {
					$individual[] = array(
						'cats'     => $cats,
						'products' => $products,
						'percent'  => $percent,
					);
				}
			}
		}
		update_option( self::OPT_INDIVIDUAL, $individual );

		// --- Reglas combo ---
		$combos = array();
		if ( ! empty( $_POST['combo_label'] ) && is_array( $_POST['combo_label'] ) ) {
			$count = count( $_POST['combo_label'] );
			for ( $i = 0; $i < $count; $i++ ) {
				$label            = isset( $_POST['combo_label'][ $i ] ) ? sanitize_text_field( $_POST['combo_label'][ $i ] ) : '';
				$group_a_cats     = isset( $_POST['combo_a_cats'][ $i ] ) ? array_map( 'intval', (array) $_POST['combo_a_cats'][ $i ] ) : array();
				$group_a_products = isset( $_POST['combo_a_products'][ $i ] ) ? $this->parse_id_list( $_POST['combo_a_products'][ $i ] ) : array();
				$group_b_cats     = isset( $_POST['combo_b_cats'][ $i ] ) ? array_map( 'intval', (array) $_POST['combo_b_cats'][ $i ] ) : array();
				$group_b_products = isset( $_POST['combo_b_products'][ $i ] ) ? $this->parse_id_list( $_POST['combo_b_products'][ $i ] ) : array();
				$percent          = isset( $_POST['combo_percent'][ $i ] ) ? floatval( $_POST['combo_percent'][ $i ] ) : 0;

				$group_a_ok = ! empty( $group_a_cats ) || ! empty( $group_a_products );
				$group_b_ok = ! empty( $group_b_cats ) || ! empty( $group_b_products );

				if ( $group_a_ok && $group_b_ok && $percent > 0 ) {
					$combos[] = array(
						'label'             => $label,
						'group_a_cats'      => $group_a_cats,
						'group_a_products'  => $group_a_products,
						'group_b_cats'      => $group_b_cats,
						'group_b_products'  => $group_b_products,
						'percent'           => $percent,
					);
				}
			}
		}
		update_option( self::OPT_COMBO, $combos );

		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-success is-dismissible"><p>Reglas de descuento guardadas correctamente.</p></div>';
		} );
	}

	/**
	 * Convierte "123, ADH-001, 456" en un arreglo de identificadores (IDs y/o SKUs) sin normalizar el tipo,
	 * ya que product_matches() decide en tiempo real si cada valor es un ID numérico o un SKU.
	 */
	private function parse_id_list( $raw ) {
		$raw   = is_array( $raw ) ? implode( ',', $raw ) : (string) $raw;
		$items = array_map( 'trim', explode( ',', $raw ) );
		$items = array_filter( $items, function ( $v ) {
			return '' !== $v;
		} );
		return array_map( 'sanitize_text_field', array_values( $items ) );
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$individual_rules = $this->get_individual_rules();
		$combo_rules       = $this->get_combo_rules();

		// Aseguramos al menos una fila vacía para que el formulario siempre muestre algo.
		if ( empty( $individual_rules ) ) {
			$individual_rules = array( array( 'cats' => array(), 'products' => array(), 'percent' => '' ) );
		}
		if ( empty( $combo_rules ) ) {
			$combo_rules = array( array( 'label' => '', 'group_a_cats' => array(), 'group_a_products' => array(), 'group_b_cats' => array(), 'group_b_products' => array(), 'percent' => '' ) );
		}

		$categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		?>
		<div class="wrap">
			<h1>Descuentos Condicionales WooCommerce</h1>
			<p>Configura descuentos individuales por categoría/producto, y descuentos combo que se activan cuando en el carrito hay productos de dos listas distintas al mismo tiempo.</p>

			<form method="post">
				<?php wp_nonce_field( 'lcd_save_settings', 'lcd_nonce' ); ?>

				<h2>1. Descuentos individuales</h2>
				<p>Ej: categoría "Cerámica" → 5%. Se aplica siempre que el producto no esté cubierto por un combo activo.</p>
				<table class="widefat" id="lcd-individual-table">
					<thead>
						<tr>
							<th style="width:35%">Categorías (selecciona una o varias)</th>
							<th style="width:25%">IDs o SKU de producto (separados por coma, opcional)</th>
							<th style="width:15%">% Descuento</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $individual_rules as $i => $rule ) : ?>
							<tr>
								<td>
									<select name="ind_cats[<?php echo (int) $i; ?>][]" multiple size="4" style="width:100%">
										<?php foreach ( $categories as $cat ) : ?>
											<option value="<?php echo (int) $cat->term_id; ?>" <?php selected( in_array( $cat->term_id, (array) $rule['cats'], true ) ); ?>>
												<?php echo esc_html( $cat->name ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<input type="text" name="ind_products[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( implode( ', ', (array) $rule['products'] ) ); ?>" placeholder="Ej: 123, ADH-001, 456" style="width:100%">
								</td>
								<td>
									<input type="number" step="0.01" min="0" max="100" name="ind_percent[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $rule['percent'] ); ?>" style="width:100%">
								</td>
								<td><button type="button" class="button lcd-remove-row">Quitar</button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button" id="lcd-add-individual">+ Agregar regla individual</button></p>

				<hr>

				<h2>2. Descuentos combo</h2>
				<p>Se activa cuando el carrito contiene al menos un producto del Grupo A <strong>y</strong> al menos un producto del Grupo B. El % de combo reemplaza al descuento individual en los productos involucrados.</p>
				<table class="widefat" id="lcd-combo-table">
					<thead>
						<tr>
							<th style="width:12%">Nombre (referencia)</th>
							<th style="width:19%">Grupo A - Categorías</th>
							<th style="width:14%">Grupo A - IDs/SKU producto</th>
							<th style="width:19%">Grupo B - Categorías</th>
							<th style="width:14%">Grupo B - IDs/SKU producto</th>
							<th style="width:10%">% Combo</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $combo_rules as $i => $rule ) : ?>
							<tr>
								<td><input type="text" name="combo_label[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $rule['label'] ); ?>" placeholder="Ej: Cerámica + Adhesivo" style="width:100%"></td>
								<td>
									<select name="combo_a_cats[<?php echo (int) $i; ?>][]" multiple size="4" style="width:100%">
										<?php foreach ( $categories as $cat ) : ?>
											<option value="<?php echo (int) $cat->term_id; ?>" <?php selected( in_array( $cat->term_id, (array) $rule['group_a_cats'], true ) ); ?>>
												<?php echo esc_html( $cat->name ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
								<td><input type="text" name="combo_a_products[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( implode( ', ', (array) $rule['group_a_products'] ) ); ?>" placeholder="Ej: 123, CER-005" style="width:100%"></td>
								<td>
									<select name="combo_b_cats[<?php echo (int) $i; ?>][]" multiple size="4" style="width:100%">
										<?php foreach ( $categories as $cat ) : ?>
											<option value="<?php echo (int) $cat->term_id; ?>" <?php selected( in_array( $cat->term_id, (array) $rule['group_b_cats'], true ) ); ?>>
												<?php echo esc_html( $cat->name ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
								<td><input type="text" name="combo_b_products[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( implode( ', ', (array) $rule['group_b_products'] ) ); ?>" placeholder="Ej: ADH-001" style="width:100%"></td>
								<td><input type="number" step="0.01" min="0" max="100" name="combo_percent[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $rule['percent'] ); ?>" style="width:100%"></td>
								<td><button type="button" class="button lcd-remove-row">Quitar</button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button" id="lcd-add-combo">+ Agregar regla combo</button></p>

				<p><button type="submit" name="lcd_save" class="button button-primary">Guardar cambios</button></p>
			</form>
		</div>

		<script>
		(function() {
			function nextIndex(tableId) {
				var rows = document.querySelectorAll('#' + tableId + ' tbody tr');
				return rows.length;
			}

			document.getElementById('lcd-add-individual').addEventListener('click', function() {
				var idx = nextIndex('lcd-individual-table');
				var tbody = document.querySelector('#lcd-individual-table tbody');
				var firstSelect = document.querySelector('#lcd-individual-table select');
				var optionsHtml = firstSelect ? firstSelect.innerHTML : '';
				var row = document.createElement('tr');
				row.innerHTML =
					'<td><select name="ind_cats[' + idx + '][]" multiple size="4" style="width:100%">' + optionsHtml + '</select></td>' +
					'<td><input type="text" name="ind_products[' + idx + ']" placeholder="Ej: 123, ADH-001, 456" style="width:100%"></td>' +
					'<td><input type="number" step="0.01" min="0" max="100" name="ind_percent[' + idx + ']" style="width:100%"></td>' +
					'<td><button type="button" class="button lcd-remove-row">Quitar</button></td>';
				tbody.appendChild(row);
			});

			document.getElementById('lcd-add-combo').addEventListener('click', function() {
				var idx = nextIndex('lcd-combo-table');
				var tbody = document.querySelector('#lcd-combo-table tbody');
				var firstSelect = document.querySelector('#lcd-combo-table select');
				var optionsHtml = firstSelect ? firstSelect.innerHTML : '';
				var row = document.createElement('tr');
				row.innerHTML =
					'<td><input type="text" name="combo_label[' + idx + ']" placeholder="Ej: Cerámica + Adhesivo" style="width:100%"></td>' +
					'<td><select name="combo_a_cats[' + idx + '][]" multiple size="4" style="width:100%">' + optionsHtml + '</select></td>' +
					'<td><input type="text" name="combo_a_products[' + idx + ']" placeholder="Ej: 123, CER-005" style="width:100%"></td>' +
					'<td><select name="combo_b_cats[' + idx + '][]" multiple size="4" style="width:100%">' + optionsHtml + '</select></td>' +
					'<td><input type="text" name="combo_b_products[' + idx + ']" placeholder="Ej: ADH-001" style="width:100%"></td>' +
					'<td><input type="number" step="0.01" min="0" max="100" name="combo_percent[' + idx + ']" style="width:100%"></td>' +
					'<td><button type="button" class="button lcd-remove-row">Quitar</button></td>';
				tbody.appendChild(row);
			});

			document.addEventListener('click', function(e) {
				if (e.target && e.target.classList.contains('lcd-remove-row')) {
					var row = e.target.closest('tr');
					var tbody = row.parentElement;
					if (tbody.children.length > 1) {
						row.remove();
					}
				}
			});
		})();
		</script>
		<?php
	}
}
