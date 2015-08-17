<?php
defined('MOODLE_INTERNAL') || die;
global $OUTPUT, $CFG, $DB, $PAGE;
$quiz_nitroreportpdf_latex_db_options_type = array('latex2image','mathml2image','latex2mathml','mathml2latex');
$quiz_nitroreportpdf_latex_db_options_typesender = array('HTTP-GET','HTTP-POST');
$quiz_nitroreportpdf_latex_db_options_format = array('JPG','GIF','PNG','JSON-TEXT','TEXT');
$options_declaration = array();
$options_declaration['DECLARATION_NOTMUSTBE'] = get_string('DECLARATION_NOTMUSTBE','quiz_nitroreportpdf');
$options_declaration['DECLARATION_MUSTBE'] = get_string('DECLARATION_MUSTBE','quiz_nitroreportpdf');
$options_declaration['DECLARATION_CHOOSE'] = get_string('DECLARATION_CHOOSE','quiz_nitroreportpdf');


/////////////////////////// Main Settings ///////////////////////////

$settings->add ( new admin_setting_heading ( 'main_settings', get_string('main_settings','quiz_nitroreportpdf'), '' ) );

$settings->add(new admin_setting_configselect('quiz_nitroreportpdf/declaration',get_string('declaration_page','quiz_nitroreportpdf'),get_string('declaration_desc','quiz_nitroreportpdf'), 'DECLARATION_NOTMUSTBE', $options_declaration));
$settings->add(new admin_setting_confightmleditor('quiz_nitroreportpdf/contact',get_string('contact','quiz_nitroreportpdf'),get_string('contact_desc','quiz_nitroreportpdf'),''));

/////////////////////////// LATEX SETTINGS ///////////////////////////
if(!empty($_POST['url'])):
	$record = new stdClass();
	$record->url = strip_tags($_POST['url']);
	$record->options_url = strip_tags($_POST['options_url']);
	$record->type = strip_tags($_POST['type']);
	$record->typesender = strip_tags($_POST['typesender']);
	$record->format = strip_tags($_POST['format']);
	$record->path = strip_tags($_POST['path']);
	$number = $DB->count_records_sql('SELECT count(id) FROM {quiz_nitroreportpdf_latex_db} WHERE url="'.strip_tags($_POST['url']).'"');
	if($number == 0):
	$DB->insert_record('quiz_nitroreportpdf_latex_db', $record, false);
	endif;	
endif;

if($_GET['action'] == 'delete'):
	$DB->delete_records('quiz_nitroreportpdf_latex_db',array('id' => $_GET['id']));
endif;

$str='';
$a = $DB->get_records('quiz_nitroreportpdf_latex_db');

foreach($a as $id => $record):
$str.='
<table>
	<tr>
		<th>'.get_string('field_url','quiz_nitroreportpdf').':</th>
		<td>'.$record->url.'</td>
	</tr>
	<tr>
		<th>'.get_string('field_extraoptions','quiz_nitroreportpdf').':</th>
		<td>'.$record->options_url.'</td>
	</tr>
	<tr>
		<th>'.get_string('field_type','quiz_nitroreportpdf').':</th>
		<td>'.$record->type.'</td>
	</tr>
	<tr>
		<th>'.get_string('field_typesender','quiz_nitroreportpdf').':</th>
		<td>'.$record->typesender.'</td>
	</tr>
	<tr>
		<th>'.get_string('field_format','quiz_nitroreportpdf').':</th>
		<td>'.$record->format.'</td>
	</tr>
	<tr>
		<th>'.get_string('field_path','quiz_nitroreportpdf').':</th>
		<td>'.$record->path.'</td>
	</tr>
	<tr>
		<th>'.get_string('actions','quiz_nitroreportpdf').':</th>
		<td>'.($OUTPUT->action_link (new moodle_url ( '/admin/settings.php?section=modsettingsquizcatnitroreportpdf', array ('action' => 'delete', 'id' => $id )),'['.get_string('delete','quiz_nitroreportpdf').']' )).'</td>
	</tr>
</table>
<hr size="1">';
endforeach;

$str.='
<p style="text-align:center;"><h3>'.get_string('addnewserver','quiz_nitroreportpdf').'</h3></p>
<table>
	<tr>
		<th>'.get_string('field_url','quiz_nitroreportpdf').':</th>
		<td><input type="text" name="url"></td>
	</tr>
	<tr>
		<th>'.get_string('field_extraoptions','quiz_nitroreportpdf').':</th>
		<td><input type="text" name="options_url"></td>
	</tr>
	<tr>
		<th>'.get_string('field_type','quiz_nitroreportpdf').':</th>
		<td><select name="type">';
foreach($quiz_nitroreportpdf_latex_db_options_type as $option)
	$str.='<option value="'.$option.'">'.$option.'</option>';
	$str.='</select></td>
	</tr>
	<tr>
		<th>'.get_string('field_typesender','quiz_nitroreportpdf').':</th>
		<td><select name="typesender">';
foreach($quiz_nitroreportpdf_latex_db_options_typesender as $option)
	$str.='<option value="'.$option.'">'.$option.'</option>';
	$str.='</select></td>
	</tr>
	<tr>
		<th>'.get_string('field_format','quiz_nitroreportpdf').':</th>
		<td><select name="format">';
foreach($quiz_nitroreportpdf_latex_db_options_format as $option)
	$str.='<option value="'.$option.'">'.$option.'</option>';
	$str.='</select></td>
	</tr>
	<tr>
		<th>'.get_string('field_path','quiz_nitroreportpdf').':</th>
		<td><input type="text" name="path"></td>
	</tr>
</table>';

$str.='<br>';
$str .= get_string('in_field','quiz_nitroreportpdf'). ' <b>"'.get_string('field_url','quiz_nitroreportpdf').'"</b> '.get_string('desc_url','quiz_nitroreportpdf').'.<br>';
$str .= get_string('in_field','quiz_nitroreportpdf'). ' <b>"'.get_string('field_extraoptions','quiz_nitroreportpdf').'"</b> '.get_string('desc_extraoptions','quiz_nitroreportpdf').'.<br>';
$str .= get_string('in_select','quiz_nitroreportpdf'). ' <b>"'.get_string('field_type','quiz_nitroreportpdf').'"</b> '.get_string('desc_type','quiz_nitroreportpdf').'.<br>';
$str .= get_string('in_select','quiz_nitroreportpdf'). ' <b>"'.get_string('field_typesender','quiz_nitroreportpdf').'"</b> '.get_string('desc_typesender','quiz_nitroreportpdf').'.<br>';
$str .= get_string('in_select','quiz_nitroreportpdf'). ' <b>"'.get_string('field_format','quiz_nitroreportpdf').'"</b> '.get_string('desc_format','quiz_nitroreportpdf').'.<br>';
$str .= get_string('in_field','quiz_nitroreportpdf'). ' <b>"'.get_string('field_path','quiz_nitroreportpdf').'"</b> '.get_string('desc_path','quiz_nitroreportpdf').'.<br>';

$settings->add ( new admin_setting_heading ( 'latex_settings', 'LaTEX Settings', $str ) );