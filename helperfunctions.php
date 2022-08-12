<?php
//Exit if not called in proper context
if (!defined('ABSPATH')) exit();

function foxyshop_load_public_scripts() {
	global $foxyshop_settings;

	$skip = 0;
	if (defined('FOXYSHOP_SKIP_FOXYCART_INCLUDES')) $foxyshop_settings['include_exception_list'] = "*";
	if ($foxyshop_settings['include_exception_list']) {
		if ($foxyshop_settings['include_exception_list'] == "*") {
			$skip = 1;
		} else {
			$include_exception_list = explode(",", str_replace(" ", "", $foxyshop_settings['include_exception_list']));
			if (is_page($include_exception_list) || is_single($include_exception_list)) $skip = 1;
		}
	}
	if ($skip) return;


	if ($foxyshop_settings['use_jquery']) foxyshop_insert_jquery();

	if (version_compare($foxyshop_settings['version'], '2.0', ">=")) {
		foxyshop_insert_foxycart_loader();
	} else {
		foxyshop_insert_foxycart_files();
	}
	foxyshop_load_site_scripts();
	if ($foxyshop_settings['ga']) foxyshop_insert_google_analytics();
}

function foxyshop_dequeue_scripts_for_templates() {
	wp_dequeue_script('foxy_colorbox');
	wp_dequeue_style('foxy_colorbox');
	wp_dequeue_script('foxy_loader');
	wp_dequeue_script('foxyshop_js');
}

add_filter( 'script_loader_tag', function ( $tag, $handle ) {
	if ( 'foxy-loader' !== $handle ) {
		return $tag;
	}
	return str_replace( ' src', ' async defer src', $tag );
}, 10, 2 );

//Import For The Header
function foxyshop_insert_foxycart_files() {
	global $foxyshop_settings;
	if (empty($foxyshop_settings['domain'])) return;
	if ($foxyshop_settings['foxycart_include_cache']) {
		echo foxy_wp_html($foxyshop_settings['foxycart_include_cache']);
	} elseif ($foxyshop_settings['version'] == '1.0') {
		wp_enqueue_style( 'foxy_colorbox',  FOXYSHOP_DIR . '/css/colorbox.1.3.19.css', [], FOXYSHOP_VERSION );
		// Javascript file is unique to the configured Foxy store, so it is loaded directly from the Foxy CDN
		wp_enqueue_script( 'foxy_colorbox', 'https://cdn.foxycart.com/' . str_replace('.foxycart.com','',$foxyshop_settings['domain']) . '/foxycart.colorbox.js', ['jquery'] );
	} elseif ($foxyshop_settings['version'] == '1.1') {
		wp_enqueue_style( 'foxy_colorbox',  FOXYSHOP_DIR . '/css/colorbox.1.3.23.css', [], FOXYSHOP_VERSION );
		// Javascript file is unique to the configured Foxy store, so it is loaded directly from the Foxy CDN
		wp_enqueue_script( 'foxy_colorbox', 'https://cdn.foxycart.com/' . str_replace('.foxycart.com','',$foxyshop_settings['domain']) . '/foxycart.colorbox.js?ver=2', ['jquery'] );
	}
}

function foxyshop_insert_foxycart_loader() {
	global $foxyshop_settings;
	if (empty($foxyshop_settings['domain'])) return;
	// Javascript file is unique to the configured Foxy store, and dynamically updated based on changes made
	// in the Foxy store administration, so the file is loaded directly from the Foxy CDN
	wp_enqueue_script( 'foxy_loader', "https://cdn.foxycart.com/" . str_replace('.foxycart.com','',$foxyshop_settings['domain']) . "/loader.js", null, null, true );
}


//Sets up the $product array
function foxyshop_setup_product($thepost = false, $shortcut = false) {
	global $foxyshop_settings, $product;

	//Product ID
	if (gettype($thepost) == "integer") {
		$newposts = get_posts(array("post_type" => "foxyshop_product", "page_id" => $thepost));
		foreach ($newposts as $newpost) {
			$thepost = $newpost;
		}

	//Product Slug
	} elseif (gettype($thepost) == "string") {
		$newposts = get_posts(array("post_type" => "foxyshop_product", "name" => $thepost));
		foreach ($newposts as $newpost) {
			$thepost = $newpost;
		}

	//Product Object
	} elseif (!$thepost) {
		global $post;
		$thepost = $post;
	}

	//Skip if $product is already set and hasn't changed ID's
	if (isset($product)) {
		if ($product['id'] == $thepost->ID) return $product;
	}

	$new_product = array();
	$new_product['id'] = $thepost->ID;
	$new_product['name'] = trim($thepost->post_title);
	$new_product['code'] = (get_post_meta($thepost->ID,'_code', 1) ? get_post_meta($thepost->ID,'_code', 1) : $thepost->ID);
	$new_product['description'] = apply_filters('the_content', $thepost->post_content);
	$new_product['short_description'] = $thepost->post_excerpt;
	$new_product['originalprice'] = number_format((double)get_post_meta($thepost->ID,'_price', 1), FOXYSHOP_DECIMAL_PLACES,".","");
	$new_product['quantity_min'] = (int)get_post_meta($thepost->ID,'_quantity_min', 1);
	$new_product['quantity_max'] = (int)get_post_meta($thepost->ID,'_quantity_max', 1);
	$new_product['quantity_max_original'] = $new_product['quantity_max'];
	$new_product['quantity_hide'] = get_post_meta($thepost->ID,'_quantity_hide', 1);
	$new_product['hide_product'] = get_post_meta($thepost->ID,'_hide_product', 1);
	$new_product['url'] = get_bloginfo("url") . FOXYSHOP_URL_BASE . '/' . apply_filters('foxyshop_product_url_slug', FOXYSHOP_PRODUCTS_SLUG, $thepost->ID) . '/' . $thepost->post_name . '/';
	$new_product['post_date'] = strtotime($thepost->post_date);

	//All fields that are loaded straight in without changing or checking data
	$fields = array('category', 'related_products', 'bundled_products', 'addon_products', 'discount_quantity_amount', 'discount_quantity_percentage', 'discount_price_amount', 'discount_price_percentage', 'sub_frequency');
	foreach ($fields as $fieldname) {
		$new_product[$fieldname] = get_post_meta($thepost->ID,'_'.$fieldname, 1);
	}

	//Calculate Subscription Start
	$foxycart_last_chars = array("m","d","y");
	$sub_startdate = get_post_meta($thepost->ID,'_sub_startdate', 1);
	if ($sub_startdate) {
		$last_char = strtolower(substr($sub_startdate, -1));
		if (!in_array($last_char, $foxycart_last_chars) && $sub_startdate != preg_replace("/[^0-9]/","", $sub_startdate)) $sub_startdate = date("Ymd", strtotime($sub_startdate));
	}
	$new_product['sub_startdate'] = $sub_startdate;

	//Calculate Subscription End
	$sub_enddate = get_post_meta($thepost->ID,'_sub_enddate', 1);
	if ($sub_enddate) {
		$last_char = strtolower(substr($sub_enddate, -1));
		if (!in_array($last_char, $foxycart_last_chars) && $sub_enddate != preg_replace("/[^0-9]/","", $sub_enddate)) $sub_enddate = date("Ymd", strtotime($sub_enddate));
	}
	$new_product['sub_enddate'] = $sub_enddate;

	//Convert Weight
	$original_weight = get_post_meta($thepost->ID,'_weight',1);
	$weight = explode(" ", $original_weight);
	if (count($weight) == 1) $weight = explode(" ", $foxyshop_settings['default_weight']);
	$weight1 = (int)$weight[0];
	$weight2 = (double)$weight[1];
	if ($weight1 == 0 && $weight2 == 0) {
		$defaultweight = explode(" ",$foxyshop_settings['default_weight']);
		$weight1 = (int)$defaultweight[0];
		$weight2 = (count($defaultweight) > 1 ? (double)$defaultweight[1] : 0);
	}
	if ($weight2 > 0) $weight2 = number_format($weight2 / ($foxyshop_settings['weight_type'] == 'metric' ? 1000 : 16), 3);
	$arr_weight2 = explode('.', $weight2);
	$weight2 = ((strpos($weight2, '.') !== false) ? end($arr_weight2) : $weight2);
	if ($original_weight) {
		$new_product['weight'] = $weight1 . "." . $weight2;
	} else {
			$new_product['weight'] = "";
	}

	//Variations
	$new_product['variations'] = get_post_meta($thepost->ID,'_variations',1);
	if (!is_array($new_product['variations'])) $new_product['variations'] = array();

	//Inventory
	$new_product['inventory_levels'] = get_post_meta($thepost->ID,'_inventory_levels',1);
	if (!is_array($new_product['inventory_levels'])) $new_product['inventory_levels'] = array();
	if (array_key_exists($new_product['code'], $new_product['inventory_levels'])) {
		if ($new_product['inventory_levels'][$new_product['code']]['count'] > $new_product['quantity_max']) $new_product['quantity_max'] = $new_product['inventory_levels'][$new_product['code']]['count'];
	}

	//Images
	$new_product['images'] = array();
	if (!$shortcut) {

		//Get Featured Image
		$imageNumber = 0;
		$featuredImageID = (has_post_thumbnail($thepost->ID) ? get_post_thumbnail_id($thepost->ID) : 0);

		//Get Attachments
		$attachments = get_posts(array('numberposts' => -1, 'post_type' => 'attachment','post_status' => null,'post_parent' => $thepost->ID, "post_mime_type" => "image", 'order' => 'ASC','orderby' => 'menu_order'));

		//Search For Featured Image in Attachments
		$featured_image_in_attachments = false;
		foreach ($attachments as $cur_value) {
			if ($cur_value->ID == $featuredImageID) {
				$featured_image_in_attachments = true;
			}
		}
		if ($featuredImageID && (!$attachments || !$featured_image_in_attachments)) {
			$attachments = array_merge($attachments, get_posts(array("p" => $featuredImageID, 'post_type' => 'attachment', "post_mime_type" => "image")));
		}
		$sizes = get_intermediate_image_sizes();
		$sizes[] = 'full';
		foreach ($attachments as $attachment) {
			$imageTitle = $attachment->post_title;
			$new_product['images'][$imageNumber] = array(
				"id" => $attachment->ID,
				"title" => $imageTitle,
				"hide_from_slideshow" => (get_post_meta($attachment->ID, "_foxyshop_hide_image", 1) ? 1 : 0)
			);
			foreach($sizes as $size) {
				$sizearray = wp_get_attachment_image_src($attachment->ID, $size);
				$new_product['images'][$imageNumber][$size] = $sizearray[0];
			}
			$new_product['images'][$imageNumber]["featured"] = $featuredImageID == $attachment->ID || ($featuredImageID == 0 && $imageNumber == 0) ? 1 : 0;
			$imageNumber++;
		}
	}

	//Sale Price
	$salestartdate = get_post_meta($thepost->ID,'_salestartdate',1);
	$saleenddate = get_post_meta($thepost->ID,'_saleenddate',1);
	if ($salestartdate == '999999999999999999') $salestartdate = 0;
	if ($saleenddate == '999999999999999999') $saleenddate = 0;
	if (get_post_meta($thepost->ID,'_saleprice', 1) > 0) {
		$beginningOK = (strtotime("now") > $salestartdate);
		$endingOK = (strtotime("now") < ($saleenddate + 86400) || $saleenddate == 0);
		if ($beginningOK && $endingOK || ($salestartdate == 0 && $saleenddate == 0)) {
			$new_product['price'] = number_format((double)get_post_meta($thepost->ID,'_saleprice', 1),FOXYSHOP_DECIMAL_PLACES,".","");
		} else {
			$new_product['price'] = number_format((double)get_post_meta($thepost->ID,'_price', 1),FOXYSHOP_DECIMAL_PLACES,".","");
		}
	} else {
		$new_product['price'] = number_format((double)get_post_meta($thepost->ID,'_price', 1),FOXYSHOP_DECIMAL_PLACES,".","");
	}

	//Price Filters
	$new_product['originalprice'] = apply_filters("foxyshop_price_adjustment", $new_product['originalprice']);
	$new_product['price'] = apply_filters("foxyshop_price_adjustment", $new_product['price']);

	//Extra Cart Parameters
	$fields = array('cart','empty','coupon','redirect','output','_cart','_empty','_coupon');
	foreach ($fields as $fieldname) {
		if (get_post_meta($thepost->ID, $fieldname, true)) {
			$new_product[str_replace("_", "", $fieldname)] = get_post_meta($thepost->ID,$fieldname, true);
		}
	}

	//Expires
	$expires = get_post_meta($thepost->ID, '_expires', true);
	if ($expires) {
		$new_product['expires'] = strpos($expires, "-") ? strtotime($expires) : $expires;
	}


	//Hook To Add Your Own Function to Update the $new_product array with your own data
	if (has_filter('foxyshop_setup_product_info')) $new_product = apply_filters('foxyshop_setup_product_info', $new_product, $thepost->ID);

	return $new_product;
}

