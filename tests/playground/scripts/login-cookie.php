<?php
// Auth cookies for user 1 so the suite can open wp-admin over plain HTTP. Test only.
require __DIR__ . '/_bootstrap.php';
$user    = get_user_by( 'id', 1 );
$expire  = time() + 2 * HOUR_IN_SECONDS;
$manager = WP_Session_Tokens::get_instance( $user->ID );
$token   = $manager->create( $expire );
nwr_out(
	array(
		'cookies' => array(
			AUTH_COOKIE      => wp_generate_auth_cookie( $user->ID, $expire, 'auth', $token ),
			LOGGED_IN_COOKIE => wp_generate_auth_cookie( $user->ID, $expire, 'logged_in', $token ),
		),
	)
);
