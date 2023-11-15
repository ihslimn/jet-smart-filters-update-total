<?php
/**
 * Plugin Name: JetSmartFilters - Update total on filtering
 * Plugin URI:  #
 * Description: Allow to update text with SQL results (works with AJAX filtering only)
 * Version:     1.0.0
 * Author:      Crocoblock
 * Author URI:  https://crocoblock.com/
 * License:     GPL-3.0+
 * License URI: http://www.gnu.org/licenses/gpl-3.0.txt
 * Domain Path: /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die();
}

if ( ! class_exists( 'JSF_Update_Total_on_Filtering' ) ) {

	class JSF_Update_Total_on_Filtering {
		
		private static $instance = null;
		
		private $current_items = array();
		
		private $items = array();
		
		private $option = 'jsf-update-total';
		
		public function __construct() {
			add_action( 'plugins_loaded', array( $this, 'init' ) );
		}
		
		public function init() {
			
			if ( ! function_exists( 'jet_smart_filters' ) || ! function_exists( 'jet_engine' ) ) {
				
				add_action( 'admin_notices', function() {
					$class = 'notice notice-error';
					$message = '<b>WARNING!</b> <b>JetSmartFilters - Update total on filtering</b> plugin requires <b>Jet Engine</b> and <b>Jet Smart Filters</b> plugins to work properly!';
					printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), wp_kses_post( $message ) );
				} );

				return;
				
			}

			add_action( 'jet-engine/query-builder/query/after-query-setup', array( $this, 'maybe_add_filter' ), 999 );
			
			add_action( 'init', array( $this, 'register_options_page' ), 20 );
			
			add_action( 'wp_head', array( $this, 'add_inline_script' ) );

			add_filter( 'jet-smart-filters/render/ajax/data', array( $this, 'add_data' ), 999 );
			
		}
		
		public static function instance() {

			if ( is_null( self::$instance ) ) {

				self::$instance = new self();

			}

			return self::$instance;

		}
		
		public function add_inline_script() {
			?>
				<script>
					document.addEventListener( 'jet-smart-filters/inited', ( e ) => {
					   jQuery( function( $ ) {

							let filterGroups = window.JetSmartFilters.filterGroups;

							for ( let group in filterGroups ) {
								console.log( filterGroups[ group ].$provider );
								if ( filterGroups[ group ].$provider.closest( '.updating-data' ).length ) {
									filterGroups[ group ].currentQuery = {"update":"true"};
									filterGroups[ group ].apply( 'ajax' );
								}
							}

						} );
					});
				</script>
			<?php
		}
		
		public function register_options_page() {
			
			$args = array (
				'slug' => $this->option,
				'labels' => 
				array (
					'name'      => 'JSF - Update Total On Filtering',
					'menu_name' => 'JSF - Update Total On Filtering',
				),
				'fields' => 
				array (
					array (
						'title'           => 'Field preferences',
						'name'            => 'field-preferences',
						'object_type'     => 'field',
						'width'           => '100%',
						'type'            => 'repeater',
						'repeater-fields' => 
						array (
							array (
								'title'       => 'Selector',
								'name'        => 'selector',
								'object_type' => 'field',
								'width'       => '100%',
								'type'        => 'text',
								'description' => 'Selector to be updated',
							),
							array (
								'title'       => 'SQL query',
								'name'        => 'sql',
								'object_type' => 'field',
								'width'       => '100%',
								'type'        => 'textarea',
							),
							array (
								'title'       => 'Prefix',
								'name'        => 'prefix',
								'object_type' => 'field',
								'width'       => '100%',
								'type'        => 'text',
							),
							array (
								'title'       => 'Suffix',
								'name'        => 'suffix',
								'object_type' => 'field',
								'width'       => '100%',
								'type'        => 'text',
							),
							array (
								'title'       => 'Format as number',
								'name'        => 'as_num',
								'object_type' => 'field',
								'width'       => '100%',
								'type'        => 'switcher',
							),
							array (
								'title'       => 'Decimal separator',
								'name'        => 'd_sep',
								'object_type' => 'field',
								'width'       => '100%',
								'type'        => 'text',
							),
							array (
								'title'       => 'Thousand separator',
								'name'        => 't_sep',
								'object_type' => 'field',
								'width'       => '100%',
								'type'        => 'text',
							),
							array (
								'title'       => 'Decimals count',
								'name'        => 'd_count',
								'object_type' => 'field',
								'width'       => '100%',
								'type'        => 'number',
								'min_value'   => '0',
							),
						),
						'repeater_collapsed' => false,
						'repeater_title_field' => 'selector',
					),
				),
				'parent' => jet_smart_filters()->post_type->slug(),
				'icon' => 'dashicons-bell',
				'capability' => 'manage_options',
				'position' => '',
				'hide_field_names' => false,
			);

			new \Jet_Engine_Options_Page_Factory( $args );
			
		}
		
		public function maybe_add_filter( $query ) {
			
			if ( \Jet_Engine\Query_Builder\Manager::instance()->listings->filters->is_filters_request( $query ) ) {
				
				$this->current_items = $query->get_items();

				$this->query_type = $query->query_type;

				switch ( $query->query_type ) {
					case 'posts':
						$query->reset_query();

						$posts_per_page = $query->final_query['posts_per_page'] ?? get_option( 'posts_per_page' );

						$query->final_query['posts_per_page'] = -1;
						$this->items = $query->_get_items();
						$query->final_query['posts_per_page'] = $posts_per_page;
						
						$query->reset_query();

						break;
					case 'users':
						$args = $query->final_query;

						unset( $args['paged'] );
						$args['number'] = -1;
						$args['offset']  = 0;

						if ( ! empty( $args['meta_query'] ) ) {
							$args['meta_query'] = $query->prepare_meta_query_args( $args );
						}
				
						if ( ! empty( $args['date_query'] ) ) {
							$args['date_query'] = $query->prepare_date_query_args( $args );
						}

						$users_query = new \WP_User_Query( $args );
						$this->items = $users_query->get_results();

						break;
					case 'custom-content-type':
						$per_page = ! empty( $query->final_query['number'] ) ? absint( $query->final_query['number'] ) : 0;

						$query->final_query['number'] = 0;

						$this->items = $query->_get_items();

						$query->final_query['number'] = $per_page;

						break;
				}

			}
			
		}

		public function stringify_items( $items = array() ) {

			$items = array_map( function( $item ) {
	            return jet_engine()->listings->data->get_current_object_id( $item );
			}, $items );
			
			if ( ! empty( $items ) ) {
				$ids = implode( ',', $items );
			} else {
				$ids = PHP_INT_MAX;
			}

			return $ids;

		}
		
		public function add_data( $data ) {
			
			$field_preferences = get_option( $this->option, array() )['field-preferences'] ?? array();
			
			if ( empty( $field_preferences ) ) {
				return $data;
			}
			
			$ids = $this->stringify_items( $this->items );
			
			$current_ids = $this->stringify_items( $this->current_items );
			
			global $wpdb;
			
			$prefix = $wpdb->prefix;
			
			foreach( $field_preferences as $field ) {

				$sql      = $field['sql'] ?? '';
				$selector = $field['selector'] ?? '';
				$f_prefix = $field['prefix'] ?? '';
				$f_suffix = $field['suffix'] ?? '';
				$as_num   = filter_var( $field['as_num'] ?? false, FILTER_VALIDATE_BOOLEAN );
				$d_sep    = $field['d_sep'] ?? '';
				$t_sep    = $field['t_sep'] ?? '';
				$d_count  = ( int ) $field['d_count'] ?? 0;
				
				if ( empty( $sql ) || empty( $selector ) ) {
					continue;
				}
				
				$sql = wp_unslash( $sql );
				$sql = str_replace( '{prefix}', $prefix, $sql );
				$sql = str_replace( '{ids}', $ids, $sql );
				$sql = str_replace( '{current_ids}', $current_ids, $sql );
				
				$results = $wpdb->get_results( $sql, 'ARRAY_A' );

				$result = $results[0]['result'] ?? 0;
				
				if ( $as_num && is_numeric( $result ) ) {
					$result = number_format( ( float ) $result, $d_count, $d_sep, $t_sep );
				}
				$data['query_type'] = $this->query_type;
				$data['fragments'][ $selector ] = sprintf( '%1$s%2$s%3$s', $f_prefix, $result, $f_suffix );
				
			}
			
			return $data;
			
		}
		
	}

}

JSF_Update_Total_on_Filtering::instance();
