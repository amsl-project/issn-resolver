<?php
/**
 * This file is part of the {@link http://amsl.technology amsl} project.
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
		// The queried schema needs to be MARC21-xml instead of RDFxml because of the more detailed information
		$query  = "http://services.dnb.de/sru/zdb?";
		$query .= "version=1.1&operation=searchRetrieve&recordSchema=MARC21-xml";
		$query .= "&query=iss%3D".$issn;


		// $file shall be MARC21/xml document as defined in the paragraph above
		$file  = file_get_contents( $query );
		$sxe   = new SimpleXMLElement( $file );

		// Register namespaces before ZDB response can be understand
		$sxe -> registerXPathNamespace('slim', 'http://www.loc.gov/MARC21/slim') ;

		// xpath to ZDB number (in field 016$a where 016$2="DE-600") of a record containing the given issn in field 022$a:
		$ZDB = $sxe->xpath('//slim:datafield[@tag="022"]/slim:subfield[@code="a" and contains(. ,"'.$issn.'")]/
							ancestor::slim:record[1]/slim:datafield[@tag="016"]/slim:subfield[@code="2" and contains(.,"DE-600")]/
							ancestor::slim:datafield[1]/slim:subfield[@code="a"]') ;

		// if there are no hits, there is either no output or attachment
		if ( count( $ZDB) > 0 ) {

			// When asking for a MARC21, there are no explicit RDF resources defined in the answer.
			// The RDF resource is build out of the prefix http://ld.zdb-services.de/resource/ and the ZDB number.
			// Building the RDF-resource:

			foreach ($ZDB as &$zdb) {
			   $zdb = 'http://ld.zdb-services.de/resource/' . $zdb ;
			}
		
			if ( $type == 'ttl'    ) { // submits turtle
				echo turtlization ( $issn, $ZDB );
			} 
			if ( $type == 'nt'     ) { // submits ntriples
				echo ntfication ( $issn, $ZDB );
			}
			if ( $type == 'jsonld' ) { // submits json/ld
				echo jsonldfication ( $issn, $ZDB );
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
	unset( $ZDB );

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


function turtlization ($issn, $_022a) {

	// Turtlization
	header('Content-Type: text/turtle');
	header('Content-Disposition: attachment; filename="'.$issn.'.ttl"');

	$turtle_out  = "@prefix umbel: <http://umbel.org/umbel#> .\n\n";
	$turtle_out .= "<urn:ISSN:".$issn."> umbel:isLike \n";
	$separator   = "";
	
	while( list( , $uri ) = each( $_022a )) {
		if ( preg_match( '/\(ISSN\)/', $uri )) continue;
		$turtle_out .= $separator . "\t\t<" . $uri.">";
		$separator   = " , \n";
	}
	return $turtle_out." .\n";
}


function ntfication ( $issn, $_022a ) {

	// NT-fication
	header('Content-Type: text/n-triples');
	header('Content-Disposition: attachment; filename="'.$issn.'.nt"');

	$ntriple_out = "";

	while( list( , $uri ) = each( $_022a )) {
		if ( preg_match( '/\(ISSN\)/', $uri )) continue;
		$ntriple_out .= "<urn:ISSN:".$issn."> <http://umbel.org/umbel/isLike> ";
		$ntriple_out .= "<" .$uri."> .\n";
	}
	return $ntriple_out;
}


function jsonldfication ( $issn, $_022a ) {

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

	while( list( , $uri ) = each( $_022a )) {
		if ( preg_match( '/\(ISSN\)/', $uri )) continue;
		$json_out  .= $separator;
		$json_out  .= "\t\t \"$uri\"";
		$separator  = ",\n";
	}
	$json_out .= "\n\t]\n}";
	$json_out .= "\n";
	return $json_out;
}
?>