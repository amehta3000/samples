<?php

class Events_Mods {

	private $single_organizer_label = 'Promoter';
	private $plural_organizer_label = 'Promoters';
	private $login_url = '/login/';
	private $community_template = 'page-community.php';
	private $submission_message = 'Your event is submitted and waiting for approval.  You\'ll be notified via email when it is live.';
	private static $instance;

	public static function init() {
		if ( ! self::instance()->community_plugin_is_active() ) {
			return;
		}
		self::instance()->hook();
		self::instance()->init_fields();
	}

	function init_fields() {

		require_once 'events-mods/community-field.php';

		// add promoter city, state, country, featured image, facebook, twitter
		new Community_Field( 'city', 'text', 'organizer' );
		new Community_Field( 'state', 'state', 'organizer' );
		new Community_Field( 'country', 'country', 'organizer' );
		new Community_Field( 'organizer_image', 'featured-image', 'organizer' );
		new Community_Field( 'twitter', 'text', 'organizer', 'Twitter URL', 'Twitter' );
		new Community_Field( 'facebook', 'text', 'organizer', 'Facebook URL', 'Facebook' );
	}

	private function community_plugin_is_active() {
		include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

		return is_plugin_active( 'the-events-calendar-community-events/tribe-community-events.php' );
	}

	private function hook() {

		// change "Organizer" to "Promoter"
		add_filter( 'tribe_organizer_label_singular', array( $this, 'single_organizer_label' ) );
		add_filter( 'tribe_organizer_label_plural', array( $this, 'plural_organizer_label' ) );

		// alter the update messages
		add_filter( 'tribe_community_events_form_errors', array( $this, 'filter_output_message' ) );
		add_filter( 'post_updated_messages', array( $this, 'filter_output_message' ), 25 );

		// properly hide the tribe bar
		// add_filter( 'tribe-events-bar-should-show', '__return_false' );

		// Access restriction management changes
		add_action( 'wp_router_alter_routes', array( $this, 'alter_community_routes' ) );
		if ( ! is_user_logged_in() ) {
			add_action( 'tribe_ce_event_submission_login_form', array( $this, 'redirect_to_login' ) );
			add_action( 'tribe_ce_event_list_login_form', array( $this, 'redirect_to_login' ) );
		}

		// add route for promoter listing
		add_action( 'wp_router_generate_routes', array( $this, 'add_promoter_listing' ) );

		// make community pages use a different template
		add_filter( 'tribe_events_community_template', array( $this, 'community_template' ) );


		// alter the message shown to promoters after they submit an event
		add_filter( 'tribe_events_community_submission_message', array( $this, 'update_message' ), 10, 2 );

		// enqueue scripts needed for xlr8r events modifications
		add_action( 'tribe_events_enqueue', array( $this, 'enqueue' ), 50 );
		add_action( 'admin_print_styles', array( $this, 'enqueue' ), 50 );

		// add_action( 'tribe_can_sell_tickets', array( $this, 'payment_options_link' ) );		

		/// make sure the admin bar always shows if the user is logged in
		if ( is_user_logged_in() ) {
			add_filter( 'show_admin_bar', '__return_true', 100 );
		}
	}

	public function single_organizer_label() {
		return $this->single_organizer_label;
	}

	public function plural_organizer_label() {
		return $this->plural_organizer_label;
	}

	public function community_template() {
		return $this->community_template;
	}

	public function update_message( $message, $type ) {
		if ( $type == 'update' ) {
			$message = str_replace( 'Event submitted.', $this->submission_message, $message );
		}

		return $message;
	}

	public function filter_output_message( $messages ) {
		if ( is_array( $messages ) ) {
			foreach ( $messages as &$message ) {
				$message = str_replace( array( 'Organizer', 'organizer' ), array(
					$this->single_organizer_label,
					strtolower( $this->single_organizer_label )
				), $message );
			}
		}

		return $messages;
	}

