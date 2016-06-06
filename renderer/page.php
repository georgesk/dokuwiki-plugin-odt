<?php
/**
 * ODT Plugin: Exports to ODT
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author Andreas Gohr <andi@splitbrain.org>
 * @author Aurelien Bompard <aurelien@bompard.org>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

require_once DOKU_PLUGIN . 'odt/helper/cssimport.php';
require_once DOKU_PLUGIN . 'odt/ODT/ODTDefaultStyles.php';
require_once DOKU_PLUGIN . 'odt/ODT/ODTmeta.php';
require_once DOKU_PLUGIN . 'odt/ODT/page.php';

// Supported document handlers.
require_once DOKU_PLUGIN . 'odt/ODT/ODTDocument.php';

/**
 * The Renderer
 */
class renderer_plugin_odt_page extends Doku_Renderer {
    /** @var array store the index info e.g. for table of contents */
    protected $all_index_settings = array();
    protected $all_index_types = array();
    protected $all_index_start_ref = array();
    public $index_count = 0;
    /** @var export mode (scratch or ODT template) */
    protected $mode = 'scratch';
    /** @var helper_plugin_odt_stylefactory */
    protected $factory = null;
    /** @var helper_plugin_odt_cssimport */
    protected $import = null;
    /** @var helper_plugin_odt_cssimportnew */
    protected $importnew = null;
    /** @var helper_plugin_odt_units */
    protected $units = null;
    /** @var ODTMeta */
    protected $meta;
    /** @var helper_plugin_odt_config */
    protected $config = null;
    public $fields = array(); // set by Fields Plugin
    protected $document = null;
    protected $highlight_style_num = 1;
    protected $quote_depth = 0;
    protected $quote_pos = 0;
    protected $refUserIndexIDCount = 0;
    /** @var string */
    protected $css;
    /** @var  int counter for styles */
    protected $style_count;
    /** @var  has any text content been added yet (excluding whitespace)? */
    protected $text_empty = true;

    // Only for debugging
    //var $trace_dump;

    /**
     * Constructor. Loads helper plugins.
     */
    public function __construct() {
        // Set up empty array with known config parameters
        $this->config = plugin_load('helper', 'odt_config');

        $this->factory = plugin_load('helper', 'odt_stylefactory');

        $this->document = new ODTDocument();

        $this->meta = new ODTMeta();
    }

    /**
     * Set a config parameter from extern.
     */
    public function setConfigParam($name, $value) {
        $this->config->setParam($name, $value);
    }

    /**
     * Is the $string specified the name of a ODT plugin config parameter?
     *
     * @return bool Is it a config parameter?
     */
    public function isConfigParam($string) {
        return $this->config->isParam($string);
    }

    /**
     * Return version info
     */
    function getInfo(){
        return confToHash(dirname(__FILE__).'/plugin.info.txt');
    }

    /**
     * Returns the format produced by this renderer.
     */
    function getFormat(){
        return "odt";
    }

    /**
     * Do not make multiple instances of this class
     */
    function isSingleton(){
        return true;
    }

    /**
     * Load and imports CSS.
     */
    protected function load_css() {
        /** @var helper_plugin_odt_dwcssloader $loader */
        $loader = plugin_load('helper', 'odt_dwcssloader');
        if ( $loader != NULL ) {
            $this->css = $loader->load
                ('odt', 'odt', $this->config->getParam('css_template'), $this->config->getParam('usestyles'));
        }

        // Import CSS (old API, deprecated)
        $this->import = plugin_load('helper', 'odt_cssimport');
        if ( $this->import != NULL ) {
            $this->import->importFromString ($this->css);
        }

        // Import CSS (new API)
        $this->importnew = plugin_load('helper', 'odt_cssimportnew');
        if ( $this->importnew != NULL ) {
            $this->importnew->importFromString ($this->css);
        }

        // Call adjustLengthValues to make our callback function being called for every
        // length value imported. This gives us the chance to convert it once from
        // pixel to points.
        $this->import->adjustLengthValues (array($this, 'adjustLengthCallback'));
        $this->importnew->adjustLengthValues (array($this, 'adjustLengthCallback'));
    }

    /**
     * Load and configure units helper.
     */
    protected function setupUnits()
    {
        // Load helper class for unit conversion.
        $this->units = plugin_load('helper', 'odt_units');
        $this->units->setPixelPerEm(14);
        $this->units->setTwipsPerPixelX($this->config->getParam ('twips_per_pixel_x'));
        $this->units->setTwipsPerPixelY($this->config->getParam ('twips_per_pixel_y'));
    }
    
    /**
     * Initialize the rendering
     */
    function document_start() {
        global $ID;

        // Reset TOC.
        $this->document->toc = array();

        // First, get export mode.
        $warning = '';
        $this->mode = $this->config->load($warning);

        // Load and import CSS files, setup Units
        $this->load_css();
        $this->setupUnits();

        switch($this->mode) {
            case 'ODT template':
                // Document based on ODT template.
                $this->document->setODTTemplate($this->config->getParam ('odt_template'),
                    $this->config->getParam ('tpl_dir'));
                break;

            case 'CSS template':
                // Document based on DokuWiki CSS template.
                $media_sel = $this->config->getParam ('media_sel');
                $template = $this->config->getParam ('odt_template');
                $directory = $this->config->getParam ('tpl_dir');
                $template_path = $this->config->getParam('mediadir').'/'.$directory."/".$template;
                $this->document->setCSSTemplate($template_path, $media_sel, $this->config->getParam('mediadir'));

                // Set outline style.
                $this->document->setOutlineStyle($this->config->getParam('outline_list_style'));
                break;

            default:
                // Document from scratch.

                // Set outline style.
                $this->document->setOutlineStyle($this->config->getParam('outline_list_style'));
                break;
        }

        // Setup page format.
        // Set the page format of the current page for calculation ($this->document->page)
        // Change the standard page layout style
        $this->document->page = new pageFormat();
        $this->document->page->setFormat($this->config->getParam ('format'),
                             $this->config->getParam ('orientation'),
                             $this->config->getParam ('margin_top'),
                             $this->config->getParam ('margin_right'),
                             $this->config->getParam ('margin_bottom'),
                             $this->config->getParam ('margin_left'));
        $first_page = $this->document->getStyleByAlias('first page');
        if ($first_page != NULL) {
            $first_page->setProperty('width', $this->document->page->getWidth().'cm');
            $first_page->setProperty('height', $this->document->page->getHeight().'cm');
            $first_page->setProperty('margin-top', $this->document->page->getMarginTop().'cm');
            $first_page->setProperty('margin-right', $this->document->page->getMarginRight().'cm');
            $first_page->setProperty('margin-bottom', $this->document->page->getMarginBottom().'cm');
            $first_page->setProperty('margin-left', $this->document->page->getMarginLeft().'cm');
        }

        // Set title in meta info.
        $this->meta->setTitle($ID); //FIXME article title != book title  SOLUTION: overwrite at the end for book

        // If older or equal to 2007-06-26, we need to disable caching
        $dw_version = preg_replace('/[^\d]/', '', getversion());  //FIXME DEPRECATED
        if (version_compare($dw_version, "20070626", "<=")) {
            $this->info["cache"] = false;
        }


        //$headers = array('Content-Type'=>'text/plain'); p_set_metadata($ID,array('format' => array('odt' => $headers) )); return ; // DEBUG
        // send the content type header, new method after 2007-06-26 (handles caching)
        $format = $this->config->getConvertTo ();
        switch ($format) {
            case 'pdf':
                $output_filename = str_replace(':','-',$ID).'.pdf';
                $headers = array(
                    'Content-Type' => 'application/pdf',
                    'Cache-Control' => 'must-revalidate, no-transform, post-check=0, pre-check=0',
                    'Pragma' => 'public',
                    'Content-Disposition' => 'attachment; filename="'.$output_filename.'";',
                );
                break;
            case 'odt':
            default:
                $output_filename = str_replace(':','-',$ID).'.odt';
                $headers = array(
                    'Content-Type' => 'application/vnd.oasis.opendocument.text',
                    'Content-Disposition' => 'attachment; filename="'.$output_filename.'";',
                );
                break;
        }
        // store the content type headers in metadata
        p_set_metadata($ID,array('format' => array('odt_page' => $headers) ));

        $this->set_page_bookmark($ID);
    }

    /**
     * Closes the document
     */
    function document_end(){
        // Close any open paragraph if not done yet.
        $this->p_close ();
        
        // Insert Indexes (if required), e.g. Table of Contents
        $this->insert_indexes();

        // DEBUG: The following puts out the loaded raw CSS code
        //$this->p_open();
        // This line outputs the raw CSS code
        //$test = 'CSS: '.$this->css;
        // The next two lines output the parsed CSS rules with linebreaks
        //$test = $this->import->rulesToString();
        //$test = $this->importnew->rulesToString();
        //$this->doc .= preg_replace ('/\n/', '<text:line-break/>', $test);
        //$this->p_open();
        //$this->doc .= 'Tracedump: '.$this->trace_dump;
        //$this->p_close();

        // Build the document
        $this->finalize_ODTfile();

        // Refresh certain config parameters e.g. 'disable_links'
        $this->config->refresh();

        // Reset state.
        $this->document->state->reset();
    }

    /**
     * This function sets the page format.
     * The format, orientation and page margins can be changed.
     * See function queryFormat() in ODT/page.php for supported formats.
     *
     * @param string  $format         e.g. 'A4', 'A3'
     * @param string  $orientation    e.g. 'portrait' or 'landscape'
     * @param numeric $margin_top     Top-Margin in cm, default 2
     * @param numeric $margin_right   Right-Margin in cm, default 2
     * @param numeric $margin_bottom  Bottom-Margin in cm, default 2
     * @param numeric $margin_left    Left-Margin in cm, default 2
     */
    public function setPageFormat ($format=NULL, $orientation=NULL, $margin_top=NULL, $margin_right=NULL, $margin_bottom=NULL, $margin_left=NULL) {
        $data = array ();

        // Fill missing values with current settings
        if ( empty($format) ) {
            $format = $this->document->page->getFormat();
        }
        if ( empty($orientation) ) {
            $orientation = $this->document->page->getOrientation();
        }
        if ( empty($margin_top) ) {
            $margin_top = $this->document->page->getMarginTop();
        }
        if ( empty($margin_right) ) {
            $margin_right = $this->document->page->getMarginRight();
        }
        if ( empty($margin_bottom) ) {
            $margin_bottom = $this->document->page->getMarginBottom();
        }
        if ( empty($margin_left) ) {
            $margin_left = $this->document->page->getMarginLeft();
        }

        // Adjust given parameters, query resulting format data and get format-string
        $this->document->page->queryFormat ($data, $format, $orientation, $margin_top, $margin_right, $margin_bottom, $margin_left);
        $format_string = $this->document->page->formatToString ($data['format'], $data['orientation'], $data['margin-top'], $data['margin-right'], $data['margin-bottom'], $data['margin-left']);

        if ( $format_string == $this->document->page->toString () ) {
            // Current page already uses this format, no need to do anything...
            return;
        }

        if ($this->text_empty) {
            // If the text is still empty, then we change the start page format now.
            $this->document->page->setFormat($data ['format'], $data ['orientation'], $data['margin-top'], $data['margin-right'], $data['margin-bottom'], $data['margin-left']);
            $first_page = $this->document->getStyleByAlias('first page');
            if ($first_page != NULL) {
                $first_page->setProperty('width', $this->document->page->getWidth().'cm');
                $first_page->setProperty('height', $this->document->page->getHeight().'cm');
                $first_page->setProperty('margin-top', $this->document->page->getMarginTop().'cm');
                $first_page->setProperty('margin-right', $this->document->page->getMarginRight().'cm');
                $first_page->setProperty('margin-bottom', $this->document->page->getMarginBottom().'cm');
                $first_page->setProperty('margin-left', $this->document->page->getMarginLeft().'cm');
            }
        } else {
            // Set marker and save data for pending change format.
            // The format change istelf will be done on the next call to p_open or header()
            // to prevent empty lines after the format change.
            $this->document->changePageFormat = $data;

            // Close paragraph if open
            $this->p_close();
        }
    }

