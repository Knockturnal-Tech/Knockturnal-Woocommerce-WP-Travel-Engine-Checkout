<?php
/**
 * Plugin Name: KT - WC Checkout (WTE-style fields + Deposit + WTE Sync)
 * Description: Adds WTE-like checkout fields and Pay Full/Deposit option to WooCommerce classic checkout. Stores wte_id on the Woo order and syncs WTE booking status on Woo payment completion.
 * Version: 1.1.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** -----------------------------
 * Helpers
 * ------------------------------ */

function kt_float( $v ) {
	return floatval( preg_replace('/[^0-9\.\-]/', '', (string) $v ) );
}

function kt_logger() {
	if ( function_exists( 'wc_get_logger' ) ) return wc_get_logger();
	return null;
}

function kt_log( $msg, $context = [] ) {
	$logger = kt_logger();
	if ( ! $logger ) return;
	$logger->info( $msg, array_merge( [ 'source' => 'kt-wte' ], $context ) );
}

/**
 * Read totals directly from WTE cart item structure.
 * Sums across cart items (in case there are multiple trips).
 */
function kt_get_wte_totals_from_cart() : array {

    $out = [
        'total'         => 0.0,
        'payable_now'   => 0.0,
        'due_total'     => 0.0,
        'partial_total' => 0.0,
    ];

    if ( ! WC()->cart ) return $out;

    foreach ( WC()->cart->get_cart() as $item ) {

        if ( empty($item['tripbooking']['totals']) ) continue;

        $t = $item['tripbooking']['totals'];

        $out['total']         += (float) ($t['total'] ?? 0);
        $out['payable_now']   += (float) ($t['payable_now'] ?? 0);
        $out['due_total']     += (float) ($t['due_total'] ?? 0);
        $out['partial_total'] += (float) ($t['partial_total'] ?? 0);
    }

    return $out;
}

/**
 * Single Truth Function (22 April 2026)
 * 
 */
function kt_get_payment_breakdown() {

	$totals = kt_get_wte_totals_from_cart();

	$full    = (float) $totals['total'];
	$deposit = (float) $totals['partial_total'];

	// fallback safety
	if ($deposit <= 0) {
		$deposit = round($full * 0.2);
	}

    $choice = 'full';
    
    if ( isset($_POST['post_data']) ) {
    	parse_str($_POST['post_data'], $posted);
    	if ( isset($posted['kt_payment_choice']) ) {
    		$choice = $posted['kt_payment_choice'];
    	}
    }

	if ( $choice === 'deposit' ) {

		return [
			'choice'  => 'deposit',
			'pay_now' => $deposit,
			'due'     => max(0, $full - $deposit),
			'full'    => $full,
			'deposit' => $deposit,
		];
	}

	return [
		'choice'  => 'full',
		'pay_now' => $full,
		'due'     => 0,
		'full'    => $full,
		'deposit' => $deposit,
	];
}

/** -----------------------------
 * A) Capture wte_id from checkout URL and keep it in session
 * ------------------------------ */

add_action( 'wp_loaded', function () {
	if ( ! function_exists('WC') || ! WC()->session ) return;
	if ( ! function_exists('is_checkout') || ! is_checkout() || is_order_received_page() ) return;

	if ( isset($_GET['wte_id']) && $_GET['wte_id'] !== '' ) {
		$wte_id = sanitize_text_field( wp_unslash( $_GET['wte_id'] ) );
		WC()->session->set( 'kt_wte_id', $wte_id );
		kt_log( 'Captured wte_id into session: ' . $wte_id );
	}
});

/** -----------------------------
 * 1) Add WTE-like checkout fields
 * ------------------------------ */

