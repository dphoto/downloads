<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

	require_once( 'database2.php' );

	$db = new Database2( );
	$user = $db->selectAll( "SELECT * FROM users WHERE user_id = 1" );

	echo "Hello";
	var_dump( $user);

?>
