<?php

/* vim: set expandtab tabstop=4 shiftwidth=4: */
//
// +----------------------------------------------------------------------+
// | PHP Version 4                                                        |
// +----------------------------------------------------------------------+
// | Copyright (c) 1997-2002 The PHP Group                                |
// +----------------------------------------------------------------------+
// | This source file is subject toversion 3.0 of the PHP license,      |
// | that is bundled with this package in the file LICENSE, and is        |
// | available at through the world-wide-web at                           |
// | http://www.php.net/license/3_0.txt.                                  |
// | If you did not receive a copy of the PHP license and are unable to   |
// | obtain it through the world-wide-web, please send a note to          |
// | license@php.net so we can mail you a copy immediately.               |
// +----------------------------------------------------------------------+
// | Authors: Alexander Zhukov <alex@veresk.ru> Original port from Python |
// | Authors: Harry Fuecks <hfuecks@phppatterns.com> Port to PEAR + more  |
// | Authors: Many @ Sitepointforums Advanced PHP Forums                  |
// +----------------------------------------------------------------------+
//
// $Id: States.php,v 1.3 2007/10/29 21:41:35 hfuecks Exp $
//
/**
 * Parsing states.
 * @package XML_HTMLSax3
 * @version $Id: States.php,v 1.3 2007/10/29 21:41:35 hfuecks Exp $
 */
/**
 * Define parser states
 */
define('XML_HTMLSAX3_STATE_STOP', 0);
define('XML_HTMLSAX3_STATE_START', 1);
define('XML_HTMLSAX3_STATE_TAG', 2);
define('XML_HTMLSAX3_STATE_OPENING_TAG', 3);
define('XML_HTMLSAX3_STATE_CLOSING_TAG', 4);
define('XML_HTMLSAX3_STATE_ESCAPE', 6);
define('XML_HTMLSAX3_STATE_JASP', 7);
define('XML_HTMLSAX3_STATE_PI', 8);

/**
 * StartingState searches for the start of any XML tag
 * @package XML_HTMLSax3
 * @access protected
 */
class XML_HTMLSax3_StartingState
{
    /**
     * @param XML_HTMLSax3_StateParser subclass
     * @return constant XML_HTMLSAX3_STATE_TAG
     * @access protected
     */
    function parse(&$context)
    {
        $data = $context->scanUntilString('<');
        if ($data != '') {
            $context->handler_object_data->
            {$context->handler_method_data}($context->htmlsax, $data);
        }
        $context->IgnoreCharacter();
        return XML_HTMLSAX3_STATE_TAG;
    }
}

/**
 * Decides which state to move one from after StartingState
 * @package XML_HTMLSax3
 * @access protected
 */
class XML_HTMLSax3_TagState
{
    /**
     * @param XML_HTMLSax3_StateParser subclass
     * @return constant the next state to move into
     * @access protected
     */
    function parse(&$context)
    {
        switch ($context->ScanCharacter()) {
            case '/':
                return XML_HTMLSAX3_STATE_CLOSING_TAG;
                break;
            case '?':
                return XML_HTMLSAX3_STATE_PI;
                break;
            case '%':
                return XML_HTMLSAX3_STATE_JASP;
                break;
            case '!':
                return XML_HTMLSAX3_STATE_ESCAPE;
                break;
            default:
                $context->unscanCharacter();
                return XML_HTMLSAX3_STATE_OPENING_TAG;
        }
    }
}

/**
 * Dealing with closing XML tags
 * @package XML_HTMLSax3
 * @access protected
 */
class XML_HTMLSax3_ClosingTagState
{
    /**
     * @param XML_HTMLSax3_StateParser subclass
     * @return constant XML_HTMLSAX3_STATE_START
     * @access protected
     */
    function parse(&$context)
    {
        $tag = $context->scanUntilCharacters('/>');
        if ($tag != '') {
            $char = $context->scanCharacter();
            if ($char == '/') {
                $char = $context->scanCharacter();
                if ($char != '>') {
                    $context->unscanCharacter();
                }
            }
            $context->handler_object_element->
            {$context->handler_method_closing}($context->htmlsax, $tag, FALSE);
        }
        return XML_HTMLSAX3_STATE_START;
    }
}

/**
 * Dealing with opening XML tags
 * @package XML_HTMLSax3
 * @access protected
 */
class XML_HTMLSax3_OpeningTagState
{
    /**
     * Handles attributes
     * @param string attribute name
     * @param string attribute value
     * @return void
     * @access protected
     * @see XML_HTMLSax3_AttributeStartState
     */
    function parseAttributes(&$context)
    {
        $Attributes = array();

        $context->ignoreWhitespace();
        $attributename = $context->scanUntilCharacters("=/> \n\r\t");
        while ($attributename != '') {
            $attributevalue = NULL;
            $context->ignoreWhitespace();
            $char = $context->scanCharacter();
            if ($char == '=') {
                $context->ignoreWhitespace();
                $char = $context->ScanCharacter();
                if ($char == '"') {
                    $attributevalue = $context->scanUntilString('"');
                    $context->IgnoreCharacter();
                } else if ($char == "'") {
                    $attributevalue = $context->scanUntilString("'");
                    $context->IgnoreCharacter();
                } else {
                    $context->unscanCharacter();
                    $attributevalue =
                        $context->scanUntilCharacters("> \n\r\t");
                }
            } else if ($char !== NULL) {
                $attributevalue = NULL;
                $context->unscanCharacter();
            }
            $Attributes[$attributename] = $attributevalue;

            $context->ignoreWhitespace();
            $attributename = $context->scanUntilCharacters("=/> \n\r\t");
        }
        return $Attributes;
    }

    /**
     * @param XML_HTMLSax3_StateParser subclass
     * @return constant XML_HTMLSAX3_STATE_START
     * @access protected
     */
    function parse(&$context)
    {
        $tag = $context->scanUntilCharacters("/> \n\r\t");
        if ($tag != '') {
            $this->attrs = array();
            $Attributes = $this->parseAttributes($context);
            $char = $context->scanCharacter();
            if ($char == '/') {
                $char = $context->scanCharacter();
                if ($char != '>') {
                    $context->unscanCharacter();
                }
                $context->handler_object_element->
                {$context->handler_method_opening}($context->htmlsax, $tag,
                    $Attributes, TRUE);
                $context->handler_object_element->
                {$context->handler_method_closing}($context->htmlsax, $tag,
                    TRUE);
            } else {
                $context->handler_object_element->
                {$context->handler_method_opening}($context->htmlsax, $tag,
                    $Attributes, FALSE);
            }
        }
        return XML_HTMLSAX3_STATE_START;
    }
}

/**
 * Deals with XML escapes handling comments and CDATA correctly
 * @package XML_HTMLSax3
 * @access protected
 */
class XML_HTMLSax3_EscapeState
{
    /**
     * @param XML_HTMLSax3_StateParser subclass
     * @return constant XML_HTMLSAX3_STATE_START
     * @access protected
     */
    function parse(&$context)
    {
        $char = $context->ScanCharacter();
        if ($char == '-') {
            $char = $context->ScanCharacter();
            if ($char == '-') {
                $context->unscanCharacter();
                $context->unscanCharacter();
                $text = $context->scanUntilString('-->');
                $text .= $context->scanCharacter();
                $text .= $context->scanCharacter();
            } else {
                $context->unscanCharacter();
                $text = $context->scanUntilString('>');
            }
        } else if ($char == '[') {
            $context->unscanCharacter();
            $text = $context->scanUntilString(']>');
            $text .= $context->scanCharacter();
        } else {
            $context->unscanCharacter();
            $text = $context->scanUntilString('>');
        }

        $context->IgnoreCharacter();
        if ($text != '') {
            $context->handler_object_escape->
            {$context->handler_method_escape}($context->htmlsax, $text);
        }
        return XML_HTMLSAX3_STATE_START;
    }
}

/**
 * Deals with JASP/ASP markup
 * @package XML_HTMLSax3
 * @access protected
 */
class XML_HTMLSax3_JaspState
{
    /**
     * @param XML_HTMLSax3_StateParser subclass
     * @return constant XML_HTMLSAX3_STATE_START
     * @access protected
     */
    function parse(&$context)
    {
        $text = $context->scanUntilString('%>');
        if ($text != '') {
            $context->handler_object_jasp->
            {$context->handler_method_jasp}($context->htmlsax, $text);
        }
        $context->IgnoreCharacter();
        $context->IgnoreCharacter();
        return XML_HTMLSAX3_STATE_START;
    }
}

/**
 * Deals with XML processing instructions
 * @package XML_HTMLSax3
 * @access protected
 */