add_filter( 'woocommerce_checkout_fields', function( $fields ) {

	$fields['billing']['billing_dietary_requirements'] = array(
		'type'     => 'text',
		'label'    => 'Dietary Requirements',
		'required' => true,
		'class'    => array('form-row-wide'),
		'priority' => 120,
	);

	$fields['billing']['billing_bed_type_preference'] = array(
		'type'     => 'select',
		'label'    => 'Bed Type Preference',
		'required' => true,
		'class'    => array('form-row-wide'),
		'priority' => 130,
		'options'  => array(
			''       => 'None',
			'twin'   => 'Twin',
			'double' => 'Double',
			'queen'  => 'Queen',
			'king'   => 'King',
		),
	);

	$fields['billing']['billing_sleeping_habits'] = array(
		'type'     => 'select',
		'label'    => 'Sleeping Habits',
		'required' => true,
		'class'    => array('form-row-wide'),
		'priority' => 140,
		'options'  => array(
			''              => 'Select…',
			'snorer'        => 'Snorer',
			'non_snorer'    => 'Non-Snorer',
			'light_sleeper' => 'Light Sleeper',
			'heavy_sleeper' => 'Heavy Sleeper',
		),
	);

	$fields['billing']['billing_snoring_disclaimer'] = array(
		'type'     => 'checkbox',
		'label'    => 'By booking a room with us, guests acknowledge that any claims of not snoring will be taken at face value. However, should a complaint be received from a roommate regarding loud or disruptive snoring, the guest in question will be required to secure their own private accommodation at their own expense.',
		'required' => true,
		'class'    => array('form-row-wide'),
		'priority' => 150,
	);

	return $fields;
});

/** -----------------------------
 * 2) Store wte_id on Woo order meta (authoritative)
 * ------------------------------ */
add_action('woocommerce_checkout_create_order', function($order, $data) {

	if ( is_admin() && ! defined('DOING_AJAX') ) return;

	$breakdown = kt_get_payment_breakdown();

	$order->update_meta_data('_kt_pay_now', $breakdown['pay_now']);

	$order->update_meta_data('_kt_payment_choice', $breakdown['choice']);
	$order->update_meta_data('_kt_full_amount', $breakdown['full']);
	$order->update_meta_data('_kt_deposit_amount', $breakdown['deposit']);
	$order->update_meta_data('_kt_balance_due', $breakdown['due']);

}, 50, 2);
/**
 * Adding new logic for DPO amount sent to payment gateway (22 April 2026)
 * 
 */
 
add_action('woocommerce_before_calculate_totals', function($cart){

	if (is_admin() && !defined('DOING_AJAX')) return;
	if (!WC()->session) return;

	$choice = WC()->session->get('kt_payment_choice', 'full');

	$totals = kt_get_wte_totals_from_cart();

	$full = (float) $totals['total'];
	$deposit = (float) $totals['partial_total'];

	if ($deposit <= 0) {
		$deposit = round($full * 0.2);
	}

	$pay_now = ($choice === 'deposit') ? $deposit : $full;

	WC()->session->set('kt_pay_now_amount', $pay_now);

}, 20);


/** -----------------------------
 * 3) Deposit vs Full choice
 * ------------------------------ */

/**
 * Render payment choice (single render per request to avoid duplicates).
 */
add_action('woocommerce_review_order_before_payment', function() {

	if ( ! WC()->cart ) return;

	$data = kt_get_payment_breakdown();

	$choice  = $data['choice'];
	$full    = $data['full'];
	$deposit = $data['deposit'];
	$pay_now = $data['pay_now'];
	$due     = $data['due'];

	echo '<div class="kt-payment-choice">';

	echo '<h3>Choose Payment Option</h3>';

	echo '<label>
	<input type="radio" name="kt_payment_choice" value="full" '.checked($choice,'full',false).'>
	Pay Full Amount (N$'.number_format($full,0).')
	</label><br>';

	echo '<label>
	<input type="radio" name="kt_payment_choice" value="deposit" '.checked($choice,'deposit',false).'>
	Pay Deposit (N$'.number_format($deposit,0).')
	</label>';

	echo '<hr>';

    echo '<div class="kt-payment-summary">';
    echo '<strong>Selected Option:</strong> ' . esc_html( ucfirst($choice) );
    echo '</div>';


});

/**
 * Persist choice into session during checkout refresh.
 */
add_action('woocommerce_checkout_update_order_review', function($posted_data) {

	parse_str($posted_data, $data);

	if ( ! WC()->session ) return;

	$choice = $data['kt_payment_choice'] ?? 'full';

	if ( ! in_array($choice, ['full','deposit'], true) ) {
		$choice = 'full';
	}

	WC()->session->set('kt_payment_choice', $choice);

	// FORCE refresh consistency
	WC()->session->set('kt_last_choice_sync', time());

}, 20);
/**
 * Apply deposit by adding a negative fee for the remainder.
 */
