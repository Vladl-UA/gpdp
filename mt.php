<html>
<head>
<link rel="stylesheet" type="text/css" href="main.css">
	<title><?echo "ÈÈÈÈÈÕÕÕÕÀÀÀÀÀÀÀÀÀÀÀ!!!!!!" ?></title>
</head>
<body>
<pre>
<?php
global $GP;
if (_METHOD == "POST") {
	$GP = $_POST;
} else {
	$GP = $_GET;
}
require 'voc.php';
require 'config.php';
	$c_date = mktime ( 0, 0, 0, $GP [dat_m], $GP [dat_day], $GP [dat_god] );

	$link = mysql_connect ( DB_HOST, DB_USER, DB_PASS ) or die ( "Could not connect : " . mysql_error () );
	mysql_select_db ( DB_NAME ) or die ( "Could not select database" );
#####################    
	menu_tables ();
print_r ( $GP );
	insert_line ($GP);
	edit_line ($GP);
	delete_line ($GP);
	if (isset($GP[action]) and $GP[action] == 'edit') {form_build_edit ($GP);}else {form_build_new ($GP);}
	t_reader ($GP, 1);
#	print_r($GLOBALS);
?></body>
    </pre>