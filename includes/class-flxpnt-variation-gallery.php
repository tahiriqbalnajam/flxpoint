<?php

/**
 * Variation gallery image support for the WooCommerce REST API.
 *
 * Extends the WooCommerce Variations REST endpoint to accept
 * an `images` array (matching the Products controller pattern),
 * enabling Flxpoint and other integrations to set full variation
 * galleries via the API.
 *
 * @link       https://tinajam.wordpress.com
 * @since      1.0.0
 *
 * @package    Flxpnt
 * @subpackage Flxpnt/includes
 */

/**
 * Handles variation gallery images for WooCommerce REST API.
 *
 * Three hooks:
 *  1. Schema  – adds `images` property to the variations endpoint schema.
 *  2. Write   – processes incoming `images` array on create/update.
 *  3. Response – injects combined (featured + gallery) images into GET responses.
 *
 * @since      1.0.0
 * @package    Flxpnt
 * @subpackage Flxpnt/includes
 * @author     Tahir Iqbal <tahiriqbal09@gmail.com>
 */
class Flxpnt_Variation_Gallery {

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	private $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of the plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param    string $plugin_name The name of this plugin.
	 * @param    string $version     The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Add `images` to the variations endpoint schema.
	 *
	 * Mirrors the `images` property from the Products controller so the
	 * field is recognised by the REST API and appears in schema output.
	 *
	 * @since    1.0.0
	 * @param    array $properties Existing schema properties.
	 * @return   array
	 */
	public function add_images_to_schema( $properties ) {
		$properties['images'] = array(
			'description' => __( 'List of variation images.', 'flxpnt' ),
			'type'        => 'array',
			'context'     => array( 'view', 'edit' ),
			'items'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'                => array(
						'description' => __( 'Image ID.', 'woocommerce' ),
						'type'        => 'integer',
						'context'     => array( 'view', 'edit' ),
					),
					'date_created'      => array(
						'description' => __( "The date the image was created, in the site's timezone.", 'woocommerce' ),
						'type'        => 'date-time',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
					'date_created_gmt'  => array(
						'description' => __( 'The date the image was created, as GMT.', 'woocommerce' ),
						'type'        => 'date-time',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
					'date_modified'     => array(
						'description' => __( "The date the image was last modified, in the site's timezone.", 'woocommerce' ),
						'type'        => 'date-time',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
					'date_modified_gmt' => array(
						'description' => __( 'The date the image was last modified, as GMT.', 'woocommerce' ),
						'type'        => 'date-time',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
					'src'               => array(
						'description' => __( 'Image URL.', 'woocommerce' ),
						'type'        => 'string',
						'format'      => 'uri',
						'context'     => array( 'view', 'edit' ),
					),
					'name'              => array(
						'description' => __( 'Image name.', 'woocommerce' ),
						'type'        => 'string',
						'context'     => array( 'view', 'edit' ),
					),
					'alt'               => array(
						'description' => __( 'Image alternative text.', 'woocommerce' ),
						'type'        => 'string',
						'context'     => array( 'view', 'edit' ),
					),
				),
			),
		);

		return $properties;
	}

	/**
	 * Inject `images` into variation GET responses.
	 *
	 * Combines the featured image and gallery images into a single
	 * array, matching the Products controller's get_images() format.
	 *
	 * @since    1.0.0
	 * @param    WP_REST_Response $response The response object.
	 * @param    WC_Data          $object   Variation data.
	 * @param    WP_REST_Request  $request  Request object.
	 * @return   WP_REST_Response
	 */
	public function add_images_to_response( $response, $object, $request ) {
		if ( empty( $response->data ) ) {
			return $response;
		}

		$images         = array();
		$attachment_ids = array();

		if ( $object->get_image_id() ) {
			$attachment_ids[] = $object->get_image_id();
		}

		$gallery_ids = $object->get_gallery_image_ids();
		if ( ! empty( $gallery_ids ) ) {
			$attachment_ids = array_merge( $attachment_ids, $gallery_ids );
		}

		$attachment_ids = array_unique( $attachment_ids );

		foreach ( $attachment_ids as $attachment_id ) {
			$attachment_post = get_post( $attachment_id );
			if ( is_null( $attachment_post ) ) {
				continue;
			}

			$attachment = wp_get_attachment_image_src( $attachment_id, 'full' );
			if ( ! is_array( $attachment ) ) {
				continue;
			}

			$images[] = array(
				'id'                => (int) $attachment_id,
				'date_created'      => wc_rest_prepare_date_response( $attachment_post->post_date, false ),
				'date_created_gmt'  => wc_rest_prepare_date_response( strtotime( $attachment_post->post_date_gmt ) ),
				'date_modified'     => wc_rest_prepare_date_response( $attachment_post->post_modified, false ),
				'date_modified_gmt' => wc_rest_prepare_date_response( strtotime( $attachment_post->post_modified_gmt ) ),
				'src'               => current( $attachment ),
				'name'              => get_the_title( $attachment_id ),
				'alt'               => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			);
		}

		$response->data['images'] = $images;

		return $response;
	}

	/**
	 * Process the `images` array on variation create/update.
	 *
	 * First image becomes the featured image, the rest become the
	 * gallery.  Downloads remote URLs via WooCommerce's own helper
	 * functions so the behaviour matches the Products controller.
	 *
	 * @since    1.0.0
	 * @param    WC_Product_Variation $variation Variation object.
	 * @param    WP_REST_Request      $request   Request object.
	 * @param    bool                 $creating  Whether this is a create operation.
	 * @return   WC_Product_Variation
	 */
	public function process_variation_images( $variation, $request, $creating ) {
		if ( ! isset( $request['images'] ) || ! is_array( $request['images'] ) ) {
			return $variation;
		}

		$images = array_values( array_filter( $request['images'] ) );

		if ( empty( $images ) ) {
			$variation->set_image_id( '' );
			$variation->set_gallery_image_ids( array() );
			return $variation;
		}

		$gallery   = array();
		$parent_id = $variation->get_parent_id() ?: 0;

		foreach ( $images as $index => $image ) {
			if ( ! is_array( $image ) ) {
				continue;
			}

			$attachment_id = isset( $image['id'] ) ? absint( $image['id'] ) : 0;

			if ( 0 === $attachment_id && isset( $image['src'] ) ) {
				$upload = wc_rest_upload_image_from_url( esc_url_raw( $image['src'] ) );

				if ( is_wp_error( $upload ) ) {
					$this->log_error(
						sprintf(
							'Variation image upload failed for %s: %s',
							$image['src'],
							$upload->get_error_message()
						),
						$variation->get_id()
					);
					continue;
				}

				$attachment_id = wc_rest_set_uploaded_image_as_attachment( $upload, $parent_id );
			}

			if ( ! wp_attachment_is_image( $attachment_id ) ) {
				continue;
			}

			if ( 0 === $index ) {
				$variation->set_image_id( $attachment_id );
			} else {
				$gallery[] = $attachment_id;
			}

			if ( ! empty( $image['alt'] ) ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', wc_clean( $image['alt'] ) );
			}

			if ( ! empty( $image['name'] ) ) {
				wp_update_post(
					array(
						'ID'         => $attachment_id,
						'post_title' => $image['name'],
					)
				);
			}
		}

		$variation->set_gallery_image_ids( $gallery );

		return $variation;
	}

	/**
	 * Log an error message via error_log when WP_DEBUG is enabled.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    string $message       Error message.
	 * @param    int    $variation_id  Optional variation ID for context.
	 */
	private function log_error( $message, $variation_id = 0 ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$context = $variation_id ? " [Variation ID: {$variation_id}]" : '';
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "[{$this->plugin_name}]{$context} {$message}" );
		}
	}
}