class XML_HTMLSax3_PiState
{
    /**
     * @param XML_HTMLSax3_StateParser subclass
     * @return constant XML_HTMLSAX3_STATE_START
     * @access protected
     */
    function parse(&$context)
    {
        $target = $context->scanUntilCharacters(" \n\r\t");
        $data = $context->scanUntilString('?>');
        if ($data != '') {
            $context->handler_object_pi->
            {$context->handler_method_pi}($context->htmlsax, $target, $data);
        }
        $context->IgnoreCharacter();
        $context->IgnoreCharacter();
        return XML_HTMLSAX3_STATE_START;
    }
}


/**
 * Trims the contents of element data from whitespace at start and end
 * @package XML_HTMLSax3
 * @access protected
 */
class XML_HTMLSax3_Trim
{
    /**
     * Original handler object
     * @var object
     * @access private
     */
    var $orig_obj;
    /**
     * Original handler method
     * @var string
     * @access private
     */
    var $orig_method;

    /**
     * Constructs XML_HTMLSax3_Trim
     * @param object handler object being decorated
     * @param string original handler method
     * @access protected
     */
    function __construct(&$orig_obj, $orig_method)
    {
        $this->orig_obj =& $orig_obj;
        $this->orig_method = $orig_method;
    }

    /**
     * Trims the data
     * @param XML_HTMLSax3
     * @param string element data
     * @access protected
     */
    function trimData(&$parser, $data)
    {
        $data = trim($data);
        if ($data != '') {
            $this->orig_obj->{$this->orig_method}($parser, $data);
        }
    }
}

/**
 * Coverts tag names to upper case
 * @package XML_HTMLSax3
 * @access protected
 */
class XML_HTMLSax3_CaseFolding
{
    /**
     * Original handler object
     * @var object
     * @access private
     */
    var $orig_obj;
    /**
     * Original open handler method
     * @var string
     * @access private
     */
    var $orig_open_method;
    /**
     * Original close handler method
     * @var string
     * @access private
     */
    var $orig_close_method;

    /**
     * Constructs XML_HTMLSax3_CaseFolding
     * @param object handler object being decorated
     * @param string original open handler method
     * @param string original close handler method
     * @access protected
     */
    function __construct(&$orig_obj, $orig_open_method, $orig_close_method)
    {
        $this->orig_obj =& $orig_obj;
        $this->orig_open_method = $orig_open_method;
        $this->orig_close_method = $orig_close_method;
    }

    /**
     * Folds up open tag callbacks
     * @param XML_HTMLSax3
     * @param string tag name
     * @param array tag attributes
     * @access protected
     */
    function foldOpen(&$parser, $tag, $attrs = array(), $empty = FALSE)
    {
        $this->orig_obj->{$this->orig_open_method}($parser, strtoupper($tag), $attrs, $empty);
    }

    /**
     * Folds up close tag callbacks
     * @param XML_HTMLSax3
     * @param string tag name
     * @access protected
     */
    function foldClose(&$parser, $tag, $empty = FALSE)
    {
        $this->orig_obj->{$this->orig_close_method}($parser, strtoupper($tag), $empty);
    }
}

/**
 * Breaks up data by linefeed characters, resulting in additional
 * calls to the data handler
 * @package XML_HTMLSax3
 * @access protected
 */
class XML_HTMLSax3_Linefeed
{
    /**
     * Original handler object
     * @var object
     * @access private
     */
    var $orig_obj;
    /**
     * Original handler method
     * @var string
     * @access private
     */
    var $orig_method;

    /**
     * Constructs XML_HTMLSax3_LineFeed
     * @param object handler object being decorated
     * @param string original handler method
     * @access protected
     */
    function __construct(&$orig_obj, $orig_method)
    {
        $this->orig_obj =& $orig_obj;
        $this->orig_method = $orig_method;
    }

    /**
     * Breaks the data up by linefeeds
     * @param XML_HTMLSax3
     * @param string element data
     * @access protected
     */
    function breakData(&$parser, $data)
    {
        $data = explode("\n", $data);
        foreach ($data as $chunk) {
            $this->orig_obj->{$this->orig_method}($parser, $chunk);
        }
    }
}

/**
 * Breaks up data by tab characters, resulting in additional
 * calls to the data handler
 * @package XML_HTMLSax3
 * @access protected
 */
class XML_HTMLSax3_Tab
{
    /**
     * Original handler object
     * @var object
     * @access private
     */
    var $orig_obj;
    /**
     * Original handler method
     * @var string
     * @access private
     */
    var $orig_method;

    /**
     * Constructs XML_HTMLSax3_Tab
     * @param object handler object being decorated
     * @param string original handler method
     * @access protected
     */
    function __construct(&$orig_obj, $orig_method)
    {
        $this->orig_obj =& $orig_obj;
        $this->orig_method = $orig_method;
    }

    /**
     * Breaks the data up by linefeeds
     * @param XML_HTMLSax3
     * @param string element data
     * @access protected
     */
    function breakData(&$parser, $data)
    {
        $data = explode("\t", $data);
        foreach ($data as $chunk) {
            $this->orig_obj->{$this->orig_method}($this, $chunk);
        }
    }
}

/**
 * Breaks up data by XML entities and parses them with html_entity_decode(),
 * resulting in additional calls to the data handler<br />
 * Requires PHP 4.3.0+
 * @package XML_HTMLSax3
 * @access protected
 */
class XML_HTMLSax3_Entities_Parsed
{
    /**
     * Original handler object
     * @var object
     * @access private
     */
    var $orig_obj;
    /**
     * Original handler method
     * @var string
     * @access private
     */
    var $orig_method;

    /**
     * Constructs XML_HTMLSax3_Entities_Parsed
     * @param object handler object being decorated
     * @param string original handler method
     * @access protected
     */
    function __construct(&$orig_obj, $orig_method)
    {
        $this->orig_obj =& $orig_obj;
        $this->orig_method = $orig_method;
    }

    /**
     * Breaks the data up by XML entities
     * @param XML_HTMLSax3
     * @param string element data
     * @access protected
     */
    function breakData(&$parser, $data)
    {
        $data = preg_split('/(&.+?;)/', $data, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        foreach ($data as $chunk) {
            $chunk = html_entity_decode($chunk, ENT_NOQUOTES);
            $this->orig_obj->{$this->orig_method}($this, $chunk);
        }
    }
}

/**
 * Compatibility with older PHP versions
 */
if (version_compare(phpversion(), '4.3', '<') && !function_exists('html_entity_decode')) {
    /**
     * PHP 4.3 前版本的 html_entity_decode 兼容垫片（仅 version_compare 判定低版本且未内置时定义）。
     * PHP 8.5 下该 if 分支恒假，本定义实际不生效；保留属第三方原件原文，勿动。
     * @param string $str 含命名实体的字符串
     * @param int $style 转义风格（默认 ENT_NOQUOTES）
     * @return string 实体还原后的字符串
     */
    function html_entity_decode($str, $style = ENT_NOQUOTES)
    {
        return strtr($str,
            array_flip(get_html_translation_table(HTML_ENTITIES, $style)));
    }
}

/**
 * Breaks up data by XML entities but leaves them unparsed,
 * resulting in additional calls to the data handler<br />
 * @package XML_HTMLSax3
 * @access protected
 */
class XML_HTMLSax3_Entities_Unparsed
{
    /**
     * Original handler object
     * @var object
     * @access private
     */
    var $orig_obj;
    /**
     * Original handler method
     * @var string
     * @access private
     */
    var $orig_method;

    /**
     * Constructs XML_HTMLSax3_Entities_Unparsed
     * @param object handler object being decorated
     * @param string original handler method
     * @access protected
     */
    function __construct(&$orig_obj, $orig_method)
    {
        $this->orig_obj =& $orig_obj;
        $this->orig_method = $orig_method;
    }

    /**
     * Breaks the data up by XML entities
     * @param XML_HTMLSax3
     * @param string element data
     * @access protected
     */
    function breakData(&$parser, $data)
    {
        $data = preg_split('/(&.+?;)/', $data, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        foreach ($data as $chunk) {
            $this->orig_obj->{$this->orig_method}($this, $chunk);
        }
    }
}

/**
 * Strips the HTML comment markers or CDATA sections from an escape.
 * If XML_OPTIONS_FULL_ESCAPES is on, this decorator is not used.<br />
 * @package XML_HTMLSax3
 * @access protected
 */
class XML_HTMLSax3_Escape_Stripper
{
    /**
     * Original handler object
     * @var object
     * @access private
     */
    var $orig_obj;
    /**
     * Original handler method
     * @var string
     * @access private
     */
    var $orig_method;

    /**
     * Constructs XML_HTMLSax3_Entities_Unparsed
     * @param object handler object being decorated
     * @param string original handler method
     * @access protected
     */
    function __construct(&$orig_obj, $orig_method)
    {
        $this->orig_obj =& $orig_obj;
        $this->orig_method = $orig_method;
    }