    /**
     * Convert exported ODT file if required.
     * Supported formats: pdf
     */
    protected function convert () {
        global $ID;
                
        $format = $this->config->getConvertTo ();
        if ($format == 'pdf') {
            // Prepare temp directory
            $temp_dir = $this->config->getParam('tmpdir');
            $temp_dir = $temp_dir."/odt/".str_replace(':','-',$ID);
            if (is_dir($temp_dir)) { io_rmdir($temp_dir,true); }
            io_mkdir_p($temp_dir);

            // Set source and dest file path
            $file = $temp_dir.'/convert.odt';
            $pdf_file = $temp_dir.'/convert.pdf';

            // Prepare command line
            $command = $this->config->getParam('convert_to_pdf');
            $command = str_replace('%outdir%', $temp_dir, $command);
            $command = str_replace('%sourcefile%', $file, $command);

            // Convert file
            io_saveFile($file, $this->doc);
            exec ($command, $output, $result);
            if ($result) {
                $errormessage = '';
                foreach ($output as $line) {
                    $errormessage .= $this->_xmlEntities($line);
                }
                $message = $this->getLang('conversion_failed_msg');
                $message = str_replace('%command%', $command, $message);
                $message = str_replace('%errorcode%', $result, $message);
                $message = str_replace('%errormessage%', $errormessage, $message);
                $message = str_replace('%pageid%', $ID, $message);
                
                $instructions = p_get_instructions($message);
                $this->doc = p_render('xhtml', $instructions, $info);

                $headers = array(
                    'Content-Type' =>  'text/html; charset=utf-8',
                );
                p_set_metadata($ID,array('format' => array('odt_page' => $headers) ));
            } else {
                $this->doc = io_readFile($pdf_file, false);
            }
            io_rmdir($temp_dir,true);
        }
    }

    /**
     * Completes the ODT file
     */
    public function finalize_ODTfile() {
        // Build/assign the document
        $this->doc = $this->document->getODTFileAsString
            ($this->doc, $this->meta->getContent(), $this->_odtUserFields());

        $this->convert();
    }

    /**
     * Simple setter to enable creating links
     */
    function enable_links() {
        $this->config->setParam ('disable_links', false);
    }

    /**
     * Simple setter to disable creating links
     */
    function disable_links() {
        $this->config->setParam ('disable_links', true);
    }

    /**
     * Dummy function.
     *
     * @return string
     */
    function render_TOC() {
        return '';
    }

    /**
     * This function does not really render an index but inserts a placeholder.
     * See also insert_indexes().
     *
     * @return string
     */
    function render_index($type='toc', $settings=NULL) {
        $this->p_close();
        $this->doc .= '<index-placeholder no="'.($this->index_count+1).'"/>';
        $this->all_index_settings [$this->index_count] = $settings;
        $this->all_index_types [$this->index_count] = $type;
        if ($type == 'chapter') {
            $this->all_index_start_ref [$this->index_count] = $this->toc_getPreviousItem(1);
        } else {
            $this->all_index_start_ref [$this->index_count] = NULL;
        }
        $this->index_count++;
        return '';
    }

    /**
     * This function creates the entries for a table of contents.
     * All heading are included up to level $max_outline_level.
     *
     * @param array   $p_styles            Array of style names for the paragraphs.
     * @param array   $stylesLNames        Array of style names for the links.
     * @param array   $max_outline_level   Depth of the table of contents.
     * @param boolean $links               Shall links be created.
     * @return string TOC body entries
     */
    protected function get_toc_body($p_styles, $stylesLNames, $max_outline_level, $links) {
        $page = 0;
        $content = '';
        foreach ($this->document->toc as $item) {
            $params = explode (',', $item);

            // Only add the heading to the TOC if its <= $max_outline_level
            if ( $params [3] <= $max_outline_level ) {
                $level = $params [3];
                $content .= '<text:p text:style-name="'.$p_styles [$level].'">';
                if ( $links == true ) {
                    $content .= '<text:a xlink:type="simple" xlink:href="#'.$params [0].'" text:style-name="'.$stylesLNames [$level].'" text:visited-style-name="'.$stylesLNames [$level].'">';
                }
                $content .= $params [2];
                $content .= '<text:tab/>';
                $page++;
                $content .= $page;
                if ( $links == true ) {
                    $content .= '</text:a>';
                }
                $content .= '</text:p>';
            }
        }
        return $content;
    }

    /**
     * This function creates the entries for a chapter index.
     * All headings of the chapter are included uo to level $max_outline_level.
     *
     * @param array   $p_styles            Array of style names for the paragraphs.
     * @param array   $stylesLNames        Array of style names for the links.
     * @param array   $max_outline_level   Depth of the table of contents.
     * @param boolean $links               Shall links be created.
     * @param string  $startRef            Reference-ID of chapter main heading.
     * @return string TOC body entries
     */
    protected function get_chapter_index_body($p_styles, $stylesLNames, $max_outline_level, $links, $startRef) {
        $start_outline = 1;
        $in_chapter = false;
        $first = true;
        $content = '';
        foreach ($this->document->toc as $item) {
            $params = explode (',', $item);

            if ($in_chapter == true || $params [0] == $startRef ) {
                $in_chapter = true;

                // Is this the start of a new chapter?
                if ( $first == false && $params [3] <= $start_outline ) {
                    break;
                }
                
                // Only add the heading to the TOC if its <= $max_outline_level
                if ( $params [3] <= $max_outline_level ) {
                    $level = $params [3];
                    $content .= '<text:p text:style-name="'.$p_styles [$level].'">';
                    if ( $links == true ) {
                        $content .= '<text:a xlink:type="simple" xlink:href="#'.$params [0].'" text:style-name="'.$stylesLNames [$level].'" text:visited-style-name="'.$stylesLNames [$level].'">';
                    }
                    $content .= $params [2];
                    $content .= '<text:tab/>';
                    $page++;
                    $content .= $page;
                    if ( $links == true ) {
                        $content .= '</text:a>';
                    }
                    $content .= '</text:p>';
                }
                $first = false;
            }
        }
        return $content;
    }

    /**
     * This function builds a TOC or chapter index.
     * The page numbers are just a counter. Update the TOC e.g. in LibreOffice to get the real page numbers!
     *
     * The layout settings are taken from the configuration and $settings.
     * $settings can include the following options syntax:
     * - Title e.g. 'title=Example;'.
     *   Default is 'Table of Contents' (for english, see language files for other languages default value).
     * - Leader sign, e.g. 'leader-sign=.;'.
     *   Default is '.'.
     * - Indents (in cm), e.g. 'indents=indents=0,0.5,1,1.5,2,2.5,3;'.
     *   Default is 0.5 cm indent more per level.
     * - Maximum outline/TOC level, e.g. 'maxtoclevel=5;'.
     *   Default is taken from DokuWiki config setting 'maxtoclevel'.
     * - Insert pagebreak after TOC, e.g. 'pagebreak=1;'.
     *   Default is '1', means insert pagebreak after TOC.
     * - Set style per outline/TOC level, e.g. 'styleL2="color:red;font-weight:900;";'.
     *   Default is 'color:black'.
     *
     * It is allowed to use defaults for all settings by omitting $settings.
     * Multiple settings can be combined, e.g. 'leader-sign=.;indents=0,0.5,1,1.5,2,2.5,3;'.
     */
    protected function build_index($type='toc', $settings=NULL, $links=true, $startRef=NULL, $indexNo) {
        $matches = array();
        $stylesL = array();
        $stylesLNames = array();

        // It seems to be not supported in ODT to have a different start
        // outline level than 1.
        $max_outline_level = $this->config->getParam('toc_maxlevel');
        if ( preg_match('/maxlevel=[^;]+;/', $settings, $matches) === 1 ) {
            $temp = substr ($matches [0], 12);
            $temp = trim ($temp, ';');
            $max_outline_level = $temp;
        }

        // Determine title, default for table of contents is 'Table of Contents'.
        // Default for chapter index is empty.
        // Syntax for 'Test' as title would be "title=test;".
        $title = '';
        if ($type == 'toc') {
            $title = $this->getLang('toc_title');
        }
        if ( preg_match('/title=[^;]+;/', $settings, $matches) === 1 ) {
            $temp = substr ($matches [0], 6);
            $temp = trim ($temp, ';');
            $title = $temp;
        }

        // Determine leader-sign, default is '.'.
        // Syntax for '.' as leader-sign would be "leader_sign=.;".
        $leader_sign = $this->config->getParam('toc_leader_sign');
        if ( preg_match('/leader_sign=[^;]+;/', $settings, $matches) === 1 ) {
            $temp = substr ($matches [0], 12);
            $temp = trim ($temp, ';');
            $leader_sign = $temp [0];
        }

        // Determine indents, default is '0.5' (cm) per level.
        // Syntax for a indent of '0.5' for 5 levels would be "indents=0,0.5,1,1.5,2;".
        // The values are absolute for each level, not relative to the higher level.
        $indents = explode (',', $this->config->getParam('toc_indents'));
        if ( preg_match('/indents=[^;]+;/', $settings, $matches) === 1 ) {
            $temp = substr ($matches [0], 8);
            $temp = trim ($temp, ';');
            $indents = explode (',', $temp);
        }

        // Determine pagebreak, default is on '1'.
        // Syntax for pagebreak off would be "pagebreak=0;".
        $pagebreak = $this->config->getParam('toc_pagebreak');
        if ( preg_match('/pagebreak=[^;]+;/', $settings, $matches) === 1 ) {
            $temp = substr ($matches [0], 10);
            $temp = trim ($temp, ';');
            $pagebreak = false;            
            if ( $temp == '1' ) {
                $pagebreak = true;
            } else if ( strcasecmp($temp, 'true') == 0 ) {
                $pagebreak = true;
            }
        }

        // Determine text style for the index heading.
        if ( preg_match('/styleH="[^"]+";/', $settings, $matches) === 1 ) {
            $quote = strpos ($matches [0], '"');
            $temp = substr ($matches [0], $quote+1);
            $temp = trim ($temp, '";');
            $styleH = $temp.';';
        }

        // Determine text styles per level.
        // Syntax for a style level 1 is "styleL1="color:black;"".
        // The default style is just 'color:black;'.
        for ( $count = 0 ; $count < $max_outline_level ; $count++ ) {
            $stylesL [$count + 1] = $this->config->getParam('toc_style');
            if ( preg_match('/styleL'.($count + 1).'="[^"]+";/', $settings, $matches) === 1 ) {
                $quote = strpos ($matches [0], '"');
                $temp = substr ($matches [0], $quote+1);
                $temp = trim ($temp, '";');
                $stylesL [$count + 1] = $temp.';';
            }
        }

        // Create Heading style if not empty.
        // Default index heading style is taken from styles.xml
        $title_style = $this->document->getStyleName('contents heading');
        if (!empty($styleH)) {
            $properties = array();
            $this->_processCSSStyle ($properties, $styleH);
            $properties ['style-parent'] = 'Heading';
            $properties ['style-class'] = 'index';
            $this->style_count++;
            $properties ['style-name'] = 'Contents_20_Heading_'.$this->style_count;
            $properties ['style-display-name'] = 'Contents Heading '.$this->style_count;
            $style_obj = $this->factory->createParagraphStyle($properties);
            $this->document->addStyle($style_obj);
            $title_style = $style_obj->getProperty('style-name');
        }
        
        // Create paragraph styles
        $p_styles = array();
        $p_styles_auto = array();
        $indent = 0;
        for ( $count = 0 ; $count < $max_outline_level ; $count++ )
        {
            $indent = $indents [$count];
            $properties = array();
            $this->_processCSSStyle ($properties, $stylesL [$count+1]);
            $properties ['style-parent'] = 'Index';
            $properties ['style-class'] = 'index';
            $properties ['style-position'] = 17 - $indent .'cm';
            $properties ['style-type'] = 'right';
            $properties ['style-leader-style'] = 'dotted';
            $properties ['style-leader-text'] = $leader_sign;
            $properties ['margin-left'] = $indent.'cm';
            $properties ['margin-right'] = '0cm';
            $properties ['text-indent'] = '0cm';
            $properties ['style-name'] = 'ToC '.$indexNo.'- Level '.($count+1);
            $properties ['style-display-name'] = 'ToC '.$indexNo.', Level '.($count+1);
            $style_obj = $this->factory->createParagraphStyle($properties);

            // Add paragraph style to common styles.
            // (It MUST be added to styles NOT to automatic styles. Otherwise LibreOffice will
            //  overwrite/change the style on updating the TOC!!!)
            $this->document->addStyle($style_obj);
            $p_styles [$count+1] = $style_obj->getProperty('style-name');

            // Create a copy of that but with parent set to the copied style
            // and no class
            $properties ['style-parent'] = $style_obj->getProperty('style-name');
            $properties ['style-class'] = NULL;
            $properties ['style-name'] = 'ToC Auto '.$indexNo.'- Level '.($count+1);
            $properties ['style-display-name'] = NULL;
            $style_obj_auto = $this->factory->createParagraphStyle($properties);
            
            // Add paragraph style to automatic styles.
            // (It MUST be added to automatic styles NOT to styles. Otherwise LibreOffice will
            //  overwrite/change the style on updating the TOC!!!)
            $this->document->addAutomaticStyle($style_obj_auto);
            $p_styles_auto [$count+1] = $style_obj_auto->getProperty('style-name');
        }

        // Create text style for TOC text.
        // (this MUST be a text style (not paragraph!) and MUST be placed in styles (not automatic styles) to work!)
        for ( $count = 0 ; $count < $max_outline_level ; $count++ ) {
            $properties = array();
            $this->_processCSSStyle ($properties, $stylesL [$count+1]);
            $properties ['style-name'] = 'ToC '.$indexNo.'- Text Level '.($count+1);
            $properties ['style-display-name'] = 'ToC '.$indexNo.', Level '.($count+1);
            $style_obj = $this->factory->createTextStyle($properties);
            $stylesLNames [$count+1] = $style_obj->getProperty('style-name');
            $this->document->addStyle($style_obj);
        }

        // Generate ODT toc tag and content
        switch ($type) {
            case 'toc':
                $tag = 'table-of-content';
                $name = 'Table of Contents';
                $index_name = 'Table of Contents';
                $source_attrs = 'text:outline-level="'.$max_outline_level.'" text:use-index-marks="false"';
            break;
            case 'chapter':
                $tag = 'table-of-content';
                $name = 'Table of Contents';
                $index_name = 'Table of Contents';
                $source_attrs = 'text:outline-level="'.$max_outline_level.'" text:use-index-marks="false" text:index-scope="chapter"';
            break;
        }

        $content  = '<text:'.$tag.' text:style-name="Standard" text:protected="true" text:name="'.$name.'">';
        $content .= '<text:'.$tag.'-source '.$source_attrs.'>';
        if (!empty($title)) {
            $content .= '<text:index-title-template text:style-name="'.$title_style.'">'.$title.'</text:index-title-template>';
        } else {
            $content .= '<text:index-title-template text:style-name="'.$title_style.'"/>';
        }

        // Create TOC templates per outline level.
        // The styles listed here need to be the same as later used for the headers.
        // Otherwise the style of the TOC entries/headers will change after an update.
        for ( $count = 0 ; $count < $max_outline_level ; $count++ )
        {
            $level = $count + 1;
            $content .= '<text:'.$tag.'-entry-template text:outline-level="'.$level.'" text:style-name="'.$p_styles [$level].'">';
            $content .= '<text:index-entry-link-start text:style-name="'.$stylesLNames [$level].'"/>';
            $content .= '<text:index-entry-chapter/>';
            $content .= '<text:index-entry-text/>';
            $content .= '<text:index-entry-tab-stop style:type="right" style:leader-char="'.$leader_sign.'"/>';
            $content .= '<text:index-entry-page-number/>';
            $content .= '<text:index-entry-link-end/>';
            $content .= '</text:'.$tag.'-entry-template>';
        }

        $content .= '</text:'.$tag.'-source>';
        $content .= '<text:index-body>';
        if (!empty($title)) {
            $content .= '<text:index-title text:style-name="Standard" text:name="'.$index_name.'_Head">';
            $content .= '<text:p text:style-name="'.$title_style.'">'.$title.'</text:p>';
            $content .= '</text:index-title>';
        }

        // Add headers to TOC.
        $page = 0;
        if ($type == 'toc') {
            $content .= $this->get_toc_body ($p_styles_auto, $stylesLNames, $max_outline_level, $links);
        } else {
            $content .= $this->get_chapter_index_body ($p_styles_auto, $stylesLNames, $max_outline_level, $links, $startRef);
        }

        $content .= '</text:index-body>';
        $content .= '</text:'.$tag.'>';

        // Add a pagebreak if required.
        if ( $pagebreak ) {
            $style_name = $this->document->createPagebreakStyle(NULL, false);
            $content .= '<text:p text:style-name="'.$style_name.'"/>';
        }

        // Only for debugging
        //foreach ($this->document->toc as $item) {
        //    $params = explode (',', $item);
        //    $content .= '<text:p>'.$params [0].'€'.$params [1].'€'.$params [2].'€'.$params [3].'</text:p>';
        //}

        // Return index content.
        return $content;
    }

