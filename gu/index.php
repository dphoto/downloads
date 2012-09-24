<?php
	
	session_start();

	require_once( 'database.php' );

	$db = new Database( 'guest-upload' );

	if ( !key_exists( 'album_key', $_REQUEST ) || !key_exists( 'auth_key', $_REQUEST ) )
		die( "No authorisation!!" );

	$album_key = $db->validate( $_REQUEST['album_key'], false );
	$auth_key = $db->validate( $_REQUEST['auth_key'], false );

	// Get user ID by hostname
	$user_id = getUserID( $_SERVER['HTTP_HOST'] );
	if ( !$user_id ) $user_id = getUserID( $_REQUEST['u'] );
	if ( !$user_id ) die( 'Invalid user ID?' );

	// Get user info
	$user = $db->select( "SELECT * FROM `users` WHERE `user_id` = $user_id LIMIT 1", 'row' );

	// Get album info by user ID and album key
	$album = $db->select( "SELECT *
		FROM `albums`
		WHERE `user_id` = '$user_id'
		AND `album_key` = '$album_key'
	", 'row' );
	$album_id = $album['album_id'];
	if ( !$album_id ) die( 'Invalid album key!' );

	// Check auth validity using album ID and auth key
	$auth = $db->select( "SELECT *
		FROM `guests`
		WHERE `album_id` = '$album_id'
		AND `guest_key` = '$auth_key'
	", 'row' );
	$valid_auth = ( key_exists( 'guest_id', $auth ) && $auth['guest_id'] > 0 ) ? true : false;
	if ( !$valid_auth ) die( "Invalid authorisation!!" );

	// Get list of files already uploaded (if any)
	$guest_uploads = array();

	// Gabarble!! ^_^
	function getUserID( $hostname ) {
		if ( stripos( $hostname, '.dphoto.com' ) !== false ) {
			$username = substr( $hostname, 0, stripos( $hostname, ".dphoto.com" ) );
			if ( stripos( $username, 'www.' ) !== false )
				$username = substr( $username, 4 );
		} 
		else {
			if ( stripos( $hostname, 'www.' ) === 0 ) $hostname = substr( $hostname, 4 );
			return $GLOBALS['db']->select( "SELECT user_id FROM galleries WHERE gallery_domain LIKE '%$hostname%'", 'value' );
		}
		return $GLOBALS['db']->select( "SELECT user_id FROM `users` WHERE user_username = '$username'", 'value' );
	}

	$upload_success = false;
	$result = array();

	if ( key_exists( 'uploadedFile', $_FILES ) ) {

		foreach ( $_FILES['uploadedFile']['error'] as $key => $error ) {

			if ( $error == UPLOAD_ERR_OK ) {

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

				// The file
				$tmp_file = $_FILES['uploadedFile']['tmp_name'][$key];
				$file = '/tmp/' . $_FILES['uploadedFile']['name'][$key];
				move_uploaded_file( $tmp_file, $file );

				// The method
				$method = 'file.upload';
				$params = array(
					'album_id' => $album_key,
					'file' => "@" . realpath( $file )
				);
				
				$response = $api->execute( 'file.upload', $params );
				$result[] = &$response;

				if ( 'ok' == $response['status'] ) {

					$db->insert( 'files_guests', array(
						'file_id'    => $response['result']['file_id'],
						'user_id'    => $user_id,
						'guest_id'   => $auth['guest_id']
					) );

					$upload_success = true;

				}

				unlink( realpath( $file ) );

			}

		}

	}

	// Upload success returns JSON info about uploaded files
	if ( $upload_success ) {
		header( 'Content-type: application/json' );
		echo json_encode( $result );
		exit();
	}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset='utf-8'>
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?php echo $album['album_name']; ?> - DPHOTO Guest upload</title>
	<?php if ( !key_exists( 'iframe', $_GET ) ) : ?>
   		<script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js"></script>
    	<script type="text/javascript" src="js/gufunc.dphoto.js"></script>
    <?php endif; ?>

    <style>
    	
    	body { background: #f2f2f2; font-family: sans-serif; }
    	iframe { border: 0; height: 1px; margin: 0; width: 1px; }

    	div.heading { padding: 10px 0; position: relative; text-align: center; }
    	div.heading div { border-bottom: 1px solid #fff; border-top: 1px solid #ddd; }
    	div.heading h2 { background-color: #f1f1f1; color: #999; font-size: 12px; font-weight: normal; left: 295px; line-height: 20px; margin: 0; position: absolute; text-shadow: 0 1px 0 rgba(255,255,255,0.9); top: 0px; width: 150px; }

    	#container { width: 800px; margin: 0 auto; }

    	#uploadForm { margin: 20px 0; }

    	#dropZone { color: #bbb; font-size: 32px; line-height: 120px; padding-bottom: 40px; position: relative; text-align: center; text-shadow: 0 1px 0 rgba(255,255,255,0.9); }
    	#dropZone input { left: 270px; position: absolute; top: 90px; width: 200px; }

    	#activeUploads {  }
    	#completedUploads ul { list-style-type: none; }
    	#completedUploads ul li {  }

    	#completedUploads {  }
    	#completedUploads ul { list-style-type: none; }
    	#completedUploads ul li { display: inline-block; }

    	#overallProgress { background-color: #ddd; border-radius: 5px; box-shadow: inset 2px 2px 5px rgba(0,0,0,0.1); height: 40px; margin: 20px 0; position: relative; }
    	#overallProgress div.progress { height: 40px; overflow: hidden; width: 0%; }
    	#overallProgress div.progress div.progCol { background-color: #0a0; border-radius: 5px; height: 40px; width: 800px; }
    	#overallProgress span.details { color: #666; font-size: 12px; line-height: 40px; position: absolute; right: 10px; text-shadow: 0 1px 0 rgba(255,255,255,0.8); top: 0px; }

    	#uploadArea { border: 2px dashed #ddd; border-radius: 10px; height: 230px; position: relative; text-align: center; }
    	#uploadArea input#fileUploadField { bottom: 0; left: 0; position: absolute; opacity: 0; text-indent: -9999px; right: 0; top: 0; z-index: 100; }
    	#uploadArea div.viewable { position: absolute; top: 70px; width: 100%; }
    	#uploadArea div.viewable h2 { display: block; margin: 0 0 20px; }
    	#uploadArea div.viewable button#fileUploadTrigger {  }

    	#recentlyCompleted { list-style-type: none; margin-top: 0; padding: 0; }
    	#recentlyCompleted li { display: inline-block; margin-right: 20px; }


    	.hidden { display: none; }

    	#file_upload_progress { background-color: #eee; }

    	#submitFrames { display: none; }

    </style>
</head>
<body>

	<div id="container">

		<!-- Upload Form -->
		<form id="uploadForm" action="index.php" method="post" enctype="multipart/form-data" target="uploadFrame">

			<input type="hidden" id="upload_tracking_key" name="<?php echo ini_get("session.upload_progress.name"); ?>" value="" />

			<input type="hidden" name="album_key" value="<?php echo $album_key; ?>" />
			<input type="hidden" name="auth_key" value="<?php echo $auth_key; ?>" />
			<input type="hidden" name="u" value="<?php echo $_REQUEST['u']; ?>" />

			<div id="uploadArea">

				<input type="file" name="uploadedFile[]" id="fileUploadField" multiple="multiple" />

				<div class="viewable">
					<h2>Drag your photos and videos here</h2>
					<button type="button" id="fileUploadTrigger">Select Files</button>
				</div>

			</div>

		</form>

		<!-- Upload Static Vars -->
		<input type="hidden" id="auth_key" value="<?php echo $_REQUEST['auth_key']; ?>" />
		<input type="hidden" id="status_url" value="status.php" />
		<input type="hidden" id="check_files_url" value="get-images.php?guest_id=<?php echo $auth['guest_id']; ?>" />

		<!-- Submission Frames -->
		<div id="submitFrames">

		</div>

		<!-- Progress Bar -->
		<div id="overallProgress">
			<div class="progress">
				<div class="progCol"></div>
			</div>
			<span class="details">0%</span>
		</div>

		<!-- Recently Completed -->
		<ul id="recentlyCompleted">

		</ul>

	</div><!-- #container -->

</body>
</html>