	public function enqueue() {
		if (true) { //( tribe_is_community_edit_event_page() ) {
			wp_enqueue_script( 'xl-events-mods', get_stylesheet_directory_uri() . '/tribe-events/events_mods.js', array(
				Tribe__Events__Main::POSTTYPE . '-premium-admin',
				'jquery'
			), null, true );
		} elseif ( function_exists( 'get_current_screen' ) ) {
			$current_screen = get_current_screen();
			if ( $current_screen->base == 'post' && $current_screen->post_type == Tribe__Events__Main::ORGANIZER_POST_TYPE ) {
				wp_enqueue_script( 'xl-events-mods', get_stylesheet_directory_uri() . '/tribe-events/events_mods.js', array(
					'tribe-events-admin',
					'jquery'
				), null, true );
			}
		}
	}

	public function add_promoter_listing( $router ) {

		if ( ! function_exists( 'tribe_community_events_list_events_link' ) ) {
			return;
		}

		$tec_template = tribe_get_option( 'tribeEventsTemplate' );

		switch ( $tec_template ) {
			case '' :
				$template_name = Tribe__Events__Templates::getTemplateHierarchy( 'default-template' );
				break;
			case 'default' :
				$template_name = 'page.php';
				break;
			default :
				$template_name = $tec_template;
		}

		$template_name = apply_filters( 'tribe_events_community_template', $template_name );

		$path = trim( str_replace( array( home_url(), 'event' ), array(
			'',
			'promoter'
		), tribe_community_events_list_events_link() ), '/' );

		$router->add_route( 'xlr8r-list-promoters-route', array(
			'path'            => '^' . $path . '(/page/(\d+))?/?$',
			'query_vars'      => array(
				'page' => 2,
			),
			'page_callback'   => array(
				$this->instance(),
				'list_promoters_callback'
			),
			'page_arguments'  => array( 'page' ),
			'access_callback' => true,
			'title'           => 'My ' . $this->plural_organizer_label,
			'template'        => $template_name,
		) );

	}

	public function list_promoters_callback() {

		add_filter( 'comments_template', array(
			Tribe__Events__Community__Main::instance(),
			'disable_comments_on_page'
		) );

		if ( ! is_user_logged_in() ) {
			return $this->redirect_to_login();
		}

		$current_user = wp_get_current_user();

		global $paged;

		if ( empty( $paged ) && ! empty( $page ) ) {
			$paged = $page;
		}

		$args = array(
			'posts_per_page' => - 1,
			'paged'          => $paged,
			'author'         => $current_user->ID,
			'post_type'      => Tribe__Events__Main::ORGANIZER_POST_TYPE,
			'post_status'    => 'any',
		);

		$promoters = new WP_Query( $args );

		tribe_get_template_part( 'community/promoter', 'list', array( 'promoters' => $promoters ) );

	}

	public function alter_community_routes( $router ) {

		if ( ! is_user_logged_in() ) {
			$router->edit_route( 'ce-edit-organizer-route', array(
				'page_callback' => array(
					self::$instance,
					'redirect_to_login'
				),
			) );
		} else {
			$router->edit_route( 'ce-edit-organizer-route', array(
				'title' => 'Edit a ' . $this->single_organizer_label,
			) );

		}
		$router->edit_route( 'ce-edit-venue-route', array(
			'page_callback' => array(
				self::$instance,
				'redirect_to_home'
			),
		) );
	}

	public function redirect_to_login() {
		wp_safe_redirect( add_query_arg( 'redirect_to', $_SERVER['REQUEST_URI'], $this->login_url ) );
		die();
	}

	public function redirect_to_home() {
		wp_safe_redirect( home_url() );
		die();
	}


	/**
	 * Hooked to the tribe_ce_after_event_list_top_buttons action to add navigation
	 */
	public function payment_options_link() {
		
		$community_tickets = Tribe__Events__Community__Tickets__Main::instance();
		if (empty($community_tickets)) {
			
		}else{
			
		}
		
		if ( ! $community_tickets->is_enabled() ) {
			return;
		}//end if

		if ( ! $community_tickets->is_split_payments_enabled() && ! current_user_can( 'edit_event_tickets' ) ) {
			return;
		}//end if

		?>
		<a href="<?php echo esc_url( $this->url() ); ?>" class="tribe-community-tickets-payment-options-link button">
			<?php echo esc_html( apply_filters( $this->hook_prefix . 'event_list_payment_options_button_text', __( 'Payment options', 'tribe-events-community-tickets' ) ) ); ?>
		</a>
		<?php
	}



	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

}