//Starts the form
function foxyshop_start_form() {
	global $product, $foxyshop_settings, $foxyshop_skip_url_link, $foxyshop_skip_cart_image;
	$localsettings = localeconv();

	$currency_symbol = $localsettings['currency_symbol'];
	$l18n_value = $currency_symbol . '|' . $localsettings['mon_decimal_point'] . '|' . $localsettings['mon_thousands_sep'] . '|' . $localsettings['p_cs_precedes'] . '|' . $localsettings['n_sep_by_space'];
	if ($localsettings['n_sep_by_space'] == 127) $l18n_value = "$|.|,|1|0";

	echo "\n";
	echo '<form action="' . esc_url('https://' . $foxyshop_settings['domain']) . '/cart" method="post" accept-charset="utf-8" class="foxyshop_product" id="foxyshop_product_form_' . esc_attr($product['id']) . '" rel="' . esc_attr($product['id']) . '"' . apply_filters('foxyshop_form_attributes', '') . '>'."\n";
	echo '<input type="hidden" name="price' . esc_attr(foxyshop_get_verification('price')) . '" id="fs_price_' . esc_attr($product['id']) . '" value="' . esc_attr($product['price']) . '" />'."\n";
	echo '<input type="hidden" name="x:originalprice" value="' . esc_attr($product['originalprice']) . '" id="originalprice_' . esc_attr($product['id']) . '" />'."\n";
	echo '<input type="hidden" name="x:l18n" value="' . esc_attr(apply_filters("foxyshop_form_l18n", $l18n_value)) . '" id="foxyshop_l18n_' . esc_attr($product['id']) . '" />'."\n";
	if (version_compare($foxyshop_settings['version'], '0.7.0', ">")) {
		if (!isset($foxyshop_skip_cart_image) && foxyshop_get_main_image()) echo '<input type="hidden" name="image' . esc_attr(foxyshop_get_verification('image',"--OPEN--")) . '" value="' . esc_url(foxyshop_get_main_image(apply_filters('foxycart_product_image_size','thumbnail'))) . '" id="foxyshop_cart_product_image_' . esc_attr($product['id']) . '" />'."\n";
		if (!isset($foxyshop_skip_url_link)) echo '<input type="hidden" name="url' . esc_attr(foxyshop_get_verification('url')) . '" value="' . esc_url($product['url']) . '" id="fs_url_' . esc_attr($product['id']) . '" />'."\n";
	}

	echo '<input type="hidden" name="quantity_min' . esc_attr(foxyshop_get_verification('quantity_min', '--OPEN--')) . '" value="' . esc_attr($product['quantity_min']) . '" id="fs_quantity_min_' . esc_attr($product['id']) . '" />'."\n";
	echo '<input type="hidden" name="quantity_max' . esc_attr(foxyshop_get_verification('quantity_max', '--OPEN--')) . '" value="' . esc_attr($product['quantity_max']) . '" id="fs_quantity_max_' . esc_attr($product['id']) . '" />'."\n";
	echo '<input type="hidden" name="x:quantity_max" value="' . esc_attr($product['quantity_max_original']) . '" id="original_quantity_max_' . esc_attr($product['id']) . '" />'."\n";
	if (FOXYSHOP_DECIMAL_PLACES != 2) echo '<input type="hidden" name="x:foxyshop_decimal_places" value="' . esc_attr(pow(10, FOXYSHOP_DECIMAL_PLACES)) . '" id="foxyshop_decimal_places" />'."\n";

	//Expires
	if (isset($product['expires']) && $product['expires']) {
		echo '<input type="hidden" name="expires' . esc_attr(foxyshop_get_verification('expires')) . '" value="' . esc_attr($product['expires']) . '" id="fs_expires_' . esc_attr($product['id']) . '" />'."\n";
	}

	//Sub Frequency
	if (!$product["sub_frequency"]) {
		foreach ($product['variations'] as $variation) {
			if (strpos($variation['value'], "fr:") !== false) $product["sub_frequency"] = "-";
		}
	}
	if ($product["sub_frequency"]) echo '<input type="hidden" name="sub_frequency' . esc_attr(foxyshop_get_verification("sub_frequency", "--OPEN--")) . '" id="fs_sub_frequency_' . esc_attr($product['id']) . '" value="' . esc_attr(str_replace("-", "", $product["sub_frequency"])) . '" />'."\n";

	$fields = array('name','code','category','weight','discount_quantity_amount','discount_quantity_percentage','discount_price_amount','discount_price_percentage','sub_startdate','sub_enddate');
	$non_verification_fields = apply_filters('foxyshop_non_verification_fields', array('cart','empty','coupon','redirect','output'));
	foreach ($fields as $fieldname) {
		if (array_key_exists($fieldname, $product)) {
			if ($product[$fieldname]) echo '<input type="hidden" name="' . esc_attr($fieldname . foxyshop_get_verification($fieldname)) . '" id="fs_' . esc_attr($fieldname . '_' . $product['id']) . '" value="' . esc_attr(strip_tags($product[$fieldname])) . '" />'."\n";
		}
	}
	foreach ($non_verification_fields as $fieldname) {
		if (array_key_exists($fieldname, $product)) {
			if ($product[$fieldname]) echo '<input type="hidden" name="' . esc_attr($fieldname) . '" id="fs_' . esc_attr($fieldname . '_' . $product['id']) . '" value="' . esc_attr(strip_tags($product[$fieldname])) . '" />'."\n";
		}
	}


	//Bundled Products
	if ($product['bundled_products']) {
		global $bundled_product;
		$bundled_product = 1;
		$original_product = $product;
		$bundledproducts = get_posts(array('post_type' => 'foxyshop_product', "post__in" => explode(",",$product['bundled_products']), 'numberposts' => -1));
		$num = 2;
		foreach($bundledproducts as $bundledproduct) {
			$product = foxyshop_setup_product($bundledproduct);
			$fields = array('name','code','category','weight','discount_quantity_amount','discount_quantity_percentage','discount_price_amount','discount_price_percentage','sub_frequency','sub_startdate','sub_enddate');

			//For version 2.0+, add the bundled parent code
			if (version_compare($foxyshop_settings['version'], '2.0', ">=")) {
				$fields[] = 'parent_code';
				$fields[] = 'quantity_min';
				$product['parent_code'] = $original_product['code'];
				$product['quantity_min'] = 1;
			}

			//Apply the Filter
			$fields = apply_filters("bundled_product_fields", $fields, $product);
			if (defined('FOXYSHOP_BUNDLED_PRODUCT_FULL_PRICE')) {
				$fields[] = 'price';
			} else {
				echo '<input type="hidden" name="' . esc_attr($num . ':price' . foxyshop_get_verification('price','0.00')) . '" id="' . esc_attr($num . ':price_' . $product['id']) . '" value="0.00" />'."\n";
			}
			foreach ($fields as $fieldname) {
				if ($product[$fieldname] !== "") echo '<input type="hidden" name="' . esc_attr($num . ':' . $fieldname . foxyshop_get_verification($fieldname)) . '" id="' . esc_attr($num . ':' . $fieldname . '_' . $product['id']) . '" value="' . esc_attr(strip_tags($product[$fieldname])) . '" />'."\n";
			}
			if (foxyshop_get_main_image() && version_compare($foxyshop_settings['version'], '0.7.0', ">")) echo '<input type="hidden" name="' . esc_attr($num . ':image' . foxyshop_get_verification('image',"--OPEN--")) . '" id="' . esc_attr($num . ':image_' . $product['id']) . '" value="' . esc_url(foxyshop_get_main_image()) . '" />'."\n";
			if (version_compare($foxyshop_settings['version'], '0.7.0', ">") && !isset($foxyshop_skip_url_link)) echo '<input type="hidden" name="' . esc_attr($num . ':url' . foxyshop_get_verification('url')) . '" id="' . esc_attr($num . ':url_' . $product['id']) . '" value="' . esc_url($product['url']) . '" />'."\n";
			$num++;
		}
		$product = $original_product;
		$bundled_product = 0;
	}

}