add_action( 'woocommerce_cart_calculate_fees', function( $cart ) {

	if ( is_admin() && ! defined('DOING_AJAX') ) return;
	if ( ! function_exists('is_checkout') || ( ! is_checkout() && ! wp_doing_ajax() ) ) return;
	if ( ! function_exists('WC') || ! WC()->session ) return;

	$choice = WC()->session->get( 'kt_payment_choice', 'full' );

	$totals  = kt_get_wte_totals_from_cart();
    $full    = (float) $totals['total'];
    $deposit = (float) $totals['partial_total'];

	WC()->session->set( 'kt_balance_due', 0 );

	if ( $choice !== 'deposit' || $deposit <= 0 || $deposit >= $full ) {
		return;
	}

	$remainder = max( 0, $full - $deposit );
	if ( $remainder > 0 ) {
		WC()->session->set( 'kt_balance_due', $remainder );
	}

}, 30 );

/**
 * Save deposit meta to order.
 */


/** -----------------------------
 * 4) Front-end behavior (deposit toggle)
 * ------------------------------ */

add_action('wp_footer', function () {
  if ( ! function_exists('is_checkout') || ! is_checkout() || is_order_received_page() ) return;
  ?>
  <script>
    (function($){

      function ktDedupePaymentChoice(){
        var $boxes = $('.kt-payment-choice');
        if ($boxes.length <= 1) return;

        // Prefer the box that has the deposit option
        var $withDeposit = $boxes.filter(function(){
          return $(this).find('input[name="kt_payment_choice"][value="deposit"]').length > 0;
        });

        var $keep;
        if ($withDeposit.length) {
          // If multiple somehow have deposit, keep the last one (usually freshest fragment)
          $keep = $withDeposit.last();
        } else {
          // Otherwise keep the last one (freshest fragment)
          $keep = $boxes.last();
        }

        $boxes.not($keep).remove();
      }

      // Run on load + after Woo fragments update
      $(function(){
        ktDedupePaymentChoice();
      });

      $(document.body).on('updated_checkout', function(){
        ktDedupePaymentChoice();
      });

      // Deposit/full triggers totals refresh
      $(document.body).on('change', 'input[name="kt_payment_choice"]', function(){
        $(document.body).trigger('update_checkout');
      });

    })(jQuery);
  </script>
  <?php
}, 50);



/** -----------------------------
 * 5) On Woo payment completion: update matching WTE booking status
 * ------------------------------ */

function kt_find_booking_id_by_wte_id( $wte_id ) : int {
	global $wpdb;

	$wte_id = trim( (string) $wte_id );
	if ( $wte_id === '' ) return 0;

	$sql = "
		SELECT pm.post_id
		FROM {$wpdb->postmeta} pm
		INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		WHERE p.post_type = 'booking'
		  AND pm.meta_value = %s
		ORDER BY pm.meta_id DESC
		LIMIT 1
	";
	$post_id = (int) $wpdb->get_var( $wpdb->prepare( $sql, $wte_id ) );
	if ( $post_id > 0 ) return $post_id;

	$like = '%' . $wpdb->esc_like( $wte_id ) . '%';
	$sql2 = "
		SELECT pm.post_id
		FROM {$wpdb->postmeta} pm
		INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		WHERE p.post_type = 'booking'
		  AND pm.meta_value LIKE %s
		ORDER BY pm.meta_id DESC
		LIMIT 1
	";
	$post_id = (int) $wpdb->get_var( $wpdb->prepare( $sql2, $like ) );

	return max(0, $post_id);
}

function kt_recursive_set_keys( &$arr, $map, &$touched = false ) {
	if ( ! is_array($arr) ) return;

	foreach ( $arr as $k => &$v ) {
		if ( is_array($v) ) {
			kt_recursive_set_keys( $v, $map, $touched );
		} else {
			if ( is_string($k) && array_key_exists($k, $map) ) {
				$v = $map[$k];
				$touched = true;
			}
		}
	}
}