    /**
     * Breaks the data up by XML entities
     * @param XML_HTMLSax3
     * @param string element data
     * @access protected
     */
    function strip(&$parser, $data)
    {
        // Check for HTML comments first
        if (substr($data, 0, 2) == '--') {
            $patterns = array(
                '/^\-\-/',          // Opening comment: --
                '/\-\-$/',          // Closing comment: --
            );
            $data = preg_replace($patterns, '', $data);

            // Check for XML CDATA sections (note: don't do both!)
        } else if (substr($data, 0, 1) == '[') {
            $patterns = array(
                '/^\[.*CDATA.*\[/s', // Opening CDATA
                '/\].*\]$/s',       // Closing CDATA
            );
            $data = preg_replace($patterns, '', $data);
        }

        $this->orig_obj->{$this->orig_method}($this, $data);
    }
}


/**
 * Base State Parser
 * @package XML_HTMLSax3
 * @access protected
 * @abstract
 */
class XML_HTMLSax3_StateParser
{
    /**
     * Instance of user front end class to be passed to callbacks
     * @var XML_HTMLSax3
     * @access private
     */
    var $htmlsax;
    /**
     * User defined object for handling elements
     * @var object
     * @access private
     */
    var $handler_object_element;
    /**
     * User defined open tag handler method
     * @var string
     * @access private
     */
    var $handler_method_opening;
    /**
     * User defined close tag handler method
     * @var string
     * @access private
     */
    var $handler_method_closing;
    /**
     * User defined object for handling data in elements
     * @var object
     * @access private
     */
    var $handler_object_data;
    /**
     * User defined data handler method
     * @var string
     * @access private
     */
    var $handler_method_data;
    /**
     * User defined object for handling processing instructions
     * @var object
     * @access private
     */
    var $handler_object_pi;
    /**
     * User defined processing instruction handler method
     * @var string
     * @access private
     */
    var $handler_method_pi;
    /**
     * User defined object for handling JSP/ASP tags
     * @var object
     * @access private
     */
    var $handler_object_jasp;
    /**
     * User defined JSP/ASP handler method
     * @var string
     * @access private
     */
    var $handler_method_jasp;
    /**
     * User defined object for handling XML escapes
     * @var object
     * @access private
     */
    var $handler_object_escape;
    /**
     * User defined XML escape handler method
     * @var string
     * @access private
     */
    var $handler_method_escape;
    /**
     * User defined handler object or NullHandler
     * @var object
     * @access private
     */
    var $handler_default;
    /**
     * Parser options determining parsing behavior
     * @var array
     * @access private
     */
    var $parser_options = array();
    /**
     * XML document being parsed
     * @var string
     * @access private
     */
    var $rawtext;
    /**
     * Position in XML document relative to start (0)
     * @var int
     * @access private
     */
    var $position;
    /**
     * Length of the XML document in characters
     * @var int
     * @access private
     */
    var $length;
    /**
     * Array of state objects
     * @var array
     * @access private
     */
    var $State = array();

    /**
     * Constructs XML_HTMLSax3_StateParser setting up states
     * @var XML_HTMLSax3 instance of user front end class
     * @access protected
     */
    function __construct(& $htmlsax)
    {
        $this->htmlsax = &$htmlsax;
        $this->State[XML_HTMLSAX3_STATE_START] = new XML_HTMLSax3_StartingState();

        $this->State[XML_HTMLSAX3_STATE_CLOSING_TAG] = new XML_HTMLSax3_ClosingTagState();
        $this->State[XML_HTMLSAX3_STATE_TAG] = new XML_HTMLSax3_TagState();
        $this->State[XML_HTMLSAX3_STATE_OPENING_TAG] = new XML_HTMLSax3_OpeningTagState();

        $this->State[XML_HTMLSAX3_STATE_PI] = new XML_HTMLSax3_PiState();
        $this->State[XML_HTMLSAX3_STATE_JASP] = new XML_HTMLSax3_JaspState();
        $this->State[XML_HTMLSAX3_STATE_ESCAPE] = new XML_HTMLSax3_EscapeState();
    }

    /**
     * Moves the position back one character
     * @access protected
     * @return void
     */
    function unscanCharacter()
    {
        $this->position -= 1;
    }

    /**
     * Moves the position forward one character
     * @access protected
     * @return void
     */
    function ignoreCharacter()
    {
        $this->position += 1;
    }

    /**
     * Returns the next character from the XML document or void if at end
     * @access protected
     * @return mixed
     */
    function scanCharacter()
    {
        if ($this->position < $this->length) {
            return $this->rawtext[$this->position++];
        }
    }

    /**
     * Returns a string from the current position to the next occurance
     * of the supplied string
     * @param string string to search until
     * @access protected
     * @return string
     */
    function scanUntilString($string)
    {
        $start = $this->position;
        $this->position = strpos($this->rawtext, $string, $start);
        if ($this->position === FALSE) {
            $this->position = $this->length;
        }
        return substr($this->rawtext, $start, $this->position - $start);
    }

    /**
     * Returns a string from the current position until the first instance of
     * one of the characters in the supplied string argument
     * @param string string to search until
     * @access protected
     * @return string
     * @abstract
     */
    function scanUntilCharacters($string)
    {
    }

    /**
     * Moves the position forward past any whitespace characters
     * @access protected
     * @return void
     * @abstract
     */
    function ignoreWhitespace()
    {
    }

    /**
     * Begins the parsing operation, setting up any decorators, depending on
     * parse options invoking _parse() to execute parsing
     * @param string XML document to parse
     * @access protected
     * @return void
     */
    function parse($data)
    {
        if ($this->parser_options['XML_OPTION_TRIM_DATA_NODES'] == 1) {
            $decorator = new XML_HTMLSax3_Trim(
                $this->handler_object_data,
                $this->handler_method_data);
            $this->handler_object_data =& $decorator;
            $this->handler_method_data = 'trimData';
        }
        if ($this->parser_options['XML_OPTION_CASE_FOLDING'] == 1) {
            $open_decor = new XML_HTMLSax3_CaseFolding(
                $this->handler_object_element,
                $this->handler_method_opening,
                $this->handler_method_closing);
            $this->handler_object_element =& $open_decor;
            $this->handler_method_opening = 'foldOpen';
            $this->handler_method_closing = 'foldClose';
        }
        if ($this->parser_options['XML_OPTION_LINEFEED_BREAK'] == 1) {
            $decorator = new XML_HTMLSax3_Linefeed(
                $this->handler_object_data,
                $this->handler_method_data);
            $this->handler_object_data =& $decorator;
            $this->handler_method_data = 'breakData';
        }
        if ($this->parser_options['XML_OPTION_TAB_BREAK'] == 1) {
            $decorator = new XML_HTMLSax3_Tab(
                $this->handler_object_data,
                $this->handler_method_data);
            $this->handler_object_data =& $decorator;
            $this->handler_method_data = 'breakData';
        }
        if ($this->parser_options['XML_OPTION_ENTITIES_UNPARSED'] == 1) {
            $decorator = new XML_HTMLSax3_Entities_Unparsed(
                $this->handler_object_data,
                $this->handler_method_data);
            $this->handler_object_data =& $decorator;
            $this->handler_method_data = 'breakData';
        }
        if ($this->parser_options['XML_OPTION_ENTITIES_PARSED'] == 1) {
            $decorator = new XML_HTMLSax3_Entities_Parsed(
                $this->handler_object_data,
                $this->handler_method_data);
            $this->handler_object_data =& $decorator;
            $this->handler_method_data = 'breakData';
        }
        // Note switched on by default
        if ($this->parser_options['XML_OPTION_STRIP_ESCAPES'] == 1) {
            $decorator = new XML_HTMLSax3_Escape_Stripper(
                $this->handler_object_escape,
                $this->handler_method_escape);
            $this->handler_object_escape =& $decorator;
            $this->handler_method_escape = 'strip';
        }
        $this->rawtext = $data;
        $this->length = strlen($data);
        $this->position = 0;
        $this->_parse();
    }

