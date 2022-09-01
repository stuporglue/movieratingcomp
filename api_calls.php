<?php

// $just_get_missing = true;

// https://developers.themoviedb.org/
$api_key = trim(file_get_contents('./api_key.txt'));

// How big of batches to collect before inserting
$insert_size = 100; 

// SQLite location
$db = new SQLite3('ratings.sqlite'); 

// Get latest ID. We will loop from 0 up to this number
$latest = json_decode(file_get_contents("https://api.themoviedb.org/3/movie/latest?api_key={$api_key}&language=en-US"),TRUE);
$latest_id = $latest['id'];

if ( !is_numeric($latest_id) ) {
	die("Couldn't get latest ID");
}

//Make the database, if it doesn't exist

$sql = "CREATE TABLE IF NOT EXISTS certifications (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	iso_3166_1 TEXT,
	certification TEXT,
	meaning TEXT,
	certorder INTEGER,
	UNIQUE(iso_3166_1,certification)
);";
$db->exec($sql);

// One table for the movies themselves
$sql = "CREATE TABLE IF NOT EXISTS movies (
	movie_id INTEGER PRIMARY KEY,
	title TEXT,
	release_date DATE,
	adult BOOL
);";

$db->exec($sql);

// Every movie can have 0 or more releases, every release may have a rating
$sql = "CREATE TABLE IF NOT EXISTS ratings (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	movie_id INTEGER,
	iso_3166_1 TEXT,
	certification TEXT,
	note TEXT,
	release_date DATETIME,
	release_type INTEGER	
);";
$db->exec($sql);


$insert_certification = $db->prepare("INSERT INTO certifications (iso_3166_1,certification,meaning,certorder) VALUES (:iso_3166_1,:certification,:meaning,:certorder)");
$insert_movie = $db->prepare("INSERT INTO movies (movie_id,title,release_date,adult) VALUES (:movie_id,:title,:release_date,:adult)");
$insert_rating = $db->prepare("INSERT INTO ratings (movie_id,iso_3166_1,certification,note,release_date,release_type) VALUES (:movie_id,:iso_3166_1,:certification,:note,:release_date,:release_type)");


// Dump all the release date info
$movies_data = array();
$release_data = array();

// Use curl and keep connection open
$ch = curl_init();
curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
curl_setopt($ch,CURLOPT_FORBID_REUSE,false);


curl_setopt($ch,CURLOPT_URL,"https://api.themoviedb.org/3/certification/movie/list?api_key=$api_key");
if ( $res = curl_exec($ch) ) {
	$certifications = json_decode($res,TRUE);
	foreach($certifications['certifications'] as $country => $certs) {
		foreach($certs as $cert){
			$insert_certification->bindValue(':iso_3166_1',$country);
			$insert_certification->bindValue(':certification',$cert['certification']);
			$insert_certification->bindValue(':meaning',$cert['meaning']);
			$insert_certification->bindValue(':certorder',$cert['order']);
			@$insert_certification->execute();
			@$insert_certification->reset();
		}	
	}
} else {
	die("No certs found");
}


// Get all missing IDs into an array
if ( $just_get_missing ) {
	$res = $db->query("SELECT movie_id FROM movies ORDER BY movie_id");

	$fetch_me = array();

	// idx always holds the next expected value
	$idx = 1;
	while($row = $res->fetchArray(SQLITE3_ASSOC)){

		// As long as idx is smaller than expected, then we missed an ID.
		while($idx < $row['movie_id']){
			$fetch_me[] = $idx;
			$idx++;
		}

		// Now $idx should equal $row['movie_id'], which we don't add to $missing, now bump it to the next value which should be the next record
		$idx++;
	}
} else {
	// Get the starting ID from the DB or start from 0
	$movie_id = $db->querySingle("SELECT MAX(movie_id) FROM movies");
	if ( !$movie_id ) {
		$movie_id = 0;
	}
	$movie_id++;

	$fetch_me = range($movie_id,$latest_id);
}