function kt_sync_wte_booking_from_order( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) return;

	if ( $order->get_meta('_kt_wte_synced') ) {
		return;
	}

	$wte_id = (string) $order->get_meta('_kt_wte_id');
	if ( $wte_id === '' && function_exists('WC') && WC()->session ) {
		$wte_id = (string) WC()->session->get('kt_wte_id', '');
	}
	if ( $wte_id === '' ) {
		kt_log( "WTE sync skipped: no wte_id on order {$order_id}" );
		return;
	}

	$booking_id = kt_find_booking_id_by_wte_id( $wte_id );
	if ( ! $booking_id ) {
		kt_log( "WTE sync: booking not found for wte_id={$wte_id} order_id={$order_id}" );
		return;
	}

	$choice = (string) $order->get_meta('_kt_payment_choice');
	$status_label = ( $choice === 'deposit' ) ? 'Deposit Paid' : 'Full Amount Paid';

	$paid_amount = (float) $order->get_total();
	$deposit_amt = (float) $order->get_meta('_kt_deposit_amount');
	$balance_amt = (float) $order->get_meta('_kt_balance_due');

	update_post_meta( $booking_id, '_kt_woo_order_id', (int) $order_id );
	update_post_meta( $booking_id, '_kt_payment_status', $status_label );
	update_post_meta( $booking_id, '_kt_amount_paid', $paid_amount );
	update_post_meta( $booking_id, '_kt_deposit_amount', $deposit_amt );
	update_post_meta( $booking_id, '_kt_balance_due', $balance_amt );

	update_post_meta( $booking_id, 'wp_travel_engine_payment_status', $status_label );
	update_post_meta( $booking_id, 'wp_travel_engine_booking_status', $status_label );
	update_post_meta( $booking_id, 'payment_status', $status_label );
	update_post_meta( $booking_id, 'booking_status', $status_label );
	update_post_meta( $booking_id, 'total_paid', $paid_amount );

	$setting = get_post_meta( $booking_id, 'wp_travel_engine_booking_setting', true );
	if ( is_array($setting) ) {
		$touched = false;

		$map = [
			'payment_status' => $status_label,
			'booking_status' => $status_label,
			'total_paid'     => $paid_amount,
			'paid_amount'    => $paid_amount,
			'amount_paid'    => $paid_amount,
		];

		kt_recursive_set_keys( $setting, $map, $touched );

		if ( $touched ) {
			update_post_meta( $booking_id, 'wp_travel_engine_booking_setting', $setting );
		}
	}

	$order->update_meta_data( '_kt_wte_synced', 1 );
	$order->save();

	kt_log( "WTE sync OK: order_id={$order_id} wte_id={$wte_id} booking_id={$booking_id} status={$status_label} paid={$paid_amount}" );

	do_action( 'kt_wte_booking_synced', $booking_id, $order_id, $status_label, $paid_amount );
}

add_action( 'woocommerce_payment_complete', function( $order_id ) {
	kt_sync_wte_booking_from_order( $order_id );
}, 20 );

add_action( 'woocommerce_order_status_changed', function( $order_id, $from, $to ) {
	if ( in_array( $to, ['processing','completed'], true ) ) {
		kt_sync_wte_booking_from_order( $order_id );
	}
}, 20, 3 );

/** ---------------------------------------
 * POST-PAYMENT UX: Redirect cart->thankyou
 * ---------------------------------------- */

add_action('woocommerce_checkout_order_processed', function($order_id) {
    if ( ! function_exists('WC') || ! WC()->session ) return;

    $order = wc_get_order($order_id);
    if ( ! $order ) return;

    WC()->session->set('kt_last_order_id', (int) $order_id);
    WC()->session->set('kt_last_order_key', (string) $order->get_order_key());

    WC()->session->set('kt_gateway_return_expected', 1);
    WC()->session->set('kt_gateway_return_set_at', time());
}, 10, 1);


add_action('template_redirect', function() {

    if ( ! function_exists('WC') || ! WC()->session ) return;

    // Some gateways return to cart, sometimes to checkout with empty cart
    $is_bad_landing =
        ( function_exists('is_cart') && is_cart() ) ||
        ( function_exists('is_checkout') && is_checkout() && ! is_order_received_page() );

    if ( ! $is_bad_landing ) return;

    // Only do this shortly after checkout
    $flag = (int) WC()->session->get('kt_gateway_return_expected', 0);
    if ( ! $flag ) return;

    $set_at = (int) WC()->session->get('kt_gateway_return_set_at', 0);
    if ( $set_at && (time() - $set_at) > 20 * 60 ) { // 20 min window
        WC()->session->__unset('kt_gateway_return_expected');
        WC()->session->__unset('kt_gateway_return_set_at');
        return;
    }

    // Only if cart is empty (prevents interrupting normal browsing)
    if ( WC()->cart && ! WC()->cart->is_empty() ) return;

    $order_id  = (int) WC()->session->get('kt_last_order_id', 0);
    $order_key = (string) WC()->session->get('kt_last_order_key', '');

    if ( ! $order_id || $order_key === '' ) return;

    $order = wc_get_order($order_id);
    if ( ! $order ) return;

    // Prevent loops
    WC()->session->__unset('kt_gateway_return_expected');
    WC()->session->__unset('kt_gateway_return_set_at');

    // Build order-received URL with key
    $url = add_query_arg(
        ['key' => $order_key],
        wc_get_endpoint_url('order-received', $order_id, wc_get_checkout_url())
    );

    wp_safe_redirect($url);
    exit;
});