    /**
     * This function builds the actual TOC and replaces the placeholder with it.
     * It is called in document_end() after all headings have been added to the TOC, see toc_additem().
     * The page numbers are just a counter. Update the TOC e.g. in LibreOffice to get the real page numbers!
     *
     * The TOC is inserted by the syntax tag '{{odt>toc:setting=value;}};'.
     * The following settings are supported:
     * - Title e.g. '{{odt>toc:title=Example;}}'.
     *   Default is 'Table of Contents' (for english, see language files for other languages default value).
     * - Leader sign, e.g. '{{odt>toc:leader-sign=.;}}'.
     *   Default is '.'.
     * - Indents (in cm), e.g. '{{odt>toc:indents=indents=0,0.5,1,1.5,2,2.5,3;}};'.
     *   Default is 0.5 cm indent more per level.
     * - Maximum outline/TOC level, e.g. '{{odt>toc:maxtoclevel=5;}}'.
     *   Default is taken from DokuWiki config setting 'maxtoclevel'.
     * - Insert pagebreak after TOC, e.g. '{{odt>toc:pagebreak=1;}}'.
     *   Default is '1', means insert pagebreak after TOC.
     * - Set style per outline/TOC level, e.g. '{{odt>toc:styleL2="color:red;font-weight:900;";}}'.
     *   Default is 'color:black'.
     *
     * It is allowed to use defaults for all settings by using '{{odt>toc}}'.
     * Multiple settings can be combined, e.g. '{{odt>toc:leader-sign=.;indents=0,0.5,1,1.5,2,2.5,3;}}'.
     */
    protected function insert_indexes() {
        for ($index_no = 0 ; $index_no < $this->index_count ; $index_no++) {
            $index_settings = $this->all_index_settings [$index_no];
            $start_ref = $this->all_index_start_ref [$index_no];

            // At the moment it does not make sense to disable links for the TOC
            // because LibreOffice will insert links on updating the TOC.
            $content = $this->build_index($this->all_index_types [$index_no], $index_settings, true, $start_ref, $index_no+1);

            // Replace placeholder with TOC content.
            $this->doc = str_replace ('<index-placeholder no="'.($index_no+1).'"/>', $content, $this->doc);
        }
    }

    /**
     * Add an item to the TOC
     * (Dummy function required by the Doku_Renderer class)
     *
     * @param string $id       the hash link
     * @param string $text     the text to display
     * @param int    $level    the nesting level
     */
    function toc_additem($id, $text, $level) {}

    /**
     * Get closest previous TOC entry with $level.
     * The function search backwards (previous) in the TOC entries
     * for the next entry with level $level and retunrs it reference ID.
     *
     * @param int    $level    the nesting level
     * @return string The reference ID or NULL
     */
    function toc_getPreviousItem($level) {
        $index = count($this->document->toc);
        for (; $index >= 0 ; $index--) {
            $item = $this->document->toc[$index];
            $params = explode (',', $item);
            if ($params [3] == $level) {
                return $params [0];
            }
        }
        return NULL;
    }

    /**
     * Return total page width in centimeters
     * (margins are included)
     *
     * @author LarsDW223
     */
    function _getPageWidth(){
        return $this->document->page->getWidth();
    }

    /**
     * Return total page height in centimeters
     * (margins are included)
     *
     * @author LarsDW223
     */
    function _getPageHeight(){
        return $this->document->page->getHeight();
    }

    /**
     * Return left margin in centimeters
     *
     * @author LarsDW223
     */
    function _getLeftMargin(){
        return $this->document->page->getMarginLeft();
    }

    /**
     * Return right margin in centimeters
     *
     * @author LarsDW223
     */
    function _getRightMargin(){
        return $this->document->page->getMarginRight();
    }

    /**
     * Return top margin in centimeters
     *
     * @author LarsDW223
     */
    function _getTopMargin(){
        return $this->document->page->getMarginTop();
    }

    /**
     * Return bottom margin in centimeters
     *
     * @author LarsDW223
     */
    function _getBottomMargin(){
        return $this->document->page->getMarginBottom();
    }

    /**
     * Return width percentage value if margins are taken into account.
     * Usually "100%" means 21cm in case of A4 format.
     * But usually you like to take care of margins. This function
     * adjusts the percentage to the value which should be used for margins.
     * So 100% == 21cm e.g. becomes 80.9% == 17cm (assuming a margin of 2 cm on both sides).
     *
     * @author LarsDW223
     *
     * @param int|string $percentage
     * @return int|string
     */
    function _getRelWidthMindMargins ($percentage = '100'){
        return $this->document->page->getRelWidthMindMargins($percentage);
    }

    /**
     * Like _getRelWidthMindMargins but returns the absulute width
     * in centimeters.
     *
     * @author LarsDW223
     * @param string|int|float $percentage
     * @return float
     */
    function _getAbsWidthMindMargins ($percentage = '100'){
        return $this->document->page->getAbsWidthMindMargins($percentage);
    }

    /**
     * Return height percentage value if margins are taken into account.
     * Usually "100%" means 29.7cm in case of A4 format.
     * But usually you like to take care of margins. This function
     * adjusts the percentage to the value which should be used for margins.
     * So 100% == 29.7cm e.g. becomes 86.5% == 25.7cm (assuming a margin of 2 cm on top and bottom).
     *
     * @author LarsDW223
     *
     * @param string|float|int $percentage
     * @return float|string
     */
    function _getRelHeightMindMargins ($percentage = '100'){
        return $this->document->page->getRelHeightMindMargins($percentage);
    }

    /**
     * Like _getRelHeightMindMargins but returns the absulute width
     * in centimeters.
     *
     * @author LarsDW223
     *
     * @param string|int|float $percentage
     * @return float
     */
    function _getAbsHeightMindMargins ($percentage = '100'){
        return $this->document->page->getAbsHeightMindMargins($percentage);
    }

    /**
     * @return string
     */
    function _odtUserFields() {
        $value = '<text:user-field-decls>';
        foreach ($this->fields as $fname=>$fvalue) {
            $value .= '<text:user-field-decl office:value-type="string" text:name="'.$fname.'" office:string-value="'.$fvalue.'"/>';
        }
        $value .= '</text:user-field-decls>';
        return $value;
    }

    /**
     * Render plain text data
     *
     * @param string $text
     */
    function cdata($text) {
        // Check if there is some content in the text.
        // Only insert bookmark/pagebreak/format change if text is not empty.
        // Otherwise a empty paragraph/line would be created!
        if ( !empty($text) && !ctype_space($text) ) {
            // Insert page bookmark if requested and not done yet.
            $this->document->insertPendingPageBookmark($this->doc);

            // Insert pagebreak or page format change if still pending.
            // Attention: NOT if $text is empty. This would lead to empty lines before headings
            //            right after a pagebreak!
            $in_paragraph = $this->document->state->getInParagraph();
            if ( ($this->document->pagebreakIsPending() || $this->document->changePageFormat != NULL) ||
                  !$in_paragraph ) {
                $this->p_open();
            }
        }
        $this->doc .= $this->_xmlEntities($text);
        if ($this->text_empty && !ctype_space($text)) {
            $this->text_empty = false;
        }
    }

    /**
     * Open a paragraph
     *
     * @param string $style
     */
    function p_open($style=NULL){
        $this->document->paragraphOpen($style, $this->doc);
    }

