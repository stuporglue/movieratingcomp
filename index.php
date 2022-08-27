<?php

/**
 * Make an infographic type page showing the movie rating mapping between two countries
 *
 * Data comes from themoviedb.org and is stored in the sqlite database. See api_calls.php
 * to see the table structure.
 */

// Default option, since we're in Germany at the moment
$from = 'DE';
$to = 'US';

// Allow override
if ( !empty($_GET['from']) && !empty($_GET['to']) ) {
	$from = $_GET['from'];
	$to = $_GET['to'];
}

// Make friendly names since not everyone knows the country codes, and it looks nicer.
$countries = array(
	'AU' => 'Austria',
	'BG' => 'Bulgaria',
	'BR' => 'Brazil',
	'CA' => 'Canada',
	'DE' => 'Germany',
	'DK' => 'Denmark',
	'FI' => 'Finland',
	'FR' => 'France',
	'GB' => 'Great Britain',
	'HU' => 'Hungary',
	'IN' => 'India',
	'IT' => 'Italy',
	'LT' => 'Lithuania',
	'MY' => 'Malaysia',
	'NL' => 'Netherlands',
	'NZ' => 'New Zealand',
	'NO' => 'Norway',
	'PH' => 'Philippines',
	'PT' => 'Portugal',
	'RU' => 'Russia',
	'ES' => 'Spain',
	'SE' => 'Sweden',
	'US' => 'United States',
);

// The Sankey js needs a list of nodes and a list of links.
$nodes = array();
$links = array();

// SQLite location
$db = new SQLite3('ratings.sqlite'); 


// Now get the links.
{

	$seen_fcerts = array();
	$seen_tcerts = array();

	$rating_query = '

	SELECT
	f.certification fcert,
	t.certification tcert,
	COUNT(*) num
	FROM
	ratings f, ratings t,
	certifications fc,
	certifications tc
	WHERE
	f.movie_id=t.movie_id

	AND f.iso_3166_1=:from
	AND f.release_type=3
	AND f.certification <> "NR"
	AND f.certification = fc.certification
	AND f.iso_3166_1=fc.iso_3166_1

	AND t.iso_3166_1=:to
	AND t.release_type=3
	AND t.certification <> "NR"
	AND t.certification = tc.certification
	AND t.iso_3166_1=tc.iso_3166_1
	';

	if ( !empty($_GET['fromdate'] ) ) {
		$rating_query .= 'AND f.release_date >= :fromdate
			AND t.release_date >= :fromdate ';
	}

	if ( !empty($_GET['todate'] ) ) {
		$rating_query .= 'AND f.release_date <= :todate
			AND t.release_date <= :todate ';
	}

	$rating_query .= '
		GROUP BY 
			f.certification,
			t.certification;';

	$certq = $db->prepare($rating_query);
	$certq->bindValue(':from',$from);
	$certq->bindValue(':to',$to);

	if ( !empty($_GET['fromdate'] ) ){
		$certq->bindValue(':fromdate',$_GET['fromdate']);
	}

	if ( !empty($_GET['todate'] ) ){
		$certq->bindValue(':todate',$_GET['todate']);
	}

	$res = $certq->execute();

	while($row = $res->fetchArray(SQLITE3_ASSOC)){
		$seen_fcerts[] = $row['fcert'];
		$seen_tcerts[] = $row['tcert'];

		$links[] = array(
			'from' => array(
				'column' => 0,
				'cert' => $row['fcert']
				// 'node' =>  array_search($row['fcert'],$from_certs)
			)	,
			'to' => array(
				'column' => 1,
				'cert' => $row['tcert']
				// 'node' => array_search($row['tcert'],$to_certs)
			),
			'value' => $row['num']
		);
	}
}

// Get the rating systems we will use. These will be the nodes.
// Except that we will exclude any ratings with no movies
{
	$from_certs = array();
	$fromq = $db->prepare('SELECT iso_3166_1,certification,meaning FROM certifications WHERE (iso_3166_1=:from OR iso_3166_1=:to) ORDER BY certorder ASC');
	$fromq->bindValue(':from',$from);
	$fromq->bindValue(':to',$to);
	$res = $fromq->execute();
	while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
		if ( $row['iso_3166_1'] == $from  && in_array($row['certification'],$seen_fcerts) ) {
			$nodes[0][] = array("label" => $row['certification'] . ' (' . $from . ')',"meaning" => $row['meaning'], "certification" => $row['certification']);
			$from_certs[] = $row['certification'];
		} 
		if ( $row['iso_3166_1'] == $to && in_array($row['certification'],$seen_tcerts) ){
			$nodes[1][] = array("label" => $row['certification'] . ' (' . $to . ')',"meaning" => $row['meaning'], "certification" => $row['certification']);
			$to_certs[] = $row['certification'];
		}
	}
}

