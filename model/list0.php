<?php
echo '<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

	for ($i=1 ; $i<=100 ; $i++){
echo "<sitemap>
      <loc>//list.php?id=".$i."</loc>
<lastmod>2018-07-10</lastmod>
</sitemap>";

	};
	echo "</sitemapindex>";