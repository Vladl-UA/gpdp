<?php
# Читает таблицу и выводит как есть. Или одну строку. или группу строк :)
function t_reader($GP, $ignore_index = 0) {
	if (isset ( $GP [table] )) {
		$t_name = $GP [table];
	} else {
		return "Не выбран объект";
	}
	if (isset ( $GP ['index'] ) && ! is_array ( $GP ['index'] )) {
		$index = $GP ['index'];
		$querry_select = ("select * from `$t_name` WHERE `index` = '$index';");
	}
	if (is_array ( $GP ['index'] )) {
		$count = count ( $GP ['index'] );
		foreach ( $GP ['index'] as $value ) {
			$count --;
			if ($count == 0) {
				$sep = "";
			} else {
				$sep = ", ";
			}
			$indexes .= "'" . $value . "'" . $sep;
		}
		$querry_select = ("select * from `$t_name` WHERE `index` in ($indexes);");
	}
	
	if (! isset ( $GP ['index'] ) || $ignore_index == 1) {
		$querry_select = ("select * from $GP[table];");
	}
	$table_name = subscr ( $t_name, 'data_table' );
	echo "<div class=\"tablename\" >$table_name</div>";
	$fields = list_fields ( $GP [table] );
	$num_cols = count ( $filds );
	$data_tes = mysql_query ( $querry_select );
	echo "<table class = \"$t_name\" >\n";
	t_header ( $fields );
		
	while ( $row = mysql_fetch_array ( $data_tes ) ) {
		echo "<form>\n<tr>";
		foreach ( $fields as $value ) {
			
			if (preg_match ( "/data_/", $value )) {
				$data = $row [$value];
				echo "<td>$data</td>";
			}
			if (preg_match ( "/enn_*/", $value )) {
				$data = $row [$value];
				echo "<td>$data</td>";
			}
			if (preg_match ( "/date/", $value )) {
				$data = $row [$value];
				echo "<td>$data</td>";
			}
			if (preg_match ( "/bul_*/", $value )) {
				if ($row [$value] != 0) {
					$data = "да";
				} else {
					$data = "нет";
				}
				echo "<td>$data</td>";
			}
			if (preg_match ( "/^prm_*/", $value )) {
				if ($row [$value] != 0) {
					$data = "да";
				} else {
					$data = "нет";
				}
				echo "<td>$data</td>";
			}
			if (preg_match ( "/footnotes/", $value )) {
				$data = $row [$value];
				echo "<td>$data</td>";
			}
			if (preg_match ( "/timestamp/", $value )) {
				$data = $row [$value];
				echo "<td>$data</td>";
			}
			if (preg_match ( "/voc_*/", $value )) {
				$data = voc ( $value, $row [$value], $item, "read" );
				echo "<td>$data</td>";
			}
			if (preg_match ( "/mch_*/", $value )) {
				$data = mch ( $value, $row [$value], $item, "read" );
				echo "<td>$data</td>";
			}
			
			if (preg_match ( "/index/", $value )) {
				$data .= "<input type=\"hidden\" name=\"table\" value=\"$GP[table]\">";
				$data .= "<input type=\"hidden\" name=\"action\" value=\"edit\">";
				$data .= "<input type=\"hidden\" name=\"index\" value=\"$row[$value]\"><input type=\"submit\" value=\"К\">";
				echo "<td>$data</td>";
			}
			if (preg_match ( "/dep_*/", $value )) {
				$data = ffh ( "hidden", "", $value, $row [$value] );
				echo "$data";
			}
			if (preg_match ( "/rel_main/", $value )) {
				$data = ffh ( "hidden", "", $value, $row [$value] );
				echo $data;
			}
			
			if (preg_match ( "/mlt_*/", $value )) {
				$names = split ( "[]:[]", $value, 2 );
				$subscr = subscr ( $names [1], 'data_fild' );
				$data = mlt ( $names [0], $row [$value], $names [1], 0, "read" );
				echo "<td>$data</td>";
			}
		
		}
		
		echo "</tr>\n</form>\n";
		unset ( $data );
		reset ( $fields );
	}
	echo "</table>";
}
function t_header($filds) {
	echo "<thead><tr class=\"hh\">";
	foreach ( $filds as $value ) {
		$name = subscr ( $value, "fld" );
		if (empty ( $name )) {
		} else {
			echo "<th>" . $name . "</th>";
		}
	}
	echo "</tr></thead>";
}
function voc($voc_name, $index, $subscr, $action) {
	$querry_list_voc = ("SELECT * FROM `" . $voc_name . "` LIMIT 0, 30;");
	$querry_find_voc = ("SELECT * FROM `" . $voc_name . "` WHERE `index` = " . $index . ";");
	if ($action == 'edit' || 'new') {
		$querry_voc_res = mysql_query ( $querry_list_voc );
		if (! $querry_voc_res) {
			echo "объект \"" . $fsubscr . "\" не содержит данных<br>";
		} else {
			while ( $row = mysql_fetch_array ( $querry_voc_res ) ) {
				if ($index == $row [index]) {
					$selected = 'SELECTED';
				} else {
					$selected = "";
				}
				$options .= ("<option value=" . $row [index] . " " . $selected . ">" . $row [data_name] . "</option>\n");
			}
			$ff = ('<select name="' . $voc_name . '">\n' . $options . '</select>');
			$result = ('<div class="form_fild_name">' . $subscr . '</div><div class="form_element">' . $ff . '<div>');
		}
	}
	if ($action == 'read') {
		$querry_voc_res = mysql_query ( $querry_find_voc );
		mysql_error ();
		$row = mysql_fetch_array ( $querry_voc_res );
		$result = $row [data_name];
	}
	return $result;
}
# Извлечение данных из таблиц типа МУЛЬТИ
function mlt($mlt_name, $index, $param, $subscr, $action) {
	$querry_list = ("SELECT * FROM `" . $mlt_name . "` WHERE `" . $param . "` = '1' LIMIT 0, 30;");
	$querry_find = ("SELECT * FROM `" . $mlt_name . "` WHERE `index` = " . $index . ";");
	if ($action == 'edit' || 'new') {
		$querry_mlt_res = mysql_query ( $querry_list );
		if (! $querry_mlt_res) {
			echo "объект \"" . $fsubscr . "\" не содержит данных<br>";
		} else {
			while ( $row = mysql_fetch_array ( $querry_mlt_res ) ) {
				if ($index == $row [index]) {
					$selected = 'SELECTED';
				} else {
					$selected = "";
				}
				$option .= ("<option value=\"$row[index]\" $selected>$row[data_name]</option>");
			}
			$ff = ("<select name=\"$mlt_name:$param\">$option</select>");
			$result = ('<div class="form_fild_name">' . $subscr . '</div><div class="form_element">' . $ff . '<div>');
		}
	}
	if ($action == 'read') {
		$querry_res = mysql_query ( $querry_find );
		mysql_error ();
		$row = mysql_fetch_array ( $querry_res );
		$result = $row [data_name];
	}
	return $result;
}
function mch($mch_name, $index, $subscr, $action) { # $voc_name - имя таблицы со словарем; $index - индекс позиции; $subscr - подпись для формы
	

	if (isset ( $index )) {
		$index_arr = explode ( ",", $index );
	}
	echo $count = count ( $index_arr );
	foreach ( $index_arr as $value ) {
		$count --;
		if ($count == 0) {
			$sep = "";
		} else {
			$sep = ", ";
		}
		$indexes .= "'" . $value . "'" . $sep;
	}
	
	if ($action == 'edit' || $action == 'new') {
		$querry_list_mlt = ("SELECT * FROM `" . $mch_name . "` LIMIT 0, 30;");
		$querry_mlt_res = mysql_query ( $querry_list_mlt );
		if (! $querry_mlt_res) {
			echo "объект \"" . $subscr . "\" не содержит данных<br>";
		} else {
			while ( $row = mysql_fetch_array ( $querry_mlt_res ) ) {
				if (in_array ( $row [index], $index_arr )) {
					$selected = 'SELECTED';
				} else {
					$selected = "";
				}
				$option .= ("<option value=\"$row[index]\" $selected >$row[data_name]</option>");
			}
			if ($GP ['action'] == "new") {
				$selected = "SELECTED";
			}
			$option .= ("<option value=\"0\" $selected >Нет</option>");
			$size = mysql_num_rows ( $querry_mlt_res );
			$ff = ('<select name="' . $mch_name . '[]" multiple size = "' . ++ $size . '">' . $option . '</select>');
			$result = ('<div class="form_fild_name">' . $subscr . '</div><div class="form_element">' . $ff . '<div>');
		}
	}
	if ($action == "read") {
		$querry_find = ("SELECT * FROM `" . $mch_name . "` WHERE `index` IN (" . $indexes . ");");
		$querry_res = mysql_query ( $querry_find );
		mysql_error ();
		if (mysql_num_rows ( $querry_res ) == 0) {
			$result = "";
		} else {
			while ( $row = mysql_fetch_array ( $querry_res ) ) {
				$result .= $row [data_name] . "+<br>";
			}
		}
	}
	
	return $result;
}
function ffh($type, $subscr, $name, $value) {
	$text = ('<input type="text" name="' . $name . '" size = "' . $size . '" value="' . $value . '" maxlength="' . $maxlength . '"> ');
	$hidden = ("<input type=\"hidden\" name=\"$name\" value=\"$value\" >");
	if ($value == 1) {
		$checked = "checked";
	}
	$checkbox = ('<input type="checkbox" name="' . $name . '" id="" value="1" "' . $checked . '"/>');
	$radio = ('<input type="radio" name="' . $name . '" id="" value="' . $value . '" />');
	$submit = ('<input type="submit" value="' . $value . '" />');
	$textarea = ('<textarea name="' . $name . '" rows="5" cols="40">' . $value . '</textarea>');
	switch ($type) {
		case "hidden" :
			$formfild = $hidden;
			break;
		case "text" :
			$formfild = $text;
			break;
		case "textarea" :
			$formfild = $textarea;
			break;
		case "checkbox" :
			$formfild = $checkbox;
			break;
	}
	if ($type == 'hidden') {
		$fild = $formfild;
	} else {
		$fild = ('<div class="form_fild_name">' . $subscr . '</div><div class="form_element">' . $formfild . "</div>\n");
	}
	return $fild;
}