    function p_close(){
        $this->document->paragraphClose($this->doc);
    }

    /**
     * Set bookmark for the start of the page. This just saves the title temporarily.
     * It is then to be inserted in the first header or paragraph.
     *
     * @param string $id    ID of the bookmark
     */
    function set_page_bookmark($id){
        $this->document->setPageBookmark($id, $this->doc);
    }

    /**
     * Render a heading
     *
     * @param string $text  the text to display
     * @param int    $level header level
     * @param int    $pos   byte position in the original source
     */
    function header($text, $level, $pos){
        $this->document->heading($text, $level, $this->doc);
    }

    function hr() {
        $this->document->horizontalRule($this->doc);
    }

    function linebreak() {
        $this->document->linebreak($this->doc);
    }

    function pagebreak() {
        $this->document->pagebreak($this->doc);
    }

    function strong_open() {
        $this->document->spanOpen($this->document->getStyleName('strong'), $this->doc);
    }

    function strong_close() {
        $this->document->spanClose($this->doc);
    }

    function emphasis_open() {
        $this->document->spanOpen($this->document->getStyleName('emphasis'), $this->doc);
    }

    function emphasis_close() {
        $this->document->spanClose($this->doc);
    }

    function underline_open() {
        $this->document->spanOpen($this->document->getStyleName('underline'), $this->doc);
    }

    function underline_close() {
        $this->document->spanClose($this->doc);
    }

    function monospace_open() {
        $this->document->spanOpen($this->document->getStyleName('monospace'), $this->doc);
    }

    function monospace_close() {
        $this->document->spanClose($this->doc);
    }

    function subscript_open() {
        $this->document->spanOpen($this->document->getStyleName('sub'), $this->doc);
    }

    function subscript_close() {
        $this->document->spanClose($this->doc);
    }

    function superscript_open() {
        $this->document->spanOpen($this->document->getStyleName('sup'), $this->doc);
    }

    function superscript_close() {
        $this->document->spanClose($this->doc);
    }

    function deleted_open() {
        $this->document->spanOpen($this->document->getStyleName('del'), $this->doc);
    }

    function deleted_close() {
        $this->document->spanClose($this->doc);
    }

    /*
     * Tables
     */

    /**
     * Start a table
     *
     * @param int $maxcols maximum number of columns
     * @param int $numrows NOT IMPLEMENTED
     */
    function table_open($maxcols = NULL, $numrows = NULL, $pos = NULL){
        $this->document->tableOpen($maxcols, $numrows, $this->doc);
    }

    function table_close($pos = NULL){
        $this->document->tableClose($this->doc);
    }

    function tablecolumn_add(){
        $this->document->tableAddColumn();
    }

    function tablerow_open(){
        $this->document->tableRowOpen($this->doc);
    }

    function tablerow_close(){
        $this->document->tableRowClose($this->doc);
    }

    /**
     * Open a table header cell
     *
     * @param int    $colspan
     * @param string $align left|center|right
     * @param int    $rowspan
     */
    function tableheader_open($colspan = 1, $align = "left", $rowspan = 1){
        $this->document->tableHeaderOpen($colspan, $rowspan, $align, $this->doc);
    }

    function tableheader_close(){
        $this->document->tableHeaderClose($this->doc);
    }

    /**
     * Open a table cell
     *
     * @param int    $colspan
     * @param string $align left|center|right
     * @param int    $rowspan
     */
    function tablecell_open($colspan = 1, $align = "left", $rowspan = 1){
        $this->document->tableCellOpen($colspan, $rowspan, $align, $this->doc);
    }

    function tablecell_close(){
        $this->document->tableCellClose($this->doc);
    }

    /**
     * Callback for footnote start syntax.
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     */
    function footnote_open() {
        $this->document->footnoteOpen($this->doc);
    }

    /**
     * Callback for footnote end syntax.
     *
     * @author Andreas Gohr
     */
    function footnote_close() {
        $this->document->footnoteClose($this->doc);
    }

    function listu_open($continue=false) {
        $this->document->listOpen($continue, $this->document->getStyleName('list'), $this->doc);
    }

    function listu_close() {
        $this->document->listClose($this->doc);
    }

    function listo_open($continue=false) {
        $this->document->listOpen($continue, $this->document->getStyleName('numbering'), $this->doc);
    }

    function listo_close() {
        $this->document->listClose($this->doc);
    }

    /**
     * Open a list item
     *
     * @param int $level the nesting level
     */
    function listitem_open($level, $node = false) {
        $this->document->listItemOpen($level, $this->doc);
    }

    function listitem_close() {
        $this->document->listItemClose($this->doc);
    }

    function listcontent_open() {
        $this->document->listContentOpen($this->doc);
    }

    function listcontent_close() {
        $this->document->listContentClose($this->doc);
    }

    /**
     * Output unformatted $text
     *
     * @param string $text
     */
    function unformatted($text) {
        $this->doc .= $this->_xmlEntities($text);
    }

    /**
     * Format an acronym
     *
     * @param string $acronym
     */
    function acronym($acronym) {
        $this->doc .= $this->_xmlEntities($acronym);
    }

    /**
     * @param string $smiley
     */
    function smiley($smiley) {
        if ( array_key_exists($smiley, $this->smileys) ) {
            $src = DOKU_INC."lib/images/smileys/".$this->smileys[$smiley];
            $this->_odtAddImage($src);
        } else {
            $this->doc .= $this->_xmlEntities($smiley);
        }
    }

    /**
     * Format an entity
     *
     * @param string $entity
     */
    function entity($entity) {
        # UTF-8 entity decoding is broken in PHP <5
        if (version_compare(phpversion(), "5.0.0") and array_key_exists($entity, $this->entities) ) {
            # decoding may fail for missing Multibyte-Support in entity_decode
            $dec = @html_entity_decode($this->entities[$entity],ENT_NOQUOTES,'UTF-8');
            if($dec){
                $this->doc .= $this->_xmlEntities($dec);
            }else{
                $this->doc .= $this->_xmlEntities($entity);
            }
        } else {
            $this->doc .= $this->_xmlEntities($entity);
        }
    }

    /**
     * Typographically format a multiply sign
     *
     * Example: ($x=640, $y=480) should result in "640×480"
     *
     * @param string|int $x first value
     * @param string|int $y second value
     */
    function multiplyentity($x, $y) {
        $this->doc .= $x.'×'.$y;
    }

    function singlequoteopening() {
        global $lang;
        $this->doc .= $lang['singlequoteopening'];
    }

    function singlequoteclosing() {
        global $lang;
        $this->doc .= $lang['singlequoteclosing'];
    }

    function apostrophe() {
        global $lang;
        $this->doc .= $lang['apostrophe'];
    }

    function doublequoteopening() {
        global $lang;
        $this->doc .= $lang['doublequoteopening'];
    }

    function doublequoteclosing() {
        global $lang;
        $this->doc .= $lang['doublequoteclosing'];
    }

    /**
     * Output inline PHP code
     *
     * @param string $text The PHP code
     */
    function php($text) {
        $this->monospace_open();
        $this->doc .= $this->_xmlEntities($text);
        $this->monospace_close();
    }

    /**
     * Output block level PHP code
     *
     * @param string $text The PHP code
     */
    function phpblock($text) {
        $this->file($text);
    }

    /**
     * Output raw inline HTML
     *
     * @param string $text The HTML
     */
    function html($text) {
        $this->monospace_open();
        $this->doc .= $this->_xmlEntities($text);
        $this->monospace_close();
    }

    /**
     * Output raw block-level HTML
     *
     * @param string $text The HTML
     */
    function htmlblock($text) {
        $this->file($text);
    }

    /**
     * static call back to replace spaces
     *
     * @param array $matches
     * @return string
     */
    function _preserveSpace($matches){
        $spaces = $matches[1];
        $len    = strlen($spaces);
        return '<text:s text:c="'.$len.'"/>';
    }

    /**
     * Output preformatted text
     *
     * @param string $text
     */
    function preformatted($text) {
        $this->_preformatted($text);
    }

    /**
     * Display text as file content, optionally syntax highlighted
     *
     * @param string $text text to show
     * @param string $language programming language to use for syntax highlighting
     * @param string $filename file path label
     */
    function file($text, $language=null, $filename=null) {
        $this->_highlight('file', $text, $language);
    }

    function quote_open() {
        // Do not go higher than 5 because only 5 quotation styles are defined.
        if ( $this->quote_depth < 5 ) {
            $this->quote_depth++;
        }
        $quotation1 = $this->document->getStyleName('quotation1');
        if ($this->quote_depth == 1) {
            // On quote level 1 open a new paragraph with 'quotation1' style
            $this->p_close();
            $this->quote_pos = strlen ($this->doc);
            $this->p_open($quotation1);
            $this->quote_pos = strpos ($this->doc, $quotation1, $this->quote_pos);
            $this->quote_pos += strlen($quotation1) - 1;
        } else {
            // Quote level is greater than 1. Set new style by just changing the number.
            // This is possible because the styles in style.xml are named 'Quotation 1', 'Quotation 2'...
            // FIXME: Unsafe as we now use freely choosen names per template class
            $this->doc [$this->quote_pos] = $this->quote_depth;
        }
    }

    function quote_close() {
        if ( $this->quote_depth > 0 ) {
            $this->quote_depth--;
        }
        if ($this->quote_depth == 0) {
            // This will only close the paragraph if we're actually in one
            $this->p_close();
        }
    }

    /**
     * Display text as code content, optionally syntax highlighted
     *
     * @param string $text text to show
     * @param string $language programming language to use for syntax highlighting
     * @param string $filename file path label
     */
    function code($text, $language=null, $filename=null) {
        $this->_highlight('code', $text, $language);
    }

    /**
     * @param string $text
     * @param string $style
     * @param bool $notescaped
     */
    function _preformatted($text, $style=null, $notescaped=true) {
        if (empty($style)) {
            $style = $this->document->getStyleName('preformatted');
        }
        if ($notescaped) {
            $text = $this->_xmlEntities($text);
        }
        if (strpos($text, "\n") !== FALSE and strpos($text, "\n") == 0) {
            // text starts with a newline, remove it
            $text = substr($text,1);
        }
        $text = str_replace("\n",'<text:line-break/>',$text);
        $text = str_replace("\t",'<text:tab/>',$text);
        $text = preg_replace_callback('/(  +)/',array($this,'_preserveSpace'),$text);

        $list_item = $this->document->state->getCurrentListItem();
        if ($list_item != NULL) {
            // if we're in a list item, we must close the <text:p> tag
            $this->p_close();
            $this->p_open($style);
            $this->doc .= $text;
            $this->p_close();
            // FIXME: query previous style before preformatted text was opened and re-use it here
            $this->p_open();
        } else {
            $this->p_close();
            $this->p_open($style);
            $this->doc .= $text;
            $this->p_close();
        }
    }

    /**
     * @param string $type
     * @param string $text
     * @param string $language
     */
    function _highlight($type, $text, $language=null) {
        $style_name = $this->document->getStyleName('source code');
        if ($type == "file") $style_name = $this->document->getStyleName('source file');

        if (is_null($language)) {
            $this->_preformatted($text, $style_name);
            return;
        }

        // Use cahched geshi
        $highlighted_code = p_xhtml_cached_geshi($text, $language, '');

        // remove useless leading and trailing whitespace-newlines
        $highlighted_code = preg_replace('/^&nbsp;\n/','',$highlighted_code);
        $highlighted_code = preg_replace('/\n&nbsp;$/','',$highlighted_code);
        // replace styles
        $highlighted_code = str_replace("</span>", "</text:span>", $highlighted_code);
        $highlighted_code = preg_replace_callback('/<span class="([^"]+)">/', array($this, '_convert_css_styles'), $highlighted_code);
        // cleanup leftover span tags
        $highlighted_code = preg_replace('/<span[^>]*>/', "<text:span>", $highlighted_code);
        $highlighted_code = str_replace("&nbsp;", "&#xA0;", $highlighted_code);
        // Replace links with ODT link syntax
        $highlighted_code = preg_replace_callback('/<a (href="[^"]*">.*)<\/a>/', array($this, '_convert_geshi_links'), $highlighted_code);

        $this->_preformatted($highlighted_code, $style_name, false);
    }

