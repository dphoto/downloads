<?php
	
	require_once( 'database.php' );

	$db = new Database( 'invoice-generator' );

	if ( !key_exists( 'album_key', $_REQUEST ) || !key_exists( 'auth_key', $_REQUEST ) )
		die( "No authorisation!!" );

	$album_key = $db->validate( $_REQUEST['album_key'], false );
	$auth_key = $db->validate( $_REQUEST['auth_key'], false );

	// Get user info by hostname
	$user_id = getUserID( $_SERVER['HTTP_HOST'] );
	if ( !$user_id ) die( 'Invalid hostname?' );

	// Get album info by user ID and album key
	$album = $db->select( "SELECT *
		FROM `albums`
		WHERE `user_id` = '$user_id'
		AND `album_key` = '$album_key'
	", 'row' );
	$album_id = $album['album_id'];
	if ( !$album_id ) die( 'Invalid album key!' );

	// Check auth validity using album ID and auth key
	$valid_auth = $db->select( "SELECT `auth_id`
		FROM `guest_auth`
		WHERE `album_id` = '$album_id'
		AND `auth_key` = '$auth_key'
	", 'value' );

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

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset='utf-8'>
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?php echo $album['album_name']; ?> - DPHOTO Guest upload</title>
    <script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js"></script>
</head>
<body>

	<p>Upload to ...</p>

	<h1><?php echo $album['album_name']; ?></h1>

	<?php if ( count( $guest_uploads ) > 0 ) : ?>

		<ul>

			<?php foreach ( $guest_uploads as $upload ) : ?><li>
				
				One upload?
			
			</li><?php endforeach; ?>

		</ul>

	<?php else: ?>

		<p>No files uploaded!</p>
		<p>Why not upload one?</p>

	<?php endif; ?>

</body>
</html>