//Writes Variations (showQuantity 0 = Not Shown, 1 = Above, 2 = Below)
function foxyshop_product_variations($showQuantity = 0, $showPriceVariations = true, $beforeVariation = "", $afterVariation = '<div class="clr"></div>') {
	global $post, $product, $foxyshop_settings, $foxyshop_write_variation_include;
	$writeUploadInclude = 0;
	$write = "";
	$var_type_array = array('dropdown' => __("Dropdown List"), 'radio' => __("Radio Buttons"), 'checkbox' => __("Checkbox"), 'text' => __("Single Line of Text"), 'textarea' => __("Multiple Lines of Text"), 'upload' => __("Custom File Upload"), 'hiddenfield' => __("Hidden Field", 'foxyshop'), 'descriptionfield' => __("Description Field"));

	//Show Quantity Before Variations
	if ($product['quantity_hide']) $showQuantity = 0;
	if ($showQuantity == 1) {
		$write .= foxyshop_get_shipto();
		$write .= foxyshop_quantity(apply_filters("foxyshop_default_quantity_value", 1), $beforeVariation, $afterVariation);
	}

	//Loop Through Variations
	$i = 1;
	foreach ($product['variations'] as $product_variation) {
		$variationName = $product_variation['name'];
		$variationDisplayName = $product_variation['name'];
		$variationType = $product_variation['type'];
		$variationValue = isset($product_variation['value']) ? $product_variation['value'] : '';
		$variationDisplayKey = isset($product_variation['displayKey']) ? $product_variation['displayKey'] : '';
		$variationRequired = isset($product_variation['required']) ? $product_variation['required'] : '';

		//This is a Saved Variation, Get the Data
		if (!array_key_exists($variationType, $var_type_array)) {
			$saved_variations = get_option('foxyshop_saved_variations');
			if (!is_array($saved_variations)) $saved_variations = array();
			$saved_var_found = 0;
			foreach($saved_variations as $saved_var) {
				// sanitize_title required for backwards compatibility
				if (sanitize_title($saved_var['refname']) == $variationType) {
					$saved_var_found = 1;
					//$variationName = $saved_var['name'];
					$variationType = $saved_var['type'];
					$variationValue = isset($saved_var['value']) ? $saved_var['value'] : '';
					$variationDisplayKey = isset($saved_var['displayKey']) ? $saved_var['displayKey'] : '';
					$variationRequired = isset($saved_var['required']) ? $saved_var['required'] : '';
				}
			}
			if (!$saved_var_found) continue;
		}

		$variationTextSize = "";
		if (!$variationName) break;
		if ($variationType == "text") {
			$arrVariationText = explode("|",$variationValue);
			$variationValue = "";
		}
		if ($variationDisplayKey) {
			$dkey = ' dkey="' . $variationDisplayKey . '"';
			$dkeyclass = " dkey";
		} else {
			$dkey = "";
			$dkeyclass = "";
		}

		//Set Variation Name and Display Name
		if (strpos($variationName,"{") !== false) {
			$variationDisplayName = substr($variationDisplayName,0,strpos($variationDisplayName,"{"));
			$variationName = substr($variationName, strpos($variationName,"{")+1, strpos($variationName,"}") - (strpos($variationName,"{")+1));
		}

		$className = "variation-" . sanitize_title_with_dashes($variationName);
		$writeBeforeVariation = $beforeVariation ? str_replace("%c", $className, $beforeVariation) . "\n" : "";
		$writeAfterVariation = $afterVariation ? $afterVariation . "\n" : "";
		if ($variationRequired) $className .= ' foxyshop_required';

		//Text
		if ($variationType == "text") {
			$write .= $writeBeforeVariation;
			$write .= '<label for="' . $product['code'] . '_' . $i . '" class="' . $className . $dkeyclass . '"'. $dkey . '>' . str_replace('_',' ',$variationDisplayName) . '</label>'."\n";
			$write .= '<input type="text" name="' . foxyshop_add_spaces($variationName) . foxyshop_get_verification(foxyshop_add_spaces($variationName),'--OPEN--') . '" id="' . $product['code'] . '_' . $i . '" value="" class="' . $className . $dkeyclass . '"';
			if ((int)$arrVariationText[0] > 0) $write .= ' style="width: ' . (int)$arrVariationText[0] * 6.5 . 'px;"';
			if ($variationDisplayKey) $write .= ' dkey="' . $variationDisplayKey . '"';
			if ($arrVariationText[1]) $write .= ' maxlength="' . $arrVariationText[1] . '"';
			$write .= ' />'."\n";
			$write .= $writeAfterVariation;

		//Textarea
		} elseif ($variationType == "textarea") {
			$write .= $writeBeforeVariation;
			$write .= '<label for="' . $product['code'] . '_' . $i . '" class="' . $className . $dkeyclass . '"'. $dkey . '>' . str_replace('_',' ',$variationDisplayName) . '</label>'."\n";
			$write .= '<textarea name="' . foxyshop_add_spaces($variationName) . foxyshop_get_verification(foxyshop_add_spaces($variationName),'--OPEN--') . '" id="' . $product['code'] . '_' . $i . '" class="foxyshop_freetext ' . $className . $dkeyclass . '" style="height: ' . 16 * (int)$variationValue . 'px;"' . $dkey . '></textarea>'."\n";
			$write .= $writeAfterVariation;

		//Upload
		} elseif ($variationType == "upload") {
			$write .= $writeBeforeVariation;
			include(foxyshop_get_template_file('foxyshop-custom-upload.php'));
			$write .= $writeAfterVariation;

		//Description Field
		} elseif ($variationType == "descriptionfield") {
			$variationValue = do_shortcode($variationValue);
			$write .= $writeBeforeVariation;
			$write .= '<div id="fs_title_' . $product['code'] . '_' . $i . '" class="foxyshop_descriptionfield_title ' . $className . $dkeyclass . '"'. $dkey . '>' . str_replace('_',' ',$variationDisplayName) . '</div>'."\n";
			$description_field = '<div id="fs_text_' . $product['code'] . '_' . $i . '" class="foxyshop_descriptionfield_text ' . $className . $dkeyclass . '"'. $dkey . '>' . $variationValue . '</div>'."\n";
			$write .= apply_filters('foxyshop_descriptionfield_variation', $description_field, $product['code'], $i, $className, $dkeyclass, $dkey, $variationValue);
			$write .= $writeAfterVariation;

		//Hidden Field
		} elseif ($variationType == "hiddenfield") {
			$write .= '<input type="hidden" name="' . foxyshop_add_spaces($variationName) . foxyshop_get_verification(foxyshop_add_spaces($variationName),$variationValue) . '" id="' . $product['code'] . '_' . $i . '" value="' . $variationValue . '" class="' . $className . $dkeyclass . '" />' . "\n";

		//Select, Checkbox, Radio
		} elseif ($variationType == "dropdown" || $variationType == "checkbox" || $variationType == "radio") {

			//Select
			if ($variationType == "dropdown") {
				$write .= $writeBeforeVariation;
				$write .= '<label for="' . $product['code'] . '_' . $i . '" class="' . $className . $dkeyclass . '"'. $dkey . '>' . str_replace('_',' ',$variationDisplayName) . '</label>'."\n";
				$write .= '<select name="' . foxyshop_add_spaces($variationName) . '" id="' . $product['code'] . '_' . $i . '" class="' . $className . $dkeyclass . '"' . $dkey . '>'."\n";
				$write .= foxyshop_run_variations($variationValue, $variationName, $showPriceVariations, $variationType, $dkey, $dkeyclass, $i, $className);
				$write .= "</select>\n";
				$write .= $writeAfterVariation;

			//Radio Buttons
			} elseif ($variationType == "radio") {
				$write .= $writeBeforeVariation;
				$write .= '<div role="radiogroup" aria-labelledby="' . $product['code'] . '_' . $i . '" class="foxyshop_radio_wrapper">';
				$write .= '<div id="' . $product['code'] . '_' . $i . '" class="foxyshop_radio_title' . $dkeyclass . '"'. $dkey . '>' . str_replace("_", " ", $variationDisplayName) . '</div>';
				$write .= foxyshop_run_variations($variationValue, $variationName, $showPriceVariations, $variationType, $dkey, $dkeyclass, $i, $className);
				$write .= '</div>';
				$write .= $writeAfterVariation;

			//Checkbox
			} elseif ($variationType == "checkbox") {
				$write .= $writeBeforeVariation;
				$write .= foxyshop_run_variations($variationValue, $variationName, $showPriceVariations, $variationType, $dkey, $dkeyclass, $i, $className);
				$write .= $writeAfterVariation;
			}
		}
		$i++;
	}
	//Show Quantity After Variations
	if ($showQuantity != 1) {
		$write .= foxyshop_get_shipto();
		if ($showQuantity == 2) $write .= foxyshop_quantity(apply_filters("foxyshop_default_quantity_value", 1), $beforeVariation, $afterVariation);
	}

	if ($write && !isset($foxyshop_write_variation_include)) {
		wp_enqueue_script('foxyshop_variation_process', FOXYSHOP_DIR . '/js/variation.process.js', ['jquery'], null, true);
		$foxyshop_write_variation_include = 1;
	}
	if ($write) {
		echo '<div class="foxyshop_variations">' . "\n" . foxy_wp_html($write) . "</div>\n\n";
	}
}


function foxyshop_run_variations($variationValue, $variationName, $showPriceVariations, $variationType, $dkey, $dkeyclass, $i, $className) {
	global $product, $foxyshop_settings;

	$write1 = "";
	$variations = preg_split("[\r\n|\r|\n]", $variationValue);
	$k = 0;
	foreach($variations as $val) {
		if ($val == '') continue;
		$option_attributes = "";
		$option_show_price_change = "";
		$displaykey = "";
		$imagekey = "";
		$alternate_value = "NO-VALUE";
		$sub_frequency = "NO-VALUE";
		$pricechange = "";
		$original_price_change = "";
		$displaypricechange = "";
		$priceset = "";
		$code = "";
		$codeadd = "";
		$price_change_multiplier = pow(10, FOXYSHOP_DECIMAL_PLACES);
		$val = apply_filters("foxyshop_variation_adjustment", trim($val));
		if (strpos($val,"*") !== false) {
			$val = str_replace("*","",$val);
			if ($variationType == "dropdown") {
				$option_attributes .= ' selected="selected"';
			} else {
				$option_attributes .= ' checked="checked"';
			}
		}
		if ($variationType == "radio" && $k == 0) $option_attributes .= ' checked="checked"';
		$variation_display_name = $val;
		if (strpos($val,"{") !== false) {
			$variation_display_name = substr($variation_display_name,0,strpos($variation_display_name,"{"));
			$variation_modifiers = substr($val, strpos($val,"{")+1, strpos($val,"}") - (strpos($val,"{")+1));

			$arr_variation_modifiers = explode("|",$variation_modifiers);
			foreach ($arr_variation_modifiers as $individual_modifier) {
				$individual_modifier = trim($individual_modifier);
				if (strtolower(substr($individual_modifier,0,4)) == "dkey") {
					$displaykey = substr($individual_modifier,5);
				} elseif (strtolower(substr($individual_modifier,0,2)) == "p:") {
					$priceset = substr($individual_modifier,2);
				} elseif (strtolower(substr($individual_modifier,0,1)) == "p") {
					$pricechange = substr($individual_modifier,1);
					if (strtolower(substr($individual_modifier,0,7)) == "price:x") $pricechange = substr($individual_modifier,7);
				} elseif (strtolower(substr($individual_modifier,0,2)) == "c:") {
					$code = substr($individual_modifier,2);
				} elseif (strtolower(substr($individual_modifier,0,2)) == "c+") {
					$codeadd = substr($individual_modifier,2);
				} elseif (strtolower(substr($individual_modifier,0,4)) == "ikey") {
					$imagekey = substr($individual_modifier,5);
				} elseif (strtolower(substr($individual_modifier,0,2)) == "v:") {
					$alternate_value = substr($individual_modifier,2);
				} elseif (strtolower(substr($individual_modifier,0,3)) == "fr:") {
					$sub_frequency = substr($individual_modifier,3);
				}
			}

			if ($pricechange != "") {
				$original_price_change = $pricechange;
				if (substr($pricechange,0,1) == '-') {
					$displaypricechange = foxyshop_currency($pricechange);
					$pricechange = $pricechange * $price_change_multiplier;
				} else {
					$displaypricechange = "+" . foxyshop_currency($pricechange);
					$pricechange = "+" . ($pricechange * $price_change_multiplier);
				}
			} elseif ($priceset != "") {
				$displaypricechange = foxyshop_currency($priceset);
				$priceset = $priceset * $price_change_multiplier;
				$test_variation_name = $variation_display_name;
				if ($test_variation_name == $displaypricechange || $test_variation_name.'.00' == $displaypricechange) $displaypricechange = "";
			}
			if ($showPriceVariations && $displaypricechange) $option_show_price_change = apply_filters('foxyshop_variation_price_change', ' (' . $displaypricechange . ')');

			//Check for sub_startdate and sub_enddate as alternate values
			if ($variationName == "sub_startdate" || $variationName == "sub_enddate") {
				if ($alternate_value != preg_replace("/[^0-9]/","", $alternate_value)) $alternate_value = date("Ymd", strtotime($alternate_value));
			}

			if ($alternate_value != "NO-VALUE") {
				$val = $alternate_value;
				if ($original_price_change != "") {
					$val .= '{p' . $original_price_change . '}';
				}
			}
			if ($priceset !== "") $option_attributes .= ' priceset="' . $priceset . '"';
			if ($pricechange !== "") $option_attributes .= ' pricechange="' . $pricechange . '"';
			if ($displaykey !== "") $option_attributes .= ' displaykey="' . $displaykey . '"';
			if ($imagekey !== "") $option_attributes .= ' imagekey="' . $imagekey . '"';
			if ($code !== "") $option_attributes .= ' code="' . $code . '"';
			if ($codeadd !== "") $option_attributes .= ' codeadd="' . $codeadd . '"';
			if ($sub_frequency != "NO-VALUE") $option_attributes .= ' subfrequency="' . $sub_frequency . '"';
		}


		//Write the Line
		$write1 .= apply_filters('foxyshop_before_variation_' . $variationType, '');
		$after_code = '';
		if ($variationType == "dropdown") {
			$write1 .= '<option value="' . $val . ($val != '' ? foxyshop_get_verification(foxyshop_add_spaces($variationName),$val) : '') . '"' . $option_attributes;
			$write1 .= '>' . $variation_display_name . $option_show_price_change . '</option>'."\n";
		} elseif ($variationType == "checkbox") {
			$write1 .= '<div class="foxyshop_short_element_holder"><input type="checkbox" name="' . foxyshop_add_spaces($variationName) . '" value="' . $val . foxyshop_get_verification(foxyshop_add_spaces($variationName),$val) . '" id="' . $product['code'] . '_' . $i . '" class="' . $className . $dkeyclass . '"' . $dkey . $option_attributes . '></div>'."\n";
			$write1 .= '<label for="' . $product['code'] . '_' . $i . '" class="' . $className . $dkeyclass . ' foxyshop_no_width foxyshop_radio_margin"'. $dkey . '>' . $variation_display_name . $option_show_price_change . '</label>'."\n";

		} elseif ($variationType == "radio") {
			$write1 .= '<div class="foxyshop_short_element_holder"><input type="radio" name="' . foxyshop_add_spaces($variationName) . '" value="' . $val . ($val != '' ? foxyshop_get_verification(foxyshop_add_spaces($variationName),$val) : '') . '" id="' . $product['code'] . '_' . $i . '_' . $k . '" class="' . $className . $dkeyclass . '"' . $dkey . $option_attributes . '></div>'."\n";
			$write1 .= '<label for="' . $product['code'] . '_' . $i . '_' . $k . '" class="' . $className . $dkeyclass . ' foxyshop_no_width"'. $dkey . '>' . $variation_display_name . $option_show_price_change . '</label>'."\n";
			$after_code = '<div class="clr"></div>';
		}
		$write1 .= apply_filters('foxyshop_after_variation_' . $variationType, $after_code);
		$k++;
	}
	return $write1;
}


