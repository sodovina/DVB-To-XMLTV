<?php
if ( isset( $argv[1] ) ) {
    $input = $argv[1];
} else if ( isset( $_GET['file'] ) ) {
    $input = $_GET['file'];
} else {
    echo "ERROR: please define input file.\n";
    echo "Script usage: php widecastdvb2xmltv.php input_file.xml [output_file.xml]\n";
    echo "or: php widecastdvb2xmltv.php /input/directory/ /output/directory/\n";
    exit;
}
$output = isset( $argv[2] ) ? $argv[2] : null;

/**
 * XML parser helper
 *
 */
class XMLReaderExt extends XMLReader {
    /**
     * Current path
     *
     * @var array
     */
    protected $path = array();

    protected $pop_next = false;

    /**
     * All items in array
     * @var array
     */
    public $items = array();

    /**
     * Last ELEMENT name
     * @var string
     */
    public $lastTag = '';

    public $elemetTypes = array(
        0  => 'NONE',
        1  => 'ELEMENT',
        2  => 'ATTRIBUTE',
        3  => 'TEXT',
        4  => 'CDATA',
        5  => 'ENTITY_REF',
        6  => 'ENTITY',
        7  => 'PI',
        8  => 'COMMENT',
        9  => 'DOC',
        10 => 'DOC_TYPE',
        11 => 'DOC_FRAGMENT',
        12 => 'NOTATION',
        13 => 'WHITESPACE',
        14 => 'SIGNIFICANT_WHITESPACE',
        15 => 'END_ELEMENT',
        16 => 'END_ENTITY',
        17 => 'XML_DECLARATION',
    );

    function getPath() {
        return join( "/", $this->path );
    }

    function read() {
        $ret = parent::read();
        if ( $ret === false ) {
            return false;
        }
        # pop last element
        if ( $this->pop_next == true ) {
            $name           = array_pop( $this->path );
            $this->pop_next = false;
        }
        if ( $this->nodeType == XMLReader::END_ELEMENT || $this->isEmptyElement == true ) {
            $this->pop_next = true;
        }
        if ( $this->nodeType == XMLReader::ELEMENT ) {
            array_push( $this->path, $this->name );
            $this->lastTag = $this->name;
        }
        return $ret;

    }

    /**
     * Reads whole document on matching path and puts items in array
     *
     * @param string $path
     */
    function readAll( $path ) {
        $attrib = array();
        $maxDepth = 0;
        $item     = array();
        $path      = trim( $path, " /" );
        $pathArr   = explode( "/", $path );
        $pathCount = count( $pathArr );

        while ( $this->read() ) {

            $nodeType       = $this->nodeType;
            $isEmptyElement = $this->isEmptyElement;

            if ( $this->depth > $maxDepth ) {
                $maxDepth = $this->depth;
            }

            if ( $path == join( "/", array_slice( $this->path, 0, $pathCount ) ) ) {
                if ( in_array( $this->nodeType, array( XMLReader::TEXT, XMLReader::CDATA ) ) ) {
                    $item[ $this->lastTag ][] = $this->value;
                }
            }

            if ( $this->hasAttributes && $this->nodeType != XMLReader::END_ELEMENT ) {
                while ( $this->moveToNextAttribute() ) {
                    $attrib[ $this->depth - 1 ][ join( ":", $this->path ) . ":" . $this->name ] = $this->value;
                }
            }
            if ( $path == join( "/", $this->path ) && ( $nodeType == XMLReader::END_ELEMENT || ( $nodeType == XMLReader::ELEMENT && $isEmptyElement == true ) ) ) {

                # fill up attributes
                foreach ( $attrib as $at ) {
                    foreach ( $at as $key => $val ) {
                        $item[ $key ] = $val;
                    }
                }
                $this->items[] = $item;
                $item = array();
            }
        }
    }
}

if ( ! file_exists( $input ) ) {
    echo "ERROR: directory or file not found\n";
    exit;
}

$files = array();
if ( is_file( $input ) ) {
    if ( is_dir( $output ) ) {
        echo "ERROR: second parameter has to be file\n";
        exit;
    }
    if ( $input == $output ) {
        echo "WARNING: input and output are same files\n";
        exit;
    }
    $files[ $input ] = $output;
}

if ( is_dir( $input ) ) {
    if ( $output==null ) {
        echo "ERROR: second parameter has to be defined\n";
        exit;
    }
    if(!file_exists($output)) mkdir($output);

    $output = realpath( $output );

    if ( ! is_dir( $output ) ) {
        echo "ERROR: second parameter has to be dir\n";
        exit;
    }

    $input = realpath( $input ) . DIRECTORY_SEPARATOR . '*.xml';
    $dir   = glob( $input  );
    foreach ( $dir as $f ) {
        $files[ $f ] = $output . DIRECTORY_SEPARATOR . basename( $f );
    }
    if(count($files)==0) {
        echo "ERROR: no *.xml files found in input dir $input\n";
        exit;
    }
}

foreach ( $files as $file_input => $file_output ) {
    $raw = file_get_contents( $file_input );
    if ( ! $raw ) {
        echo "ERROR: file '$file_input' not found or empty\n";
        continue;
    }
    $parser = new XMLReaderExt();
    if ( ! $parser->XML( $raw ) ) {
        echo "ERROR: Cannot parse XML file '$file_input'\n";
        continue;
    }
    $parser->readAll( 'WIDECAST_DVB/channel/event' );
    if ( count( $parser->items ) == 0 ) {
        echo "ERROR: XML file '$file_input' is not WIDECAST_DVB format, no event items found\n";
        continue;
    }
	$channelTitle;
	foreach($parser -> items as $p2){
		$channelTitle = htmlspecialchars( $p2['WIDECAST_DVB:channel:name'], ENT_QUOTES );
	}
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
    $xml .= '<tv>' . PHP_EOL;
    $xml .= '<channel id="' . $channelTitle . '">' . PHP_EOL;
	$xml .= '<display-name>' . PHP_EOL;
	$xml .= '</display-name>' . PHP_EOL;
	$xml .= '<url></url>' . PHP_EOL;
	$xml .= '</channel>' . PHP_EOL;
    foreach ( $parser->items as $p ) {
        $start = strtotime( $p['WIDECAST_DVB:channel:event:start_time'] );
        $stop  = $start + $p['WIDECAST_DVB:channel:event:duration'];
        $title = trim( $p['WIDECAST_DVB:channel:event:short_event_descriptor:name'] );
        if ( ! empty( $p['short_event_descriptor'][0] ) ) {
            $title .= ', ' . trim( $p['short_event_descriptor'][0] );
        }
        $xml .= '<programme start="' . date( 'YmdHis O', $start ) . '" stop="' . date( 'YmdHis O', $stop ) . '" channel="' . htmlspecialchars( $p['WIDECAST_DVB:channel:name'], ENT_QUOTES ) . '">' . PHP_EOL;
        $xml .= '<title>' . htmlspecialchars( $title, ENT_QUOTES ) . '</title>' . PHP_EOL;
        if ( ! empty( $p['text'][0] ) ) {
            $xml .= '<desc>' . htmlspecialchars( trim( $p['text'][0] ), ENT_QUOTES ) . '</desc>' . PHP_EOL;
        }
        $xml .= '</programme>' . PHP_EOL;
    }
    $xml .= '</tv>';
    if ( $file_output != null ) {
        if ( ! file_put_contents( $file_output, $xml ) ) {
            echo "ERROR: cannot write to '$file_output'";
        }
    } else {
        header( 'Content-type: text/xml' );
        echo $xml;
        exit;
    }
}
?>