// No go back and fill in the nodes for the link objects
foreach($links as &$link){
	$link['from']['node'] = array_search($link['from']['cert'],$from_certs);
	$link['to']['node'] = array_search($link['to']['cert'],$to_certs);
}

// We'll use this in the table later
$f_rating_to_color = array();
$t_rating_to_color = array();

// Build our color gradients for the from side
{
	foreach($from_certs as $i => $cert){
		$tmp = (1+$i) / count($from_certs);
		$nodes[0][$i]['color'] = 'rgb(' . min(255,2*255*$tmp) . ',' . min(255,2*255*(1-$tmp)) . ',0)';
		$f_rating_to_color[$cert] = $nodes[0][$i]['color'];
	}

	// Build our color gradients for the to side
	foreach($to_certs as $i => $cert){
		$tmp = (1+$i) / count($to_certs);
		$nodes[1][$i]['color'] = 'rgb(' . min(255,2*255*$tmp) . ',' . min(255,2*255*(1-$tmp)) . ',0)';
		$t_rating_to_color[$cert] = $nodes[1][$i]['color'];
	}
}

?><!DOCTYPE HTML>
<html>
	<head>
		<meta charset="utf-8">
		<title>Movie Ratings Mapping between <?php print $countries[$from];?> and <?php print $countries[$to];?></title>
		<link rel="stylesheet" href='./SanKEY.js/SanKEY_styles.css'>
		<link rel="stylesheet" href='ratings.css'>
<?php

print "<script type='module'>\n";
print "import {PlotCreator} from './SanKEY.js/SanKEY_script.js';\n";
print "let nodes = " . json_encode($nodes) . ";\n";
print "let links = " . json_encode($links) . ";\n";

print "new PlotCreator(
	document.getElementById('ratingmap'), nodes, links, 800, 600, 0, 2,
{
	plot_background_color: '#f4edf7',
default_links_opacity: 0.8,
default_gradient_links_opacity: 0.8,
	lines_style_object: {stroke:'black','stroke-opacity':0.2},
		vertical_gap_between_nodes: 0.5,
		show_column_lines: false,
		column_names_style_object: { color:'black', opacity:0.6,},
		column_names: ['{$countries[$from]}','{$countries[$to]}']
		}
	)";
print "</script>";
?>
	</head>
	<body>
	<h1><?php print $countries[$from];?> to <?php print $countries[$to];?> Movie Rating Mappings</h1>
		<form>
			<label for="from">From:</label>
			<select name="from">
<?php
foreach($countries as $iso => $label){
	print "<option value='$iso' " . ($from==$iso ? 'selected="selected"' : '') . ">" . $label . "</option>";
}
?>
			</select>

			<label for="to">To:</label>
			<select name="to">
<?php
foreach($countries as $iso => $label){
	print "<option value='$iso' " . ($to==$iso ? 'selected="selected"' : '') . ">" . $label . "</option>";
}
?>
			</select>

			<br>
			<br>
			<label for="fromdate">Earliest Date</label><input type="date" name="fromdate" <?php if ( !empty($_GET['fromdate']) ){ print "value='{$_GET['fromdate']}'"; }?>></label>
			<label for="todate">Latest Date</label><input type="date" name="todate" <?php if ( !empty($_GET['todate']) ){ print "value='{$_GET['todate']}'"; }?>></label>
			<br>
			<br>
			<input type="submit" value="Change">
		</form>

		<div id="ratingmap"></div>
<?php

print "<h2>Certifications for " . $countries[$from] . "</h2>";
print "<table>";
print "<tr><th>Certification</th><th>Definition</th></tr>";
foreach($nodes[0] as $cert){
	print "<tr><td>" . $cert['label'] . "</td><td>" . $cert['meaning'] . "</td></tr>";
}
print "</table>";

