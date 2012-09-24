<?php

	require_once( 'database.php' );

	$db = new Database( 'guest-upload' );

	$guest_id = $_REQUEST['guest_id'];

	$json_response = array( 'error' => 'noresults' );

	$safe_guest_id = $db->validate( $guest_id );
	$file_ids = $db->select( "
		SELECT GROUP_CONCAT( f.file_id ) 
		FROM `files_guests` fg 
		LEFT JOIN `files` f ON fg.`file_id` = f.`file_id` 
		WHERE fg.`guest_id` = $safe_guest_id
		AND f.`file_uploaded` > DATE_SUB( NOW(), INTERVAL 5 MINUTE) 
		GROUP BY fg.`guest_id` 
		ORDER BY f.`file_uploaded` 
		DESC 
		LIMIT 5
	", 'value' );

	// API app credentials
	$api_creds = array(
		'app_key'        => 'aab10566b2dce8e5b4470894f6f20a28',
		'app_secret'     => '331e8c45a8d51ae8c974ecf3053781e8'
	);

	// Load api helper
	require_once( '1.1/dphoto.1.1.internal.php' );
	$api = new DPHOTO( $api_creds );

	// Set current working user
	$api->set_user( 18898, '0f6715764566ca441e1e2cbda88f6281' );

	if ( $file_ids ) {
		$json_response = array();
		$files = $api->file_getInfo( $file_ids, 'thumb' )['result'];
		if ( key_exists( 'file_id', $files ) ) {
			$files = array( $files );
		}
		foreach ( $files as $file ) {

			$json_response[] = array(
				'file_id'       => $file['file_id'],
				'file_url'      => $file['file_thumb_url'],
				'file_width'    => $file['file_thumb_width'],
				'file_height'   => $file['file_thumb_height']
			);

		}
	}

	// Output JSON results
	header( 'Content-type: application/json' );
	echo json_encode( $json_response );
	exit;

?>