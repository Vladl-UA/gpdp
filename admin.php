<pre>
<?php
require 'voc.php';
require 'config.php';
$c_date = mktime (0, 0, 0, $GP[dat_m], $GP[dat_day], $GP[dat_god]);

    $link = mysql_connect(DB_HOST, DB_USER, DB_PASS) or die("Could not connect : " . mysql_error());
    mysql_select_db(DB_NAME) or die("Could not select database");

t_reader("main"); 

form_build('main');

     	
?>
    </pre>