print "<h2>Certifications for " . $countries[$to] . "</h2>";
print "<table>";
print "<tr><th>Certification</th><th>Definition</th></tr>";
foreach($nodes[1] as $cert){
	print "<tr><td>" . $cert['label'] . "</td><td>" . $cert['meaning'] . "</td></tr>";
}
print "</table>";
?>
<h2>Top 50 Movies with the Biggest Ratings Differences</h2>
<?php

$diffq =
	"SELECT

	DISTINCT 
	m.movie_id,
	m.title,
	m.release_date,
	f.iso_3166_1 fiso,
	f.certification fcert,
	t.iso_3166_1 tiso,
	t.certification tcert
FROM
ratings f, ratings t,
certifications fc,
certifications tc,
movies m
WHERE
f.movie_id=t.movie_id
AND f.movie_id=m.movie_id";

if ( !empty($_GET['fromdate']) ) {
	$diffq .= " AND f.release_date >= :fromdate ";
	$diffq .= " AND t.release_date >= :fromdate ";
}

if ( !empty($_GET['todate']) ) {
	$diffq .= " AND f.release_date <= :todate";
	$diffq .= " AND t.release_date <= :todate";
}

$diffq .= "

AND f.iso_3166_1=:from
AND f.release_type=3
AND f.certification <> 'NR'
AND f.certification = fc.certification
AND f.iso_3166_1=fc.iso_3166_1

AND t.iso_3166_1=:to
AND t.release_type=3
AND t.certification <> 'NR'
AND t.certification = tc.certification
AND t.iso_3166_1=tc.iso_3166_1

ORDER BY ABS(
					CAST(fc.certorder AS REAL) / ( 
					SELECT certorder 
					FROM certifications
					WHERE iso_3166_1=:from
					ORDER BY certorder DESC
					LIMIT 1
				) - CAST(tc.certorder AS REAL) / (
					SELECT certorder 
					FROM certifications
					WHERE iso_3166_1=:to
					ORDER BY certorder DESC
				)
			)
		DESC

LIMIT 50
";

$diffq = $db->prepare($diffq);
$diffq->bindValue(':from',$from);
$diffq->bindValue(':to',$to);

if ( !empty($_GET['fromdate'] ) ){
	$diffq->bindValue(':fromdate',$_GET['fromdate']);
}

if ( !empty($_GET['todate'] ) ){
	$diffq->bindValue(':todate',$_GET['todate']);
}

$res = $diffq->execute();


print '<table>';
print '<tr><th>Title</th><th>Release Date</th><th>Rating in ' . $countries[$from] . '</th><th>Rating in ' . $countries[$to] . '</th><tr>';
while($row = $res->fetchArray(SQLITE3_ASSOC)){
	print '<tr>';
	print "<td><a href='https://www.themoviedb.org/movie/{$row['movie_id']}' rel='nofollow' target='_blank'>" . htmlentities($row['title']) . '</td>';
	print '<td>' . htmlentities($row['release_date']) . '</td>';
	print '<td style="background-color:' . $f_rating_to_color[$row['fcert']] . '">' . $row['fcert'] . '</td>';
	print '<td style="background-color:' . $t_rating_to_color[$row['tcert']] . '">' . $row['tcert'] . '</td>';
	print '</tr>';
}
?>

</table>
<h3>Info</h3>
<?php

$total_movies = 0;
foreach($links as $link) {
	$total_movies += $link['value'];
}

print "I found " . $total_movies . " movies with a theatrical release in both " . $countries[$from] . " and " . $countries[$to];
if ( !empty($_GET['fromdate']) && !empty($_GET['todate']) ) {
	print " between " . $_GET['fromdate'] . " and " . $_GET['todate'] . ". ";
} else if ( !empty($_GET['fromdate']) ) {
	print " after . " . $_GET['fromdate'] . ". ";
} else if ( !empty($_GET['todate']) ) {
	print " before . " . $_GET['todate'] . ". ";
} else {
	print ". ";
}
print "I excluded any movies with a listed NR (Not Rated) entry in the data, since NR is not an official rating. If you notice something that is wrong, please consider submiting a correction to themoviedb.org. ";
?>
<ul>
<li>All data from <a href="https://www.themoviedb.org/">https://www.themoviedb.org/</a>.</li>
<li>Sankey diagram code from <a href="https://github.com/Krzysiekzd/SanKEY.js">https://github.com/Krzysiekzd/SanKEY.js</a></li>
	</body>
</html>