/** ---------------------------------------
 * DPO RETURN FIX: Stop returning to /cart/
 * ---------------------------------------- */

/**
 * DPO uses the Woo "cancel/back" URL. By default Woo's cancel URL is the CART.
 * We override it ONLY for the DPO gateway so the return goes to a safe endpoint
 * (no order cancellation) and then we redirect to the real order-received page.
 */
add_filter('woocommerce_get_cancel_order_url_raw', function($url, $order, $redirect){

	if ( ! $order instanceof WC_Order ) return $url;

	// Only affect DPO gateway (from the DPO plugin: $this->id = 'woocommerce_dpo')
	if ( $order->get_payment_method() !== 'woocommerce_dpo' ) return $url;

	// Custom safe return endpoint on your site (no cancel_order side effects)
	return add_query_arg([
		'kt_dpo_return' => 1,
		'order_id'      => $order->get_id(),
		'key'           => $order->get_order_key(),
	], home_url('/'));

}, 20, 3);

add_filter('woocommerce_available_payment_gateways', function($gateways){

	if (is_admin()) return $gateways;
	if (!is_checkout()) return $gateways;

	// REMOVE Direct Bank Transfer
	unset($gateways['bacs']);

	return $gateways;
});

/**
 * When DPO returns to our custom endpoint, send customer to the real Thank You page.
 */
add_action('template_redirect', function(){

	// New safe return endpoint
	if ( isset($_GET['kt_dpo_return']) ) {

		$order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
		$key      = isset($_GET['key']) ? sanitize_text_field( wp_unslash($_GET['key']) ) : '';

		if ( ! $order_id ) return;

		$order = wc_get_order($order_id);
		if ( ! $order ) return;

		// Basic key validation
		if ( $key && $key !== $order->get_order_key() ) return;

		wp_safe_redirect( $order->get_checkout_order_received_url() );
		exit;
	}

	// Fallback: if DPO ever still lands on cart with order params, bounce to thank you.
	if ( function_exists('is_cart') && is_cart() && isset($_GET['order_id']) ) {

		$order_id = absint($_GET['order_id']);
		if ( ! $order_id ) return;

		$order = wc_get_order($order_id);
		if ( ! $order ) return;

		wp_safe_redirect( $order->get_checkout_order_received_url() );
		exit;
	}

}, 1);


/** ---------------------------------------
 * THANK YOU PAGE: show status + auto-refresh
 * ---------------------------------------- */

add_action('woocommerce_thankyou', function($order_id) {

	if ( ! $order_id ) return;
	$order = wc_get_order($order_id);
	if ( ! $order ) return;

	$status = $order->get_status();

	$choice = (string) $order->get_meta('_kt_payment_choice', true);
	$choice = $choice ?: 'full';

	$status_label = ($choice === 'deposit') ? 'Deposit Paid' : 'Full Amount Paid';

	echo '<div class="kt-thankyou-status" style="margin:18px 0; padding:16px; border:1px solid #e5e7eb; border-radius:12px;">';

	if ( in_array($status, ['processing','completed'], true) ) {
		echo '<h2 style="margin:0 0 8px;">Payment Complete ✅</h2>';
		echo '<p style="margin:0;">Thank you! Your payment was successful. Status: <strong>' . esc_html($status_label) . '</strong>.</p>';
	} elseif ( $status === 'failed' ) {
		echo '<h2 style="margin:0 0 8px;">Payment Failed ❌</h2>';
		echo '<p style="margin:0;">Your payment did not complete. Please try again or contact support.</p>';
	} else {
		echo '<h2 style="margin:0 0 8px;">Confirming your payment…</h2>';
		echo '<p style="margin:0;">We’ve received your return from the payment gateway. This page will update automatically once the payment is confirmed.</p>';
	}

	echo '</div>';

	if ( ! in_array($status, ['processing','completed','failed'], true) ) {
		?>
		<script>
		(function(){
			const orderId = <?php echo (int) $order_id; ?>;
			let tries = 0;

			async function checkStatus(){
				tries++;
				try{
					const res = await fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>?action=kt_check_order_status&order_id=' + orderId, { credentials: 'same-origin' });
					const json = await res.json();
					if (json && json.success && json.data && json.data.status) {
						if (json.data.status === 'processing' || json.data.status === 'completed' || json.data.status === 'failed') {
							window.location.reload();
							return;
						}
					}
				} catch(e){}

				if (tries < 30) {
					setTimeout(checkStatus, 5000);
				}
			}
			setTimeout(checkStatus, 5000);
		})();
		</script>
		<?php
	}

}, 20);

