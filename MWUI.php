<?php
/*
Plugin Name: Mstar Woo User Input
Description: This plugin creates a checkmark CMB within "products" to allow front-end users to enter a price for items (IE: gift cards, donations, etc).
Version:     1.0
Author:      Elson Smith
License:     GPL3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
*/
/*-----------------------------------------------------------------------------------*/
/* Add Custom Fields to Single Product */
/*-----------------------------------------------------------------------------------*/
/* Create the database table and items needed to store and link the UID (unique key/unique ID) with the price of the item */
global $jal_db_version;
$jal_db_version = '1.0';
global $wpdb;
global $jal_db_version;
$table_name = $wpdb->prefix . 'mstar_woo_user_input';
$charset_collate = $wpdb->get_charset_collate();
$sql = "CREATE TABLE $table_name (
	id mediumint(9) NOT NULL AUTO_INCREMENT,
	UID text NOT NULL,
	price decimal(6,2) NOT NULL,
	UNIQUE KEY id (id)
) $charset_collate;";
require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
dbDelta( $sql );
add_option( 'jal_db_version', $jal_db_version );	

/* Create meta box to decide which products will have the custom field or not */
function custom_user_input_for_woocommerce_add_meta_box() {
	add_meta_box('custom_user_input_for_woocommerce', 'Custom User Input for WooCommerce', '_cmb_custom_user_input_for_woocommerce', 'product', 'side', 'low');
}
add_action( 'add_meta_boxes', 'custom_user_input_for_woocommerce_add_meta_box' );

function _cmb_custom_user_input_for_woocommerce($post) {
	wp_nonce_field( '_custom_user_input_for_woocommerce_nonce', 'custom_user_input_for_woocommerce_nonce' ); ?>
	<p>
		<input type="checkbox" name="custom_user_input_for_woocommerce" id="custom_user_input_for_woocommerce" <?php if(get_post_meta( $post->ID, _cmb_custom_user_input_for_woocommerce, true)) { echo 'checked="checked"'; } ?>>
		<label for="custom_user_input_for_woocommerce">Checkmark if this item needs custom user input.</label>	
	</p>
<?php }
function custom_user_input_for_woocommerce_save( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
	if ( ! isset( $_POST['custom_user_input_for_woocommerce_nonce'] ) || ! wp_verify_nonce( $_POST['custom_user_input_for_woocommerce_nonce'], '_custom_user_input_for_woocommerce_nonce' ) ) return;
	if ( ! current_user_can( 'edit_post', $post_id ) ) return;
	if ( isset( $_POST['custom_user_input_for_woocommerce'] ) )
		update_post_meta( $post_id, '_cmb_custom_user_input_for_woocommerce', esc_attr( $_POST['custom_user_input_for_woocommerce'] ) );
	else
		update_post_meta( $post_id, '_cmb_custom_user_input_for_woocommerce', null );
}
add_action( 'save_post', 'custom_user_input_for_woocommerce_save' );

/* Add the field to the single */
add_action( 'woocommerce_before_add_to_cart_button', 'giftcard_custom_checkout_field' );
function giftcard_custom_checkout_field() {
	global $post;
	session_start(); //this is to set the "session" cookie of the price
	//ONLY add new form/user-input-field to the page ID of 548 (Gift Cards)
	if(get_post_meta( $post->ID, _cmb_custom_user_input_for_woocommerce, true)) {
		// Set Input
		woocommerce_form_field('giftCardInput', array(
			'type' => 'text',
			'class' => array('gift-card-input'),
			'label' => 'Please enter an amount:',
			'placeholder' => 'Enter a number here',
			'maxlength' => 8,
		));
		//Get the value from the input box upon add to cart click (do not pass value if it's empty/null)
		if ( !empty( $_POST[giftCardInput] )) {
			$_SESSION['giftCardInput'] = ($_POST[giftCardInput]);
			$_SESSION['giftCardInput'] = str_replace("$", "", $_SESSION['giftCardInput']);
			global $wpdb;
			$table_name = $wpdb->prefix.'mstar_woo_user_input';
			$wpdb->insert($table_name, array('UID' => $_SESSION['giftCardUID'], 'price' => $_SESSION['giftCardInput']));	
		}
	}
} // end function