function subscr($fild, $part) {
	switch ($part) { #Расшифровка сокращений для обращения к столбцам таблицы с подписями.
		case "fild" :
			$part = "data_fild";
			break;
		case "fld" :
			$part = "data_fld";
			break;
		case "table" :
			$part = "data_table";
			break;
		case "tbl" :
			$part = "data_tbl";
			break;
	}
	$res = "SELECT `" . $part . "` FROM subscr WHERE `data_element` = '" . $fild . "';";
	$subscr = mysql_fetch_array ( mysql_query ( $res ) );
	return $subscr [0];
}
function form_build_new($GP) {
	#	if ($GP[action] != "new") {echo "<form action=\"test.php\"><input type=\"hidden\" name=\"table\" value=\"" . $GP[table] . "\"><input type=\"submit\" name=\"action\" value=\"form_new\"> </form>";}
	if (isset ( $GP [table] )) {
		$t_name = $GP [table];
	} else {
		return;
	}
	$form_name = subscr ( $t_name, 'data_table' );
	echo "<form> <fieldset><legend>Добавить запись: " . $form_name . "</legend>\n";
	echo "<input type=\"hidden\" name=\"table\" value=\"" . $t_name . "\">";
	echo "<input type=\"hidden\" name=\"action\" value=\"new_now\">";
	$filds = list_fields ( $t_name );
	while ( $fild = each ( $filds ) ) {
		$subscr = subscr ( $fild [1], 'data_fild' );
		if (preg_match ( "/^index/", $fild [1] )) {
			echo ffh ( "hidden", $subscr, $fild [1], '' );
		}
		if (preg_match ( "/^enn_*/", $fild [1] )) {
			echo enn ( $t_name, $fild [1], $subscr, '' );
		}
		if (preg_match ( "/^data_*/", $fild [1] )) {
			echo ffh ( "text", $subscr, $fild [1], '' );
		}
		if (preg_match ( "/^footnotes/", $fild [1] )) {
			echo ffh ( "textarea", $subscr, $fild [1], '' );
		}
		if (preg_match ( "/^date/", $fild [1] )) {
			echo date_ ( "" );
		}
		if (preg_match ( "/^voc_*/", $fild [1] )) {
			echo voc ( $fild [1], 0, $subscr, 'new' );
		}
		if (preg_match ( "/^mch_*/", $fild [1] )) {
			echo mch ( $fild [1], 0, $subscr, "new" );
		}
		if (preg_match ( "/^bul_*/", $fild [1] )) {
			echo ffh ( "checkbox", $subscr, $fild [1], '' );
		}
		if (preg_match ( "/^(prm_)(.*)$/", $fild [1] )) {
			echo ffh ( "checkbox", $subscr, $fild [1], '' );
		}
		if (preg_match ( "/^dep_*/", $fild [1] )) {
			echo ffh ( "hidden", $subscr, $fild [1], $GP [$fild [1]] );
		}
		if (preg_match ( "/^rel_main/", $fild [1] )) {
			echo ffh ( "hidden", $subscr, $fild [1], $GP ["rel_main"] );
		}
		if (preg_match ( "/^mlt_*/", $fild [1] )) {
			$names = split ( "[]:[]", $fild [1], 2 );
			$subscr = subscr ( $names [1], 'data_fild' );
			echo mlt ( $names [0], 0, $names [1], $subscr, "new" );
		}
	}
	echo "<input type=\"submit\" value=\"Добавить запись\"></fieldset></form>";
}
function form_build_edit($GP) {
	if (isset ( $GP [table] )) {
		$table = $GP [table];
	} else {
		$item = '0';
	}
	echo $data = read_line ( $GP [index], $GP [table] );
	$form_name = subscr ( $table, 'data_table' );
	$filds = list_fields ( $table );
	echo "<form> <fieldset><legend>Изменить запись: " . $form_name . "</legend>\n";
	echo "<input type=\"hidden\" name=\"table\" value=\"" . $table . "\">";
	echo "<input type=\"hidden\" name=\"action\" value=\"edit_now\">";
	$filds = list_fields ( $table );
	while ( $fild = each ( $filds ) ) {
		$subscr = subscr ( $fild [1], 'data_fild' );
		if (preg_match ( "/^dep_*/", $fild [1] )) {
			echo ffh ( "hidden", $subscr, $fild [1], $data [$fild [1]] );
		}
		if (preg_match ( "/^rel_main/", $fild [1] )) {
			echo ffh ( "hidden", $subscr, $fild [1], $data [$fild [1]] );
		}
		if (preg_match ( "/^index/", $fild [1] )) {
			echo ffh ( "hidden", $subscr, $fild [1], $data [$fild [1]] );
		}
		if (preg_match ( "/^data_*/", $fild [1] )) {
			echo ffh ( "text", $subscr, $fild [1], $data [$fild [1]] );
		}
		if (preg_match ( "/^enn_*/", $fild [1] )) {
			echo enn ( $table, $fild [1], $subscr, $data [$fild [1]] );
		}
		if (preg_match ( "/^footnotes/", $fild [1] )) {
			echo ffh ( "textarea", $subscr, $fild [1], $data [$fild [1]] );
		}
		if (preg_match ( "/^date/", $fild [1] )) {
			echo date_ ( $data [$fild [1]] );
		}
		if (preg_match ( "/^voc_*/", $fild [1] )) {
			echo voc ( $fild [1], $data [$fild [1]], $subscr, 'edit' );
		}
		if (preg_match ( "/^mch_*/", $fild [1] )) {
			echo mch ( $fild [1], $data [$fild [1]], $subscr, "edit" );
		}
		if (preg_match ( "/^bul_*/", $fild [1] )) {
			echo ffh ( "checkbox", $subscr, $fild [1], $data [$fild [1]] );
		}
		if (preg_match ( "/^prm_*/", $fild [1] )) {
			echo ffh ( "checkbox", $subscr, $fild [1], $data [$fild [1]] );
		}
		
		if (preg_match ( "/^mlt_*/", $fild [1] )) {
			$names = split ( "[]:[]", $fild [1], 2 );
			$subscr = subscr ( $names [1], 'data_fild' );
			echo mlt ( $names [0], $data [$fild [1]], $names [1], $subscr, "edit" );
		}
	}
	echo "<input type=\"submit\" value=\"Изменить\"></fieldset></form>";
	echo "<form><fieldset><legend>Удаление записи</legend>";
	echo "<div class=\" delete\"><input type=\"hidden\" name=\"table\" value=\"" . $table . "\">";
	echo "<input type=\"hidden\" name=\"index\" value=\"" . $GP [index] . "\">";
	echo "<input type=\"hidden\" name=\"rel_main\" value=\"" . $GP [rel_main] . "\">";
	echo "<input type=\"hidden\" name=\"action\" value=\"delete_now\">";
	echo "<input type=\"submit\" value=\"Удалить запись\"></div></fieldset></form>";
}
function read_line($index, $table) {
	$row = mysql_fetch_assoc ( mysql_query ( "SELECT * FROM `" . $table . "` WHERE `index` = " . $index . ";" ) );
	return $row;
}
function list_fields($t_name) {
	$querry_colnums = ("SHOW COLUMNS FROM " . DB_NAME . "." . $t_name . "");
	$colnums_res = mysql_query ( $querry_colnums );
	while ( $row = mysql_fetch_array ( $colnums_res ) ) {
		$filds [] = $row [0];
	}
	return $filds;
}
function insert_line($GP) {
	if ($GP [action] != "new_now") {
		return;
	}
	$filds = list_fields ( $GP [table] );
	$num_filds = count ( $filds );
	if (isset ( $GP [day] ) and isset ( $GP [month] ) and isset ( $GP [year] )) {
		$GP [date] = $GP [year] . "-" . $GP [month] . "-" . $GP [day];
	}
	while ( $fild = each ( $filds ) ) {
		$num_filds --;
		if ($num_filds == 0) {
			$coma = "";
		} else {
			$coma = ", ";
		}
		$into .= "`" . $fild [1] . "`" . $coma;
		if (is_array ( $GP [$fild [1]] )) {
			$GP [$fild [1]] = implode ( ",", $GP [$fild [1]] );
		}
		$values .= "'" . $GP [$fild [1]] . "'" . $coma;
	}
	echo $insert_querry = "INSERT INTO `" . $GP [table] . "` (" . $into . ") VALUES (" . $values . ");";
	
	mysql_query ( $insert_querry );
	$result = mysql_insert_id ();
	return $result;
}
function edit_line($GP) {
	if ($GP [action] != "edit_now") {
		return;
	}
	$filds = list_fields ( $GP [table] );
	$num_filds = count ( $filds );
	if (isset ( $GP [day] ) and isset ( $GP [month] ) and isset ( $GP [year] )) {
		$GP [date] = $GP [year] . "-" . $GP [month] . "-" . $GP [day];
	}
	while ( $fild = each ( $filds ) ) {
		$num_filds --;
		if ($num_filds == 0) {
			$coma = "";
		} else {
			$coma = ", ";
		}
		if (is_array ( $GP [$fild [1]] )) {
			$GP [$fild [1]] = implode ( ",", $GP [$fild [1]] );
		}
		if ($fild [1] != "index") {
			$update .= "`" . $fild [1] . "` = '" . $GP [$fild [1]] . "'" . $coma;
		}
	}
	$insert_querry = "UPDATE `" . $GP [table] . "` SET " . $update . " WHERE `index` = " . $GP [index] . ";";
	$inserting = mysql_query ( $insert_querry );
}
function delete_line($GP) {
	if (isset ( $GP [action] ) and $GP [action] != 'delete_now') {
		return;
	}
	$intem_del = mysql_query ( "DELETE FROM `" . $GP [table] . "` WHERE `index` = '" . $GP [index] . "' LIMIT 1;" );
	#echo "deleted - " . mysql_affected_rows() . "line(s)";
}
function menu_tables() {
	$tables_res = mysql_list_tables ( 'stat' );
	while ( $r = mysql_fetch_array ( $tables_res ) ) {
		$s [] = $r [0];
	}
	echo "<table><tr>";
	foreach ( $s as $key ) {
		$count ++;
		if (preg_match ( "/voc_*/", $key ) || preg_match ( "/mlt_*/", $key ) || preg_match ( "/mch_*/", $key ) || preg_match ( "/subscr/", $key )) {
		} else {
			$sub = subscr ( $key, 'table' );
			if (is_null ( $sub )) {
				$sub = $key;
			}
			echo "<td><form><input type=\"hidden\" name=\"table\" value=\"" . $key . "\">";
			echo "<input type=\"submit\" value=\"" . $sub . "\"></form></td>";
			if ($count == 10) {
				echo "</tr><tr>";
				$count = 0;
			}
		}
	}
	echo "</tr></table>";
}
function menu_serv_tables() {
	$tables_res = mysql_list_tables ( 'stat' );
	while ( $row = mysql_fetch_array ( $tables_res ) ) {
		$s [] = $row [0];
	}
	echo "<table><tr>";
	foreach ( $s as $key ) {
		$count ++;
		if (preg_match ( "/voc_*/", $key ) || preg_match ( "/mlt_*/", $key ) || preg_match ( "/mch_*/", $key ) || preg_match ( "/subscr/", $key )) {
			$sub = subscr ( $key, 'table' );
			if (is_null ( $sub )) {
				$sub = $key;
			}
			echo "<td><form><input type=\"hidden\" name=\"table\" value=\"$key\">";
			echo "<input type=\"submit\" value=\"$sub\"></form></td>";
			if ($count == 8) {
				echo "</tr><tr>";
				$count = 0;
			}
		}
	}
	echo "</tr></table>";
}
function enn($table, $field, $subscr, $data) {
	$values = array ();
	$sql = "SHOW COLUMNS FROM `" . $table . "` LIKE '" . $field . "'";
	$res = mysql_query ( $sql );
	if (mysql_num_rows ( $res )) {
		$values_t = mysql_result ( $res, 0, 1 );
		$values_t = explode ( "(", $values_t );
		$values_t = explode ( ")", $values_t [1] );
		$values = explode ( ",", strtolower ( trim ( $values_t [0] ) ) );
		$vvv = str_replace ( "'", "", $values );
	} #print_r($vvv);
	foreach ( $vvv as $value ) {
		if ($value == $data) {
			$selected = 'SELECTED';
		} else {
			$selected = "";
		}
		$options .= ("<option value=" . $value . " " . $selected . ">" . $value . "</option>\n");
	}
	$ff = ('<select name="' . $field . '">\n' . $options . '</select>');
	$result = ('<div class="form_fild_name">' . $subscr . '</div><div class="form_element">' . $ff . '<div>');
	return $result;
}
function date_($dt) {
	$date_in = explode ( "-", $dt );
	foreach ( range ( 2000, 2009 ) as $key => $value ) {
		if ($value == $date_in [0]) {
			$selected = 'SELECTED';
		} else {
			$selected = "";
		}
		$opt_year .= ("<option value=" . $value . " " . $selected . ">" . $value . "</option>");
	}
	$years = ('<select name="year">' . $opt_year . '</select>');
	
	foreach ( range ( 01, 12 ) as $key => $value ) {
		if ($value == $date_in [1]) {
			$selected = 'SELECTED';
		} else {
			$selected = "";
		}
		if ($value < 10) {
			$value = "0" . $value;
		}
		$opt_month .= ("<option value=" . $value . " " . $selected . ">" . $value . "</option>");
	}
	$month = ('<select name="month">' . $opt_month . '</select>');
	foreach ( range ( 01, 31 ) as $key => $value ) {
		if ($value == $date_in [2]) {
			$selected = 'SELECTED';
		} else {
			$selected = "";
		}
		if ($value < 10) {
			$value = "0" . $value;
		}
		$opt_days .= ("<option value=" . $value . " " . $selected . ">" . $value . "</option>");
	}
	$days = ('<select name="day">\n' . $opt_days . '</select>');
	$result = ('<div class="form_fild_name">Дата</div><div class="form_element">' . $years . "-" . $month . "-" . $days . '<div>');
	return $result;
}