    /**
     * Performs the parsing itself, delegating calls to a specific parser
     * state
     * @param constant state object to parse with
     * @access protected
     * @return void
     */
    function _parse($state = XML_HTMLSAX3_STATE_START)
    {
        do {
            $state = $this->State[$state]->parse($this);
        } while ($state != XML_HTMLSAX3_STATE_STOP &&
        $this->position < $this->length);
    }
}

/**
 * Parser for PHP Versions below 4.3.0. Uses a slower parsing mechanism than
 * the equivalent PHP 4.3.0+  subclass of StateParser
 * @package XML_HTMLSax3
 * @access protected
 * @see XML_HTMLSax3_StateParser_Gtet430
 */
class XML_HTMLSax3_StateParser_Lt430 extends XML_HTMLSax3_StateParser
{
    /**
     * Constructs XML_HTMLSax3_StateParser_Lt430 defining available
     * parser options
     * @var XML_HTMLSax3 instance of user front end class
     * @access protected
     */
    function __construct(& $htmlsax)
    {
        parent::__construct($htmlsax);
        $this->parser_options['XML_OPTION_TRIM_DATA_NODES'] = 0;
        $this->parser_options['XML_OPTION_CASE_FOLDING'] = 0;
        $this->parser_options['XML_OPTION_LINEFEED_BREAK'] = 0;
        $this->parser_options['XML_OPTION_TAB_BREAK'] = 0;
        $this->parser_options['XML_OPTION_ENTITIES_PARSED'] = 0;
        $this->parser_options['XML_OPTION_ENTITIES_UNPARSED'] = 0;
        $this->parser_options['XML_OPTION_STRIP_ESCAPES'] = 0;
    }

    /**
     * Returns a string from the current position until the first instance of
     * one of the characters in the supplied string argument
     * @param string string to search until
     * @access protected
     * @return string
     */
    function scanUntilCharacters($string)
    {
        $startpos = $this->position;
        while ($this->position < $this->length && !str_contains($string, $this->rawtext[$this->position])) {
            $this->position++;
        }
        return substr($this->rawtext, $startpos, $this->position - $startpos);
    }

    /**
     * Moves the position forward past any whitespace characters
     * @access protected
     * @return void
     */
    function ignoreWhitespace()
    {
        while ($this->position < $this->length &&
            str_contains(" \n\r\t", $this->rawtext[$this->position])) {
            $this->position++;
        }
    }

    /**
     * Begins the parsing operation, setting up the unparsed XML entities
     * decorator if necessary then delegating further work to parent
     * @param string XML document to parse
     * @access protected
     * @return void
     */
    function parse($data)
    {
        parent::parse($data);
    }
}

/**
 * Parser for PHP Versions equal to or greater than 4.3.0. Uses a faster
 * parsing mechanism than the equivalent PHP < 4.3.0 subclass of StateParser
 * @package XML_HTMLSax3
 * @access protected
 * @see XML_HTMLSax3_StateParser_Lt430
 */
class XML_HTMLSax3_StateParser_Gtet430 extends XML_HTMLSax3_StateParser
{
    /**
     * Constructs XML_HTMLSax3_StateParser_Gtet430 defining available
     * parser options
     * @var XML_HTMLSax3 instance of user front end class
     * @access protected
     */
    function __construct(& $htmlsax)
    {
        parent::__construct($htmlsax);
        $this->parser_options['XML_OPTION_TRIM_DATA_NODES'] = 0;
        $this->parser_options['XML_OPTION_CASE_FOLDING'] = 0;
        $this->parser_options['XML_OPTION_LINEFEED_BREAK'] = 0;
        $this->parser_options['XML_OPTION_TAB_BREAK'] = 0;
        $this->parser_options['XML_OPTION_ENTITIES_PARSED'] = 0;
        $this->parser_options['XML_OPTION_ENTITIES_UNPARSED'] = 0;
        $this->parser_options['XML_OPTION_STRIP_ESCAPES'] = 0;
    }

    /**
     * Returns a string from the current position until the first instance of
     * one of the characters in the supplied string argument.
     * @param string string to search until
     * @access protected
     * @return string
     */
    function scanUntilCharacters($string)
    {
        $startpos = $this->position;
        $length = strcspn($this->rawtext, $string, $startpos);
        $this->position += $length;
        return substr($this->rawtext, $startpos, $length);
    }

    /**
     * Moves the position forward past any whitespace characters
     * @access protected
     * @return void
     */
    function ignoreWhitespace()
    {
        $this->position += strspn($this->rawtext, " \n\r\t", $this->position);
    }

    /**
     * Begins the parsing operation, setting up the parsed and unparsed
     * XML entity decorators if necessary then delegating further work
     * to parent
     * @param string XML document to parse
     * @access protected
     * @return void
     */
    function parse($data)
    {
        parent::parse($data);
    }
}

/**
 * Default NullHandler for methods which were not set by user
 * @package XML_HTMLSax3
 * @access protected
 */
class XML_HTMLSax3_NullHandler
{
    /**
     * Generic handler method which does nothing
     * @access protected
     * @return void
     */
    function DoNothing()
    {
    }
}

/**
 * User interface class. All user calls should only be made to this class
 * @package XML_HTMLSax3
 * @access public
 */
class XML_HTMLSax3
{
    /**
     * Instance of concrete subclass of XML_HTMLSax3_StateParser
     * @var XML_HTMLSax3_StateParser
     * @access private
     */
    var $state_parser;

    /**
     * Constructs XML_HTMLSax3 selecting concrete StateParser subclass
     * depending on PHP version being used as well as setting the default
     * NullHandler for all callbacks<br />
     * <b>Example:</b>
     * <pre>
     * $myHandler = & new MyHandler();
     * $parser = new XML_HTMLSax3();
     * $parser->set_object($myHandler);
     * $parser->set_option('XML_OPTION_CASE_FOLDING');
     * $parser->set_element_handler('myOpenHandler','myCloseHandler');
     * $parser->set_data_handler('myDataHandler');
     * $parser->parser($xml);
     * </pre>
     * @access public
     */
    function __construct()
    {
        if (version_compare(phpversion(), '4.3', 'ge')) {
            $this->state_parser = new XML_HTMLSax3_StateParser_Gtet430($this);
        } else {
            $this->state_parser = new XML_HTMLSax3_StateParser_Lt430($this);
        }
        $nullhandler = new XML_HTMLSax3_NullHandler();
        $this->set_object($nullhandler);
        $this->set_element_handler('DoNothing', 'DoNothing');
        $this->set_data_handler('DoNothing');
        $this->set_pi_handler('DoNothing');
        $this->set_jasp_handler('DoNothing');
        $this->set_escape_handler('DoNothing');
    }

    /**
     * Sets the user defined handler object. Returns a PEAR Error
     * if supplied argument is not an object.
     * @param object handler object containing SAX callback methods
     * @access public
     * @return mixed
     */
    function set_object(&$object)
    {
        if (is_object($object)) {
            $this->state_parser->handler_default =& $object;
            return true;
        } else {
            require_once('PEAR.php');
            PEAR::raiseError('XML_HTMLSax3::set_object requires ' .
                'an object instance');
        }
    }

    /**
     * Sets a parser option. By default all options are switched off.
     * Returns a PEAR Error if option is invalid<br />
     * <b>Available options:</b>
     * <ul>
     * <li>XML_OPTION_TRIM_DATA_NODES: trim whitespace off the beginning
     * and end of data passed to the data handler</li>
     * <li>XML_OPTION_LINEFEED_BREAK: linefeeds result in additional data
     * handler calls</li>
     * <li>XML_OPTION_TAB_BREAK: tabs result in additional data handler
     * calls</li>
     * <li>XML_OPTION_ENTITIES_UNPARSED: XML entities are returned as
     * seperate data handler calls in unparsed form</li>
     * <li>XML_OPTION_ENTITIES_PARSED: (PHP 4.3.0+ only) XML entities are
     * returned as seperate data handler calls and are parsed with
     * PHP's html_entity_decode() function</li>
     * <li>XML_OPTION_STRIP_ESCAPES: strips out the -- -- comment markers
     * or CDATA markup inside an XML escape, if found.</li>
     * </ul>
     * To get HTMLSax to behave in the same way as the native PHP SAX parser,
     * using it's default state, you need to switch on XML_OPTION_LINEFEED_BREAK,
     * XML_OPTION_ENTITIES_PARSED and XML_OPTION_CASE_FOLDING
     * @param string name of parser option
     * @param int (optional) 1 to switch on, 0 for off
     * @access public
     * @return boolean
     */
    function set_option($name, $value = 1)
    {
        if (array_key_exists($name, $this->state_parser->parser_options)) {
            $this->state_parser->parser_options[$name] = $value;
            return true;
        } else {
            require_once('PEAR.php');
            PEAR::raiseError('XML_HTMLSax3::set_option(' . $name . ') illegal');
        }
    }

    /**
     * Sets the data handler method which deals with the contents of XML
     * elements.<br />
     * The handler method must accept two arguments, the first being an
     * instance of XML_HTMLSax3 and the second being the contents of an
     * XML element e.g.
     * <pre>
     * function myDataHander(& $parser,$data){}
     * </pre>
     * @param string name of method
     * @access public
     * @return void
     * @see set_object
     */
    function set_data_handler($data_method)
    {
        $this->state_parser->handler_object_data =& $this->state_parser->handler_default;
        $this->state_parser->handler_method_data = $data_method;
    }