add_action('wp_ajax_kt_check_order_status', function(){
	$order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
	if ( ! $order_id ) wp_send_json_error(['message' => 'Missing order_id']);

	$order = wc_get_order($order_id);
	if ( ! $order ) wp_send_json_error(['message' => 'Order not found']);

	wp_send_json_success([
		'status' => $order->get_status(),
	]);
});

add_action('wp_ajax_nopriv_kt_check_order_status', function(){
	$order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
	if ( ! $order_id ) wp_send_json_error(['message' => 'Missing order_id']);

	$order = wc_get_order($order_id);
	if ( ! $order ) wp_send_json_error(['message' => 'Order not found']);

	wp_send_json_success([
		'status' => $order->get_status(),
	]);
});

/** -----------------------------
 * 7) Enqueue checkout stylesheet
 * ------------------------------ */

add_action('wp_enqueue_scripts', function () {
	if ( ! function_exists('is_checkout') || ! is_checkout() || is_order_received_page() ) return;

	wp_enqueue_style(
		'kt-wc-wte-checkout',
		plugin_dir_url(__FILE__) . 'kt-wc-wte-checkout.css',
		[],
		'1.1.1'
	);
}, 20);

/**
 * ---- UI WRAPPERS: make Woo classic checkout use WTE booking-flow layout ----
 * Uses Travel Monster theme's existing `.wpte-bf-*` styles.
 */
function kt_wte_ui_step($num, $label, $active = false) {
	$cls = $active ? 'wpte-bf-step active' : 'wpte-bf-step';
	return '<div class="'.esc_attr($cls).'">
		<span class="wpte-bf-step-number">'.esc_html($num).'</span>
		<span class="wpte-bf-step-label">'.esc_html($label).'</span>
	</div>';
}

add_action('woocommerce_before_checkout_form', function () {
	if ( ! function_exists('is_checkout') || ! is_checkout() || is_order_received_page() ) return;

	echo '<div class="wpte-bf-outer kt-wte-woo-checkout">';
	echo '  <div class="wpte-bf-booking-steps wpte-bf-checkout">';
	echo '    <div class="wpte-bf-step-wrap">';
	echo          kt_wte_ui_step(1, 'Trip Details', false);
	echo          kt_wte_ui_step(2, 'Travellers Info', false);
	echo          kt_wte_ui_step(3, 'Checkout', true);
	echo '    </div>';
	echo '  </div>';
	
}, 1);

add_action('woocommerce_after_checkout_form', function () {
	if ( ! function_exists('is_checkout') || ! is_checkout() || is_order_received_page() ) return;
	echo '</div>';
}, 99);

add_action('woocommerce_checkout_before_customer_details', function () {
	if ( ! function_exists('is_checkout') || ! is_checkout() || is_order_received_page() ) return;
	echo '<div class="wpte-bf-step-content-wrap">';
	echo '  <div class="wpte-bf-checkout-form">';
}, 1);

add_action('woocommerce_checkout_after_customer_details', function () {
	if ( ! function_exists('is_checkout') || ! is_checkout() || is_order_received_page() ) return;
	echo '  </div>';
	echo '  <div class="wpte-bf-book-summary">';
	echo '    <div class="wpte-bf-summary-wrap">';
}, 99);

add_action('woocommerce_checkout_after_order_review', function () {
	if ( ! function_exists('is_checkout') || ! is_checkout() || is_order_received_page() ) return;
	echo '    </div>';
	echo '  </div>';
	echo '</div>';
}, 99);

add_action('wp_footer', function () {
if (!is_checkout()) return;
?>
<script>
jQuery(function($){

	function ktFixDuplicatePaymentBox(){
		let boxes = $('.kt-payment-choice');
		if (boxes.length <= 1) return;

		// keep last Woo fragment only
		boxes.not(boxes.last()).remove();
	}

	$(document.body).on('updated_checkout', function(){
		ktFixDuplicatePaymentBox();
	});

	$(function(){
		ktFixDuplicatePaymentBox();
	});

	$(document.body).on('change','input[name="kt_payment_choice"]',function(){
		$('body').trigger('update_checkout');
	});

});
</script>
<?php
}, 50);