    /**
     * @param array $matches
     * @return string
     */
    function _convert_css_styles($matches) {
        $class = $matches[1];
        
        // Get CSS properties for that geshi class and create
        // the text style (if not already done)
        $style_name = 'highlight_'.$class;
        if (!$this->document->styleExists($style_name)) {
            $properties = array();
            $properties ['style-name'] = $style_name;
            $this->getODTProperties ($properties, NULL, 'code '.$class, NULL, 'screen');

            $style_obj = $this->factory->createTextStyle($properties);
            $this->document->addAutomaticStyle($style_obj);
        }
        
        // Now make use of the new style
        return '<text:span text:style-name="'.$style_name.'">';
    }

    /**
     * Callback function which creates a link from the part 'href="[^"]*">.*'
     * in the pattern /<a (href="[^"]*">.*)<\/a>/. See function _highlight().
     * 
     * @param array $matches
     * @return string
     */
    function _convert_geshi_links($matches) {
        $content_start = strpos ($matches[1], '>');
        $content = substr ($matches[1], $content_start+1);
        preg_match ('/href="[^"]*"/', $matches[1], $urls);
        $url = substr ($urls[0], 5);
        $url = trim($url, '"');
        // Keep '&' and ':' in the link unescaped, otherwise url parameter passing will not work
        $url = str_replace('&amp;', '&', $url);
        $url = str_replace('%3A', ':', $url);

        return $this->_doLink($url, $content);
    }

    /**
     * Render an internal media file
     *
     * @param string $src       media ID
     * @param string $title     descriptive text
     * @param string $align     left|center|right
     * @param int    $width     width of media in pixel
     * @param int    $height    height of media in pixel
     * @param string $cache     cache|recache|nocache
     * @param string $linking   linkonly|detail|nolink
     * @param bool   $returnonly whether to return odt or write to doc attribute
     */
    function internalmedia ($src, $title=NULL, $align=NULL, $width=NULL,
                            $height=NULL, $cache=NULL, $linking=NULL, $returnonly = false) {
        global $ID;
        resolve_mediaid(getNS($ID),$src, $exists);
        list(/* $ext */,$mime) = mimetype($src);

        if(substr($mime,0,5) == 'image'){
            $file = mediaFN($src);
            if($returnonly) {
              return $this->_odtAddImage($file, $width, $height, $align, $title, true);
            } else {
              $this->_odtAddImage($file, $width, $height, $align, $title);
            }
        }else{
/*
            // FIXME build absolute medialink and call externallink()
            $this->code('FIXME internalmedia: '.$src);
*/
            //FIX by EPO/Intersel - create a link to the dokuwiki internal resource
            if (empty($title)) {$title=explode(':',$src); $title=end($title);}
            if($returnonly) {
              return $this->externalmedia(str_replace('doku.php?id=','lib/exe/fetch.php?media=',wl($src,'',true)),$title,
                                        null, null, null, null, null, true);
            } else {
              $this->externalmedia(str_replace('doku.php?id=','lib/exe/fetch.php?media=',wl($src,'',true)),$title,
                                        null, null, null, null, null);
            }
            //End of FIX
        }
    }

    /**
     * Render an external media file
     *
     * @param string $src        full media URL
     * @param string $title      descriptive text
     * @param string $align      left|center|right
     * @param int    $width      width of media in pixel
     * @param int    $height     height of media in pixel
     * @param string $cache      cache|recache|nocache
     * @param string $linking    linkonly|detail|nolink
     * @param bool   $returnonly whether to return odt or write to doc attribute
     */
    function externalmedia ($src, $title=NULL, $align=NULL, $width=NULL,
                            $height=NULL, $cache=NULL, $linking=NULL, $returnonly = false) {
        list($ext,$mime) = mimetype($src);

        if(substr($mime,0,5) == 'image'){
            $tmp_dir = $this->config->getParam ('tmpdir')."/odt";
            $tmp_name = $tmp_dir."/".md5($src).'.'.$ext;
            $final_name = 'Pictures/'.md5($tmp_name).'.'.$ext;
            if(!$this->document->fileExists($final_name)){
                $client = new DokuHTTPClient;
                $img = $client->get($src);
                if ($img === FALSE) {
                    $tmp_name = $src; // fallback to a simple link
                } else {
                    if (!is_dir($tmp_dir)) io_mkdir_p($tmp_dir);
                    $tmp_img = fopen($tmp_name, "w") or die("Can't create temp file $tmp_img");
                    fwrite($tmp_img, $img);
                    fclose($tmp_img);
                }
            }
            if($returnonly) {
              $ret = $this->_odtAddImage($tmp_name, $width, $height, $align, $title, true);
              if (file_exists($tmp_name)) unlink($tmp_name);
              return $ret;
            } else {
              $this->_odtAddImage($tmp_name, $width, $height, $align, $title);
              if (file_exists($tmp_name)) unlink($tmp_name);
            }
        }else{
            if($returnonly) {
              return $this->externallink($src,$title,true);
            } else {
              $this->externallink($src,$title);
            }
        }
    }

    /**
     * Render a CamelCase link
     *
     * @param string $link       The link name
     * @param bool   $returnonly whether to return odt or write to doc attribute
     * @see http://en.wikipedia.org/wiki/CamelCase
     */
    function camelcaselink($link, $returnonly = false) {
        if($returnonly) {
          return $this->internallink($link,$link, null, true);
        } else {
          $this->internallink($link, $link);
        }
    }

    /**
     * @param string $id
     * @param string $name
     */
    function reference($id, $name = NULL) {
        $ret = '<text:a xlink:type="simple" xlink:href="#'.$id.'"';
        if ($name) {
            $ret .= '>'.$this->_xmlEntities($name).'</text:a>';
        } else {
            $ret .= '/>';
        }
        return $ret;
    }

    /**
     * Render a wiki internal link
     *
     * @param string       $id         page ID to link to. eg. 'wiki:syntax'
     * @param string|array $name       name for the link, array for media file
     * @param bool         $returnonly whether to return odt or write to doc attribute
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     */
    function internallink($id, $name = NULL, $returnonly = false) {
        global $ID;
        // default name is based on $id as given
        $default = $this->_simpleTitle($id);
        // now first resolve and clean up the $id
        resolve_pageid(getNS($ID),$id,$exists);
        $name = $this->_getLinkTitle($name, $default, $isImage, $id);

        // build the absolute URL (keeping a hash if any)
        list($id,$hash) = explode('#',$id,2);
        $url = wl($id,'',true);
        if($hash) $url .='#'.$hash;

        if ($ID == $id) {
          if($returnonly) {
            return $this->reference($hash, $name);
          } else {
            $this->doc .= $this->reference($hash, $name);
          }
        } else {
          if($returnonly) {
            return $this->_doLink($url,$name);
          } else {
            $this->doc .= $this->_doLink($url,$name);
          }
        }
    }

    /**
     * Add external link
     *
     * @param string       $url        full URL with scheme
     * @param string|array $name       name for the link, array for media file
     * @param bool         $returnonly whether to return odt or write to doc attribute
     */
    function externallink($url, $name = NULL, $returnonly = false) {
        $name = $this->_getLinkTitle($name, $url, $isImage);

        if($returnonly) {
          return $this->_doLink($url,$name,$returnonly);
        } else {
          $this->doc .= $this->_doLink($url,$name);
        }
    }

    /**
     * Insert local link placeholder with name.
     * The reference will be resolved on calling replaceLocalLinkPlaceholders();
     *
     * @fixme add image handling
     *
     * @param string $hash hash link identifier
     * @param string $id   name for the link (the reference)
     * @param string $name text for the link (text inserted instead of reference)
     */
    function locallink_with_name($hash, $id = NULL, $name = NULL){
        $id  = $this->_getLinkTitle($id, $hash, $isImage);
        $this->doc .= '<locallink name="'.$name.'">'.$id.'</locallink>';
    }

    /**
     * Insert local link placeholder.
     * The reference will be resolved on calling replaceLocalLinkPlaceholders();
     *
     * @fixme add image handling
     *
     * @param string $hash hash link identifier
     * @param string $name name for the link
     */
    function locallink($hash, $name = NULL){
        $name  = $this->_getLinkTitle($name, $hash, $isImage);
        $this->doc .= '<locallink name="'.$name.'">'.$hash.'</locallink>';
    }

    /**
     * Render an interwiki link
     *
     * You may want to use $this->_resolveInterWiki() here
     *
     * @param string       $match      original link - probably not much use
     * @param string|array $name       name for the link, array for media file
     * @param string       $wikiName   indentifier (shortcut) for the remote wiki
     * @param string       $wikiUri    the fragment parsed from the original link
     * @param bool         $returnonly whether to return odt or write to doc attribute
     */
    function interwikilink($match, $name = NULL, $wikiName, $wikiUri, $returnonly = false) {
        $name  = $this->_getLinkTitle($name, $wikiUri, $isImage);
        $url = $this-> _resolveInterWiki($wikiName,$wikiUri);
        if($returnonly) {
          return $this->_doLink($url,$name);
        } else {
          $this->doc .= $this->_doLink($url,$name);
        }
    }

    /**
     * Just print WindowsShare links
     *
     * @fixme add image handling
     *
     * @param string       $url        the link
     * @param string|array $name       name for the link, array for media file
     * @param bool         $returnonly whether to return odt or write to doc attribute
     */
    function windowssharelink($url, $name = NULL, $returnonly = false) {
        $name  = $this->_getLinkTitle($name, $url, $isImage);
        if($returnonly) {
          return $name;
        } else {
          $this->doc .= $name;
        }
    }

    /**
     * Just print email links
     *
     * @fixme add image handling
     *
     * @param string       $address    Email-Address
     * @param string|array $name       name for the link, array for media file
     * @param bool         $returnonly whether to return odt or write to doc attribute
     */
    function emaillink($address, $name = NULL, $returnonly = false) {
        $name  = $this->_getLinkTitle($name, $address, $isImage);
        if($returnonly) {
          return $this->_doLink("mailto:".$address,$name);
        } else {
          $this->doc .= $this->_doLink("mailto:".$address,$name);
        }
    }

    /**
     * Add a hyperlink, handling Images correctly
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     *
     * @param string $url
     * @param string|array $name
     */
    function _doLink($url,$name){
        $url = $this->_xmlEntities($url);
        $doc = '';
        if(is_array($name)){
            // Images
            if($url && !$this->config->getParam ('disable_links')) $doc .= '<draw:a xlink:type="simple" xlink:href="'.$url.'">';

            if($name['type'] == 'internalmedia'){
                $doc .= $this->internalmedia($name['src'],
                                     $name['title'],
                                     $name['align'],
                                     $name['width'],
                                     $name['height'],
                                     $name['cache'],
                                     $name['linking'],
                                     true);
            }

            if($url && !$this->config->getParam ('disable_links')) $doc .= '</draw:a>';
        }else{
            // Text
            if($url && !$this->config->getParam ('disable_links')) {
                $doc .= '<text:a xlink:type="simple" xlink:href="'.$url.'"';
                $doc .= ' text:style-name="'.$this->document->getStyleName('internet link').'"';
                $doc .= ' text:visited-style-name="'.$this->document->getStyleName('visited internet link').'"';
                $doc .= '>';
            }
            $doc .= $name; // we get the name already XML encoded
            if($url && !$this->config->getParam ('disable_links')) $doc .= '</text:a>';
        }
        return $doc;
    }

    /**
     * Construct a title and handle images in titles
     *
     * @author Harry Fuecks <hfuecks@gmail.com>
     *
     * @param string|array|null $title
     * @param string $default
     * @param bool|null $isImage
     * @param string $id
     * @return mixed
     */
    function _getLinkTitle($title, $default, & $isImage, $id=null) {
        $isImage = false;
        if (is_null($title) || trim($title) == '') {
            if ($this->config->getParam ('useheading') && $id) {
                $heading = p_get_first_heading($id);
                if ($heading) {
                    return $this->_xmlEntities($heading);
                }
            }
            return $this->_xmlEntities($default);
        } else if ( is_array($title) ) {
            $isImage = true;
            return $title;
        } else {
            return $this->_xmlEntities($title);
        }
    }

    /**
     * @param string $value
     * @return string
     */
    function _xmlEntities($value) {
        return str_replace( array('&','"',"'",'<','>'), array('&#38;','&#34;','&#39;','&#60;','&#62;'), $value);
    }