function find_dep($t_name) {
	$the_fild = "dep_" . $t_name;
	
	$tables_res = mysql_list_tables ( "stat" );
	while ( $row = mysql_fetch_array ( $tables_res ) ) {
		
		$filds = list_fields ( $row [0] );
		if (in_array ( $the_fild, $filds )) {
			$tables [] = $row [0];
		}
	}
	if (! isset ( $tables )) {
		$result = "non";
		return $result;
	} else {
		return $tables;
	}
}
function related_records($t_name, $uper_table, $upper_index) {
	$fild = "dep_$uper_table";
	$querry = ("SELECT * FROM `$t_name` WHERE `$fild` = '$upper_index';");
	$res = mysql_query ( $querry );
	$num = @mysql_num_rows ( $res );
	if (is_null ( $num )) {
		$result = "0";
	} else {
		while ( $row = mysql_fetch_assoc ( $res ) ) {
			$result [] = $row [index];
		}
	}
	return $result;
}
function button_new($t_name, $main_index, $upper_index, $upper_table) {
	$subscr = subscr ( $t_name, "table" );
	$result = "<form><input type=\"hidden\" name=\"table\" value=\"$t_name\">";
	$result .= "<input type=\"hidden\" name=\"dep_$upper_table\" value=\"$upper_index\">";
	$result .= "<input type=\"hidden\" name=\"action\" value=\"new\">";
	$result .= "<input type=\"hidden\" name=\"rel_main\" value=\"$main_index\">";
	$result .= "<input type=\"submit\"  value=\"Новая запись в: $subscr\"></form>";
	return $result;
}
function db_tree($t_name, $main_index, $upper_table, $upper_index) {
	$depth = find_dep ( $t_name );
	# первая итерация
	if ($t_name == 'main' && ! is_null ( $main_index )) {
		$upper_index = $main_index;
		$GP ['table'] = 'main';
		$GP ['index'] = $main_index;
		t_reader ( $GP, $main_index );
		foreach ( $depth as $value ) {
			db_tree ( $value, $main_index, $t_name, $upper_index );
		}
	} #Последующие итерации	
else {
		$records = related_records ( $t_name, $upper_table, $upper_index );
		#	print_r($records);
		if ($records == 0) {
			echo button_new ( $t_name, $main_index, $upper_index, $upper_table );
			return;
		}
		if ($depth != 0) {
			#	print_r ( $records );
			foreach ( $records as $value ) {
				$current_index = $value;
				$tt ['table'] = $t_name;
				$tt ['index'] = $value;
				t_reader ( $tt );
				foreach ( $depth as $value ) {
					db_tree ( $value, $main_index, $t_name, $current_index );
				}
			}
			echo button_new ( $t_name, $main_index, $upper_index, $upper_table );
		}
		
		if ($depth == 0) {
			$tt ['table'] = $t_name;
			$tt ['index'] = $records;
			t_reader ( $tt );
			echo button_new ( $t_name, $main_index, $upper_index, $upper_table );
		}
	
	}
}
?>