    /**
     * Sets the open and close tag handlers
     * <br />The open handler method must accept three arguments; the parser,
     * the tag name and an array of attributes e.g.
     * <pre>
     * function myOpenHander(& $parser,$tagname,$attrs=array()){}
     * </pre>
     * The close handler method must accept two arguments; the parser and
     * the tag name e.g.
     * <pre>
     * function myCloseHander(& $parser,$tagname){}
     * </pre>
     * @param string name of open method
     * @param string name of close method
     * @access public
     * @return void
     * @see set_object
     */
    function set_element_handler($opening_method, $closing_method)
    {
        $this->state_parser->handler_object_element =& $this->state_parser->handler_default;
        $this->state_parser->handler_method_opening = $opening_method;
        $this->state_parser->handler_method_closing = $closing_method;
    }

    /**
     * Sets the processing instruction handler method e.g. for PHP open
     * and close tags<br />
     * The handler method must accept three arguments; the parser, the
     * PI target and data inside the PI
     * <pre>
     * function myPIHander(& $parser,$target, $data){}
     * </pre>
     * @param string name of method
     * @access public
     * @return void
     * @see set_object
     */
    function set_pi_handler($pi_method)
    {
        $this->state_parser->handler_object_pi =& $this->state_parser->handler_default;
        $this->state_parser->handler_method_pi = $pi_method;
    }

    /**
     * Sets the XML escape handler method e.g. for comments and doctype
     * declarations<br />
     * The handler method must accept two arguments; the parser and the
     * contents of the escaped section
     * <pre>
     * function myEscapeHander(& $parser, $data){}
     * </pre>
     * @param string name of method
     * @access public
     * @return void
     * @see set_object
     */
    function set_escape_handler($escape_method)
    {
        $this->state_parser->handler_object_escape =& $this->state_parser->handler_default;
        $this->state_parser->handler_method_escape = $escape_method;
    }

    /**
     * Sets the JSP/ASP markup handler<br />
     * The handler method must accept two arguments; the parser and
     * body of the JASP tag
     * <pre>
     * function myJaspHander(& $parser, $data){}
     * </pre>
     * @param string name of method
     * @access public
     * @return void
     * @see set_object
     */
    function set_jasp_handler($jasp_method)
    {
        $this->state_parser->handler_object_jasp =& $this->state_parser->handler_default;
        $this->state_parser->handler_method_jasp = $jasp_method;
    }

    /**
     * Returns the current string position of the "cursor" inside the XML
     * document
     * <br />Intended for use from within a user defined handler called
     * via the $parser reference e.g.
     * <pre>
     * function myDataHandler(& $parser,$data) {
     *     echo( 'Current position: '.$parser->get_current_position() );
     * }
     * </pre>
     * @access public
     * @return int
     * @see get_length
     */
    function get_current_position()
    {
        return $this->state_parser->position;
    }

    /**
     * Returns the string length of the XML document being parsed
     * @access public
     * @return int
     */
    function get_length()
    {
        return $this->state_parser->length;
    }

