<?php

	session_start(); 

	$keys = explode( ',', $_REQUEST['key'] );
	$nokey_array = array( 'error' => 'nokey' );

	$response = array();

	foreach ( $keys as $key ) {
		$this_response = '';
		$this_key = ini_get("session.upload_progress.prefix") . $key;
		$response[$key] = ( !key_exists( $this_key, $_SESSION ) ) ? $nokey_array : $_SESSION[$this_key];
	}
	echo json_encode( $response );

?>