    /**
     * Render the output of an RSS feed
     *
     * @param string $url    URL of the feed
     * @param array  $params Finetuning of the output
     */
    function rss ($url,$params){
        global $lang;

        require_once(DOKU_INC . 'inc/FeedParser.php');
        $feed = new FeedParser();
        $feed->feed_url($url);

        //disable warning while fetching
        $elvl = null;
        if (!defined('DOKU_E_LEVEL')) { $elvl = error_reporting(E_ERROR); }
        $rc = $feed->init();
        if (!defined('DOKU_E_LEVEL')) { error_reporting($elvl); }

        //decide on start and end
        if($params['reverse']){
            $mod = -1;
            $start = $feed->get_item_quantity()-1;
            $end   = $start - ($params['max']);
            $end   = ($end < -1) ? -1 : $end;
        }else{
            $mod   = 1;
            $start = 0;
            $end   = $feed->get_item_quantity();
            $end   = ($end > $params['max']) ? $params['max'] : $end;;
        }

        $this->listu_open();
        if($rc){
            for ($x = $start; $x != $end; $x += $mod) {
                $item = $feed->get_item($x);
                $this->document->listItemOpen(0, $this->doc);
                $this->document->listContentOpen($this->doc);

                $this->externallink($item->get_permalink(),
                                    $item->get_title());
                if($params['author']){
                    $author = $item->get_author(0);
                    if($author){
                        $name = $author->get_name();
                        if(!$name) $name = $author->get_email();
                        if($name) $this->cdata(' '.$lang['by'].' '.$name);
                    }
                }
                if($params['date']){
                    $this->cdata(' ('.$item->get_date($this->config->getParam ('dformat')).')');
                }
                if($params['details']){
                    $this->cdata(strip_tags($item->get_description()));
                }
                $this->document->listContentClose($this->doc);
                $this->document->listItemClose($this->doc);
            }
        }else{
            $this->document->listItemOpen(0, $this->doc);
            $this->document->listContentOpen($this->doc);
            $this->emphasis_open();
            $this->cdata($lang['rssfailed']);
            $this->emphasis_close();
            $this->externallink($url);
            $this->document->listContentClose($this->doc);
            $this->document->listItemClose($this->doc);
        }
        $this->listu_close();
    }

    /**
     * Adds the content of $string as a SVG picture to the document.
     * The other parameters behave in the same way as in _odtAddImage.
     *
     * @author LarsDW223
     *
     * @param string $string
     * @param  $width
     * @param  $height
     * @param  $align
     * @param  $title
     * @param  $style
     */
    function _addStringAsSVGImage($string, $width = NULL, $height = NULL, $align = NULL, $title = NULL, $style = NULL) {

        if ( empty($string) ) { return; }

        $ext  = '.svg';
        $mime = '.image/svg+xml';
        $name = 'Pictures/'.md5($string).'.'.$ext;
        $this->document->addFile($name, $mime, $string);

        // make sure width and height are available
        if (!$width || !$height) {
            list($width, $height) = $this->_odtGetImageSizeString($string, $width, $height);
        }

        if($align){
            $anchor = 'paragraph';
        }else{
            $anchor = 'as-char';
        }

        if (!$style or !$this->document->styleExists($style)) {
            $style = $this->document->getStyleName('media '.$align);
        }

        // Open paragraph if necessary
        if (!$this->document->state->getInParagraph()) {
            $this->p_open();
        }

        if ($title) {
            $this->doc .= '<draw:frame draw:style-name="'.$style.'" draw:name="'.$this->_xmlEntities($title).' Legend"
                            text:anchor-type="'.$anchor.'" draw:z-index="0" svg:width="'.$width.'">';
            $this->doc .= '<draw:text-box>';
            $this->p_open($this->document->getStyleName('legend center'));
        }
        $this->doc .= '<draw:frame draw:style-name="'.$style.'" draw:name="'.$this->_xmlEntities($title).'"
                        text:anchor-type="'.$anchor.'" draw:z-index="0"
                        svg:width="'.$width.'" svg:height="'.$height.'" >';
        $this->doc .= '<draw:image xlink:href="'.$this->_xmlEntities($name).'"
                        xlink:type="simple" xlink:show="embed" xlink:actuate="onLoad"/>';
        $this->doc .= '</draw:frame>';
        if ($title) {
            $this->doc .= $this->_xmlEntities($title);
            $this->p_close();
            $this->doc .= '</draw:text-box></draw:frame>';
        }
    }

    /**
     * Adds the content of $string as a SVG picture file to the document.
     * The link name which can be used for the ODT draw:image xlink:href
     * is returned. The caller is responsible for creating the frame and image tag
     * but therefore has full control over it. This means he can also set parameters
     * in the odt frame and image tag which can not be changed using the function _odtAddImage.
     *
     * @author LarsDW223
     *
     * @param string $string SVG code to add
     * @return string
     */
    function _addStringAsSVGImageFile($string) {

        if ( empty($string) ) { return; }

        $ext  = '.svg';
        $mime = '.image/svg+xml';
        $name = 'Pictures/'.md5($string).'.'.$ext;
        $this->document->addFile($name, $mime, $string);
        return $name;
    }

    /**
     * Adds the image $src as a picture file without adding it to the content
     * of the document. The link name which can be used for the ODT draw:image xlink:href
     * is returned. The caller is responsible for creating the frame and image tag
     * but therefore has full control over it. This means he can also set parameters
     * in the odt frame and image tag which can not be changed using the function _odtAddImage.
     *
     * @author LarsDW223
     *
     * @param string $src
     * @return string
     */
    function _odtAddImageAsFileOnly($src){
        return $this->document->addFileAsPicture($src);
    }

    /**
     * @param string $src
     * @param  $width
     * @param  $height
     * @param  $align
     * @param  $title
     * @param  $style
     * @param  $returnonly
     */
    function _odtAddImage($src, $width = NULL, $height = NULL, $align = NULL, $title = NULL, $style = NULL, $returnonly = false){
        $this->document->addImage($this->doc, $src, $width, $height, $align, $title, $style, $returnonly);
    }

    /**
     * The function tries to examine the width and height
     * of the image stored in file $src.
     * 
     * @see ODTDocument::getImageSize for API wrapper function
     * @see ODTUtility::getImageSize for a detailed description
     */
    public static function _odtGetImageSize($src, $maxwidth=NULL, $maxheight=NULL){
        $this->document->getImageSize($src, $maxwidth, $maxheight);
    }

    /**
     * @param string $src
     * @param  $width
     * @param  $height
     * @return array
     */
    function _odtGetImageSizeString($src, $width = NULL, $height = NULL){
        return $this->document->getImageSizeString($src, $width, $height);
    }

    /**
     * This function opens a new span using the style as set in the imported CSS $import.
     * So, the function requires the helper class 'helper_plugin_odt_cssimport'.
     * The CSS style is selected by the element type 'span' and the specified classes in $classes.
     * The property 'background-image' is not supported by an ODT span. This will be emulated
     * by inserting an image manually in the span. If the url from the CSS should be converted to
     * a local path, then the caller can specify a $baseURL. The full path will then be $baseURL/background-image.
     *
     * This function calls _odtSpanOpenUseProperties. See the function description for supported properties.
     *
     * The span should be closed by calling '_odtSpanClose'.
     *
     * @author LarsDW223
     *
     * @param helper_plugin_odt_cssimport $import
     * @param $classes
     * @param $baseURL
     * @param $element
     */
    function _odtSpanOpenUseCSS(helper_plugin_odt_cssimport $import, $classes, $baseURL = NULL, $element = NULL){
        $properties = array();
        if ( empty($element) ) {
            $element = 'span';
        }
        $this->_processCSSClass ($properties, $import, $classes, $baseURL, $element);
        $this->_odtSpanOpenUseProperties($properties);
    }

    /**
     * This function opens a new span using the style as specified in $style.
     * The property 'background-image' is not supported by an ODT span. This will be emulated
     * by inserting an image manually in the span. If the url from the CSS should be converted to
     * a local path, then the caller can specify a $baseURL. The full path will then be $baseURL/background-image.
     *
     * This function calls _odtSpanOpenUseProperties. See the function description for supported properties.
     *
     * The span should be closed by calling '_odtSpanClose'.
     *
     * @author LarsDW223
     *
     * @param $style
     * @param $baseURL
     */
    function _odtSpanOpenUseCSSStyle($style, $baseURL = NULL){
        $properties = array();
        $this->_processCSSStyle ($properties, $style, $baseURL);
        $this->_odtSpanOpenUseProperties($properties);
    }

    /**
     * This function opens a new span using the style as set in the assoziative array $properties.
     * The parameters in the array should be named as the CSS property names e.g. 'color' or 'background-color'.
     * The property 'background-image' is not supported by an ODT span. This will be emulated
     * by inserting an image manually in the span.
     *
     * background-color, color, font-style, font-weight, font-size, border, font-family, font-variant, letter-spacing,
     * vertical-align, background-image (emulated)
     *
     * The span should be closed by calling '_odtSpanClose'.
     *
     * @author LarsDW223
     *
     * @param array $properties
     */
    function _odtSpanOpenUseProperties($properties){
        $disabled = array ();

        $odt_bg = $properties ['background-color'];
        $picture = $properties ['background-image'];

        if ( !empty ($picture) ) {
            $this->style_count++;

            // If a picture/background-image is set, than we insert it manually here.
            // This is a workaround because ODT does not support the background-image attribute in a span.

            // Define graphic style for picture
            $style_name = 'odt_auto_style_span_graphic_'.$this->style_count;
            $image_style = '<style:style style:name="'.$style_name.'" style:family="graphic" style:parent-style-name="'.$this->document->getStyleName('graphics').'"><style:graphic-properties style:vertical-pos="middle" style:vertical-rel="text" style:horizontal-pos="from-left" style:horizontal-rel="paragraph" fo:background-color="'.$odt_bg.'" style:flow-with-text="true"></style:graphic-properties></style:style>';

            // Add style and image to our document
            // (as unknown style because style-family graphic is not supported)
            $style_obj = ODTUnknownStyle::importODTStyle($image_style);
            $this->document->addAutomaticStyle($style_obj);
            $this->_odtAddImage ($picture,NULL,NULL,NULL,NULL,$style_name);
        }

        // Create a text style for our span
        $disabled ['background-image'] = 1;
        $style_name = $this->_createTextStyle ($properties, $disabled);

        // Open span
        $this->_odtSpanOpen($style_name);
    }

    function _odtSpanOpen($style_name){
        $this->document->spanOpen($style_name, $this->doc);
    }

    /**
     * This function closes a span (previously opened with _odtSpanOpenUseCSS).
     *
     * @author LarsDW223
     */
    function _odtSpanClose(){
        $this->document->spanClose($this->doc);
    }

    /**
     * This function opens a new paragraph using the style as set in the imported CSS $import.
     * So, the function requires the helper class 'helper_plugin_odt_cssimport'.
     * The CSS style is selected by the element type 'p' and the specified classes in $classes.
     * The property 'background-image' is emulated by inserting an image manually in the paragraph.
     * If the url from the CSS should be converted to a local path, then the caller can specify a $baseURL.
     * The full path will then be $baseURL/background-image.
     *
     * This function calls _odtParagraphOpenUseProperties. See the function description for supported properties.
     *
     * The span should be closed by calling '_odtParagraphClose'.
     *
     * @author LarsDW223
     *
     * @param helper_plugin_odt_cssimport $import
     * @param $classes
     * @param $baseURL
     * @param $element
     */
    function _odtParagraphOpenUseCSS(helper_plugin_odt_cssimport $import, $classes, $baseURL = NULL, $element = NULL){
        $properties = array();
        if ( empty($element) ) {
            $element = 'p';
        }
        $this->_processCSSClass ($properties, $import, $classes, $baseURL, $element);
        $this->_odtParagraphOpenUseProperties($properties);
    }

