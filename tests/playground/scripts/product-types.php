<?php
// Test product types (class names follow WC_Product_Factory's convention). Never deploy.
if ( ! class_exists( 'WC_Product_Composite' ) ) {
	class WC_Product_Composite extends WC_Product_Simple {
		public function get_type() {
			return 'composite';
		}
	}
}
if ( ! class_exists( 'WC_Product_Gift_Card' ) ) {
	class WC_Product_Gift_Card extends WC_Product_Simple {
		public function get_type() {
			return 'gift-card';
		}
	}
}