    /**
     * Start parsing some XML
     * @param string XML document
     * @access public
     * @return void
     */
    function parse($data)
    {
        $this->state_parser->parse($data);
    }
}

// class HTML_White by axiuno@gmail.com —— 第三方署名，保留（见根目录 NOTICE）
class HTML_White
{
    private $_stack = array();    //
    private $_dcStack = array();    // 删除的栈
    private $_dcCounter = array();    // 删除的标签数
    private $_xhtml = '';
    private $_counter = array();         // 打开的标签数
    private $tableTags = array('caption', 'col', 'colgroup', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr');
    private $closeParagraph = array(
        'address', 'blockquote', 'center', 'dd', 'dir', 'div',
        'dl', 'dt', 'h1', 'h2', 'h3', 'h4',
        'h5', 'h6', 'hr', 'isindex', 'listing', 'marquee',
        'menu', 'multicol', 'ol', 'p', 'plaintext', 'pre',
        'table', 'ul', 'xmp',
    );

    private $listTags = array('dir', 'menu', 'ol', 'ul', 'dl');
    private $_liStack = array();
    private $_listScope = 0;
    protected $protoRegexps = array();
    protected $cssRegexps = array();
    protected $allowTags = array();
    private $singleTags = array('area', 'br', 'img', 'input', 'hr', 'wbr');
    public $deleteTags = array(
        'applet', 'base', 'basefont', 'bgsound', 'blink', 'body',
        'embed', 'frame', 'frameset', 'head', 'html', 'ilayer',
        'iframe', 'layer', 'link', 'meta', 'object', 'style',
        'title', 'script',
    );
    public $deleteTagsContent = array('script', 'style', 'title', 'xml',);
    /**
     * Type of protocols filtering ('white' or 'black')
     *
     * @var string
     */
    public $protocolFiltering = 'white';
    /**
     * List of "dangerous" protocols (used for blacklist-filtering)
     *
     * @var array
     */
    public $blackProtocols = array(
        'about', 'chrome', 'data', 'disk', 'hcp',
        'help', 'javascript', 'livescript', 'lynxcgi', 'lynxexec',
        'ms-help', 'ms-its', 'mhtml', 'mocha', 'opera',
        'res', 'resource', 'shell', 'vbscript', 'view-source',
        'vnd.ms.radio', 'wysiwyg',
    );
    /**
     * List of "safe" protocols (used for whitelist-filtering)
     *
     * @var array
     */
    public $whiteProtocols = array(
        'ed2k', 'file', 'ftp', 'gopher', 'http', 'https',
        'irc', 'mailto', 'news', 'nntp', 'telnet', 'webcal',
        'xmpp', 'callto',
    );
    /**
     * List of attributes that can contain protocols
     *
     * @var array
     */
    public $protocolAttributes = array(
        'action', 'background', 'codebase', 'dynsrc', 'href', 'lowsrc', 'src',
    );
    /**
     * List of dangerous attributes
     *
     * @var array
     */
    public $attributes = array('dynsrc', 'id', 'name',);
    public $cssKeywords = array(
        'absolute', 'behavior', 'behaviour', 'content', 'expression',
        'fixed', 'include-source', 'moz-binding',
    );

    private $noClose = array();

    private $white_tag = array();
    private $white_css = array();
    private $white_value = array();
    private $args = array();

    function __construct($white_tag, $white_value, $white_css, $args)
    {
        $this->white_tag = $white_tag;
        $this->white_css = $white_css;
        $this->white_value = $white_value;
        $this->args = $args;

        //making regular expressions based on Proto & CSS arrays
        foreach ($this->blackProtocols as $proto) {
            $preg = "/[\s\x01-\x1F]*";
            for ($i = 0; $i < strlen($proto); $i++) {
                $preg .= $proto[$i] . "[\s\x01-\x1F]*";
            }
            $preg .= ":/i";
            $this->protoRegexps[] = $preg;
        }

        foreach ($this->cssKeywords as $css) {
            $this->cssRegexps[] = '/' . $css . '/i';
        }
    }

    /**
     * HTML_Safe/HTML_White 主入口：预处理文档后交给 XML_HTMLSax3 逐标签回调过滤，
     * 最后由 getXHTML 产出白名单净化结果。预处理含：孤立尖括号转义、剥 NUL、
     * UTF-8 重码（Opera6 历史 bug）转义、泰文剥除（本项目增补）；UTF-7 解码调用已被注释停用。
     * @param string $doc 待净化的 HTML 原文
     * @return string 净化后的 XHTML
     */
    public function parse($doc)
    {
        // Save all '<' symbols
        $doc = preg_replace("/<(?=[^a-zA-Z\/\!\?\%])/", '&lt;', $doc);

        // Web documents shouldn't contains \x00 symbol
        $doc = str_replace("\x00", '', $doc);

        // Opera6 bug workaround
        $doc = str_replace("\xC0\xBC", '&lt;', $doc);

        // UTF-7 encoding ASCII decode
        // $doc = $this->repackUTF7($doc);

        // 过滤泰文, Filter Thai Character
        $doc = preg_replace('/\p{Thai}/u', '', $doc);

        // Instantiate the parser
        $parser = new XML_HTMLSax3();

        // Set up the parser
        $parser->set_object($this);

        $parser->set_element_handler('_openHandler', '_closeHandler');
        $parser->set_data_handler('_dataHandler');
        $parser->set_escape_handler('_escapeHandler');

        $parser->parse($doc);

        return $this->getXHTML();
    }

    /**
     * 收尾出栈：把标签栈中未闭合的标签全部补闭合，返回累积的净化结果。
     * @return string 已过滤的 XHTML
     */
    public function getXHTML()
    {
        while ($tag = array_pop($this->_stack)) {
            $this->_closeTag($tag);
        }
        return $this->_xhtml;
    }

    /**
     * 过滤属性（原注释口径）—— HTML_White 白名单净化器的属性写出环节：把解析出的属性逐条按规则
     * 收紧后，拼成 空格 + 名="值" 追加进结果串 $this->_xhtml。
     *
     * 逐条判定顺序（任一不通过就是**丢弃该属性**，标签本身仍保留）：
     *  - 属性名先小写化；**名字里含 on 或 data 的直接跳过**（一刀切挡 on* 事件与 data-* 注入，
     *    代价是名字带这两个子串的合法属性也会被丢）；
     *  - 名字不在 $attributes 白名单里 → 跳过；
     *  - style：先剥无意义反斜杠与 CSS 注释（斜杠星开头、星号斜杠结尾的成对定界内容），把 & 归一成
     *    &amp; 再解析成声明对，逐条按 white_css 的 range / list / pcre 三档钳值
     *    （width 与 min-width 超过 args['table_max_width'] 时转成百分比 100% 形态），
     *    钳完再整体过 cssRegexps 与 protoRegexps，命中即整条属性丢弃；钳完为空串的 style **不输出**
     *    （避免留一个空 style 属性，原注释「如果为 table，转换为百分比，参考值可以通过参数控制」即此）；
     *  - src：走 white_value 的 pcre 判定，且**标签是 embed / iframe 时额外收紧**——
     *    只放行 youku player 的 embed 前缀形态，其余一律置空（原注释「过滤危险 iframe / embed src」）；
     *  - 其他名字：按 white_value 的 range / list / pcre 钳值，钳不出合法值就用该规则的默认值；
     *    名字完全不在白名单里 → 跳过。
     *
     * ⚠ 协议闸（安全修复保留的要点）：属于 protocolAttributes 的属性（href/src 一类）在写出前会把
     *  数字实体（十进制与十六进制两种形态）经 chr_callback / chr_hexdec_callback 解码，再做一轮
     *  **命名实体解码 + 去空白与控制符**后取协议前缀判定（命名实体如 &colon; 可穿过字符类白名单
     *  直达浏览器解码，故必须解码后再看协议），非 whiteProtocols 的协议直接丢弃；
     *  唯一例外是 src 上的 data 协议图片（image 的 jpg/jpeg/png/gif/webp + base64 前缀）放行。
     * ⚠ 写出值本身只做**双引号转义**（双引号换成 &amp;quot; 实体），其余靠上面的白名单与钳值兜——
     *  定位是「富文本入库前的白名单过滤」层（红线 §二.4），**不是通用输出转义函数**，
     *  模板输出侧仍按上下文转义（模板裸 echo，审计 S-1）。
     * @param mixed $attrs 属性数组（名 => 值）；**不是数组时什么都不写并直接返回 TRUE**（形态由调用方兜）
     * @param string $tagname 所属标签名（仅用于 embed / iframe 的 src 特例判定）
     * @return bool 恒 TRUE（丢属性不改变返回值 ⇒ 判断某属性是否存活要看结果串，不能看返回值）
     */
    private function _writeAttrs($attrs, $tagname)
    {
        if (!is_array($attrs)) {
            return true;
        }
        foreach ($attrs as $name => $value) {

            $name = strtolower($name);

            if (str_contains($name, 'on')) continue;

            if (str_contains($name, 'data')) continue;

            if (in_array($name, $this->attributes)) continue;

            if ($name == 'style') {
                // removes insignificant backslahes
                $value = str_replace("\\", '', $value);
                // removes CSS comments
                while (1) {
                    $_value = preg_replace("!/\*.*?\*/!s", '', $value);
                    if ($_value == $value) break;
                    $value = $_value;
                }

                // replace all & to &amp;
                $value = str_replace('&amp;', '&', $value);
                $value = str_replace('&', '&amp;', $value);

                preg_match_all('#\s*([\w\-]+)\s*:\s*([^;]+)\s*;?\s*#is', $value, $m);

                $csskv = !empty($m[1]) && !empty($m[2]) ? array_combine($m[1], $m[2]) : array();

                foreach ($csskv as $cssname => &$cssvalue) {
                    if (!isset($this->white_css[$cssname])) {
                        unset($csskv[$cssname]);
                        continue;
                    }
                    $v = $this->white_css[$cssname];
                    if ($v[0] == 'range') {
                        $px = 0;
                        if (strtolower(substr($cssvalue, -2)) == 'px') {
                            $cssvalue = intval($cssvalue);
                            $px = 1;
                        }
                        if ($cssvalue < $v[2][0]) {
                            $cssvalue = $v[2][0];
                        } else if ($cssvalue > $v[2][1]) {
                            $cssvalue = $v[2][1];
                        }
                        // 如果为 table，转换为百分比，参考值可以通过参数控制。
                        if ($cssname == 'width' || $cssname == 'min-width') {
                            $px AND $cssvalue > $this->args['table_max_width'] AND $cssvalue = '100%' AND $px = 0;
                        }
                        $px AND $cssvalue .= 'px';

                    } elseif ($v[0] == 'list') {
                        if (!in_array($cssvalue, $v[2])) $cssvalue = $v[1];
                    } elseif ($v[0] == 'pcre') {
                        $ok = 0;
                        foreach ($v[2] as $pcre) {
                            if (preg_match($pcre, $cssvalue)) {
                                $ok = 1;
                                break;
                            }
                        }
                        $cssvalue = $ok ? $cssvalue : $v[1];
                    } else {
                        continue;
                    }
                }
                $value = '';
                foreach ($csskv as $k => $v) {
                    $value .= "$k:$v; ";
                }
                $value = substr($value, 0, -1);

                foreach ($this->cssRegexps as $css) {
                    if (preg_match($css, $value)) {
                        continue 2;
                    }
                }

                foreach ($this->protoRegexps as $proto) {
                    if (preg_match($proto, $value)) {
                        continue 2;
                    }
                }

                // 过滤危险 iframe / embed src=
            } elseif ($name == 'src') {
                $v = $this->white_value[$name];
                $ok = 0;
                foreach ($v[2] as $pcre) {
                    if (preg_match($pcre, $value)) {
                        $ok = 1;
                        break;
                    }
                }

                //$tag = array_pop($this->_stack);
                //array_push($this->_stack, $tag);
                if ($tagname == 'embed' || $tagname == 'iframe') {
                    //  && str_contains($value, '.swf')
                    // 'http://player.youku.com/embed/'+matches[1];
                    if (!preg_match('#^http://player\.youku\.com/embed/[\w=]+$#i', $value)) {
                        $value = '';
                    }
                }
                $value = $ok ? $value : $v[1];
                // 白名单
            } elseif (isset($this->white_value[$name])) {
                $v = $this->white_value[$name];
                if ($v[0] == 'range') {
                    $px = 0;
                    if (strtolower(substr($value, -2)) == 'px') {
                        $value = intval($value);
                        $px = 1;
                    }
                    if ($value < $v[2][0] || $value > $v[2][1]) $value = $v[1];
                    if ($px) $value .= 'px';
                } elseif ($v[0] == 'list') {
                    if (!in_array($value, $v[2])) $value = $v[1];
                } elseif ($v[0] == 'pcre') {
                    $ok = 0;
                    foreach ($v[2] as $pcre) {
                        if (preg_match($pcre, $value)) {
                            $ok = 1;
                            break;
                        }
                    }
                    $value = $ok ? $value : $v[1];
                } else {
                    continue;
                }
            } else {
                continue;
            }

            if (($value === true) || (is_null($value))) {
                $value = $name;
            }

            $tempval = preg_replace_callback('/&#(\d+);?/m', array($this, 'chr_callback'), $value); //"'
            $tempval = preg_replace_callback('/&#x([0-9a-f]+);?/mi', array($this, 'chr_hexdec_callback'), $tempval);

            // 安全修复：协议判定复活。上方 $tempval 仅解码数字实体，命名实体（&colon; 等）
            // 可穿过 href 字符类白名单直达浏览器解码执行——这里再做一轮命名实体解码 + 去空白后判定协议前缀。
            if (in_array($name, $this->protocolAttributes)) {
                $probe = preg_replace('/[\s\x00-\x1F]+/', '', strtolower(html_entity_decode($tempval, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                if (preg_match('#^([a-z0-9.+-]+):#i', $probe, $protoMatch)) {
                    $proto = $protoMatch[1];
                    $dataImageOk = ('data' === $proto && 'src' === $name && preg_match('#^data:image/(jpe?g|png|gif|webp);base64,#', $probe));
                    if (!in_array($proto, $this->whiteProtocols) && !$dataImageOk) {
                        continue; // 非白名单协议：丢弃该属性
                    }
                }
            }

            $value = str_replace("\"", "&quot;", $value);
            if ($value == '' && $name == 'style') {
                //$this->_xhtml .= ' ' . $name . '="' . $value . '"';
            } else {
                $this->_xhtml .= ' ' . $name . '="' . $value . '"';
            }
        }
        return true;
    }

    /**
     * preg_replace_callback 回调：把十进制数字实体（形如 &#60;）还原为单字节字符，
     * 供属性协议判定前归一实体绕过。
     * @param array $matchs 正则捕获组（$matchs[1]=十进制码位）
     * @return string 对应字符
     */
    private function chr_callback($matchs)
    {
        $s = $matchs[1];
        return chr($s);
    }

    /**
     * preg_replace_callback 回调：把十六进制数字实体还原为单字节字符（与 chr_callback 同用途）。
     * @param array $matchs 正则捕获组（$matchs[1]=十六进制码位串）
     * @return string 对应字符
     */
    private function chr_hexdec_callback($matchs)
    {
        $s = $matchs[1];
        return chr(hexdec($s));
    }

    /**
     * XML_HTMLSax3 开标签回调（SAX 处理器，白名单过滤核心）：
     * 删除危险标签（deleteTags）、非白名单标签连内容一并吞（_dcStack 压栈）；
     * 单标签自闭合输出；table 上下文/段落隐式闭合/同层 li 互斥/list 层级栈等 XHTML 规整逻辑；
     * 通过者经 _writeAttrs 过滤属性后写入 _xhtml。
     * @param object $parser 解析器实例（引用，SAX 约定签名）
     * @param string $name 标签名（小写化后判定）
     * @param array $attrs 属性键值
     * @return bool 恒 TRUE（SAX 处理器契约）
     */
    public function _openHandler(&$parser, $name, $attrs)
    {
        $name = strtolower($name);

        // 删除危险标签内容
        if (in_array($name, $this->deleteTagsContent)) {
            array_push($this->_dcStack, $name);
            $this->_dcCounter[$name] = isset($this->_dcCounter[$name])
                ? $this->_dcCounter[$name] + 1 : 1;
        }

        // 删除危险标签
        if (in_array($name, $this->deleteTags) && !in_array($name, $this->allowTags)
        ) {
            return true;
        }

        // 删除标签和内容
        if (!in_array($name, $this->white_tag)) {
            array_push($this->_dcStack, $name);
            $this->_dcCounter[$name] = isset($this->_dcCounter[$name]) ? $this->_dcCounter[$name] + 1 : 1;
        }

        if (count($this->_dcStack) != 0) {
            return true;
        }

        if (!preg_match("/^[a-z0-9]+$/i", $name)) {
            if (preg_match("!(?:\@|://)!i", $name)) {
                $this->_xhtml .= '&lt;' . $name . '&gt;';
            }
            return true;
        }

        if (in_array($name, $this->singleTags)) {
            $this->_xhtml .= '<' . $name;
            $this->_writeAttrs($attrs, $name);
            $this->_xhtml .= ' />';
            return true;
        }

        // TABLES: cannot open table elements when we are not inside table
        if ((isset($this->_counter['table'])) && ($this->_counter['table'] <= 0) && (in_array($name, $this->tableTags))) {
            return true;
        }

        // PARAGRAPHS: close paragraph when closeParagraph tags opening
        if ((in_array($name, $this->closeParagraph)) && (in_array('p', $this->_stack))) {
            $this->_closeHandler($parser, 'p');
        }

        // LISTS: we should close <li> if <li> of the same level opening
        if ($name == 'li' && count($this->_liStack) &&
            $this->_listScope == $this->_liStack[count($this->_liStack) - 1]
        ) {
            $this->_closeHandler($parser, 'li');
        }

        // LISTS: we want to know on what nesting level of lists we are
        if (in_array($name, $this->listTags)) {
            $this->_listScope++;
        }
        if ($name == 'li') {
            array_push($this->_liStack, $this->_listScope);
        }

        $this->_xhtml .= '<' . $name;
        $this->_writeAttrs($attrs, $name);
        $this->_xhtml .= '>';
        array_push($this->_stack, $name);
        $this->_counter[$name] = isset($this->_counter[$name]) ? $this->_counter[$name] + 1 : 1;
        return true;
    }

    /**
     * XML_HTMLSax3 闭标签回调：维持 _dcStack/_dcCounter（被吞标签的配对计数）；
     * 栈非空时继续吞内容不输出；正常标签回退栈补齐未闭合的中间标签后闭合。
     * @param object $parser 解析器实例（引用，SAX 约定签名）
     * @param string $name 标签名
     * @return bool 恒 TRUE
     */
    public function _closeHandler(&$parser, $name)
    {

        $name = strtolower($name);

        if (isset($this->_dcCounter[$name])
            && ($this->_dcCounter[$name] > 0)
            && (in_array($name, $this->deleteTagsContent))
        ) {
            while ($name != ($tag = array_pop($this->_dcStack))) {
                --$this->_dcCounter[$tag];
            }

            --$this->_dcCounter[$name];
        }

        if (count($this->_dcStack) != 0) {
            return true;
        }

        if ((isset($this->_counter[$name])) && ($this->_counter[$name] > 0)) {
            while ($name != ($tag = array_pop($this->_stack))) {
                $this->_closeTag($tag);
            }
            $this->_closeTag($name);
        }
        return true;
    }

    /**
     * XML_HTMLSax3 文本数据回调：不在被吞标签上下文（_dcStack 为空）时把文本追加进结果，
     * 其中尖括号右半转义为实体（左半已在 parse 预处理阶段处理）。
     * @param object $parser 解析器实例（引用，SAX 约定签名）
     * @param string $data 标签间文本
     * @return bool 恒 TRUE
     */
    public function _dataHandler(&$parser, $data)
    {
        if (count($this->_dcStack) == 0) {
            $this->_xhtml .= str_replace('>', '&gt;', $data);
        }
        return true;
    }

    /**
     * parser comment recall（原注释口径）—— XML_HTMLSax3 的 escape 回调，
     * 在 parse() 里经 set_escape_handler 注册（与 element/data 两个回调并列的第三路）。
     * escape 段是解析器识别出来的**注释与 CDATA 一类转义块**（上游把两者都走这条回调）。
     * 本实现是刻意的**黑洞**：什么都不追加进 $this->_xhtml，直接返回 TRUE 让解析继续
     *   ⇒ 净化结果里**不会出现任何注释与 CDATA 内容**。
     * 为什么必须这样：HTML 注释可藏条件注释与被注释掉的闭合标签（跨标签闭合把戏可复活脚本），
     * CDATA 在 HTML 语境里等价于裸文本，留着就等于绕开标签/属性白名单往输出里塞原始内容。
     * ⚠ 想改成「保留注释」前先评估这条绕过链（净化口径见 xn_html_safe()），不要顺手 return $data。
     * 返回 TRUE 是 SAX 约定的「继续解析」信号；返回 false 会让整次解析中断（不要这么写）。
     * @param object $parser 解析器实例（引用传递，SAX 约定的签名，本实现不使用）
     * @param string $data 被吞掉的注释/CDATA 原文（本实现**丢弃**，不参与输出）
     * @return bool 恒 TRUE（表示继续解析）
     */
    public function _escapeHandler(&$parser, $data)
    {
        return true;
    }

    /**
     * 向结果追加闭标签（noClose 名单除外），并维护 _counter/listScope/_liStack 计数栈。
     * @param string $tag 标签名
     * @return bool 恒 TRUE
     */
    public function _closeTag($tag)
    {
        if (!in_array($tag, $this->noClose)) {
            $this->_xhtml .= '</' . $tag . '>';
        }

        --$this->_counter[$tag];

        if (in_array($tag, $this->listTags)) {
            --$this->_listScope;
        }

        if ($tag == 'li') {
            array_pop($this->_liStack);
        }
        return true;
    }

    /**
     * UTF-7 编码段还原（形如 +AEs- 的 base64 段转普通字符）。当前 parse() 中调用点已被注释停用。
     * @param string $str 输入文本
     * @return string 还原后的文本
     */
    private function repackUTF7($str)
    {
        return preg_replace_callback('!\+([0-9a-zA-Z/]+)\-!', array($this, 'repackUTF7Callback'), $str);
    }

    /**
     * repackUTF7Callback 内层回调：把解码出的 UTF-16BE 段重新编码回 base64 UTF-7 形式。
     * @param array $str 正则捕获组
     * @return string 重新打包的片段
     */
    function repackUTF7Back($str)
    {
        return $str[1] . '+' . rtrim(base64_encode($str[2]), '=') . '-';
    }

    /**
     * repackUTF7 的 preg_replace_callback 回调：base64 解码 UTF-7 段并还原 NUL 间隔的 ASCII 字符。
     * @param array $str 正则捕获组（$str[1]=base64 串）
     * @return string 还原后的字符
     */
    public function repackUTF7Callback($str)
    {
        $str = base64_decode($str[1]);
        $str = preg_replace_callback('/^((?:\x00.)*)((?:[^\x00].)+)/', array($this, 'repackUTF7Back'), $str);
        return preg_replace('/\x00(.)/', '$1', $str);
    }
}


// 以下为 class xn_html_safe 的历史实现（作者 axiuno@gmail.com），已废弃保留供参考；现役实现见 core/func/security.php
/*function xn_html_safe($doc, $arg = array()) {
	empty($arg['table_max_width']) AND $arg['table_max_width'] = 746; // 这个宽度为 bbs 回帖宽度
	$pattern = array (
		//'img_url'=>'#^(https?://[^\'"\\\\<>:\s]+(:\d+)?)?([^\'"\\\\<>:\s]+?)*$#is',
		'img_url'=>'#^(((https?://[^\'"\\\\<>:\s]+(:\d+)?)?([^\'"\\\\<>:\s]+?)*)|(data:image/png;base64,[\w\/+]+))$#is',
		'url'=>'#^(https?://[^\'"\\\\<>:\s]+(:\d+)?)?([^\'"\\\\<>:\s]+?)*$#is', // '#https?://[\w\-/%?.=]+#is'
		'mailto'=>'#^mailto:([\w%\-\.]+)@([\w%\-\.]+)(\.[\w%\-\.]+?)+$#is',
		'ftp_url'=>'#^ftp:([\w%\-\.]+)@([\w%\-\.]+)(\.[\w%\-\.]+?)+$#is',
		'ed2k_url'=>'#^(?:ed2k|thunder|qvod|magnet)://[^\s\'\"\\\\<>]+$#is',
		'color'=>'#^(\#\w{3,6})|(rgb\(\d+,\s*\d+,\s*\d+\)|(\w{3,10}))$#is',
		'safe'=>'#^[\w\-:;\.\s\x7f-\xff]+$#is',
		'css'=>'#^[\(,\)\#;\w\-\.\s\x7f-\xff]+$#is',
		'word'=>'#^[\w\-\x7f-\xff]+$#is',
	);

	$white_tag = array('a', 'b', 'i', 'u', 'font', 'strong', 'em', 'span',
		'table', 'tr', 'td', 'th', 'tbody', 'thead', 'tfoot','caption',
		'ol', 'ul', 'li', 'dl', 'dt', 'dd', 'menu', 'multicol',
		'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'p', 'div', 'pre',
		'br', 'img', 'area',  'embed', 'code', 'blockquote', 'iframe', 'section', 'fieldset', 'legend'
	);
	$white_value = array(
		'href'=>array('pcre', '', array($pattern['url'], $pattern['ed2k_url'])),
		'src'=>array('pcre', '', array($pattern['img_url'])),
		'width'=>array('range', '', array(0, 4096)),
		'height'=>array('range', 'auto', array(0, 80000)),
		'size'=>array('range', 4, array(-10, 10)),
		'border'=>array('range', 0, array(0, 10)),
		'family'=>array('pcre', '', array($pattern['word'])),
		'class'=>array('pcre', '', array($pattern['safe'])),
		'face'=>array('pcre', '', array($pattern['word'])),
		'color'=>array('pcre', '', array($pattern['color'])),
		'alt'=>array('pcre', '', array($pattern['safe'])),
		'label'=>array('pcre', '', array($pattern['safe'])),
		'title'=>array('pcre', '', array($pattern['safe'])),
		'target'=>array('list', '_self', array('_blank', '_self')),
		'type'=>array('pcre', '', array('#^[\w/\-]+$#')),
		'allowfullscreen'=>array('list', 'true', array('true', '1', 'on')),
		'wmode'=>array('list', 'transparent', array('transparent', '')),
		'allowscriptaccess'=>array('list', 'never', array('never')),
		'value'=>array('list', '', array('#^[\w+/\-]$#')),
		'cellspacing'=>array('range', 0, array(0, 10)),
		'cellpadding'=>array('range', 0, array(0, 10)),
		'frameborder'=>array('range', 0, array(0, 10)),
		'allowfullscreen'=>array('range', 0, array(0, 10)),
		'align'=>array('list', 'left', array('left', 'center', 'right')),
		'valign'=>array('list', 'middle', array('middle', 'top', 'bottom')),
        'name'=>array('pcre', '', array($pattern['word'])),
	);
	$white_css = array(
		'font'=>array('pcre', 'none', array($pattern['safe'])),
		'font-style'=>array('pcre', 'none', array($pattern['safe'])),
		'font-weight'=>array('pcre', 'none', array($pattern['safe'])),
		'font-family'=>array('pcre', 'none', array($pattern['word'])),
		'font-size'=>array('range', 12, array(6, 48)),
		'width'=>array('range', '100%', array(1, 1800)),
		'height'=>array('range', '', array(1, 80000)),
		'min-width'=>array('range', 1, array(1, 80000)),
		'min-height'=>array('range', 400, array(1, 80000)),
		'max-width'=>array('range', 1800, array(1, 80000)),
		'max-height'=>array('range', 80000, array(1, 80000)),
		'line-height'=>array('range', '14px', array(1, 50)),
		'color'=>array('pcre', '#000000', array($pattern['color'])),
		'background'=>array('pcre', 'none', array($pattern['color'], '#url\((https?://[^\'"\\\\<>]+?:?\d?)?([^\'"\\\\<>:]+?)*\)[\w\s\-]*$#')),
		'background-color'=>array('pcre', 'none', array($pattern['color'])),
		'background-image'=>array('pcre', 'none', array($pattern['img_url'])),
		'background-position'=>array('pcre', 'none', array($pattern['safe'])),
		'border'=>array('pcre', 'none', array($pattern['css'])),
		'border-left'=>array('pcre', 'none', array($pattern['css'])),
		'border-right'=>array('pcre', 'none', array($pattern['css'])),
		'border-top'=>array('pcre', 'none', array($pattern['css'])),
		'border-left-color'=>array('pcre', 'none', array($pattern['css'])),
		'border-right-color'=>array('pcre', 'none', array($pattern['css'])),
		'border-top-color'=>array('pcre', 'none', array($pattern['css'])),
		'border-bottom-color'=>array('pcre', 'none', array($pattern['css'])),
		'border-left-width'=>array('pcre', 'none', array($pattern['css'])),
		'border-right-width'=>array('pcre', 'none', array($pattern['css'])),
		'border-top-width'=>array('pcre', 'none', array($pattern['css'])),
		'border-bottom-width'=>array('pcre', 'none', array($pattern['css'])),
		'border-bottom-style'=>array('pcre', 'none', array($pattern['css'])),
		'margin-left'=>array('range', 0, array(0, 100)),
		'margin-right'=>array('range', 0, array(0, 100)),
		'margin-top'=>array('range', 0, array(0, 100)),
		'margin-bottom'=>array('range', 0, array(0, 100)),
		'margin'=>array('pcre', '', array($pattern['safe'])),
		'padding'=>array('pcre', '', array($pattern['safe'])),
		'padding-left'=>array('range', 0, array(0, 100)),
		'padding-right'=>array('range', 0, array(0, 100)),
		'padding-top'=>array('range', 0, array(0, 100)),
		'padding-bottom'=>array('range', 0, array(0, 100)),
		'zoom'=>array('range', 1, array(1, 10)),
		'list-style'=>array('list', 'none', array('disc', 'circle', 'square', 'decimal', 'lower-roman', 'upper-roman', 'none')),
		'text-align'=>array('list', 'left', array('left', 'right', 'center', 'justify')),
		'text-indent'=>array('range', 0, array(0, 100)),
		
		// 代码高亮需要支持，但是不安全！
		
//		'position'=>array('list', 'static', array('absolute', 'fixed', 'relative', 'static')),
//		'left'=>array('range', 0, array(0, 1000)),
//		'top'=>array('range', 0, array(0, 1000)),
//		'white-space'=>array('list', 'nowrap', array('nowrap', 'pre')),
//		'word-wrap'=>array('list', 'normal', array('break-word', 'normal')),
//		'word-break'=>array('list', 'break-all', array('break-all', 'normal')),
//		'display'=>array('list', 'block', array('block', 'table', 'none', 'inline-block', 'table-cell')),
//		'overflow'=>array('list', 'auto', array('scroll', 'hidden', 'auto')),
//		'overflow-x'=>array('list', 'auto', array('scroll', 'hidden', 'auto')),
//		'overflow-y'=>array('list', 'auto', array('scroll', 'hidden', 'auto')),
//		
		
	);
	$safehtml = new HTML_White($white_tag, $white_value, $white_css, $arg);
	$result = $safehtml->parse($doc);
	return $result;
}*/