function foxyshop_add_spaces($str) {
	return str_replace(" ", "_", $str);
}



//Writes the Ship To Box
function foxyshop_get_shipto() {
	global $foxyshop_settings;
	if ($foxyshop_settings['enable_ship_to'] == "on") {
		foxyshop_insert_multship_js();
		$write = '<div class="shipto_container">'."\n";
		$write .= '<div class="shipto_select" style="display:none">'."\n";
		$write .= '<label>' . apply_filters('foxyshop_shipname_to', 'Ship this item to') . '</label>'."\n";
		$write .= '<select name="x:shipto_name_select">'."\n";
		$write .= '</select>'."\n";
		$write .= '</div>'."\n";
		$write .= '<div class="shipto_name" style="display: none;">'."\n";
		$write .= '<label>' . apply_filters('foxyshop_recipient_name', 'Recipient Name') . '</label>'."\n";
		$write .= '<input type="text" name="shipto' . foxyshop_get_verification("shipto",'--OPEN--') . '" class="shiptoname" value="" />'."\n";
		$write .= '</div>'."\n";
		$write .= '<div class="clr"></div>'."\n";
		$write .= '</div>'."\n";
		return $write;
	}
	return "";
}

function foxyshop_insert_multship_js() {
	global $foxyshop_settings;
	$v2 = version_compare($foxyshop_settings['version'], '2.0', "<") ? "" : "2";
	wp_enqueue_script( 'foxyshop_multiship', FOXYSHOP_DIR . '/js/multiship' . $v2 . '.js', ['jquery'], null, true );
}



//Writes the Quantity Box
function foxyshop_quantity($qty = 1, $beforeVariation = "", $afterVariation = '<div class="clr"></div>', $numberPrefix = "") {
	global $product;

	if ($beforeVariation) $writeBeforeVariation = str_replace("%c", "foxyshop-quantity-holder", $beforeVariation) . "\n";
	if ($afterVariation) $writeAfterVariation = $afterVariation . "\n";

	$quantity_title = apply_filters("foxyshop_quantity_title", "Quantity");

	$write = "";
	if (isset($writeBeforeVariation)) $write .= $writeBeforeVariation;
	if ($product['quantity_min'] > 0) $qty = $product['quantity_min'];
	$write .= '<label class="foxyshop_quantity" for="quantity_' . $product['id'] . '">' . $quantity_title . '</label>'."\n";
	if ($product['quantity_max_original'] > 0) {
		if ($numberPrefix) {
			$write .= '<select class="foxyshop_quantity foxyshop_addon_fields" originalname="quantity" name="x:quantity" rel="' . $numberPrefix . '" id="quantity_' . $product['id'] . '">';
		} else {
			$write .= '<select class="foxyshop_quantity" name="quantity" id="quantity_' . $product['id'] . '">';
		}
		for ($i=($product['quantity_min'] > 0 ? $product['quantity_min'] : 1); $i <= $product['quantity_max_original']; $i++) {
			$write .= '<option value="' . $i . foxyshop_get_verification('quantity',$i) . '">' . $i . '</option>'."\n";
		}
		$write .= '</select>'."\n";
	} else {
		if ($numberPrefix) {
			$write .= '<input type="text" name="x:quantity' . foxyshop_get_verification('quantity','--OPEN--') . '" originalname="quantity' . foxyshop_get_verification('quantity','--OPEN--') . '" rel="' . $numberPrefix . '" id="quantity_' . $product['id'] . '" value="' . $qty . '" class="foxyshop_quantity foxyshop_addon_fields" />'."\n";
		} else {
			$write .= '<input type="text" name="quantity' . foxyshop_get_verification('quantity','--OPEN--') . '" id="quantity_' . $product['id'] . '" value="' . $qty . '" class="foxyshop_quantity" />'."\n";
		}
	}
	if (isset($writeAfterVariation)) $write .= $writeAfterVariation;
	return $write;
}



//Writes a Straight Text Link
function foxyshop_product_link($AddText = "Add To Cart", $linkOnly = false, $variations = "", $quantity = "") {
	global $product, $foxyshop_settings, $foxyshop_skip_url_link;

	$url = 'price' . foxyshop_get_verification('price') . '=' . urlencode($product['price']);
	if (foxyshop_get_main_image() && version_compare($foxyshop_settings['version'], '0.7.0', ">")) $url .= '&amp;image' . foxyshop_get_verification('image',"--OPEN--") . '=' . urlencode(foxyshop_get_main_image());
	if ($quantity) $url .= '&amp;quantity' . foxyshop_get_verification('quantity', $quantity) . '=' . $quantity;
	if (version_compare($foxyshop_settings['version'], '0.7.0', ">") && !isset($foxyshop_skip_url_link)) $url .= '&amp;url' . foxyshop_get_verification('url') . '=' . urlencode($product['url']);
	$fields = array('name','code','category','weight','quantity_min','quantity_max','discount_quantity_amount','discount_quantity_percentage','discount_price_amount','discount_price_percentage','sub_frequency','sub_startdate','sub_enddate');
	$non_verification_fields = apply_filters('foxyshop_non_verification_fields', array('cart','empty','coupon','redirect','output'));
	foreach ($fields as $fieldname) {
		if (array_key_exists($fieldname, $product)) {
			if ($product[$fieldname]) $url .= '&amp;' . urlencode($fieldname) . foxyshop_get_verification($fieldname) . '=' . urlencode(strip_tags($product[$fieldname]));
		}
	}
	foreach ($non_verification_fields as $fieldname) {
		if (array_key_exists($fieldname, $product)) {
			if ($product[$fieldname]) $url .= '&amp;' . urlencode($fieldname) . '=' . urlencode(strip_tags($product[$fieldname]));
		}
	}

	//Build Variations
	if ($variations != "") {
		$variation_args = wp_parse_args(html_entity_decode($variations));
		foreach($variation_args as $key => $val) {
			$url .= '&amp;' . urlencode($key) . foxyshop_get_verification($key, $val) . '=' . urlencode($val);
		}
	}


	//Bundled Products
	if ($product['bundled_products']) {
		global $bundled_product;
		$bundled_product = 1;
		$original_product = $product;
		$bundledproducts = get_posts(array('post_type' => 'foxyshop_product', "post__in" => explode(",",$product['bundled_products']), 'numberposts' => -1));
		$num = 2;
		foreach($bundledproducts as $bundledproduct) {
			$product = foxyshop_setup_product($bundledproduct);
			$fields = array('name','code','category','weight','discount_quantity_amount','discount_quantity_percentage','discount_price_amount','discount_price_percentage','sub_frequency','sub_startdate','sub_enddate');

			//For version 2.0+, add the bundled parent code
			if (version_compare($foxyshop_settings['version'], '2.0', ">=")) {
				$fields[] = 'parent_code';
				$fields[] = 'quantity_min';
				$product['parent_code'] = $original_product['code'];
				$product['quantity_min'] = 1;
			}

			$fields = apply_filters("bundled_product_fields", $fields, $product);
			if (defined('FOXYSHOP_BUNDLED_PRODUCT_FULL_PRICE')) {
				$fields[] = "price";
			} else {
				$url .= '&amp;' . urlencode($num . ':') . 'price' . foxyshop_get_verification('price', '0.00') . '=' . urlencode('0.00');
			}
			foreach ($fields as $fieldname) {
				if (array_key_exists($fieldname, $product)) {
					if ($product[$fieldname]) $url .= '&amp;'. urlencode($num . ':' . $fieldname) . foxyshop_get_verification($fieldname) . '=' . urlencode($product[$fieldname]);
				}
			}
			if (foxyshop_get_main_image() && version_compare($foxyshop_settings['version'], '0.7.0', ">")) $url .= '&amp;' . $num . urlencode(':image') . foxyshop_get_verification('image',"--OPEN--") . '=' . urlencode(foxyshop_get_main_image());
			if (version_compare($foxyshop_settings['version'], '0.7.0', ">")) $url .= '&amp;' . $num . ':url' . foxyshop_get_verification('url') . '=' . urlencode($product['url']);
			$num++;
		}
		$product = $original_product;
		$bundled_product = 1;
	}

	if ($linkOnly) {
		return 'https://' . $foxyshop_settings['domain'] . '/cart?' . $url;
	} else {
		echo '<a href="' . esc_url('https://' . $foxyshop_settings['domain'] . '/cart?' . $url) . '" class="foxyshop_button">' . esc_html(str_replace('%name%',$product['name'],$AddText)) . '</a>';
	}
}


//Set Social Media Meta Tags In Header (Facebook, Google+)
//Google+ suggests you to put this in your <html> tag on the product pages: <html itemscope itemtype="https://schema.org/Product">
function foxyshop_social_media_header_meta() {
	global $product;
	$product = foxyshop_setup_product();
	echo '<meta property="og:title" content="' . esc_attr($product['name']) . '" />'."\n";
	echo '<meta property="og:type" content="product" />'."\n";
	echo '<meta property="og:url" content="' . esc_url($product['url']) . '" />'."\n";
	echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) , '" />'."\n";
	if (foxyshop_get_main_image()) {
		echo '<meta property="og:image" content="' . esc_url(foxyshop_get_main_image()) . '" />'."\n";
		echo '<link rel="image_src" href="' . esc_url(foxyshop_get_main_image()) . '" />'."\n";
		echo '<meta itemprop="image" content="' . esc_url(foxyshop_get_main_image()) . '" />'."\n";
	}
	echo '<meta itemprop="name" content="' . esc_attr($product['name']) . '" />'."\n";
	if ($product['short_description']) echo '<meta itemprop="description" content="' . esc_attr($product['short_description']) . '" />'."\n";

	//Add Filter
	echo foxy_wp_html(apply_filters('foxyshop_social_media_header', ""));
}

//Writes the Price with Sale Information
function foxyshop_price($skip_sale_price = false, $echo_output = true) {
	global $product;
	$write = '<div class="foxyshop_price">';
	if ($product['price'] == $product['originalprice']) {
		$write .= '<span class="foxyshop_currentprice">' . foxyshop_currency($product['price']) . '</span>';
	} else {
		if (!$skip_sale_price) $write .= '<span class="foxyshop_oldprice">' . foxyshop_currency($product['originalprice']) . '</span>';
		$write .= '<span class="foxyshop_currentprice foxyshop_saleprice">' . foxyshop_currency($product['price']) . '</span>';
	}
	$write .= '</div>';
	if ($echo_output) {
		echo foxy_wp_html($write);
	} else {
		return $write;
	}
}


//Returns Sale Status (true or false)
function foxyshop_is_on_sale() {
	global $product;
	return ($product['price'] != $product['originalprice']);
}


//Returns Product NEW Status (true or false)
function foxyshop_is_product_new($number_of_days = 14) {
	global $product;
	return ($product['post_date'] >= strtotime("-$number_of_days days"));
}


//Returns URL for main product image (or other info)
function foxyshop_get_main_image($size = "thumbnail") {
	global $product, $foxyshop_settings;
	$image = "";
	if (!$size) $size = "thumbnail";
	if (!is_array($product['images'])) return "";
	foreach ($product['images'] as $imageArray) {
		if ($imageArray['featured']) {
			$image = $imageArray[$size];
		}
	}
	if (!$image && count($product['images']) > 0) $image = $product['images'][0][$size];
	if (!$image) $image = $foxyshop_settings['default_image'];
	if (!$image) $image = FOXYSHOP_PLUGIN_URL."images/no-photo.png";
	if ($image == "none") $image = "";
	return $image;
}


