<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

	require_once( 'database.php' );

	$db = new Database( 'test' );
	$user = $db->select( "SELECT * FROM users WHERE user_id = 1", 'row' );

	echo "Hello";
	var_dump( $user);

?>
