<?php
/**
 * This file is part of the {@link http://amsl.technology amls} project.
 *
 * @copyright Copyright (c) 2014, {@link http://amsl.technology amsl}
 * @license http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 */


error_reporting( 0 );


if ( isset( $_GET["issn"] ))
{
	$issn    = htmlspecialchars( $_GET["issn"] );
	$pattern = "/^\d{4}-\d{3}[0-9xX]$/";

	$header  = apache_request_headers();
	$type    = parseAccept( $header['Accept'] );
	
	if ( preg_match( $pattern, $issn )) {

		// $url, $def, $query points to the source
		$query  = "http://services.dnb.de/sru/zdb?";
		$query .= "version=1.1&operation=searchRetrieve&recordSchema=RDFxml";
		$query .= "&query=iss%3D".$issn;

		// $file shall be rdf/xml document as defined in the paragraph above
		$file  = file_get_contents( $query );	
		$sxe   = new SimpleXMLElement( $file );

		// Register namespaces before ZDB response can be understand
		$sxe -> registerXPathNamespace('rdf',   'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
		$sxe -> registerXPathNamespace('bibo',  'http://purl.org/ontology/bibo/');
		$sxe -> registerXPathNamespace('umbel', 'http://umbel.org/umbel#');

		// Climb along the xml tree path and extract parameters
		$umbl_islike = $sxe->xpath('//rdf:Description/umbel:isLike/@rdf:*');

		// if there are no hits, there is either no output or attachment
		if ( count( $umbl_islike ) > 0 ) {

			if ( $type == 'ttl'    ) { // submits turtle
				echo turtlization ( $issn, $umbl_islike );
			} 
			if ( $type == 'nt'     ) { // submits ntriples
				echo ntfication ( $issn, $umbl_islike );
			}
			if ( $type == 'jsonld' ) { // submits json/ld
				echo jsonldfication ( $issn, $umbl_islike );
			}
			
		} else {
			header("HTTP/1.0 404 Not Found");
			echo "<h1>404 / ISSN not found</h1>";
		}
	} else {
		header("HTTP/1.0 404 Not Found");
		echo "<h1>404 / No valid ISSN</h1>";
	}

	unset( $sxe   );
	unset( $file  );
	unset( $query );
	unset( $umbl_islike );

} else {
	echo "Parameter not expected or does not exist.";
	die();
}


function parseAccept ($accept) {

	$mimetypes = array( 	// associate types with file extensions
		'text/turtle' 		=> 'ttl',
		'text/ntriples'		=> 'nt',
		'text/nt'		=> 'nt',
		'application/json'   	=> 'js',
		'application/turtle' 	=> 'ttl',
		'application/n-triples'	=> 'nt',
		'application/ld+json'	=> 'jsonld'
	);

	$types = array();
	foreach (explode(',', $accept) as $mediaRange) {
		$mediaRange = trim( $mediaRange );
		// the q parameter must be the first one according to the RFC 2616
		@list ($type, $qparam) = preg_split ('/\s*;\s*/', $mediaRange); 
		$q = substr ($qparam, 0, 2) == 'q=' ? floatval ( substr($qparam, 2)) : 1;
		if ($q <= 0) continue;
		if (substr($type, -1) == '*') $q -= 0.0001;
		if (@$type[0] == '*') $q -= 0.0001;
		$types[$type] = $q;
	}
	arsort ($types); // sort from highest to lowest q value
	foreach ($types as $type => $q) {
		if (isset ($mimetypes[$type])) return $mimetypes[$type];
	}
	return 'ttl';
}


function turtlization ($issn, $umbl_islike) {

	// Turtlization
	header('Content-Type: text/turtle');
	header('Content-Disposition: attachment; filename="'.$issn.'.ttl"');

	$turtle_out  = "@prefix umbel: <http://umbel.org/umbel#> .\n\n";
	$turtle_out .= "<urn:ISSN:".$issn."> umbel:isLike \n";
	$separator   = "";
	
	while( list( , $uri ) = each( $umbl_islike )) {
		if ( preg_match( '/\(ISSN\)/', $uri )) continue;
		$uri 	     = preg_replace( '/\/data\//', '/resource/', $uri );
		$turtle_out .= $separator . "\t\t<" . $uri.">";
		$separator   = " , \n";
	}
	return $turtle_out." .\n";
}


function ntfication ( $issn, $umbl_islike ) {

	// NT-fication
	header('Content-Type: text/n-triples');
	header('Content-Disposition: attachment; filename="'.$issn.'.nt"');

	$ntriple_out = "";

	while( list( , $uri ) = each( $umbl_islike )) {
		if ( preg_match( '/\(ISSN\)/', $uri )) continue;
		$ntriple_out .= "<urn:ISSN:".$issn."> <http://umbel.org/umbel/isLike> ";
		$uri 	      = preg_replace( '/\/data\//', '/resource/', $uri );
		$ntriple_out .= "<" .$uri."> .\n";
	}
	return $ntriple_out;
}


function jsonldfication ( $issn, $umbl_islike ) {

	// JSON-LD-fication
	header('Content-Type: application/ld+json');
	header('Content-Disposition: attachment; filename="'.$issn.'.jsonld"');
	
	$json_out  = "{\n\t\"@context\": {";
	$json_out .= "\n\t\t\"umbel\": \"http://umbel.org/umbel#\",";
	$json_out .= "\n\t\t\"umbel:isLike\": {";
	$json_out .= "\n\t\t\"@type\": \"@id\" \n\t\t}\n\t},";
	$json_out .= "\n\t\"@id\":   \"urn:ISSN:".$issn."\",";
	$json_out .= "\n\t\"@type\": \"\",";
	$json_out .= "\n\t\"umbel:isLike\": [\n";
	$separator = "";

	while( list( , $uri ) = each( $umbl_islike )) {
		if ( preg_match( '/\(ISSN\)/', $uri )) continue;
		$json_out  .= $separator;
		$uri	    = preg_replace( '/\/data\//', '/resource/', $uri );
		$json_out  .= "\t\t \"$uri\"";
		$separator  = ",\n";
	}
	$json_out .= "\n\t]\n}";
	$json_out .= "\n";
	return $json_out;
}
?>