function foxyshop_build_image_slideshow($slideshow_type = "prettyPhoto", $use_includes = true) {
	global $product, $foxyshop_slideshow_includes_set;

	if (!foxyshop_get_main_image()) return;

	$deprecated_slideshow_types = ["magnific"];

	if ($slideshow_type == "luminous" || in_array($slideshow_type, $deprecated_slideshow_types)) {
		if ($use_includes && !isset($foxyshop_slideshow_includes_set)) {
			wp_enqueue_script( 'foxyshop_slideshow', FOXYSHOP_DIR . '/js/luminous.min.js', [], FOXYSHOP_VERSION, true );
			wp_enqueue_style( 'foxyshop_slideshow', FOXYSHOP_DIR . '/css/luminous.css', [], FOXYSHOP_VERSION);
			wp_add_inline_script( 'foxyshop_slideshow', "
jQuery(document).ready(function($) {
	const luminous = new LuminousGallery(document.querySelectorAll(\"a[rel^='foxyshop_gallery']\"), {}, {
         caption: function(trigger) {
           return trigger.querySelector('img').getAttribute('alt');
         }
	});
});
			");
			$foxyshop_slideshow_includes_set = 1;
		}


		$imagecount = count($product['images']);
		$use_link = (foxyshop_get_main_image("medium") != foxyshop_get_main_image("full") || $imagecount > 1 ? 1 : 0);

		echo '<div class="foxyshop_product_image">'."\n";
		echo '<div class="foxyshop_product_image_holder">'."\n";

		if ($use_link) echo '<a href="' . esc_url(foxyshop_get_main_image('large')) . '" rel="foxyshop_gallery' . esc_attr($imagecount > 1 ? '[' . $product['id'] . ']' : '') . '"  title="' . esc_attr(apply_filters('foxyshop_image_link_title', '')) . '">';
		echo '<img src="' . esc_url(foxyshop_get_main_image('medium')) . '" class="foxyshop_main_product_image" alt="' . esc_attr(foxyshop_get_main_image('title')) . '" title="" />';
		if ($use_link) echo "</a>\n";

		echo "</div>\n";
		foxyshop_image_slideshow("thumbnail", false, "Click Below For More Images:", "foxyshop_gallery[" . $product['id'] . "]");
		echo "</div>\n";

	//PrettyPhoto (Lightbox)
	} elseif ($slideshow_type == "prettyPhoto") {

		if ($use_includes && !isset($foxyshop_slideshow_includes_set)) {
			wp_enqueue_script( 'foxyshop_slideshow', FOXYSHOP_DIR . '/js/prettyphoto/prettyPhoto.js', ['jquery'], FOXYSHOP_VERSION, true );
			wp_enqueue_style( 'foxyshop_slideshow', FOXYSHOP_DIR . '/js/prettyphoto/prettyPhoto.css', [], FOXYSHOP_VERSION);
			wp_add_inline_script( 'foxyshop_slideshow', "
jQuery(document).ready(function($) {
	$(\"a[rel^='foxyshop_gallery']\").prettyPhoto({
		theme: 'light_square',
		overlay_gallery: false,
		slideshow: 3000,
		social_tools: ''
	});
});
			");
			$foxyshop_slideshow_includes_set = 1;
		}


		$imagecount = count($product['images']);
		$use_link = (foxyshop_get_main_image("medium") != foxyshop_get_main_image("full") || $imagecount > 1 ? 1 : 0);

		echo '<div class="foxyshop_product_image">'."\n";
		echo '<div class="foxyshop_product_image_holder">'."\n";

		if ($use_link) echo '<a href="' . esc_url(foxyshop_get_main_image('large')) . '" rel="foxyshop_gallery' . esc_attr($imagecount > 1 ? '[' . $product['id'] . ']' : '') . '"  title="' . esc_attr(apply_filters('foxyshop_image_link_title', '')) . '">';
		echo '<img src="' . esc_url(foxyshop_get_main_image('medium')) . '" class="foxyshop_main_product_image" alt="' . esc_attr(foxyshop_get_main_image('title')) . '" title="" />';
		if ($use_link) echo "</a>\n";

		echo "</div>\n";
		foxyshop_image_slideshow("thumbnail", false, "Click Below For More Images:", "foxyshop_gallery[" . $product['id'] . "]");
		echo "</div>\n";



	//ColorBox (Lightbox)
	} elseif ($slideshow_type == "colorbox") {

		if ($use_includes && !isset($foxyshop_slideshow_includes_set)) {
			wp_add_inline_script( 'foxy_colorbox', "
jQuery(document).ready(function($) {
	if ($().colorbox) {
		$(\"a[rel^='foxyshop_gallery'\").colorbox({sldeshow: true, maxHeight: \"80%\"});
	}
});
			");
			$foxyshop_slideshow_includes_set = 1;
		}


		$imagecount = count($product['images']);
		$use_link = (foxyshop_get_main_image("medium") != foxyshop_get_main_image("full") || $imagecount > 1 ? 1 : 0);

		echo '<div class="foxyshop_product_image">'."\n";
		echo '<div class="foxyshop_product_image_holder">'."\n";

		if ($use_link) echo '<a href="' . esc_url(foxyshop_get_main_image('large')) . '" rel="foxyshop_gallery' . esc_attr($imagecount > 1 ? '[' . $product['id'] . ']' : '') . '"  title="' . esc_attr(apply_filters('foxyshop_image_link_title', '')) . '">';
		echo '<img src="' . esc_url(foxyshop_get_main_image('medium')) . '" class="foxyshop_main_product_image" alt="' . esc_attr(foxyshop_get_main_image('title')) . '" title="" />';
		if ($use_link) echo "</a>\n";

		echo "</div>\n";
		foxyshop_image_slideshow("thumbnail", false, "Click Below For More Images:", "foxyshop_gallery[" . $product['id'] . "]");
		echo "</div>\n";



	//Cloudzoom (inline zooming)
	} elseif ($slideshow_type == "cloud-zoom") {

		if ($use_includes && !isset($foxyshop_slideshow_includes_set)) {
			wp_enqueue_script( 'foxyshop_slideshow', FOXYSHOP_DIR . '/js/cloud-zoom.1.0.2.js', ['jquery'], FOXYSHOP_VERSION, true );
			wp_enqueue_style( 'foxyshop_slideshow', FOXYSHOP_DIR . '/css/cloud-zoom.css', [], FOXYSHOP_VERSION);
			wp_add_inline_script( 'foxyshop_slideshow', "
function foxyshop_cloudzoom_image_change(new_ikey) {
	jQuery('#foxyshop_slideshow_thumb_' + ikey[new_ikey][0]).trigger('click');
}
			");
			$foxyshop_slideshow_includes_set = 1;
		}

		$imagecount = count($product['images']);
		$use_link = (foxyshop_get_main_image("medium") != foxyshop_get_main_image("full") || $imagecount > 1 ? 1 : 0);
		echo '<div class="foxyshop_product_image">'."\n";
		echo '<div class="foxyshop_product_image_holder">'."\n";

		if ($use_link) echo '<a href="' . esc_url(foxyshop_get_main_image("full")) . '" id="foxyshop_main_product_image_link_' . esc_attr($product['id']) . '" class="cloud-zoom" rel="adjustX: 10, adjustY:-4"  title="' . esc_attr(apply_filters('foxyshop_image_link_title', '')) . '">';
		echo '<img src="' . esc_url(foxyshop_get_main_image("medium")) . '" class="foxyshop_main_product_image" alt="' . esc_attr(foxyshop_get_main_image("title")) . '" title="" />';
		if ($use_link) echo "</a>\n";

		echo "</div>\n";

		if ($imagecount > 1) foxyshop_image_slideshow("thumbnail", true, "Click Below For More Images:", "useZoom: 'foxyshop_main_product_image_link_" . $product['id'] . "', smallImage: '%medium'", "cloud-zoom-gallery");

		echo "</div>\n";

	}
}


//Writes Image Slideshow (if available)
//Arguments Accepted: Small Size, Include Featured, Title Text, and Rel (can use %small and %large)
function foxyshop_image_slideshow($size = "thumbnail", $includeFeatured = true, $titleText = "Click Below For More Images:", $rel = "foxyshop_gallery[fs_gall]", $linkclass = "") {
	global $product;
	$write = "";
	$ikey = [];
	$useikey = 0;

	//Check for imagekey usage
	foreach ($product['variations'] as $product_variation) {
		if (strrpos($product_variation['value'],"ikey:") > 0) $useikey = 1;
	}
	if ($useikey) $includeFeatured = true;
	$largesize = $linkclass == "cloud-zoom-gallery" ? "full" : "large";
	$largesize = apply_filters('foxyshop_gallery_image_large_size', $largesize);
	$mediumsize = apply_filters('foxyshop_gallery_image_medium_size', "medium");
	foreach ($product['images'] as $imageArray) {
		if ($useikey) {
			$ikey[] = [
				$imageArray['id'],
				$imageArray['thumbnail'],
				$imageArray[$mediumsize],
				$imageArray[$largesize],
				str_replace("'","\'",$imageArray['title']),
				foxyshop_get_verification('image',
				$imageArray['thumbnail'])
			];
		}
		if ((!$imageArray['featured'] || $includeFeatured)) {
			$image_link_title = "";
			if (apply_filters('foxyshop_gallery_image_link_title', false)) $image_link_title = $imageArray['title'];
			$current_rel = $rel;
			$current_rel = str_replace("%thumbnail", $imageArray['thumbnail'], $current_rel);
			$current_rel = str_replace("%small", $imageArray['thumbnail'], $current_rel);
			$current_rel = str_replace("%medium", $imageArray[$mediumsize], $current_rel);
			$current_rel = str_replace("%large", $imageArray[$largesize], $current_rel);
			$write .= '<li' . ($imageArray['hide_from_slideshow'] ? ' style="display: none;"' : '') . '><a href="' . $imageArray[$largesize] . '" id="foxyshop_slideshow_thumb_' . $imageArray['id'] . '"' . ($linkclass ? ' class="' . $linkclass . '"' : '') . ' rel="' . $current_rel . '" title="' . $image_link_title . '"><img src="' . $imageArray[$size] . '" alt="' . $imageArray['title'] . '" /></a></li>'."\n";
		}
	}
	if ($write && (count($product['images']) != 1 || $includeFeatured)) {
		echo foxy_wp_html(apply_filters('foxyshop_slideshow_before', ""));
		$titleText = apply_filters('foxyshop_slideshow_title', $titleText, $product['id']);
		if ($titleText) echo '<div class="foxyshop_slideshow_title">' . foxy_wp_html($titleText) . '</div>';
		echo '<ul class="foxyshop_slideshow">' . foxy_wp_html($write) . '</ul>'."\n";
		echo apply_filters('foxyshop_slideshow_after', "<div class=\"clr\"></div>\n");
		if ($ikey) {
			wp_add_inline_script( 'foxyshop_slideshow', "var ikey = " . wp_json_encode($ikey) . ";");
		}
	}
}


//Writes the Children Categories of a Category (if available)
function foxyshop_category_children($categoryID = 0, $showCount = false, $showDescription = true, $categoryImageSize = "thumbnail") {
	global $taxonomy_images_plugin;
	$write = "";

	$args = array('hide_empty' => 0, 'hierarchical' => 0, 'parent' => $categoryID, 'orderby' => 'name', 'order' => 'ASC');
	$termchildren = get_terms('foxyshop_categories', apply_filters('foxyshop_category_children_query',$args));
	if ($termchildren) {

		//Sort Categories
		$skip_category_sort = apply_filters('foxyshop_categories_skip_sort', 0);
		if (!$skip_category_sort) {
			$termchildren = foxyshop_sort_categories($termchildren, $categoryID);
		}

		foreach ($termchildren as $child) {
			$term = get_term_by('id', $child->term_id, "foxyshop_categories");
			if (substr($term->name,0,1) == "_") continue;

			$productCount = ($showCount ? " (" . $term->count . ")" : "");
			$url = get_term_link($term, "foxyshop_categories");
			$liwrite = "";
			$liwrite .= '<li id="foxyshop_category_' . $term->term_id . '">';
			$liwrite .= '<h2><a href="' . $url . '">' . $term->name . '</a>' . $productCount . '</h2>';
			if ($showDescription && $term->description) $liwrite .= apply_filters('the_content', $term->description);
			if (isset($taxonomy_images_plugin)) {
				$img = $taxonomy_images_plugin->get_image_html($categoryImageSize, $term->term_taxonomy_id);
				if(!empty($img)) $liwrite .= '<a href="' . $url . '" class="foxyshop_category_image">' . $img . '</a>';
			}
			$liwrite .= '</li>'."\n";
			$write .= apply_filters('foxyshop_category_children_write', $liwrite, $term);
		}
		if ($write) echo '<ul class="foxyshop_categories">' . foxy_wp_html($write) . '</ul>' . foxy_wp_html(apply_filters('foxyshop_after_category_display', '<div class="clr"></div>'));
	}
}


//Writes a Simple List of Children Categories of a Category (if available)
function foxyshop_simple_category_children($category_id = 0, $depth = 1) {
	global $foxyshop_category_write;
	global $foxyshop_category_depth;
	if ($depth <= 0) $depth = 100;
	$foxyshop_category_depth = $depth;
	$foxyshop_category_write = "";
	foxyshop_category_writer($category_id, 1);
	if ($foxyshop_category_write) echo foxy_wp_html($foxyshop_category_write);
}

function foxyshop_category_writer($category_id, $depth) {
	global $foxyshop_category_write;
	global $foxyshop_category_depth;
	$args = array(
		'hide_empty' => 0,
		'hierarchical' => 0,
		'parent' => $category_id,
		'orderby' => 'name',
		'order' => "ASC",
	);
	$termchildren = get_terms('foxyshop_categories', apply_filters('foxyshop_categories_get_terms', $args));
	if ($termchildren) {


		//Get Category Array For Product
		if (get_post_type() == "foxyshop_product") {
			global $post;
			$current_term_id = wp_get_post_terms($post->ID, 'foxyshop_categories', array("fields" => "ids"));

		//Get Current Category
		} else {
			$current_term_obj = get_term_by('slug', get_query_var('term'), "foxyshop_categories");
			$current_term_id = $current_term_obj ? $current_term_obj->term_id : '';
			$current_term_id = array($current_term_id);
		}


		$skip_category_sort = apply_filters('foxyshop_category_writer_skip_sort', 0);
		if (!$skip_category_sort) {
			$termchildren = foxyshop_sort_categories($termchildren, $category_id);
		}
		$termchildren = apply_filters("foxyshop_simple_category_custom_sort", $termchildren);
		if ($depth > 1) $foxyshop_category_write .= '<ul class="children">';
		foreach ($termchildren as $child) {
			$term = get_term_by('id', $child->term_id, "foxyshop_categories");
			if (substr($term->name,0,1) == "_" || apply_filters('foxyshop_category_writer_skip', 0, $term)) continue;
			$url = get_term_link($term, "foxyshop_categories");
			$foxyshop_category_write .= '<li class="cat-item cat-item-' . $term->term_id . (in_array($term->term_id, $current_term_id) ? ' foxyshop-active-category' : '') . '">';
			$foxyshop_category_write .= '<a href="' . $url . '">' . $term->name . '</a>';

			if ($depth < $foxyshop_category_depth) {
				$new_depth = $depth + 1;
				foxyshop_category_writer($child->term_id, $new_depth);
			}

			$foxyshop_category_write .= "</li>\n";
		}
		if ($depth > 1) $foxyshop_category_write .= '</ul>';
	}
}



//Generates Verification Code for HMAC Anti-Tampering
function foxyshop_get_verification($name, $value = "") {
	global $product, $foxyshop_settings;
	if (!$foxyshop_settings['use_cart_validation']) return "";
	$open_text = $value === "--OPEN--" ? "||open" : "";
	$product_code = array_key_exists('parent_code', $product) ? $product['code'] . $product['parent_code'] : $product['code'];
	if ($value === "") $value = strip_tags($product[$name]);
	$encodingval = htmlspecialchars($product_code . $name . $value);
	return '||' . hash_hmac('sha256', $encodingval, $foxyshop_settings['api_key']) . $open_text;
}



//Writes Breadcrumbs For Products and Categories
//If there are no categories, $product_fallback indicates whether a link back to the product list should be shown.
//If it is entered, the link text is shown. If the string is blank, the fallback breadcrumb bar will not be shown.
function foxyshop_breadcrumbs($sep = " &raquo; ", $product_fallback = "&laquo; Back to Products", $base_name = "Products") {
	global $post, $product;
	$this_term_id = 0;

	//Category Page
	if (get_query_var('taxonomy') == "foxyshop_categories") {
		$term = get_term_by('slug', get_query_var('term'), get_query_var('taxonomy'));
		if (!$term) return;
		$breadcrumbarray[] = $term->term_id;
		$this_term_id = $term->term_id;
		$tempterm = $term;

	//Product Page
	} elseif ($post->ID) {
		$term = wp_get_post_terms($post->ID, 'foxyshop_categories');
		$matchedTerm = 0;

		//If in multiple categories, check referrer to see which one we should pick, otherwise first one found
		$referrer_category = (array_key_exists('HTTP_REFERER', $_SERVER) ? $referrer_category = basename($_SERVER['HTTP_REFERER']) : "");
		foreach ($term as $tempterm1) {
			if ($tempterm1->slug == $referrer_category) {
				$matchedTerm = $tempterm1->term_id;
				$tempterm = $tempterm1;
			}
		}
		if ($term) {
			if ($matchedTerm == 0) {
				$matchedTerm = $term[0]->term_id;
				$tempterm = $term[0];
			}
			$breadcrumbarray[] = $matchedTerm;
			$this_term_id = $tempterm->term_id;
		}
	}

	//Do The Write
	if ($this_term_id > 0) {

		$breadcrumbarray = array_merge($breadcrumbarray,get_ancestors($tempterm->term_id, 'foxyshop_categories'));
		$breadcrumbarray = array_reverse($breadcrumbarray);

		$write1 = '<li class="foxyshop_breadcrumb_base"><a href="' . apply_filters('foxyshop_breadcrumbs_base_link', get_bloginfo('url') . FOXYSHOP_URL_BASE . '/' . FOXYSHOP_PRODUCT_CATEGORY_SLUG . '/') . '">'. $base_name . '</a></li>';
		foreach($breadcrumbarray as $termid) {
			$write1 .= '<li class="foxyshop_category_separator">' . $sep .'</li>';
			$terminfo = get_term_by('id',$termid,"foxyshop_categories");
			if ($terminfo->term_id != $this_term_id || get_query_var('taxonomy') != "foxyshop_categories") {
				$url = get_term_link($terminfo, "foxyshop_categories");
				$write1 .= '<li class="foxyshop_breadcrumb_' . $terminfo->term_id . '"><a href="' . $url . '">' . str_replace("_","",$terminfo->name) . '</a></li>';
			} else {
				$write1 .= '<li class="foxyshop_breadcrumb_' . $terminfo->term_id . ' foxyshop_breadcrumb_current" aria-current="page">' . str_replace("_","",$terminfo->name) . '</li>';
			}
		}
		//Put product at end if this is a product page
		if (get_query_var('taxonomy') != "foxyshop_categories") {
			$write1 .= '<li class="foxyshop_category_separator">' . $sep .'</li>';
			$write1 .= '<li>'.$post->post_title.'</li>';
		}

		if ($write1) echo '<ul id="foxyshop_breadcrumbs" aria-label="Breadcrumb">' . foxy_wp_html($write1) . apply_filters('foxyshop_breadcrumb_nofloat', '<li style="float: none; text-indent: -99999px; width: 1px; margin: 0;">-</li>') . '</ul>';

	//Product Fallback
	} elseif ($post->ID && $product_fallback != "") {
		echo '<ul id="foxyshop_breadcrumbs" aria-label="Breadcrumb"><li><a href="' . esc_url(get_bloginfo('url') . FOXYSHOP_URL_BASE . '/' . apply_filters('foxyshop_template_redirect_product_slug', FOXYSHOP_PRODUCTS_SLUG) . '/') . '">'. foxy_wp_html($product_fallback) . '</a></li>' . apply_filters('foxyshop_breadcrumb_nofloat', '<li style="float: none; text-indent: -99999px; width: 1px; margin: 0;">-</li>') . '</ul>';
	}

}



//Checks Inventory Status
// %c = Item Count, %s = plural indicator (no s for 1), %n = product name
function foxyshop_inventory_management($alertMessage = "There are %c of these item%s left in stock.", $noStockMessage = "This item is no longer in stock.", $allowBackOrder = false) {
	global $product, $foxyshop_settings;
	if (!$foxyshop_settings['manage_inventory_levels']) return false;
	if (count($product['inventory_levels']) == 0) return false;
	$stockStatus = foxyshop_check_inventory();
	$noStockMessage = apply_filters('foxyshop_no_stock_message', $noStockMessage);
	$currentCount = "-1";
	if (array_key_exists($product['code'],$product['inventory_levels'])) $currentCount = $product['inventory_levels'][$product['code']]['count'];

	$arr_foxyshop_inventory_data = "";
	$i = 0;
	foreach ($product['inventory_levels'] as $ivcode => $iv) {
		$arr_foxyshop_inventory_data .= "arr_foxyshop_inventory[" . $product['id'] . "][" . $i . "] = [" . json_encode(str_replace("'","\'",$ivcode)) . "," . json_encode($iv['count']) . "," . json_encode(($iv['alert'] == '' ? $foxyshop_settings['inventory_alert_level'] : $iv['alert'])) . "];\n";
		$i++;
	}
	//Writes Javascript
	wp_add_inline_script( 'foxyshop_js', "
if (typeof arr_foxyshop_inventory_stock_alert == 'undefined') var arr_foxyshop_inventory_stock_alert = [];
arr_foxyshop_inventory_stock_alert[" . esc_attr($product['id']) . "] = " . json_encode(str_replace("'","\'",$alertMessage)) . ";
if (typeof arr_foxyshop_inventory_stock_none == 'undefined') var arr_foxyshop_inventory_stock_none = [];
arr_foxyshop_inventory_stock_none[" . esc_attr($product['id']) . "] = " . json_encode(str_replace("'","\'",$noStockMessage)) . ";
var foxyshop_allow_backorder = " . ($allowBackOrder ? "true" : "false") . ";
if (typeof arr_foxyshop_inventory == 'undefined') var arr_foxyshop_inventory = [];
arr_foxyshop_inventory[" . esc_attr($product['id']) . "] = [];
" . foxy_wp_html($arr_foxyshop_inventory_data));

	if ($stockStatus == -1 && !$allowBackOrder) {
		wp_add_inline_script( 'foxyshop_js', "
jQuery(document).ready(function($){
	$('#foxyshop_product_form_" . esc_attr($product['id']) . " .productsubmit, #foxyshop_product_form_" . esc_attr($product['id']) . " #productsubmit').attr('disabled','disabled').addClass('foxyshop_disabled');
});
		");
	}

	$alertMessage = str_replace('%n',$product['name'],$alertMessage);
	$alertMessage = str_replace('%c',$currentCount,$alertMessage);
	$alertMessage = str_replace('%s',($currentCount != 1 ? 's' : ''),$alertMessage);
	$noStockMessage = str_replace('%n',$product['name'],$noStockMessage);
	$noStockMessage = str_replace('%code',$product['code'],$noStockMessage);
	$noStockMessage = str_replace('%c',$currentCount,$noStockMessage);
	$noStockMessage = str_replace('%s',($currentCount != 1 ? 's' : ''),$noStockMessage);


	if ($stockStatus == 0) {
		echo '<div class="foxyshop_stock_alert">' . wp_kses_post($alertMessage) . '</div>';
	} elseif ($stockStatus == -1) {
		echo '<div class="foxyshop_stock_alert foxyshop_out_of_stock">' . wp_kses_post($noStockMessage) . '</div>';
	} else {
		echo '<div class="foxyshop_stock_alert" style="display: none;"></div>';
	}
}


//Checks Inventory Status For a Main Product Code
//Returns -1 (not in stock), 0 (stock alert), 1 (no alert)
function foxyshop_check_inventory() {
	global $product, $foxyshop_settings;
	if (!$foxyshop_settings['manage_inventory_levels']) return 1;
	if (count($product['inventory_levels']) == 0) return 1;
	foreach ($product['inventory_levels'] as $ivcode => $iv) {
		if ($ivcode == $product['code']) {
			$alert = ($iv['alert'] == '' ? $foxyshop_settings['inventory_alert_level'] : $iv['alert']);
			if ((int)$iv['count'] <= (int)$alert) {
				if ((int)$iv['count'] <= 0) {
					return -1;
				} else {
					return 0;
				}
			}
		}
	}
	return 1;
}


//Shows a Featured Category
function foxyshop_featured_category($categoryName, $showAddToCart = false, $showMoreDetails = false, $showMax = -1, $simpleList = false) {
	global $product;
	$term = get_term_by('slug', $categoryName, "foxyshop_categories");
	$currentCategorySlug = $term->slug;
	$currentCategoryID = $term->term_id;

	$args = array('post_type' => 'foxyshop_product', "foxyshop_categories" => $currentCategorySlug, 'numberposts' => $showMax);
	$args = array_merge($args,foxyshop_sort_order_array());
	$args = array_merge($args,foxyshop_hide_children_array($currentCategoryID));

	echo '<ul class="foxyshop_featured_product_list' . ($simpleList ? "_simple" : "") . '">';
	$featuredlist = get_posts($args);
	foreach($featuredlist as $featuredprod) {
		$product = foxyshop_setup_product($featuredprod);
		if ($product['hide_product']) continue;

		if ($simpleList) {
			$simplewrite = '<li><a href="' . $product['url'] . '">' . apply_filters('the_title', $product['name']) . '</a></li>'."\n";
			echo foxy_wp_html(apply_filters("foxyshop_featured_category_simple", $simplewrite, $product));
		} else {
			$thumbnailSRC = foxyshop_get_main_image("thumbnail");
			$write = '<li class="foxyshop_product_box">'."\n";
			$write .= '<div class="foxyshop_product_image">';
			$write .= '<a href="' . $product['url'] . '"><img src="' . $thumbnailSRC . '" alt="' . $product['name'] . '" class="foxyshop_main_image" /></a>';
			$write .= "</div>\n";
			$write .= '<div class="foxyshop_product_info">';
			$write .= '<h2><a href="' . $product['url'] . '">' . apply_filters('the_title', $product['name']) . '</a></h2>';
			$write .= foxyshop_price(0, 0);
			if ($showMoreDetails) $write .= '<a href="' . $product['url'] . '" class="foxyshop_button">' . __('More Details') . '</a>';
			if ($showAddToCart) $write .= '<a href="' . foxyshop_product_link("", true) . '" class="foxyshop_button">' . __('Add To Cart') . '</a>';
			$write .= "</div>\n";
			$write .= '<div class="clr"></div>';
			$write .= "</li>\n";
			echo foxy_wp_html(apply_filters("foxyshop_featured_category_html", $write, $product));
		}
	}
	echo "</ul><div class=\"clr\"></div>\n";
}



//Shopping Cart Link
function foxyshop_cart_link($linkText = "View Cart", $hideEmpty = false) {
	global $foxyshop_settings;
	$linkText = str_replace(array('%q%', '%q'),'<span id="fc_quantity">0</span>',$linkText);
	$linkText = str_replace(array('%p%', '%p'),'<span id="fc_total_price">' . number_format(0,2) . '</span>',$linkText);
	if ($hideEmpty) echo '<div id="fc_minicart">';
	echo '<a href="' . esc_url('https://' . $foxyshop_settings['domain'] . '/cart?cart=view') . '" class="foxycart">' . wp_kses_post($linkText) . '</a>';
	if ($hideEmpty) echo '</div>';
}



//Shows Related Products
function foxyshop_related_products($sectiontitle = "Related Products", $maxproducts = 5) {
	global $product, $post, $foxyshop_settings;

	$related_order = "";
	$args = array('post_type' => 'foxyshop_product', "post__not_in" => array($product['id']));
	//Native Related Products
	if ($foxyshop_settings['related_products_custom'] && $product['related_products']) {
		$args['post__in'] = explode(",",$product['related_products']);
		$args['posts_per_page'] = -1;
		if ($related_order = get_post_meta($product['id'], "_related_order", 1)) add_filter('posts_orderby', 'foxyshop_related_order');

	//Tags
	} elseif ($foxyshop_settings['related_products_tags']) {
		$tags = wp_get_post_terms($product['id'], 'foxyshop_tags', array("fields" => "ids"));
		if (count($tags) > 0) {
			$args['tax_query'] = array(array('taxonomy' => 'foxyshop_tags', 'field' => 'id', 'terms' => $tags));
			$args['posts_per_page'] = $maxproducts;
			$args['orderby'] = "rand";
		} else {
			$args['post__in'] = array("-1");
		}
	} else {
		return;
	}
	if (!array_key_exists('orderby', $args)) $args = array_merge($args,foxyshop_sort_order_array());
	$relatedlist = new WP_Query($args);

	if (count($relatedlist->posts) > 0) {
		$original_product = $product;
		echo '<ul class="foxyshop_related_product_list">';
		echo '<li class="titleline"><h3>' . wp_kses_post($sectiontitle) . '</h3></li>';
		while ($relatedlist->have_posts() ) :
			$relatedlist->the_post();
			$product = foxyshop_setup_product();
			$thumbnailSRC = foxyshop_get_main_image(apply_filters('foxyshop_related_products_thumbnail_size',"thumbnail"));
			$write = '<li class="foxyshop_product_box">'."\n";
			$write .= '<div class="foxyshop_product_image">';
			$write .= '<a href="' . $product['url'] . '"><img src="' . $thumbnailSRC . '" alt="' . $product['name'] . '" class="foxyshop_main_image" /></a>';
			$write .= "</div>\n";
			$write .= '<div class="foxyshop_product_info">';
			$write .= '<h2><a href="' . $product['url'] . '">' . apply_filters('the_title', $product['name']) . '</a></h2>';
			$write .= foxyshop_price(0,0);
			$write .= "</div>\n";
			$write .= '<div class="clr"></div>';
			$write .= "</li>\n";
			echo foxy_wp_html(apply_filters('foxyshop_related_products_html', $write, $product));

		endwhile;
		echo "</ul>\n";
		echo '<div class="clr"></div>';
		$product = $original_product;
		wp_reset_postdata();
	}
	if ($related_order) remove_filter('posts_orderby', 'foxyshop_related_order');
}

function foxyshop_related_order($orderby) {
	global $post;
	$delimiter = ",";
	$str = get_post_meta($post->ID, "_related_order", 1);
	if (!$str) return $orderby;
	$str = preg_replace(array('/[^\d'.$delimiter.']/', '/(?<='.$delimiter.')'.$delimiter.'+/', '/^'.$delimiter.'+/', '/'.$delimiter.'+$/'), '', $str);
	return "field(ID," . $str . ")";
}

function foxyshop_addon_order($orderby) {
	global $post;
	$delimiter = ",";
	$str = get_post_meta($post->ID, "_addon_order", 1);
	if (!$str) return $orderby;
	$str = preg_replace(array('/[^\d'.$delimiter.']/', '/(?<='.$delimiter.')'.$delimiter.'+/', '/^'.$delimiter.'+/', '/'.$delimiter.'+$/'), '', $str);
	return "field(ID," . $str . ")";
}


//Add-On Products
function foxyshop_addon_products($show_qty = false, $before_entry = "", $after_entry = '<div class="clr"></div>') {
	global $foxyshop_settings, $product, $foxyshop_skip_url_link;
	if (!$foxyshop_settings['enable_addon_products'] || !$product['addon_products']) return;
	$original_product = $product;
	echo '<div class="foxyshop_addon_container">'."\n";

	$args = array('post_type' => 'foxyshop_product', "post__in" => explode(",",$product['addon_products']), 'posts_per_page' => -1);
	if ($addonorder_order = get_post_meta($original_product['id'], "_addon_order", 1)) add_filter('posts_orderby', 'foxyshop_addon_order');
	$addonproducts = new WP_Query($args);
	if (!$product['bundled_products']) {
		$num = 2;
	} else {
		$bundled_products = explode(",",$product['bundled_products']);
		$num = count($bundled_products) + 2;
	}
	while ($addonproducts->have_posts()) :
		$addonproducts->the_post();

		//Setup
		$product = foxyshop_setup_product();

		//Check Inventory Levels
		if (isset($product['inventory_levels'][$product['code']]['count'])) {
			if ($product['inventory_levels'][$product['code']]['count'] <= 0) {
				continue;
			}
		}


		$fields = array('name','price','code','category','weight','discount_quantity_amount','discount_quantity_percentage','discount_price_amount','discount_price_percentage','sub_frequency','sub_startdate','sub_enddate');
		foreach ($fields as $fieldname) {
			if ($product[$fieldname]) echo '<input type="hidden" class="foxyshop_addon_fields" rel="' . esc_attr($num) . '" originalname="' . esc_attr($fieldname . foxyshop_get_verification($fieldname)) . '" name="x:' . esc_attr($fieldname . foxyshop_get_verification($fieldname)) . '" id="' . esc_attr($num . ':' . $fieldname . '_' . $product['id']) . '" value="' . esc_attr($product[$fieldname]) . '" />'."\n";
		}
		if (foxyshop_get_main_image() && version_compare($foxyshop_settings['version'], '0.7.0', ">")) echo '<input type="hidden" class="foxyshop_addon_fields" rel="' . esc_attr($num) . '" originalname="' . esc_attr('image' . foxyshop_get_verification('image','--OPEN--')) . '" name="' . esc_attr('x:image' . foxyshop_get_verification('image','--OPEN--')) . '" id="' . esc_attr($num . ':image_' . $product['id']) . '" value="' . esc_url(foxyshop_get_main_image()) . '" />'."\n";
		if (version_compare($foxyshop_settings['version'], '0.7.0', ">") && !isset($foxyshop_skip_url_link)) echo '<input type="hidden" class="foxyshop_addon_fields" rel="' . esc_attr($num) . '" originalname="' . esc_attr('url' . foxyshop_get_verification('url')) . '" name="' . esc_attr('x:url' . foxyshop_get_verification('url')) . '" id="' . esc_attr($num . ':url_' . $product['id']) . '" value="' . esc_url($product['url']) . '" />'."\n";

		//Output
		echo foxy_wp_html($before_entry);
		echo ('<input type="checkbox" name="x:addon_'.esc_attr($num).'" id="addon_'.esc_attr($num).'" rel="'.esc_attr($num).'" class="foxyshop_addon_checkbox" />');
		echo ('<label for="addon_'.esc_attr($num).'" class="addon_main_label">' . esc_html($product['name']) . '</label>');
		echo ('<input type="hidden" name="x:addon_price_'.esc_attr($num).'" id="addon_price_'.esc_attr($num).'" value="' . esc_attr($product['price']) . '" />');
		foxyshop_price(0, 1);
		if ($show_qty) echo foxy_wp_html(foxyshop_quantity(apply_filters("foxyshop_default_quantity_value", 1), "", "", $num));
		echo foxy_wp_html($after_entry);


		$num++;
	endwhile;
	wp_reset_query();
	echo '</div>'."\n";
	$product = $original_product;
	wp_add_inline_script( 'foxyshop_js', "
function foxyshop_addon_enable(rel) {
	if (jQuery(\"#addon_\" + rel).is(\":checked\")) {
		jQuery(\".foxyshop_addon_fields[rel='\" + rel + \"']\").each(function() {
			jQuery(this).attr(\"name\", rel + \":\" + jQuery(this).attr(\"originalname\"));
		});
		jQuery(\".foxyshop_quantity.foxyshop_addon_fields[rel=\" + rel + \"]\").prop(\"disabled\", false);
	} else {
		jQuery(\".foxyshop_addon_fields[rel='\" + rel + \"']\").each(function() {
			jQuery(this).attr(\"name\", \"x:\" + jQuery(this).attr(\"originalname\"))
		});
		jQuery(\".foxyshop_quantity.foxyshop_addon_fields[rel=\" + rel + \"]\").prop(\"disabled\", true);
	}
}

jQuery(document).ready(function($){
	$(\".foxyshop_addon_checkbox\").click(function() {
		foxyshop_addon_enable($(this).attr(\"rel\"));
	});
	$(\"input.foxyshop_quantity.foxyshop_addon_fields\").keyup(function() {
		$(this).val($(this).val().replace(/\D/g,''));
		$(\".foxyshop_addon_checkbox\").trigger(\"change\");
	});
});
	");
	remove_filter('posts_orderby', 'foxyshop_addon_order');
}


//Get Sort Order
function foxyshop_sort_order_array($category_id = 0) {
	global $foxyshop_settings;
	if (isset($_COOKIE['sort_key'])) $foxyshop_settings['sort_key'] = sanitize_text_field($_COOKIE['sort_key']);
	if (isset($_GET['sort_key'])) $foxyshop_settings['sort_key'] = sanitize_text_field($_GET['sort_key']);
	if ($foxyshop_settings['sort_key'] == "name") {
		return array('orderby' => 'title', 'order' => 'ASC');
	} elseif ($foxyshop_settings['sort_key'] == "price_asc") {
		return array('orderby' => 'meta_value_num', 'meta_key' => '_price', 'order' => 'ASC');
	} elseif ($foxyshop_settings['sort_key'] == "price_desc") {
		return array('orderby' => 'meta_value_num', 'meta_key' => '_price', 'order' => 'DESC');
	} elseif ($foxyshop_settings['sort_key'] == "date_asc") {
		return array('orderby' => 'date', 'order' => 'ASC');
	} elseif ($foxyshop_settings['sort_key'] == "date_desc") {
		return array('orderby' => 'date', 'order' => 'DESC');
	} elseif ($category_id > 0) {
		return array('orderby' => 'meta_value_num', "meta_key" => "_foxyshop_menu_order_" . (int)$category_id, 'order' => 'ASC');
	} else {
		return array('orderby' => 'menu_order', 'order' => 'ASC');
	}
}


//Product Sort Dropdown
function foxyshop_sort_dropdown($title = "Sort Products") {
	global $arr_dropdown_sort, $foxyshop_settings;
	if (!isset($arr_dropdown_sort)) $arr_dropdown_sort = array(
		"default" => apply_filters('foxyshop_sort_default', 'Default'),
		"price_asc" => apply_filters('foxyshop_sort_price_low', 'Price (Low to High)'),
		"price_desc" => apply_filters('foxyshop_sort_price_high', 'Price (High to Low)'),
		"date_desc" => apply_filters('foxyshop_sort_newer_first', 'Newer Products First'),
		"date_asc" => apply_filters('foxyshop_sort_older_first', 'Older Products First')
	);
	if (isset($_COOKIE['sort_key'])) $current_sort_key = sanitize_text_field($_COOKIE['sort_key']);
	if (isset($_GET['sort_key'])) $current_sort_key = sanitize_text_field($_GET['sort_key']);
	if (!isset($current_sort_key)) $current_sort_key = $foxyshop_settings['sort_key'];
	echo '<form id="foxyshop_sort_dropdown">'."\n";
	echo '<label for="sort_key">' . wp_kses_post($title) . '</label>'."\n";
	echo '<select name="sort_key" id="sort_key" onchange="foxyshop_sort_dropdown(this);">'."\n";
	foreach ($arr_dropdown_sort AS $key=>$val) {
		echo '<option value="' . esc_attr($key) . '"' . ($current_sort_key == $key ? ' selected="selected"' : '') . '>' . esc_html($val) . '</option>'."\n";
	}
	echo '</select>'."\n";
	echo '</form>'."\n";

}


//Includes Header and Footer Files
function foxyshop_include($filename = "header") {
	include foxyshop_get_template_file('/foxyshop-' . $filename . '.php');
}


//Function to pick which template file to use
function foxyshop_get_template_file($filename) {
	if (!defined('FOXYSHOP_TEMPLATE_PATH')) define('FOXYSHOP_TEMPLATE_PATH',STYLESHEETPATH);
	$url = "";
	if (file_exists(FOXYSHOP_TEMPLATE_PATH . '/' . $filename)) $url = FOXYSHOP_TEMPLATE_PATH . '/' . $filename;
	if (STYLESHEETPATH != TEMPLATEPATH && !$url) if (file_exists(TEMPLATEPATH . '/' . $filename)) $url = TEMPLATEPATH . '/' . $filename;
	if (!$url) $url = FOXYSHOP_PATH . '/themefiles/' . $filename;
	return apply_filters("foxyshop_template_redirect", $url, $filename);
}


//Show Orders For A Customer
//Sample Usage: foxyshop_customer_order_history(get_user_meta(wp_get_current_user()->ID, 'foxycart_customer_id', 1));
function foxyshop_customer_order_history($customer_id = 0, $date_filter = 'n/j/Y', $no_results_message = "No Records Found.", $filter_options = array()) {
	global $foxyshop_settings;

	//Setup Fields and Defaults
	$foxy_data_defaults = array("customer_id_filter" => $customer_id);
	$foxy_data = wp_parse_args(array("api_action" => "transaction_list"), $foxy_data_defaults);
	$foxy_data['pagination_start'] = (isset($_GET['pagination_start']) ? (int)sanitize_text_field($_GET['pagination_start']) : 0);

	if(!empty($filter_options)){
		$foxy_data = wp_parse_args( $foxy_data, $filter_options );
	}

	if (version_compare($foxyshop_settings['version'], '0.7.0', ">")) $foxy_data['entries_per_page'] = 50;
	$foxy_response = foxyshop_get_foxycart_data($foxy_data);
	$xml = simplexml_load_string($foxy_response, NULL, LIBXML_NOCDATA);

	//No Results
	if ($xml->result == "ERROR") {
		$msg = $xml->messages->message;
		if ($msg == "No transactions found. Please double check your filter fields.") $msg = $no_results_message;
		echo '<div class="foxyshop_customer_order_history_no_results">' . wp_kses_post($msg) . '</div>';
		return;
	}

	//Table Header
	echo '<table cellpadding="0" cellspacing="0" border="0" class="foxyshop_table_list" id="foxyshop_customer_order_history">'."\n";
	echo '<thead>'."\n";
	echo '<tr>'."\n";
	echo '<th>Order ID</th>'."\n";
	echo '<th>Date</th>'."\n";
	echo '<th>Total</th>'."\n";
	echo '<th>&nbsp;</th>'."\n";
	echo '</tr>'."\n";
	echo '</thead>'."\n";
	echo '<tbody>'."\n";
	foreach($xml->transactions->transaction as $transaction) {
		$transaction_id = $transaction->id;
		echo '<tr rel="' . esc_attr($transaction_id) . '">';
		echo '<td class="order_id">' . wp_kses($transaction_id, []) . '</td>';
		echo '<td class="order_date">' . wp_kses(date($date_filter, strtotime($transaction->transaction_date)), []) . '</td>';
		echo '<td class="order_total">' . wp_kses(foxyshop_currency((double)$transaction->order_total), []) . '</td>';
		echo '<td class="order_receipt"><a href="' . esc_url($transaction->receipt_url) . '" target="_blank">Show Receipt</a></td>';
		echo '</tr>'."\n";
	}

	echo '</tbody></table>';

	//Pagination
	$p = (int)(version_compare($foxyshop_settings['version'], '0.7.0', "==") ? 50 : 50);
	$total_records = (int)$xml->statistics->total_orders;
	$filtered_total = (int)$xml->statistics->filtered_total;
	$pagination_start = (int)$xml->statistics->pagination_start;
	$pagination_end = (int)$xml->statistics->pagination_end;
	if ($pagination_start > 1 || $filtered_total > $pagination_end) {
		echo '<div id="foxyshop_list_pagination">';
		echo foxy_wp_html($xml->messages->message[1]) . '<br />';
		if ($pagination_start > 1) echo '<a href="edit.php' . esc_attr($querystring) . '&amp;pagination_start=' . esc_attr($pagination_start - $p - 1) . '">&laquo; Previous</a>';
		if ($pagination_end < $filtered_total) {
			if ($pagination_start > 1) echo ' | ';
			echo '<a href="edit.php' . esc_attr($querystring) . '&amp;pagination_start=' . esc_attr($pagination_end) . '">Next &raquo;</a>';
		}
		echo '</div>';
	}

}


//Gets Subscription Status (0 = Not Found Or Inactive, 1 = active)
function foxyshop_subscription_active($product_code) {
	if (!function_exists('wp_get_current_user')) return 0;
	$current_user = wp_get_current_user();
	$current_user_id = $current_user->ID;
	if ($current_user_id == 0) return 0;
	$foxyshop_subscription = get_user_meta($current_user_id, 'foxyshop_subscription', true);
	if (!is_array($foxyshop_subscription)) return 0;
	if (array_key_exists($product_code,$foxyshop_subscription)) {
		if ($foxyshop_subscription[$product_code]['is_active'] == 1) {
			return 1;
		} else {
			return 0;
		}
	} else {
		return 0;
	}
}




//Create Subscription Transaction Template
function foxyshop_subscription_template($id) {
	global $product, $foxyshop_settings;
	$original_product = $product;
	$template_id = get_posts(array('post_type' => 'foxyshop_product', 'p' => $id));
	foreach($template_id as $the_template_id) {
		$product = foxyshop_setup_product($the_template_id);
		if ($product['quantity_min'] == 0) $product['quantity_min'] = 1;
		if (!$product['category']) $product['category'] = "DEFAULT";
		if (!$product['weight']) $product['weight'] = "0";
		$product = apply_filters("foxyshop_template_transaction_product_array", $product);
		$xml = "<transaction_template>\n";
		$xml .= "\t<custom_fields />\n";
		$xml .= "\t<discounts />\n";
		$xml .= "\t<transaction_details>\n";
		$xml .= "\t<transaction_detail>\n";
		$xml .= "\t\t<product_name><![CDATA[" . $product['name'] . "]]></product_name>\n";
		$xml .= "\t\t<product_price><![CDATA[" . $product['price'] . "]]></product_price>\n";
		$xml .= "\t\t<product_quantity><![CDATA[" . $product['quantity_min'] . "]]></product_quantity>\n";
		$xml .= "\t\t<product_weight><![CDATA[" . $product['weight'] . "]]></product_weight>\n";
		$xml .= "\t\t<product_code><![CDATA[" . $product['code'] . "]]></product_code>\n";
		$xml .= "\t\t<image><![CDATA[" . foxyshop_get_main_image() . "]]></image>\n";
		$xml .= "\t\t<url><![CDATA[" . $product['url'] . "]]></url>\n";
		$xml .= "\t\t<category_code><![CDATA[" . $product['category'] . "]]></category_code>\n";
		$xml .= "\t\t<transaction_detail_options />\n";
		$xml .= "\t</transaction_detail>\n";
		$xml .= "\t</transaction_details>\n";
		$xml .= "</transaction_template>";
		$xml = apply_filters("foxyshop_template_transaction_xml", $xml, $product);
	}
	$product = $original_product;
	return $xml;
}




//Pagination Function
function foxyshop_get_pagination($range = 4) {
	global $paged, $wp_query;
	if (!isset($max_page)) $max_page = $wp_query->max_num_pages;
	if($max_page > 1) {
		echo '<div id="foxyshop_pagination">';
		if(!$paged) $paged = 1;
		if($paged != 1) echo "<a href=" . esc_url(get_pagenum_link(1)) . "> First </a>";
		previous_posts_link(' &laquo; ');
		if($max_page > $range) {
			if($paged < $range) {
				for($i = 1; $i <= ($range + 1); $i++) {
					echo "<a href='" . esc_url(get_pagenum_link($i)) ."'";
					if ($i==$paged) echo "class='current'";
					echo ">" . esc_html($i) . "</a>";
				}
			} elseif ($paged >= ($max_page - ceil(($range/2)))) {
				for($i = $max_page - $range; $i <= $max_page; $i++) {
					echo "<a href='" . esc_url(get_pagenum_link($i)) ."'";
					if($i==$paged) echo "class='current'";
					echo ">" . esc_html($i) . "</a>";
				}
			} elseif ($paged >= $range && $paged < ($max_page - ceil(($range/2)))) {
				for($i = ($paged - ceil($range/2)); $i <= ($paged + ceil(($range/2))); $i++){
					echo "<a href='" . esc_url(get_pagenum_link($i)) ."'";
					if($i==$paged) echo "class='current'";
					echo ">" . esc_html($i) . "</a>";
				}
			}
		} else {
			for($i = 1; $i <= $max_page; $i++) {
				echo "<a href='" . esc_url(get_pagenum_link($i)) ."'";
				if ($i==$paged) echo "class='current'";
				echo ">" . esc_html($i) . "</a>";
			}
		}
		next_posts_link(' &raquo; ');
		if($paged != $max_page) echo " <a href=" . esc_url(get_pagenum_link($max_page)) . "> Last </a>";
		echo '</div>';
	}
}

function format_money($ignore, $value, $locale_code) {
	$format = numfmt_create($locale_code, NumberFormatter::CURRENCY);
	$symbol = $format->getSymbol(NumberFormatter::INTL_CURRENCY_SYMBOL);
	return $format->formatCurrency($value, $symbol);
}

function foxyshop_currency($input, $currencysymbol = true) {
	global $foxyshop_settings;
	if (method_exists('NumberFormatter','formatCurrency')) {
		$money_format_string = "%" . ($currencysymbol ? "" : "!") . "." . FOXYSHOP_DECIMAL_PLACES . "n";
		$currency = format_money(apply_filters("foxyshop_money_format_string", $money_format_string), (double)$input, $foxyshop_settings['locale_code']);
	} else {
		//Windows: no internationalisation support
		$currency_code = ($foxyshop_settings['locale_code'] == "en_GB" ? "&pound;" : "$");
		$currency = $currency_code . number_format((double)$input, FOXYSHOP_DECIMAL_PLACES, ".", ",");
	}
	return apply_filters("foxyshop_currency", $currency);
}

function is_foxyshop() {
	return defined("IS_FOXYSHOP");
}