    /**
     * This function opens a new paragraph using the style as specified in $style.
     * The property 'background-image' is emulated by inserting an image manually in the paragraph.
     * If the url from the CSS should be converted to a local path, then the caller can specify a $baseURL.
     * The full path will then be $baseURL/background-image.
     *
     * This function calls _odtParagraphOpenUseProperties. See the function description for supported properties.
     *
     * The paragraph must be closed by calling 'p_close'.
     *
     * @author LarsDW223
     *
     * @param $style
     * @param $baseURL
     */
    function _odtParagraphOpenUseCSSStyle($style, $baseURL = NULL){
        $properties = array();
        $this->_processCSSStyle ($properties, $style, $baseURL);
        $this->_odtParagraphOpenUseProperties($properties);
    }

    /**
     * This function opens a new paragraph using the style as set in the assoziative array $properties.
     * The parameters in the array should be named as the CSS property names e.g. 'color' or 'background-color'.
     * The property 'background-image' is emulated by inserting an image manually in the paragraph.
     *
     * The currently supported properties are:
     * background-color, color, font-style, font-weight, font-size, border, font-family, font-variant, letter-spacing,
     * vertical-align, line-height, background-image (emulated)
     *
     * The paragraph must be closed by calling 'p_close'.
     *
     * @author LarsDW223
     *
     * @param array $properties
     */
    function _odtParagraphOpenUseProperties($properties){
        $disabled = array ();

        $in_paragraph = $this->document->state->getInParagraph();
        if ($in_paragraph) {
            // opening a paragraph inside another paragraph is illegal
            return;
        }

        $odt_bg = $properties ['background-color'];
        $picture = $properties ['background-image'];

        if ( !empty ($picture) ) {
            // If a picture/background-image is set, than we insert it manually here.
            // This is a workaround because ODT background-image works different than in CSS.

            // Define graphic style for picture
            $this->style_count++;
            $style_name = 'odt_auto_style_span_graphic_'.$this->style_count;
            $image_style = '<style:style style:name="'.$style_name.'" style:family="graphic" style:parent-style-name="'.$this->document->getStyleName('graphics').'"><style:graphic-properties style:vertical-pos="middle" style:vertical-rel="text" style:horizontal-pos="from-left" style:horizontal-rel="paragraph" fo:background-color="'.$odt_bg.'" style:flow-with-text="true"></style:graphic-properties></style:style>';

            // Add style and image to our document
            // (as unknown style because style-family graphic is not supported)
            $style_obj = ODTUnknownStyle::importODTStyle($image_style);
            $this->document->addAutomaticStyle($style_obj);
            $this->_odtAddImage ($picture,NULL,NULL,NULL,NULL,$style_name);
        }

        // Create the style for the paragraph.
        $disabled ['background-image'] = 1;
        $style_name = $this->_createParagraphStyle ($properties, $disabled);

        // Open a paragraph
        $this->p_open($style_name);
    }

    /**
     * Open a text box using CSS.
     * 
     * @see ODTDocument::openTextBoxUseCSS for API wrapper function
     * @see ODTFrame::openTextBoxUseCSS for detailed documentation
     */
    function _odtOpenTextBoxUseCSS ($attributes=NULL, cssimportnew $import=NULL) {
        $this->document->openTextBoxUseCSS ($this->doc, $attributes, $import);
    }

    /**
     * This function opens a div. As divs are not supported by ODT, it will be exported as a frame.
     * To be more precise, to frames will be created. One including a picture nad the other including the text.
     * A picture frame will only be created if a 'background-image' is set in the CSS style.
     *
     * The currently supported CSS properties are:
     * background-color, color, padding, margin, display, border-radius, min-height.
     * The background-image is simulated using a picture frame.
     * FIXME: Find a way to successfuly use the background-image in the graphic style (see comments).
     *
     * The div should be closed by calling '_odtDivCloseAsFrame'.
     *
     * @author LarsDW223
     *
     * @param array $properties
     */
    function _odtDivOpenAsFrameUseProperties ($properties) {
        dbg_deprecated('_odtOpenTextBoxUseProperties');
        $this->_odtOpenTextBoxUseProperties ($properties);
    }

    /**
     * This function closes a div/frame (previously opened with _odtDivOpenAsFrameUseCSS).
     *
     * @author LarsDW223
     */
    function _odtDivCloseAsFrame () {
        $this->_odtCloseTextBox();
    }

    /**
     * This function opens a new table using the style as set in the imported CSS $import.
     * So, the function requires the helper class 'helper_plugin_odt_cssimport'.
     * The CSS style is selected by the element type 'td' and the specified classes in $classes.
     *
     * This function calls _odtTableOpenUseProperties. See the function description for supported properties.
     *
     * The table should be closed by calling 'table_close()'.
     *
     * @author LarsDW223
     *
     * @param null $maxcols
     * @param null $numrows
     * @param string $attributes HTML attributes for the table element
     * @param cssimportnew $import Imported CSS code to use.
     *                             If NULL then internal CSS code will be used (if present/loaded).
     */
    function _odtTableOpenUseCSS($maxcols = NULL, $numrows = NULL, $attributes = NULL, cssimportnew $import = NULL){
        $this->document->tableOpenUseCSS($this->doc, $maxcols, $numrows, $attributes, $import);
    }

    /**
     * This function opens a new table using the style as set in the assoziative array $properties.
     * The parameters in the array should be named as the CSS property names e.g. 'width'.
     *
     * The currently supported properties are:
     * width, border-collapse, background-color
     *
     * The table must be closed by calling 'table_close'.
     *
     * @author LarsDW223
     *
     * @param array $properties
     * @param null $maxcols
     * @param null $numrows
     */
    function _odtTableOpenUseProperties ($properties, $maxcols = 0, $numrows = 0){
        $this->document->tableOpenUseProperties ($this->doc, $properties, $maxcols, $numrows);
    }

    function _odtTableClose () {
        $this->document->tableClose($this->doc);
    }

    /**
     * @param array $properties
     */
    function _odtTableAddColumnUseCSS ($attributes=NULL, cssimportnew $import=NULL){
        $this->document->tableAddColumnUseCSS ($attributes, $import);
    }

    /**
     * @param array $properties
     */
    function _odtTableAddColumnUseProperties (array $properties = NULL){
        $this->document->tableAddColumnUseProperties($properties);
    }

    /**
     * @param helper_plugin_odt_cssimport $import
     * @param $classes
     * @param null $baseURL
     * @param null $element
     * @param int $colspan
     * @param int $rowspan
     */
    function _odtTableHeaderOpenUseCSS($colspan = 1, $rowspan = 1, $attributes=NULL, cssimportnew $import=NULL){
        $this->document->tableHeaderOpenUseCSS($this->doc, $colspan, $rowspan, $attributes, $import);
    }

    /**
     * @param null $properties
     * @param int $colspan
     * @param int $rowspan
     */
    function _odtTableHeaderOpenUseProperties ($properties = NULL, $colspan = 1, $rowspan = 1){
        $this->document->tableHeaderOpenUseProperties($this->doc, $properties, $colspan = 1, $rowspan = 1);
    }

    /**
     * This function opens a new table row using the style as set in the imported CSS $import.
     * So, the function requires the helper class 'helper_plugin_odt_cssimport'.
     * The CSS style is selected by the element type 'td' and the specified classes in $classes.
     *
     * This function calls _odtTableRowOpenUseProperties. See the function description for supported properties.
     *
     * The row should be closed by calling 'tablerow_close()'.
     *
     * @author LarsDW223
     * @param helper_plugin_odt_cssimport $import
     * @param $classes
     * @param null $baseURL
     * @param null $element
     */
    function _odtTableRowOpenUseCSS($attributes=NULL, cssimportnew $import=NULL){
        $this->document->tableRowOpenUseCSS($this->doc, $attributes, $import);
    }

    /**
     * @param array $properties
     */
    function _odtTableRowOpenUseProperties ($properties){
        $this->document->tableRowOpenUseProperties($this->doc, $properties);
    }

    /**
     * This function opens a new table cell using the style as set in the imported CSS $import.
     * So, the function requires the helper class 'helper_plugin_odt_cssimport'.
     * The CSS style is selected by the element type 'td' and the specified classes in $classes.
     *
     * This function calls _odtTableCellOpenUseProperties. See the function description for supported properties.
     *
     * The cell should be closed by calling 'tablecell_close()'.
     *
     * @author LarsDW223
     *
     * @param helper_plugin_odt_cssimport $import
     * @param $classes
     * @param null $baseURL
     * @param null $element
     */
    function _odtTableCellOpenUseCSS($attributes=NULL, cssimportnew $import=NULL, $colspan = 1, $rowspan = 1){
        $this->document->tableCellOpenUseCSS($this->doc, $attributes, $import);
    }

    /**
     * @param $properties
     */
    function _odtTableCellOpenUseProperties ($properties, $colspan = 1, $rowspan = 1){
        $this->document->tableCellOpenUseProperties($this->doc, $properties, $colspan, $rowspan);
    }

    /**
     * This function creates a text style using the style as set in the assoziative array $properties.
     * The parameters in the array should be named as the CSS property names e.g. 'color' or 'background-color'.
     * Properties which shall not be used in the style can be disabled by setting the value in disabled_props
     * to 1 e.g. $disabled_props ['color'] = 1 would block the usage of the color property.
     *
     * The currently supported properties are:
     * background-color, color, font-style, font-weight, font-size, border, font-family, font-variant, letter-spacing,
     * vertical-align, background-image
     *
     * The function returns the name of the new style or NULL if all relevant properties are empty.
     *
     * @author LarsDW223
     *
     * @param array $properties
     * @param array $disabled_props
     * @return null|string
     */
    protected function _createTextStyle($properties, $disabled_props = NULL){
        $save = $disabled_props ['font-size'];

        $odt_fo_size = '';
        if ( empty ($disabled_props ['font-size']) ) {
            $odt_fo_size = $properties ['font-size'];
        }
        $parent = '';
        $length = strlen ($odt_fo_size);
        if ( $length > 0 && $odt_fo_size [$length-1] == '%' ) {
            // A font-size in percent is only supported in common style definitions, not in automatic
            // styles. Create a common style and set it as parent for this automatic style.
            $name = 'Size'.trim ($odt_fo_size, '%').'pc';
            $style_obj = $this->factory->createSizeOnlyTextStyle ($name, $odt_fo_size);
            $this->document->addStyle($style_obj);
            $parent = $style_obj->getProperty('style-name');
        }

        if (!empty($parent)) {
            $properties ['style-parent'] = $parent;
        }
        $style_obj = $this->factory->createTextStyle($properties, $disabled_props);
        $this->document->addAutomaticStyle($style_obj);
        $style_name = $style_obj->getProperty('style-name');

        $disabled_props ['font-size'] = $save;

        return $style_name;
    }

    /**
     * This function creates a paragraph style using. It uses the createParagraphStyle function
     * from the stylefactory helper class but takes care of the extra handling required for the
     * font-size attribute.
     *
     * The function returns the name of the new style or NULL if all relevant properties are empty.
     *
     * @author LarsDW223
     *
     * @param array $properties
     * @param array $disabled_props
     * @return string|null
     */
    protected function _createParagraphStyle($properties, $disabled_props = NULL){
        $save = $disabled_props ['font-size'];

        $odt_fo_size = '';
        if ( empty ($disabled_props ['font-size']) ) {
            $odt_fo_size = $properties ['font-size'];
        }
        $parent = '';
        $length = strlen ($odt_fo_size);
        if ( $length > 0 && $odt_fo_size [$length-1] == '%' ) {
            // A font-size in percent is only supported in common style definitions, not in automatic
            // styles. Create a common style and set it as parent for this automatic style.
            $name = 'Size'.trim ($odt_fo_size, '%').'pc';
            $style_obj = $this->factory->createSizeOnlyTextStyle ($name, $odt_fo_size);
            $this->document->addStyle($style_obj);
            $parent = $style_obj->getProperty('style-name');
        }

        $length = strlen ($properties ['text-indent']);
        if ( $length > 0 && $properties ['text-indent'] [$length-1] == '%' ) {
            // Percentage value needs to be converted to absolute value.
            // ODT standard says that percentage value should work if used in a common style.
            // This did not work with LibreOffice 4.4.3.2.
            $value = trim ($properties ['text-indent'], '%');
            $properties ['text-indent'] = $this->_getAbsWidthMindMargins ($value).'cm';
        }

        if (!empty($parent)) {
            $properties ['style-parent'] = $parent;
        }
        $style_obj = $this->factory->createParagraphStyle($properties, $disabled_props);
        $this->document->addAutomaticStyle($style_obj);
        $style_name = $style_obj->getProperty('style-name');

        $disabled_props ['font-size'] = $save;

        return $style_name;
    }

