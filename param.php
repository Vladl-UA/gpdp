<html>
<meta http-equiv="content-type" content="text/html; charset=windows-1251" />
<head>
<link rel="stylesheet" type="text/css" href="main.css">
	<title><?echo "Óïðàâëåíèå äàííûìè!" ?></title>
</head>
<body>
<pre>
<?php


global $GP;
global$new_line_index;
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
 $new_line_index = insert_line ($GP); edit_line ($GP); delete_line ($GP);

if ($GP['table'] != "main" && !is_null($GP['rel_main'])){ $main_index = $GP['rel_main'];}
if ($GP['table'] != "main" && !is_null($GP['dep_main'])){ $main_index = $GP['dep_main'];}
if ($GP['table'] == "main" && !is_null($GP['index'])) { $main_index = $GP['index'];}
if ($GP['table'] == "main" && !is_null($new_line_index)){ $main_index = $new_line_index;}
if ($GP['action'] == "edit") {form_build_edit($GP); return;}
if ($GP['action'] == "new"){form_build_new($GP); return;}	
if (is_null($main_index)){echo "new"; $GP[table]='main'; form_build_new($GP);}
if (!is_null($main_index) && $GP['action'] != 'edit'){ db_tree("main", $main_index,"","");}

$GP[table] = "main";
t_reader($GP, 1);
?></pre></body></html>
