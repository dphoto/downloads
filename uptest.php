
<html>
<head>
<title>Upload Test</title>
</head>
<body>
	<form enctype="multipart/form-data" method="post" action="up.php">
		<p>
			<label for="fd_file">File Upload!!!</label>
			<input type="file" name="fd_file" id="fd_file" value="" />
		</p>
		<p>
			<label for="fd_title">File Title</label>
			<input type="text" name="fd_title" id="fd_title" value="" />
			<label for="fd_description">File Description</label>
			<input type="text" name="fd_description" id="fd_description" value="" />
			<label for="fd_tags">File Tags</label>
			<input type="text" name="fd_tags" id="fd_tags" value="" />						
		</p>
		<p>
			<button>Submit</button>
		</p>
	</form>
</body>
</html>