    /**
     * This function processes the CSS declarations in $style and saves them in $properties
     * as key - value pairs, e.g. $properties ['color'] = 'red'. It also adjusts the values
     * for the ODT format and changes URLs to local paths if required, using $baseURL).
     *
     * @author LarsDW223
     * @param array $properties
     * @param $style
     * @param null $baseURL
     */
    public function _processCSSStyle(&$properties, $style, $baseURL = NULL){
        if ( $this->import == NULL ) {
            $this->import = new helper_plugin_odt_cssimport ();
            if ( $this->import == NULL ) {
                // Failed to create helper. Can't proceed.
                return;
            }
        }

        // Create rule with selector '*' (doesn't matter) and declarations as set in $style
        $rule = new css_rule ('*', $style);
        $rule->getProperties ($properties);
        foreach ($properties as $property => $value) {
            $properties [$property] = $this->adjustValueForODT ($property, $value, 14);
        }

        if ( !empty ($properties ['background-image']) ) {
            if ( !empty ($baseURL) ) {
                // Replace 'url(...)' with $baseURL
                $properties ['background-image'] = $this->import->replaceURLPrefix ($properties ['background-image'], $baseURL);
            }
        }
    }

    /**
     * This function examines the CSS properties for $classes and $element based on the data
     * in $import and saves them in $properties as key - value pairs, e.g. $properties ['color'] = 'red'.
     * It also adjusts the values for the ODT format and changes URLs to local paths if required, using $baseURL).
     *
     * @author LarsDW223
     *
     * @param array $properties
     * @param helper_plugin_odt_cssimport $import
     * @param $classes
     * @param null $baseURL
     * @param null $element
     */
    public function _processCSSClass(&$properties, helper_plugin_odt_cssimport $import, $classes, $baseURL = NULL, $element = NULL){
        $import->getPropertiesForElement($properties, $element, $classes);
        foreach ($properties as $property => $value) {
            $properties [$property] = $this->adjustValueForODT ($property, $value, 14);
        }

        if ( !empty ($properties ['background-image']) ) {
            if ( !empty ($baseURL) ) {
                // Replace 'url(...)' with $baseURL
                $properties ['background-image'] = $import->replaceURLPrefix ($properties ['background-image'], $baseURL);
            }
        }
    }

    /**
     * Open a multi column text box in a frame using properties.
     * 
     * @see ODTDocument::openMultiColumnTextBoxUseProperties for API wrapper function
     * @see ODTFrame::openMultiColumnTextBoxUseProperties for detailed documentation
     */
    function _odtOpenMultiColumnFrame ($properties) {
        $this->document->openMultiColumnTextBoxUseProperties($this->doc, $properties);
    }

    /**
     * This function closes a multi column frame (previously opened with _odtOpenMultiColumnFrame).
     *
     * @see ODTDocument::closeTextBox for API wrapper function
     * @see ODTFrame::closeTextBox for detailed documentation
     * @author LarsDW223
     */
    function _odtCloseMultiColumnFrame () {
        $this->document->closeMultiColumnTextBox($this->doc);
    }

    /**
     * Open a text box in a frame using properties.
     * 
     * @see ODTDocument::openTextBoxUseProperties for API wrapper function
     * @see ODTFrame::openTextBoxUseProperties for detailed documentation
     */
    function _odtOpenTextBoxUseProperties ($properties) {
        $this->document->openTextBoxUseProperties ($this->doc, $properties);
    }

    /**
     * This function closes a textbox.
     *
     * @see ODTDocument::closeTextBox for API wrapper function
     * @see ODTFrame::closeTextBox for detailed documentation
     * @author LarsDW223
     */
    function _odtCloseTextBox () {
        $this->document->closeTextBox($this->doc);
    }

    /**
     * @param array $dest
     * @param $element
     * @param $classString
     * @param $inlineStyle
     */
    public function getCSSProperties (&$dest, $element, $classString, $inlineStyle) {
        // Get properties for our class/element from imported CSS
        $this->import->getPropertiesForElement($dest, $element, $classString);

        // Interpret and add values from style to our properties
        $this->_processCSSStyle($dest, $inlineStyle);
    }

    /**
     * @param array $dest
     * @param $element
     * @param $classString
     * @param $inlineStyle
     */
    public function getODTProperties (&$dest, $element, $classString, $inlineStyle, $media_sel=NULL, $cssId=NULL) {
        if ($media_sel === NULL) {
            $media_sel = $this->config->getParam ('media_sel');
        }
        // Get properties for our class/element from imported CSS
        $this->import->getPropertiesForElement($dest, $element, $classString, $media_sel, $cssId);

        // Interpret and add values from style to our properties
        $this->_processCSSStyle($dest, $inlineStyle);

        // Adjust values for ODT
        foreach ($dest as $property => $value) {
            $dest [$property] = $this->adjustValueForODT ($property, $value, 14);
        }
    }

    /**
     * @param $URL
     * @param $replacement
     * @return string
     */
    public function replaceURLPrefix ($URL, $replacement) {
        return $this->import->replaceURLPrefix ($URL, $replacement);
    }

    /**
     * @param $pixel
     * @return float
     */
    public function pixelToPointsX ($pixel) {
        return ($pixel * $this->config->getParam ('twips_per_pixel_x')) / 20;
    }

    /**
     * @param $pixel
     * @return float
     */
    public function pixelToPointsY ($pixel) {
        return ($pixel * $this->config->getParam ('twips_per_pixel_y')) / 20;
    }

    /**
     * @param $property
     * @param $value
     * @param int $emValue
     * @return string
     */
    public function adjustValueForODT ($property, $value, $emValue = 0) {
        return $this->factory->adjustValueForODT ($property, $value, $emValue);
    }

    /**
     * This function adjust the length string $value for ODT and returns the adjusted string:
     * If no unit or pixel are specified, then pixel are converted to points using the
     * configured twips per point (X axis).
     *
     * @author LarsDW223
     *
     * @param $value
     * @return string
     */
    function adjustXLengthValueForODT ($value) {
        // If there are only digits or if the unit is pixel,
        // convert from pixel to point.
        if ( ctype_digit($value) === true ) {
            $value = $this->pixelToPointsX($value).'pt';
        } else {
            $length = strlen ($value);
            if ( $length > 2 && $value [$length-2] == 'p' && $value [$length-1] == 'x' ) {
                $number = trim($value, 'px');
                $value = $this->pixelToPointsX($number).'pt';
            }
        }
        return $value;
    }

    /**
     * This function adjust the length string $value for ODT and returns the adjusted string:
     * If no unit or pixel are specified, then pixel are converted to points using the
     * configured twips per point (Y axis).
     *
     * @author LarsDW223
     *
     * @param $value
     * @return string
     */
    function adjustYLengthValueForODT ($value) {
        // If there are only digits or if the unit is pixel,
        // convert from pixel to point.
        if ( ctype_digit($value) === true ) {
            $value = $this->pixelToPointsY($value).'pt';
        } else {
            $length = strlen ($value);
            if ( $length > 2 && $value [$length-2] == 'p' && $value [$length-1] == 'x' ) {
                $number = trim($value, 'px');
                $value = $this->pixelToPointsY($number).'pt';
            }
        }
        return $value;
    }

    /**
     * @param $property
     * @param $value
     * @param $type
     * @return string
     */
    public function adjustLengthCallback ($property, $value, $type) {
        // Replace px with pt (px does not seem to be supported by ODT)
        $length = strlen ($value);
        if ( $length > 2 && $value [$length-2] == 'p' && $value [$length-1] == 'x' ) {
            $number = trim($value, 'px');
            switch ($type) {
                case CSSValueType::LengthValueXAxis:
                    $adjusted = $this->pixelToPointsX($number).'pt';
                break;

                case CSSValueType::StrokeOrBorderWidth:
                    switch ($property) {
                        case 'border':
                        case 'border-left':
                        case 'border-right':
                        case 'border-top':
                        case 'border-bottom':
                            // border in ODT spans does not support 'px' units, so we convert it.
                            $adjusted = $this->pixelToPointsY($number).'pt';
                        break;

                        default:
                            $adjusted = $value;
                        break;
                    }
                break;

                case CSSValueType::LengthValueYAxis:
                default:
                    $adjusted = $this->pixelToPointsY($number).'pt';
                break;
            }
            // Only for debugging.
            //$this->trace_dump .= 'adjustLengthCallback: '.$property.':'.$value.'==>'.$adjusted.'<text:line-break/>';
            return $adjusted;
        }
        // Only for debugging.
        //$this->trace_dump .= 'adjustLengthCallback: '.$property.':'.$value.'<text:line-break/>';
        return $value;
    }

    /**
     * This function read the template page and imports all cdata and code content
     * as additional CSS. ATTENTION: this might overwrite already imported styles
     * from an ODT or CSS template file.
     *
     * @param $pagename The name of the template page
     */
    public function read_templatepage ($pagename) {
        $instructions = p_cached_instructions(wikiFN($pagename));
        $text = '';
        foreach($instructions as $instruction) {
            if($instruction[0] == 'code') {
                $text .= $instruction[1][0];
            } elseif ($instruction[0] == 'cdata') {
                $text .= $instruction[1][0];
            }
        }

        $this->document->importCSSFromString($text, $media_sel, $this->config->getParam('mediadir'));
    }

    /**
     * @param array $dest
     * @param $element
     * @param $classString
     * @param $inlineStyle
     */
    public function getODTPropertiesNew (&$dest, iElementCSSMatchable $element, $media_sel=NULL) {
        if ($media_sel === NULL) {
            $media_sel = $this->config->getParam ('media_sel');
        }

        $save = $this->importnew->getMedia();
        $this->importnew->setMedia($media_sel);
        
        // Get properties for our class/element from imported CSS
        $this->importnew->getPropertiesForElement($dest, $element);

        // Adjust values for ODT
        foreach ($dest as $property => $value) {
            $dest [$property] = $this->adjustValueForODT ($property, $value, 14);
        }

        $this->importnew->setMedia($save);
    }

    /**
     * This function creates a text style.
     * 
     * @see ODTDocument::createTextStyle for detailed desciption.
     */
    public function createTextStyle ($properties, $common=true) {
        $this->document->createTextStyle ($properties, $common);
    }

    /**
     * This function creates a paragraph style.
     * 
     * @see ODTDocument::createParagraphStyle for detailed desciption.
     */
    public function createParagraphStyle ($properties, $common=true) {
        $this->document->createParagraphStyle ($properties, $common);
    }

    /**
     * This function creates a table style.
     * 
     * @see ODTDocument::createTableStyle for detailed desciption.
     */
    public function createTableStyle ($properties, $common=true) {
        $this->document->createTableStyle ($properties, $common);
    }

    /**
     * This function creates a table row style.
     * 
     * @see ODTDocument::createTableRowStyle for detailed desciption.
     */
    public function createTableRowStyle ($properties, $common=true) {
        $this->document->createTableRowStyle ($properties, $common);
    }

    /**
     * This function creates a table cell style.
     * 
     * @see ODTDocument::createTableCellStyle for detailed  desciption.
     */
    public function createTableCellStyle ($properties, $common=true) {
        $this->document->createTableCellStyle ($properties, $common);
    }

    /**
     * This function creates a table column style.
     * 
     * @see ODTDocument::createTableColumnStyle for detailed desciption.
     */
    public function createTableColumnStyle ($properties, $common=true) {
        $this->document->createTableColumnStyle ($properties, $common);
    }

    public function styleExists ($style_name) {
        return $this->document->styleExists($style_name);
    }

    /**
     * General internal function for closing an element.
     * Can always be used to close any open element if no more actions
     * are required apart from generating the closing tag and
     * removing the element from the state stack.
     */
    protected function closeCurrentElement(&$content=NULL) {
        $current = $this->document->state->getCurrent();
        if ($current != NULL) {
            $this->doc .= $current->getClosingTag($content);
            $this->document->state->leave();
        }
    }
}

//Setup VIM: ex: et ts=4 enc=utf-8 :
