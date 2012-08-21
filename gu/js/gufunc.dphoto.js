
	var globGU = {

		// Variables
		"pollingInterval": 1000,
		"recentPollingInterval": 8000,
		"nextIframeID": 0,
		"totalFiles": 0,
		"completedFiles": 0,

		"uploadTimer": null,
		"recentTimer": null,
		"progressTimer": null,

		"isUploading": false,
		"currentFile": null,

		"bytesProcessed": 0,
		"contentLength": 0,

		// Arrays
		"uploadedFileIDs": [],
		"progressKeys": [],
		"progressData": [],
		"uploads": [],
		"fileQueue": [],
		"completed": [],

		// Objects
		"uploadProgress": {},
		"recentFiles": {},
		"debugging": {
			"enabled": false
		},

		// Functions
		log: function( msg ) {
			if ( !this.debugging.enabled ) return;
			console.log( msg );
		}

	};

	jQuery( document ).ready( function() {

		update_file_upload_progress();
		update_file_progress();
		get_recently_completed_files();

		// On file upload field change
		jQuery( '#fileUploadField' ).change( function() {
			submit_file_upload();
		} );

		// On form submit trigger
		jQuery( "#uploadForm" ).bind( "submit", function() {

		} );

		// On progress update
		jQuery( document ).bind( "progressUpdate", function() {
			calculateTotals();
		} );

		// On click 'select files' trigger upload field click
		jQuery( '#fileUploadTrigger' ).click( function() {
			jQuery( '#fileUploadField' ).click();
		} );

		globGU.log( 'ready' );

	} );

	function submit_file_upload() {

		var theFiles = jQuery( '#fileUploadField' ).get( 0 ).files;

		for ( i = 0; i < theFiles.length; i++ ) {
			globGU.contentLength = globGU.contentLength + theFiles[i].size;
			globGU.fileQueue[globGU.fileQueue.length] = theFiles[i];
		}

	}

	function add_recently_completed_file( file ) {

		var theHTML = '<li id="file-' + file.file_id + '" style="display: none;">'
			+ '<img src="' + file.file_url + '" height="' + file.file_height + '" width="' + file.file_width + '" />'
			+ '</li>';

		jQuery( '#recentlyCompleted' ).append( theHTML ).find( 'li#file-' + file.file_id ).children( 'img' ).load( function() {
			jQuery( this ).parent().fadeIn();
			globGU.recentFiles[file.file_id] = file;
		} );

	}

	function get_recently_completed_files() {

		var checkFilesURL = jQuery( '#check_files_url' ).val();

		jQuery.get( checkFilesURL, function( data ) {

			var recentFilesJSON = data;

			if ( recentFilesJSON && typeof recentFilesJSON == 'object' && !( 'error' in recentFilesJSON ) ) {

				for ( var i = 0; i < recentFilesJSON.length; i++ ) {

					if ( recentFilesJSON[i].file_id in globGU.recentFiles )
						continue;

					add_recently_completed_file( recentFilesJSON[i] );

				}

			}

		} );

		globGU.recentTimer = setTimeout( get_recently_completed_files, globGU.recentPollingInterval );

	}

	function update_file_upload_progress() {

		globGU.uploadTimer = setTimeout( update_file_upload_progress, globGU.pollingInterval );

		if ( globGU.isUploading ) {
			return;
		}

		if ( globGU.fileQueue.length > 0 ) {

			globGU.currentFile = globGU.fileQueue.splice( 0, 1 );
			globGU.isUploading = true;

			var unixNow = Math.round( ( new Date() ).getTime() / 1000 );
			globGU.currentFile[0].trackingKey = jQuery( 'input[name=auth_key]' ).val() + unixNow;
			globGU.currentFile[0].started = true;
			globGU.currentFile[0].completed = false;
			globGU.currentFile[0].bytes_processed = 0;

			var postData = new FormData();
			postData.append( jQuery( "#upload_tracking_key" ).attr( 'name' ), globGU.currentFile[0].trackingKey );
			postData.append( "auth_key", jQuery( 'input[name=auth_key]' ).val() );
			postData.append( "album_key", jQuery( 'input[name=album_key]' ).val() );
			postData.append( "u", jQuery( 'input[name=u]' ).val() );
			postData.append( "uploadedFile[]", globGU.currentFile[0] );

			calculateTotals();

			jQuery.ajax( {
				"url":               'index.php',
				"type":              'POST',
				"data":              postData,
				"processData":       false,
				"contentType":       false,
				"beforeSend":        function( xhr ) {
					jQuery( xhr ).bind( "readystatechange", function (e) { alert("changed " + e.target.readyState); } );
				},
				"success":           function( json ) { 

					globGU.log( 'received back from ajax' ); 
					globGU.log( json );

					globGU.currentFile[0].file_id = json[0].result.file_id;
					globGU.currentFile[0].completed = true;
					globGU.currentFile[0].bytes_processed = globGU.currentFile[0].size;
					globGU.completed.push( globGU.currentFile.splice( 0, 1 )[0] );

					globGU.isUploading = false;
					globGU.currentFile = null;

					calculateTotals();

				}
			} );

		}

	}

	function update_file_progress() {

		globGU.progressTimer = setTimeout( update_file_progress, globGU.pollingInterval );

		if ( !globGU.isUploading )
			return;

		var postData = new FormData();
		postData.append( "key", globGU.currentFile[0].trackingKey );

		jQuery.ajax( {
			"url":            jQuery( '#status_url' ).val(),
			"type":           'POST',
			"data":           postData,
			"processData":    false,
			"contentType":    false,
			"success":        function( json ) {

				json = jQuery.parseJSON( json );
				if ( json && typeof json == 'object' ) {

					if ( !globGU.isUploading || 'error' in json[globGU.currentFile[0].trackingKey] )
						return;

					globGU.currentFile[0].bytes_processed = json[globGU.currentFile[0].trackingKey].bytes_processed;

					calculateTotals();

				}

			}
		} );

	}

	function calculateTotals() {

		globGU.log( 'Calculating totals ...' );

		var totals = {
			'bytes_processed': 0,
			'content_length': globGU.contentLength,
			'completed_files': globGU.completed.length,
			'total_files': globGU.fileQueue.length + globGU.completed.length + ( ( globGU.isUploading ) ? 1 : 0 )
		};

		if ( globGU.isUploading ) {
			totals.bytes_processed = totals.bytes_processed + globGU.currentFile[0].bytes_processed;
		}

		for ( x in globGU.completed ) {
			totals.bytes_processed = totals.bytes_processed + globGU.completed[x].bytes_processed;
		}

		globGU.log( totals );
		globGU.log( globGU.uploadProgress );

		globGU.completedFiles = totals.completed_files;
		globGU.totalFiles = totals.total_files;

		setProgressPercent( ( totals.bytes_processed / totals.content_length ) * 100 );

		return totals;

	}

	function setProgressPercent( newValue ) {

		var pContainer = jQuery( '#overallProgress' );
		var pBar = pContainer.children( 'div.progress' );
		var pDetails = pContainer.children( 'span.details' );

		var newValue = newValue.toFixed( 2 ) + '%';

		var completedNum = ( globGU.completedFiles == globGU.totalFiles ) ? globGU.completedFiles : globGU.completedFiles + 1;

		pDetails.html( completedNum + ' of ' + globGU.totalFiles );
		pBar.animate( { "width": newValue }, 300 );

	}