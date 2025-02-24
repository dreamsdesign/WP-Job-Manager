<?php
/**
 * File containing the class WP_Job_Manager_Install.
 *
 * @package wp-job-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the installation of the WP Job Manager plugin.
 *
 * @since 1.0.0
 */
class WP_Job_Manager_Install {

	/**
	 * Installs WP Job Manager.
	 */
	public static function install() {
		global $wpdb;

		self::init_user_roles();
		self::default_terms();

		$is_new_install = false;

		// Fresh installs should be prompted to set up their instance.
		if ( ! get_option( 'wp_job_manager_version' ) ) {
			include_once JOB_MANAGER_PLUGIN_DIR . '/includes/admin/class-wp-job-manager-admin-notices.php';
			WP_Job_Manager_Admin_Notices::add_notice( WP_Job_Manager_Admin_Notices::NOTICE_CORE_SETUP );
			$is_new_install = true;
			set_transient( '_job_manager_activation_redirect', 1, HOUR_IN_SECONDS );
		}

		// Update featured posts ordering.
		if ( version_compare( get_option( 'wp_job_manager_version', JOB_MANAGER_VERSION ), '1.22.0', '<' ) ) {
			$wpdb->query( "UPDATE {$wpdb->posts} p SET p.menu_order = 0 WHERE p.post_type='job_listing';" );
			$wpdb->query( "UPDATE {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id SET p.menu_order = -1 WHERE pm.meta_key = '_featured' AND pm.meta_value='1' AND p.post_type='job_listing';" );
		}

		// Update default term meta with employment types.
		if ( version_compare( get_option( 'wp_job_manager_version', JOB_MANAGER_VERSION ), '1.28.0', '<' ) ) {
			self::add_employment_types();
		}

		// Update legacy options.
		if ( false === get_option( 'job_manager_submit_job_form_page_id', false ) && get_option( 'job_manager_submit_page_slug' ) ) {
			$page_id = get_page_by_path( get_option( 'job_manager_submit_page_slug' ) )->ID;
			update_option( 'job_manager_submit_job_form_page_id', $page_id );
		}
		if ( false === get_option( 'job_manager_job_dashboard_page_id', false ) && get_option( 'job_manager_job_dashboard_page_slug' ) ) {
			$page_id = get_page_by_path( get_option( 'job_manager_job_dashboard_page_slug' ) )->ID;
			update_option( 'job_manager_job_dashboard_page_id', $page_id );
		}

		if ( $is_new_install ) {
			$permalink_options                 = (array) json_decode( get_option( 'job_manager_permalinks', '[]' ), true );
			$permalink_options['jobs_archive'] = '';
			update_option( 'job_manager_permalinks', wp_json_encode( $permalink_options ) );
		}

		delete_transient( 'wp_job_manager_addons_html' );
		update_option( 'wp_job_manager_version', JOB_MANAGER_VERSION );
	}

	/**
	 * Initializes user roles.
	 */
	private static function init_user_roles() {
		global $wp_roles;

		if ( class_exists( 'WP_Roles' ) && ! isset( $wp_roles ) ) {
			$wp_roles = new WP_Roles();
		}

		if ( is_object( $wp_roles ) ) {
			add_role(
				'employer',
				__( 'Employer', 'wp-job-manager' ),
				array(
					'read'         => true,
					'edit_posts'   => false,
					'delete_posts' => false,
				)
			);

			$capabilities = self::get_core_capabilities();

			foreach ( $capabilities as $cap_group ) {
				foreach ( $cap_group as $cap ) {
					$wp_roles->add_cap( 'administrator', $cap );
				}
			}
		}
	}

	/**
	 * Returns capabilities.
	 *
	 * @return array
	 */
	private static function get_core_capabilities() {
		return array(
			'core'        => array(
				'manage_job_listings',
			),
			'job_listing' => array(
				'edit_job_listing',
				'read_job_listing',
				'delete_job_listing',
				'edit_job_listings',
				'edit_others_job_listings',
				'publish_job_listings',
				'read_private_job_listings',
				'delete_job_listings',
				'delete_private_job_listings',
				'delete_published_job_listings',
				'delete_others_job_listings',
				'edit_private_job_listings',
				'edit_published_job_listings',
				'manage_job_listing_terms',
				'edit_job_listing_terms',
				'delete_job_listing_terms',
				'assign_job_listing_terms',
			),
		);
	}

	/**
	 * Sets up the default WP Job Manager terms.
	 */
	private static function default_terms() {
		if ( 1 === intval( get_option( 'job_manager_installed_terms' ) ) ) {
			return;
		}

		$taxonomies = self::get_default_taxonomy_terms();
		foreach ( $taxonomies as $taxonomy => $terms ) {
			foreach ( $terms as $term => $meta ) {
				if ( ! get_term_by( 'slug', sanitize_title( $term ), $taxonomy ) ) {
					$tt_package = wp_insert_term( $term, $taxonomy );
					if ( is_array( $tt_package ) && isset( $tt_package['term_id'] ) && ! empty( $meta ) ) {
						foreach ( $meta as $meta_key => $meta_value ) {
							add_term_meta( $tt_package['term_id'], $meta_key, $meta_value );
						}
					}
				}
			}
		}

		update_option( 'job_manager_installed_terms', 1 );
	}

	/**
	 * Default taxonomy terms to set up in WP Job Manager.
	 *
	 * @return array Default taxonomy terms.
	 */
	private static function get_default_taxonomy_terms() {
		return array(
			'job_listing_type' => array(
				'Full Time'  => array(
					'employment_type' => 'FULL_TIME',
				),
				'Part Time'  => array(
					'employment_type' => 'PART_TIME',
				),
				'Temporary'  => array(
					'employment_type' => 'TEMPORARY',
				),
				'Freelance'  => array(
					'employment_type' => 'CONTRACTOR',
				),
				'Internship' => array(
					'employment_type' => 'INTERN',
				),
			),
		);
	}

	/**
	 * Adds the employment type to default job types when updating from a previous WP Job Manager version.
	 */
	private static function add_employment_types() {
		$taxonomies = self::get_default_taxonomy_terms();
		$terms      = $taxonomies['job_listing_type'];

		foreach ( $terms as $term => $meta ) {
			$term = get_term_by( 'slug', sanitize_title( $term ), 'job_listing_type' );
			if ( $term ) {
				foreach ( $meta as $meta_key => $meta_value ) {
					if ( ! get_term_meta( (int) $term->term_id, $meta_key, true ) ) {
						add_term_meta( (int) $term->term_id, $meta_key, $meta_value );
					}
				}
			}
		}
	}
}
