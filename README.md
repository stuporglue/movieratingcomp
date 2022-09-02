Movie Rating Comparisons
==========================

This is a movie rating (certification) comparison visualization between different countries, based on data from TMDB. I created it because my family just moved to Germany and I wanted to understand 
the movie rating system as my kids were asking about watching different shows.

Includes a script to pull the data down from TheMovieDB.org. 

Uses the Sankey diagram from https://github.com/Krzysiekzd/SanKEY.js to visualize the differences.

![Showing German to US ratings mappings](screenshot.png)

Usage
-----

You will need a key from [TheMovieDB.org](https://www.themoviedb.org/documentation/api)

 * Put the key in a file called `api_key.txt`. 
 * Run api_calls.php, preferrably from the command line so it can run for a longer time
   - This script can be interrupted. It will continue from the last data stored in the database. 
   - It will take a long time (several days, maybe) to download all the data. 
   - If there is a network hiccup the script may hang and need to be killed and restarted.
 * View index.php to see the results

Caveats & Notes
-------

 * TheMovieDB is not comprehensive and has some mistakes
 * Not all countries are represented, and different countries have different amounts of coverage (but everything in TheMovieDB is shown)
 * For this comparison only the ratings for theatrical releases of non-adult movies are considered
 * Some contradictions appear, such as _The Big Red One_ having both a PG and R rating are due to TMDB listing the original 1980 and a later re-rating as theatrical releases. 
 * I know the PHP isn't super clean or pretty. I just wanted to easily see the data. If it bothers you, I'm happy to take any cleanup pull requests.

To calculate the greatest differences between movie certification systems, here's what I did. Every certification is listed in order, with an index. Eg. for the US system we have _G (0), PG (1), PG-13 (2), R (3), NC-17 (4)_. I found how far up the certification list a rating was by dividing the max index by the movie's index. So a PG movie would be 1/4 (PG/NC-17), or 0.25. By doing the same for the other country's certification system I could compare a German 12 (1/3, 0.33) to a US PG. I took the absolute value of the difference between the two country ratings for a movie, and sorted by this value in descending order. 

I haven't thoroughly tested this approach, but the results seem reasonable. The highest ratings difference between the US and German movies is then a German 16 vs. a US G. Ratings difference on older movies may reflect changes to certification systems. Older movies don't usually get re-rated, maybe only if they are re-released.