/*-----------------------------------------------------------------------------------*/
/* Verify if there is input for the gift card amount. Deny adding to cart if empty. */
/*-----------------------------------------------------------------------------------*/
function no_amount_entered_validation() {
	if (isset($_POST['giftCardInput'])) { //skip doing this and report true with the "elseif" if the $_POST item doesn't exist
		if (empty($_POST['giftCardInput'])) { //if empty run this error and report false to prevent going to cart
			wc_add_notice( __('Please enter a Gift Card amount below.','woocommerce'),'error');
			$passed = false;
		}
		else { //if it's not empty, add to cart and proceed
			$passed = true;
		}
		return $passed; //send the bool to the "add_action"
	}
	elseif (!isset($_POST['giftCardInput'])) { //if there is no specific $_POST item, just report true (possible bugs here...)
		return true;
	}
}
add_action('woocommerce_add_to_cart_validation','no_amount_entered_validation',10,5);  

/*-----------------------------------------------------------------------------------*/
/* Pass the value to the checkout */
/*-----------------------------------------------------------------------------------*/
add_action( 'woocommerce_after_calculate_totals', 'add_custom_price' ); //this triggers AFTER you hit add to cart
function add_custom_price( $cart_object ) {
	global $woocommerce;
	foreach ( $cart_object->cart_contents as $key => $value ) {
		if ($value['unique_key']) {
			$meta_key = $value['unique_key'];
			global $wpdb;
			$mstar_woo_user_price = $wpdb->get_var($wpdb->prepare("SELECT price FROM wp_mstar_woo_user_input WHERE UID = %s", $meta_key) );
			$value['data']->price = $mstar_woo_user_price;
		}
	}
}

/*-----------------------------------------------------------------------------------*/
/* Recalculate the value to give the correct Subtotal and Total */
/*-----------------------------------------------------------------------------------*/
add_action( 'woocommerce_before_cart_table', 'woo_pre_giftCardInput' );
function woo_pre_giftCardInput() {
	global $woocommerce;
	$woocommerce->cart->calculate_totals();
}

/*-----------------------------------------------------------------------------------*/
/* Remove the "Add to Cart" since it'll add a gift card with $0; replace it with "Select Options". Also remove price. */
/*-----------------------------------------------------------------------------------*/
add_action('woocommerce_before_shop_loop_item','remove_loop_button');
function remove_loop_button(){
	global $product;
	global $post;
	if(get_post_meta( $post->ID, _cmb_custom_user_input_for_woocommerce, true)) {
		remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );
		remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10 );
	}
}

add_action('woocommerce_after_shop_loop_item','replace_add_to_cart');
function replace_add_to_cart() {
	global $product;
	global $post;
	if(get_post_meta( $post->ID, _cmb_custom_user_input_for_woocommerce, true)) {
		$link = $product->get_permalink();
		echo '<a href="'.$link.'" class="button add_to_cart_button">Select Options</a>';
		add_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );
		add_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10 );
	}
}

add_action ('woocommerce_before_single_product_summary', 'remove_single_cart_button');
function remove_single_cart_button() {
	global $post;
	if(get_post_meta( $post->ID, _cmb_custom_user_input_for_woocommerce, true)) {
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );
	}
}

/*-----------------------------------------------------------------------------------*/
/* Allows multiple gift cards with various values/amounts on them */
/*-----------------------------------------------------------------------------------*/
add_filter('woocommerce_add_cart_item_data','namespace_for_individual_cart_items',10,2);
function namespace_for_individual_cart_items($cart_item_data, $product_id) {
	if(get_post_meta( $product_id, _cmb_custom_user_input_for_woocommerce, true)) {
		$unique_cart_item_key = md5(microtime().rand()."Bowling159For951The357Most456Random648String972Ever389");
		session_start();
		$_SESSION['giftCardUID'] = $unique_cart_item_key;
		$cart_item_data['unique_key'] = $unique_cart_item_key;
		return $cart_item_data;
	}
}
?>