print("00.00%");
foreach($fetch_me as $movie_id){
	$percent = str_pad(number_format($movie_id / $latest_id * 100,2),5);

	// Status update line. \33[2K\r puts the cursor at the start of the current line
	print("\33[2K\r" . $percent . '% (' . $movie_id . '/' . $latest_id . ')');

	curl_setopt($ch, CURLOPT_URL, "https://api.themoviedb.org/3/movie/$movie_id?api_key=$api_key&language=en-US&append_to_response=release_dates");
	if ( $res = curl_exec($ch) ) {
		$movie = json_decode($res,TRUE);
	} else {
		continue;
	}

	if (array_key_exists('status_code',$movie) && $movie['status_code'] == 34) {
		$movie['id'] = $movie_id;
		$movie['adult'] = false;
		$movie['title'] = "TMDB Code 34";
		$movie['release_date'] = "FALSE";
		$movie['release_dates'] = array(
			'results' => array()
		);
		}

	$movie_data = array(
		'movie_id' => $movie['id'],
		'title' => $movie['title'],
		'release_date' => $movie['release_date'],
		'adult' => $movie['adult']
	);

	$movies_data[$movie['id']] = $movie_data;

	foreach($movie['release_dates']['results'] as $country_releases){

		if ( empty($country_releases['iso_3166_1']) ) {
			continue;
		}

		foreach($country_releases['release_dates'] as $release){
			if ( empty($release['certification']) ) {
				continue;
			} else {
				@$release_data[] = array(
					'movie_id' => $movie['id'],
					'iso_3166_1' => $country_releases['iso_3166_1'],
					'certification' => $release['certification'],
					'note' => $release['note'],
					'release_date' => $release['release_date'],
					'release_type' => $release['type']
				);
			}
		}
	}

	// Gather and do bulk inserts
	if ( ($movie_id % $insert_size ) === 0 ) {
		$db->exec("BEGIN;");

		foreach($movies_data as $movie_data){
			$insert_movie->bindValue(':movie_id',$movie_data['movie_id'],SQLITE3_INTEGER);
			$insert_movie->bindValue(':title',$movie_data['title'],SQLITE3_TEXT);
			$insert_movie->bindValue(':release_date',$movie_data['release_date'],SQLITE3_TEXT);
			$insert_movie->bindValue(':adult',$movie_data['adult'],SQLITE3_INTEGER);
			$insert_movie->execute();
			$insert_movie->reset();
		}

		$movies_data = array();

		foreach($release_data as $release) {
			$insert_rating->bindValue(':movie_id',$release['movie_id'],SQLITE3_INTEGER);
			$insert_rating->bindValue(':iso_3166_1',$release['iso_3166_1'],SQLITE3_TEXT);
			$insert_rating->bindValue(':certification',$release['certification'],SQLITE3_TEXT);
			$insert_rating->bindValue(':note',$release['note'],SQLITE3_TEXT);
			$insert_rating->bindValue(':release_date',$release['release_date'],SQLITE3_TEXT);
			$insert_rating->bindValue(':release_type',$release['release_type'],SQLITE3_INTEGER);
			$insert_rating->execute();
			$insert_rating->reset();
		}

		$release_data = array();
		$db->exec("COMMIT;");
	}
}

// Clean up any leftovers
$db->exec("BEGIN;");

foreach($movies_data as $movie_data){
	$insert_movie->bindValue(':movie_id',$movie_data['movie_id'],SQLITE3_INTEGER);
	$insert_movie->bindValue(':title',$movie_data['title'],SQLITE3_TEXT);
	$insert_movie->bindValue(':release_date',$movie_data['release_date'],SQLITE3_TEXT);
	$insert_movie->execute();
	$insert_movie->reset();
}

$movies_data = array();

foreach($release_data as $release) {
	$insert_rating->bindValue(':movie_id',$release['movie_id'],SQLITE3_INTEGER);
	$insert_rating->bindValue(':iso_3166_1',$release['iso_3166_1'],SQLITE3_TEXT);
	$insert_rating->bindValue(':certification',$release['certification'],SQLITE3_TEXT);
	$insert_rating->bindValue(':note',$release['note'],SQLITE3_TEXT);
	$insert_rating->bindValue(':release_date',$release['release_date'],SQLITE3_TEXT);
	$insert_rating->bindValue(':release_type',$release['release_type'],SQLITE3_INTEGER);
	$insert_rating->execute();
	$insert_rating->reset();
}

$release_data = array();
$db->exec("COMMIT;");


/*
 *
 * Release types
    1. Premiere
    2. Theatrical (limited)
    3. Theatrical
    4. Digital
    5. Physical
    6. TV
*/
