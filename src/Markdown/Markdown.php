<?php

namespace Markdown;

/*
 * Markdown Extra  -  A text-to-HTML conversion tool for web writers
 *
 * PHP Markdown & Extra
 * Copyright (c) 2004-2012 Michel Fortin  
 * <http://michelf.com/projects/php-markdown/>
 *
 * Original Markdown
 * Copyright (c) 2004-2006 John Gruber  
 * <http://daringfireball.net/projects/markdown/>
 */


#
# Markdown Parser Class
#

class Markdown {

	# Regex to match balanced [brackets].
	# Needed to insert a maximum bracked depth while converting to PHP.
	public $nestedBracketsDepth = 6;
	protected $nestedBracketsRe;
	
	public $nestedUrlParenthesisDepth = 4;
	protected $nestedUrlParenthesisRe;

	# Table of hash values for escaped characters:
	protected $escapeChars = '\`*_{}[]()>#+-~.!';
	protected $escapeCharsRe;

	# Change to ">" for HTML output.
	public $emptyElementSuffix = ' />';
	public $tabWidth = 4;
	
	# Change to `true` to disallow markup or entities.
	public $noMarkup = false;
	public $noEntities = false;
	
	# Predefined urls and titles for reference links and images.
	protected $predefUrls = array();
	protected $predefTitles = array();


	/*
	 * Constructor function. Initialize appropriate member variables.
	 */
	public function __construct()
	{
		$this->initDetab();
		$this->prepareItalicsAndBold();
		$this->prepareStrikesAndSpoilers();
	
		$this->nestedBracketsRe = 
			str_repeat('(?>[^\[\]]+|\[', $this->nestedBracketsDepth).
			str_repeat('\])*', $this->nestedBracketsDepth);
	
		$this->nestedUrlParenthesisRe = 
			str_repeat('(?>[^()\s]+|\(', $this->nestedUrlParenthesisDepth).
			str_repeat('(?>\)))*', $this->nestedUrlParenthesisDepth);
		
		$this->escapeCharsRe = '['.preg_quote($this->escapeChars).']';
		
		# Sort document, block, and span gamut in ascendent priority order.
		asort($this->documentGamut);
		asort($this->blockGamut);
		asort($this->spanGamut);
	}

	# Internal hashes used during transformation.
	protected $urls = array();
	protected $titles = array();
	protected $htmlHashes = array();
	
	# Status flag to avoid invalid nesting.
	protected $inAnchor = false;
	
	
	/*
	 * Called before the transformation process starts to setup parser 
	 * states.
	 */
	public function setup()
	{
		# Clear global hashes.
		$this->urls = $this->predefUrls;
		$this->titles = $this->predefTitles;
		$this->htmlHashes = array();
		
		$inAnchor = false;
	}
	

	/*
	 * Called after the transformation process to clear any variable 
	 * which may be taking up memory unnecessarly.
	 */
	public function teardown()
	{
		$this->urls = array();
		$this->titles = array();
		$this->htmlHashes = array();
	}


	
	/*
	 * Main function. Performs some preprocessing on the input text
	 * and pass it through the document gamut.
	 */
	public function transform($text)
	{
		$this->setup();
	
		# Remove UTF-8 BOM and marker character in input, if present.
		$text = preg_replace('{^\xEF\xBB\xBF|\x1A}', '', $text);

		# Standardize line endings:
		#   DOS to Unix and Mac to Unix
		$text = preg_replace('{\r\n?}', "\n", $text);

		# Make sure $text ends with a couple of newlines:
		$text .= "\n\n";

		# Convert all tabs to spaces.
		$text = $this->detab($text);

		# Turn block-level HTML blocks into hash entries
		$text = $this->hashHTMLBlocks($text);

		# Strip any lines consisting only of spaces and tabs.
		# This makes subsequent regexen easier to write, because we can
		# match consecutive blank lines with /\n+/ instead of something
		# contorted like /[ ]*\n+/ .
		$text = preg_replace('/^[ ]+$/m', '', $text);

		# Run document gamut methods.
		foreach ($this->documentGamut as $method => $priority) {
			$text = $this->$method($text);
		}
		
		$this->teardown();

		return $text . "\n";
	}
	
	protected $documentGamut = array(
		# Strip link definitions, store in hashes.
		'stripLinkDefinitions' => 20,
		'runBasicBlockGamut'   => 30,
	);


	public function stripLinkDefinitions($text)
	{
	#
	# Strips link definitions from text, stores the URLs and titles in
	# hash references.
	#
		$lessThanTab = $this->tabWidth - 1;

		# Link defs are in the form: ^[id]: url "optional title"
		$text = preg_replace_callback('{
							^[ ]{0,'.$lessThanTab.'}\[(.+)\][ ]?:	# id = $1
							  [ ]*
							  \n?				# maybe *one* newline
							  [ ]*
							(?:
							  <(.+?)>			# url = $2
							|
							  (\S+?)			# url = $3
							)
							  [ ]*
							  \n?				# maybe one newline
							  [ ]*
							(?:
								(?<=\s)			# lookbehind for whitespace
								["(]
								(.*?)			# title = $4
								[")]
								[ ]*
							)?	# title is optional
							(?:\n+|\Z)
			}xm',
			array(&$this, 'stripLinkDefinitionsCallback'),
			$text);
		return $text;
	}
	private function stripLinkDefinitionsCallback($matches)
	{
		$linkId = strtolower($matches[1]);
		$url = $matches[2] == '' ? $matches[3] : $matches[2];
		$this->urls[$linkId] = $url;
		$this->titles[$linkId] =& $matches[4];
		return ''; # String that will replace the block
	}


	function hashHTMLBlocks($text) {
		if ($this->noMarkup) return $text;

		$lessThanTab = $this->tabWidth - 1;

		# Hashify HTML blocks:
		# We only want to do this for block-level HTML tags, such as headers,
		# lists, and tables. That's because we still want to wrap <p>s around
		# "paragraphs" that are wrapped in non-block-level tags, such as anchors,
		# phrase emphasis, and spans. The list of tags we're looking for is
		# hard-coded:
		#
		# *  List "a" is made of tags which can be both inline or block-level.
		#    These will be treated block-level when the start tag is alone on 
		#    its line, otherwise they're not matched here and will be taken as 
		#    inline later.
		# *  List "b" is made of tags which are always block-level;
		#
		$blockTagsARe = 'ins|del';
		$blockTagsBRe = 'p|div|h[1-6]|blockquote|pre|table|dl|ol|ul|address|'.
						   'script|noscript|form|fieldset|iframe|math';

		# Regular expression for the content of a block tag.
		$nestedTagsLevel = 4;
		$attr = '
			(?>				# optional tag attributes
			  \s			# starts with whitespace
			  (?>
				[^>"/]+		# text outside quotes
			  |
				/+(?!>)		# slash not followed by ">"
			  |
				"[^"]*"		# text inside double quotes (tolerate ">")
			  |
				\'[^\']*\'	# text inside single quotes (tolerate ">")
			  )*
			)?	
			';
		$content =
			str_repeat('
				(?>
				  [^<]+			# content without tag
				|
				  <\2			# nested opening tag
					'.$attr.'	# attributes
					(?>
					  />
					|
					  >', $nestedTagsLevel).	# end of opening tag
					  '.*?'.					# last level nested tag content
			str_repeat('
					  </\2\s*>	# closing nested tag
					)
				  |				
					<(?!/\2\s*>	# other tags with a different name
				  )
				)*',
				$nestedTagsLevel);
		$content2 = str_replace('\2', '\3', $content);

		# First, look for nested blocks, e.g.:
		# 	<div>
		# 		<div>
		# 		tags for inner block must be indented.
		# 		</div>
		# 	</div>
		#
		# The outermost tags must start at the left margin for this to match, and
		# the inner nested divs must be indented.
		# We need to do this before the next, more liberal match, because the next
		# match will start at the first `<div>` and stop at the first `</div>`.
		$text = preg_replace_callback('{(?>
			(?>
				(?<=\n\n)		# Starting after a blank line
				|				# or
				\A\n?			# the beginning of the doc
			)
			(						# save in $1

			  # Match from `\n<tag>` to `</tag>\n`, handling nested tags 
			  # in between.
					
						[ ]{0,'.$lessThanTab.'}
						<('.$blockTagsBRe.')# start tag = $2
						'.$attr.'>			# attributes followed by > and \n
						'.$content.'		# content, support nesting
						</\2>				# the matching end tag
						[ ]*				# trailing spaces/tabs
						(?=\n+|\Z)	# followed by a newline or end of document

			| # Special version for tags of group a.

						[ ]{0,'.$lessThanTab.'}
						<('.$blockTagsARe.')# start tag = $3
						'.$attr.'>[ ]*\n	# attributes followed by >
						'.$content2.'		# content, support nesting
						</\3>				# the matching end tag
						[ ]*				# trailing spaces/tabs
						(?=\n+|\Z)	# followed by a newline or end of document
					
			| # Special case just for <hr />. It was easier to make a special 
			  # case than to make the other regex more complicated.
			
						[ ]{0,'.$lessThanTab.'}
						<(hr)				# start tag = $2
						'.$attr.'			# attributes
						/?>					# the matching end tag
						[ ]*
						(?=\n{2,}|\Z)		# followed by a blank line or end of document
			
			| # Special case for standalone HTML comments:
			
					[ ]{0,'.$lessThanTab.'}
					(?s:
						<!-- .*? -->
					)
					[ ]*
					(?=\n{2,}|\Z)		# followed by a blank line or end of document
			
			| # PHP and ASP-style processor instructions (<? and <%)
			
					[ ]{0,'.$lessThanTab.'}
					(?s:
						<([?%])			# $2
						.*?
						\2>
					)
					[ ]*
					(?=\n{2,}|\Z)		# followed by a blank line or end of document
					
			)
			)}Sxmi',
			array(&$this, 'hashHTMLBlocksCallback'),
			$text);

		return $text;
	}

	private function hashHTMLBlocksCallback($matches)
	{
		$text = $matches[1];
		$key  = $this->hashBlock($text);
		return "\n\n$key\n\n";
	}


	public function hashPart($text, $boundary = 'X')
	{
	#
	# Called whenever a tag must be hashed when a function insert an atomic 
	# element in the text stream. Passing $text to through this function gives
	# a unique text-token which will be reverted back when calling unhash.
	#
	# The $boundary argument specify what character should be used to surround
	# the token. By convension, "B" is used for block elements that needs not
	# to be wrapped into paragraph tags at the end, ":" is used for elements
	# that are word separators and "X" is used in the general case.
	#
		# Swap back any tag hash found in $text so we do not have to `unhash`
		# multiple times at the end.
		$text = $this->unhash($text);
		
		# Then hash the block.
		static $i = 0;
		$key = "$boundary\x1A" . ++$i . $boundary;
		$this->htmlHashes[$key] = $text;
		return $key; # String that will replace the tag.
	}


	/*
	 * Shortcut function for hashPart with block-level boundaries.
	 */
	public function hashBlock($text)
	{
		return $this->hashPart($text, 'B');
	}


	# These are all the transformations that form block-level
	# tags like paragraphs, headers, and list items.
	protected $blockGamut = array(
		'doHeaders'         => 10,
		'doHorizontalRules' => 20,
		
		'doLists'           => 40,
		'doCodeBlocks'      => 50,
		'doBlockQuotes'     => 60,
	);

	public function runBlockGamut($text)
	{
	#
	# Run block gamut tranformations.
	#
		# We need to escape raw HTML in Markdown source before doing anything 
		# else. This need to be done for each block, and not only at the 
		# begining in the Markdown function since hashed blocks can be part of
		# list items and could have been indented. Indented blocks would have 
		# been seen as a code block in a previous pass of hashHTMLBlocks.
		$text = $this->hashHTMLBlocks($text);
		
		return $this->runBasicBlockGamut($text);
	}
	
	public function runBasicBlockGamut($text)
	{
	#
	# Run block gamut tranformations, without hashing HTML blocks. This is 
	# useful when HTML blocks are known to be already hashed, like in the first
	# whole-document pass.
	#
		foreach ($this->blockGamut as $method => $priority) {
			$text = $this->$method($text);
		}
		
		# Finally form paragraph and restore hashed blocks.
		$text = $this->formParagraphs($text);

		return $text;
	}
	
	
	public function doHorizontalRules($text)
	{
		# Do Horizontal Rules:
		return preg_replace(
			'{
				^[ ]{0,3}	# Leading space
				([-*_])		# $1: First marker
				(?>			# Repeated marker group
					[ ]{0,2}	# Zero, one, or two spaces.
					\1			# Marker character
				){2,}		# Group repeated at least twice
				[ ]*		# Tailing spaces
				$			# End of line.
			}mx',
			"\n".$this->hashBlock("<hr$this->emptyElementSuffix")."\n", 
			$text);
	}


	protected $spanGamut = array(
	#
	# These are all the transformations that occur *within* block-level
	# tags like paragraphs, headers, and list items.
	#
		# Process character escapes, code spans, and inline HTML
		# in one shot.
		'parseSpan'           => -30,

		# Process anchor and image tags. Images must come first,
		# because ![foo][f] looks like an anchor.
		'doImages'            =>  10,
		'doAnchors'           =>  20,
		
		# Make links out of things like `<http://example.com/>`
		# Must come after doAnchors, because you can use < and >
		# delimiters in inline links like [this](<url>).
		'doAutoLinks'         =>  30,
		'encodeAmpsAndAngles' =>  40,

		'doItalicsAndBold'    =>  50,
		'doStrikesAndSpoilers'=>  60,
		'doHardBreaks'        =>  70,
	);


	/*
	 * Run span gamut tranformations.
	 */
	public function runSpanGamut($text)
	{
		foreach ($this->spanGamut as $method => $priority) {
			$text = $this->$method($text);
		}

		return $text;
	}
	
	
	public function doHardBreaks($text)
	{
		# Do hard breaks:
		return preg_replace_callback('/( {2,}\n|\n{1})/', 
			array(&$this, 'doHardBreaksCallback'), $text);
	}

	private function doHardBreaksCallback($matches)
	{
		return $this->hashPart("<br$this->emptyElementSuffix\n");
	}


	
	/*
	 * Turn Markdown link shortcuts into XHTML <a> tags.
	 */
	public function doAnchors($text)
	{
		if ($this->inAnchor) return $text;
		$this->inAnchor = true;
		
		#
		# First, handle reference-style links: [link text] [id]
		#
		$text = preg_replace_callback('{
			(					# wrap whole match in $1
			  \[
				('.$this->nestedBracketsRe.')	# link text = $2
			  \]

			  [ ]?				# one optional space
			  (?:\n[ ]*)?		# one optional newline followed by spaces

			  \[
				(.*?)		# id = $3
			  \]
			)
			}xs',
			array(&$this, 'doAnchorsReferenceCallback'), $text);

		#
		# Next, inline-style links: [link text](url "optional title")
		#
		$text = preg_replace_callback('{
			(				# wrap whole match in $1
			  \[
				('.$this->nestedBracketsRe.')	# link text = $2
			  \]
			  \(			# literal paren
				[ \n]*
				(?:
					<(.+?)>	# href = $3
				|
					('.$this->nestedUrlParenthesisRe.')	# href = $4
				)
				[ \n]*
				(			# $5
				  ([\'"])	# quote char = $6
				  (.*?)		# Title = $7
				  \6		# matching quote
				  [ \n]*	# ignore any spaces/tabs between closing quote and )
				)?			# title is optional
			  \)
			)
			}xs',
			array(&$this, 'doAnchorsInlineCallback'), $text);

		#
		# Last, handle reference-style shortcuts: [link text]
		# These must come last in case you've also got [link text][1]
		# or [link text](/foo)
		#
		$text = preg_replace_callback('{
			(					# wrap whole match in $1
			  \[
				([^\[\]]+)		# link text = $2; can\'t contain [ or ]
			  \]
			)
			}xs',
			array(&$this, 'doAnchorsReferenceCallback'), $text);

		$this->inAnchor = false;
		return $text;
	}

	private function doAnchorsReferenceCallback($matches)
	{
		$wholeMatch =  $matches[1];
		$linkText   =  $matches[2];
		$linkId     =& $matches[3];

		if ($linkId == '') {
			# for shortcut links like [this][] or [this].
			$linkId = $linkText;
		}
		
		# lower-case and turn embedded newlines into spaces
		$linkId = strtolower($linkId);
		$linkId = preg_replace('{[ ]?\n}', ' ', $linkId);

		if (isset($this->urls[$linkId])) {
			$url = $this->urls[$linkId];
			$url = $this->encodeAttribute($url);
			
			$result = "<a href=\"$url\"";
			if ( isset( $this->titles[$linkId] ) ) {
				$title = $this->titles[$linkId];
				$title = $this->encodeAttribute($title);
				$result .=  " title=\"$title\"";
			}
		
			$linkText = $this->runSpanGamut($linkText);
			$result .= ">$linkText</a>";
			$result = $this->hashPart($result);
		}
		else {
			$result = $wholeMatch;
		}
		return $result;
	}

	private function doAnchorsInlineCallback($matches)
	{
		$wholeMatch	=  $matches[1];
		$linkText		=  $this->runSpanGamut($matches[2]);
		$url			=  $matches[3] == '' ? $matches[4] : $matches[3];
		$title			=& $matches[7];

		$url = $this->encodeAttribute($url);

		$result = "<a href=\"$url\"";
		if (isset($title)) {
			$title = $this->encodeAttribute($title);
			$result .=  " title=\"$title\"";
		}
		
		$linkText = $this->runSpanGamut($linkText);
		$result .= ">$linkText</a>";

		return $this->hashPart($result);
	}


	/*
	 * Turn Markdown image shortcuts into <img> tags.
	 */
	public function doImages($text)
	{

		# First, handle reference-style labeled images: ![alt text][id]
		$text = preg_replace_callback('{
			(				# wrap whole match in $1
			  !\[
				('.$this->nestedBracketsRe.')		# alt text = $2
			  \]

			  [ ]?				# one optional space
			  (?:\n[ ]*)?		# one optional newline followed by spaces

			  \[
				(.*?)		# id = $3
			  \]

			)
			}xs', 
			array(&$this, 'doImagesReferenceCallback'), $text);

		/*
		 * Next, handle inline images:  ![alt text](url "optional title")
		 * Don't forget: encode * and _
		 */
		$text = preg_replace_callback('{
			(				# wrap whole match in $1
			  !\[
				('.$this->nestedBracketsRe.')		# alt text = $2
			  \]
			  \s?			# One optional whitespace character
			  \(			# literal paren
				[ \n]*
				(?:
					<(\S*)>	# src url = $3
				|
					('.$this->nestedUrlParenthesisRe.')	# src url = $4
				)
				[ \n]*
				(			# $5
				  ([\'"])	# quote char = $6
				  (.*?)		# title = $7
				  \6		# matching quote
				  [ \n]*
				)?			# title is optional
			  \)
			)
			}xs',
			array(&$this, 'doImagesInlineCallback'), $text);

		return $text;
	}

	private function doImagesReferenceCallback($matches)
	{
		$wholeMatch = $matches[1];
		$altText    = $matches[2];
		$linkId     = strtolower($matches[3]);

		if ($linkId == "") {
			$linkId = strtolower($altText); # for shortcut links like ![this][].
		}

		$altText = $this->encodeAttribute($altText);
		if (isset($this->urls[$linkId])) {
			$url = $this->encodeAttribute($this->urls[$linkId]);
			$result = "<img src=\"$url\" alt=\"$altText\"";
			if (isset($this->titles[$linkId])) {
				$title = $this->titles[$linkId];
				$title = $this->encodeAttribute($title);
				$result .=  " title=\"$title\"";
			}
			$result .= $this->emptyElementSuffix;
			$result = $this->hashPart($result);
		}
		else {
			# If there's no such link ID, leave intact:
			$result = $wholeMatch;
		}

		return $result;
	}

	private function doImagesInlineCallback($matches)
	{
		$wholeMatch	= $matches[1];
		$altText		= $matches[2];
		$url			= $matches[3] == '' ? $matches[4] : $matches[3];
		$title			=& $matches[7];

		$altText = $this->encodeAttribute($altText);
		$url = $this->encodeAttribute($url);
		$result = "<img src=\"$url\" alt=\"$altText\"";
		if (isset($title)) {
			$title = $this->encodeAttribute($title);
			$result .=  " title=\"$title\""; # $title already quoted
		}
		$result .= $this->emptyElementSuffix;

		return $this->hashPart($result);
	}


	public function doHeaders($text)
	{
		# Setext-style headers:
		#	  Header 1
		#	  ========
		#  
		#	  Header 2
		#	  --------
		#
		$text = preg_replace_callback('{ ^(.+?)[ ]*\n(=+|-+)[ ]*\n+ }mx',
			array(&$this, 'doHeadersCallbackSetext'), $text);

		# atx-style headers:
		#	# Header 1
		#	## Header 2
		#	## Header 2 with closing hashes ##
		#	...
		#	###### Header 6
		#
		$text = preg_replace_callback('{
				^(\#{1,6})	# $1 = string of #\'s
				[ ]*
				(.+?)		# $2 = Header text
				[ ]*
				\#*			# optional closing #\'s (not counted)
				\n+
			}xm',
			array(&$this, 'doHeadersCallbackAtx'), $text);

		return $text;
	}

	private function doHeadersCallbackSetext($matches)
	{
		# Terrible hack to check we haven't found an empty list item.
		if ($matches[2] == '-' && preg_match('{^-(?: |$)}', $matches[1]))
			return $matches[0];
		
		$level = $matches[2]{0} == '=' ? 1 : 2;
		$block = "<h$level>".$this->runSpanGamut($matches[1])."</h$level>";
		return "\n" . $this->hashBlock($block) . "\n\n";
	}

	private function doHeadersCallbackAtx($matches)
	{
		$level = strlen($matches[1]);
		$block = "<h$level>".$this->runSpanGamut($matches[2])."</h$level>";
		return "\n" . $this->hashBlock($block) . "\n\n";
	}


	/*
	 * Form HTML ordered (numbered) and unordered (bulleted) lists.
	 */
	public function doLists($text)
	{
		$lessThanTab = $this->tabWidth - 1;

		# Re-usable patterns to match list item bullets and number markers:
		$markerUlRe  = '[*+-]';
		$markerOlRe  = '\d+[\.]';
		$markerAnyRe = "(?:$markerUlRe|$markerOlRe)";

		$markersRelist = array(
			$markerUlRe => $markerOlRe,
			$markerOlRe => $markerUlRe,
		);

		foreach ($markersRelist as $markerRe => $otherMarkerRe) {
			# Re-usable pattern to match any entirel ul or ol list:
			$wholeListRe = '
				(								# $1 = whole list
				  (								# $2
					([ ]{0,'.$lessThanTab.'})	# $3 = number of spaces
					('.$markerRe.')			# $4 = first list item marker
					[ ]+
				  )
				  (?s:.+?)
				  (								# $5
					  \z
					|
					  \n{2,}
					  (?=\S)
					  (?!						# Negative lookahead for another list item marker
						[ ]*
						'.$markerRe.'[ ]+
					  )
					|
					  (?=						# Lookahead for another kind of list
					    \n
						\3						# Must have the same indentation
						'.$otherMarkerRe.'[ ]+
					  )
				  )
				)
			'; // mx
			
			# We use a different prefix before nested lists than top-level lists.
			# See extended comment in _ProcessListItems().
		
			if ($this->listLevel) {
				$text = preg_replace_callback('{
						^
						'.$wholeListRe.'
					}mx',
					array(&$this, 'doListsCallback'), $text);
			}
			else {
				$text = preg_replace_callback('{
						(?:(?<=\n)\n|\A\n?) # Must eat the newline
						'.$wholeListRe.'
					}mx',
					array(&$this, 'doListsCallback'), $text);
			}
		}

		return $text;
	}

	private function doListsCallback($matches)
	{
		# Re-usable patterns to match list item bullets and number markers:
		$markerUlRe  = '[*+-]';
		$markerOlRe  = '\d+[\.]';
		$markerAnyRe = "(?:$markerUlRe|$markerOlRe)";
		
		$list = $matches[1];
		$listType = preg_match("/$markerUlRe/", $matches[4]) ? "ul" : "ol";
		
		$markerAnyRe = ( $listType == "ul" ? $markerUlRe : $markerOlRe );
		
		$list .= "\n";
		$result = $this->processListItems($list, $markerAnyRe);
		
		$result = $this->hashBlock("<$listType>\n" . $result . "</$listType>");
		return "\n". $result ."\n\n";
	}

	protected $listLevel = 0;

	public function processListItems($listStr, $markerAnyRe)
	{
	#
	#	Process the contents of a single ordered or unordered list, splitting it
	#	into individual list items.
	#
		# The $this->listLevel global keeps track of when we're inside a list.
		# Each time we enter a list, we increment it; when we leave a list,
		# we decrement. If it's zero, we're not in a list anymore.
		#
		# We do this because when we're not inside a list, we want to treat
		# something like this:
		#
		#		I recommend upgrading to version
		#		8. Oops, now this line is treated
		#		as a sub-list.
		#
		# As a single paragraph, despite the fact that the second line starts
		# with a digit-period-space sequence.
		#
		# Whereas when we're inside a list (or sub-list), that line will be
		# treated as the start of a sub-list. What a kludge, huh? This is
		# an aspect of Markdown's syntax that's hard to parse perfectly
		# without resorting to mind-reading. Perhaps the solution is to
		# change the syntax rules such that sub-lists must start with a
		# starting cardinal number; e.g. "1." or "a.".
		
		$this->listLevel++;

		# trim trailing blank lines:
		$listStr = preg_replace("/\n{2,}\\z/", "\n", $listStr);

		$listStr = preg_replace_callback('{
			(\n)?							# leading line = $1
			(^[ ]*)							# leading whitespace = $2
			('.$markerAnyRe.'				# list marker and space = $3
				(?:[ ]+|(?=\n))	# space only required if item is not empty
			)
			((?s:.*?))						# list item text   = $4
			(?:(\n+(?=\n))|\n)				# tailing blank line = $5
			(?= \n* (\z | \2 ('.$markerAnyRe.') (?:[ ]+|(?=\n))))
			}xm',
			array(&$this, 'processListItemsCallback'), $listStr);

		$this->listLevel--;
		return $listStr;
	}

	private function processListItemsCallback($matches)
	{
		$item = $matches[4];
		$leadingLine =& $matches[1];
		$leadingSpace =& $matches[2];
		$markerSpace = $matches[3];
		$tailingBlankLine =& $matches[5];

		if ($leadingLine || $tailingBlankLine || 
			preg_match('/\n{2,}/', $item))
		{
			# Replace marker with the appropriate whitespace indentation
			$item = $leadingSpace . str_repeat(' ', strlen($markerSpace)) . $item;
			$item = $this->runBlockGamut($this->outdent($item)."\n");
		}
		else {
			# Recursion for sub-lists:
			$item = $this->doLists($this->outdent($item));
			$item = preg_replace('/\n+$/', '', $item);
			$item = $this->runSpanGamut($item);
		}

		return "<li>" . $item . "</li>\n";
	}


	/*
	 *Process Markdown `<pre><code>` blocks.
	 */
	public function doCodeBlocks($text)
	{
		$text = preg_replace_callback('{
				(?:\n\n|\A\n?)
				(	            # $1 = the code block -- one or more lines, starting with a space/tab
				  (?>
					[ ]{'.$this->tabWidth.'}  # Lines must start with a tab or a tab-width of spaces
					.*\n+
				  )+
				)
				((?=^[ ]{0,'.$this->tabWidth.'}\S)|\Z)	# Lookahead for non-space at line-start, or end of doc
			}xm',
			array(&$this, 'doCodeBlocksCallback'), $text);

		return $text;
	}

	private function doCodeBlocksCallback($matches)
	{
		$codeblock = $matches[1];

		$codeblock = $this->outdent($codeblock);
		$codeblock = htmlspecialchars($codeblock, ENT_NOQUOTES);

		# trim leading newlines and trailing newlines
		$codeblock = preg_replace('/\A\n+|\n+\z/', '', $codeblock);

		$codeblock = "<pre><code>$codeblock\n</code></pre>";
		return "\n\n".$this->hashBlock($codeblock)."\n\n";
	}


	/*
	 * Create a code span markup for $code. Called from handleSpanToken.
	 */
	public function makeCodeSpan($code)
	{
		$code = htmlspecialchars(trim($code), ENT_NOQUOTES);
		return $this->hashPart("<code>$code</code>");
	}

	protected $emRelist = array(
		''  => '(?:(?<!\*)\*(?!\*)|(?<!_)_(?!_))(?=\S|$)(?![\.,:;]\s)',
		'*' => '(?<=\S|^)(?<!\*)\*(?!\*)',
		'_' => '(?<=\S|^)(?<!_)_(?!_)',
	);
	protected $strongRelist = array(
		''   => '(?:(?<!\*)\*\*(?!\*)|(?<!_)__(?!_))(?=\S|$)(?![\.,:;]\s)',
		'**' => '(?<=\S|^)(?<!\*)\*\*(?!\*)',
		'__' => '(?<=\S|^)(?<!_)__(?!_)',
	);
	protected $emStrongRelist = array(
		''    => '(?:(?<!\*)\*\*\*(?!\*)|(?<!_)___(?!_))(?=\S|$)(?![\.,:;]\s)',
		'***' => '(?<=\S|^)(?<!\*)\*\*\*(?!\*)',
		'___' => '(?<=\S|^)(?<!_)___(?!_)',
	);
	protected $emStrongPreparedRelist;
	

	
	/*
	 * Prepare regular expressions for searching emphasis tokens in any
	 * context.
	 */
	public function prepareItalicsAndBold()
	{
		foreach ($this->emRelist as $em => $emRe) {
			foreach ($this->strongRelist as $strong => $strongRe) {
				# Construct list of allowed token expressions.
				$tokenRelist = array();
				if (isset($this->emStrongRelist["$em$strong"])) {
					$tokenRelist[] = $this->emStrongRelist["$em$strong"];
				}
				$tokenRelist[] = $emRe;
				$tokenRelist[] = $strongRe;
				
				# Construct master expression from list.
				$tokenRe = '{('. implode('|', $tokenRelist) .')}';
				$this->emStrongPreparedRelist["$em$strong"] = $tokenRe;
			}
		}
	}
	
	public function doItalicsAndBold($text)
	{
		$tokenStack = array('');
		$textStack = array('');
		$em = '';
		$strong = '';
		$treeCharEm = false;
		
		while (1) {
			#
			# Get prepared regular expression for seraching emphasis tokens
			# in current context.
			#
			$tokenRe = $this->emStrongPreparedRelist["$em$strong"];
			
			#
			# Each loop iteration search for the next emphasis token. 
			# Each token is then passed to handleSpanToken.
			#
			$parts = preg_split($tokenRe, $text, 2, PREG_SPLIT_DELIM_CAPTURE);
			$textStack[0] .= $parts[0];
			$token =& $parts[1];
			$text =& $parts[2];
			
			if (empty($token)) {
				# Reached end of text span: empty stack without emitting.
				# any more emphasis.
				while ($tokenStack[0]) {
					$textStack[1] .= array_shift($tokenStack);
					$textStack[0] .= array_shift($textStack);
				}
				break;
			}
			
			$tokenLen = strlen($token);
			if ($treeCharEm) {
				# Reached closing marker while inside a three-char emphasis.
				if ($tokenLen == 3) {
					# Three-char closing marker, close em and strong.
					array_shift($tokenStack);
					$span = array_shift($textStack);
					$span = $this->runSpanGamut($span);
					$span = "<strong><em>$span</em></strong>";
					$textStack[0] .= $this->hashPart($span);
					$em = '';
					$strong = '';
				} else {
					# Other closing marker: close one em or strong and
					# change current token state to match the other
					$tokenStack[0] = str_repeat($token{0}, 3-$tokenLen);
					$tag = $tokenLen == 2 ? "strong" : "em";
					$span = $textStack[0];
					$span = $this->runSpanGamut($span);
					$span = "<$tag>$span</$tag>";
					$textStack[0] = $this->hashPart($span);
					$$tag = ''; # $$tag stands for $em or $strong
				}
				$treeCharEm = false;
			} else if ($tokenLen == 3) {
				if ($em) {
					# Reached closing marker for both em and strong.
					# Closing strong marker:
					for ($i = 0; $i < 2; ++$i) {
						$shiftedToken = array_shift($tokenStack);
						$tag = strlen($shiftedToken) == 2 ? "strong" : "em";
						$span = array_shift($textStack);
						$span = $this->runSpanGamut($span);
						$span = "<$tag>$span</$tag>";
						$textStack[0] .= $this->hashPart($span);
						$$tag = ''; # $$tag stands for $em or $strong
					}
				} else {
					# Reached opening three-char emphasis marker. Push on token 
					# stack; will be handled by the special condition above.
					$em = $token{0};
					$strong = "$em$em";
					array_unshift($tokenStack, $token);
					array_unshift($textStack, '');
					$treeCharEm = true;
				}
			} else if ($tokenLen == 2) {
				if ($strong) {
					# Unwind any dangling emphasis marker:
					if (strlen($tokenStack[0]) == 1) {
						$textStack[1] .= array_shift($tokenStack);
						$textStack[0] .= array_shift($textStack);
					}
					# Closing strong marker:
					array_shift($tokenStack);
					$span = array_shift($textStack);
					$span = $this->runSpanGamut($span);
					$span = "<strong>$span</strong>";
					$textStack[0] .= $this->hashPart($span);
					$strong = '';
				} else {
					array_unshift($tokenStack, $token);
					array_unshift($textStack, '');
					$strong = $token;
				}
			} else {
				# Here $tokenLen == 1
				if ($em) {
					if (strlen($tokenStack[0]) == 1) {
						# Closing emphasis marker:
						array_shift($tokenStack);
						$span = array_shift($textStack);
						$span = $this->runSpanGamut($span);
						$span = "<em>$span</em>";
						$textStack[0] .= $this->hashPart($span);
						$em = '';
					} else {
						$textStack[0] .= $token;
					}
				} else {
					array_unshift($tokenStack, $token);
					array_unshift($textStack, '');
					$em = $token;
				}
			}
		}
		return $textStack[0];
	}


	protected $strikeRelist = array(
		''  => '(?:(?<!\~)\~(?!\~))(?=\S|$)(?![.,:;]\s)',
		'~' => '(?<=\S|^)(?<!\~)\~(?!\~)',
	);
	protected $spoilerRelist = array(
		''   => '(?:(?<!\~)\~\~(?!\~))(?=\S|$)(?![.,:;]\s)',
		'~~' => '(?<=\S|^)(?<!\~)\~\~(?!\~)',
	);
	protected $strikeSpoilerRelist = array(
		''    => '(?:(?<!\~)\~\~\~(?!\~))(?=\S|$)(?![.,:;]\s)',
		'~~~' => '(?<=\S|^)(?<!\~)\~\~\~(?!\~)',
	);
	protected $strikeSpoilerPreparedRelist;
	

	/*
	 * Prepare regular expressions for searching strike/spoiler tokens in any
	 * context.
	 */
	public function prepareStrikesAndSpoilers()
	{
		foreach ($this->strikeRelist as $strike => $strikeRe) {
			foreach ($this->spoilerRelist as $spoiler => $spoilerRe) {
				# Construct list of allowed token expressions.
				$tokenRelist = array();
				if (isset($this->strikeSpoilerRelist["$strike$spoiler"])) {
					$tokenRelist[] = $this->strikeSpoilerRelist["$strike$spoiler"];
				}
				$tokenRelist[] = $strikeRe;
				$tokenRelist[] = $spoilerRe;

				# Construct master expression from list.
				$tokenRe = '{('. implode('|', $tokenRelist) .')}';
				$this->strikeSpoilerPreparedRelist["$strike$spoiler"] = $tokenRe;
			}
		}
	}



	public function doStrikesAndSpoilers($text)
	{
		$tokenStack = array('');
		$textStack = array('');
		$strike = '';
		$spoiler = '';
		$treeChar = false;
		
		while (1) {
			#
			# Get prepared regular expression for seraching strike/spoiler tokens
			# in current context.
			#
			$tokenRe = $this->strikeSpoilerPreparedRelist["$strike$spoiler"];
			
			#
			# Each loop iteration search for the next strike/spoiler token. 
			# Each token is then passed to handleSpanToken.
			#
			$parts = preg_split($tokenRe, $text, 2, PREG_SPLIT_DELIM_CAPTURE);
			$textStack[0] .= $parts[0];
			$token =& $parts[1];
			$text =& $parts[2];
			
			if (empty($token)) {
				# Reached end of text span: empty stack without emitting.
				# any more strikes or spoilers.
				while ($tokenStack[0]) {
					$textStack[1] .= array_shift($tokenStack);
					$textStack[0] .= array_shift($textStack);
				}
				break;
			}
			
			$tokenLen = strlen($token);
			if ($treeChar) {
				# Reached closing marker while inside a three-char strike/spoiler.
				if ($tokenLen == 3) {
					# Three-char closing marker, close strike and sspoiler.
					array_shift($tokenStack);
					$span = array_shift($textStack);
					$span = $this->runSpanGamut($span);
					$span = "<del><s>$span</s></del>";
					$textStack[0] .= $this->hashPart($span);
					$strike = '';
					$spoiler = '';
				} else {
					# Other closing marker: close one strike or spoiler and
					# change current token state to match the other
					$tokenStack[0] = str_repeat($token{0}, 3-$tokenLen);
					$tag = $tokenLen == 2 ? "del" : "s";
					$span = $textStack[0];
					$span = $this->runSpanGamut($span);
					$span = "<$tag>$span</$tag>";
					$textStack[0] = $this->hashPart($span);
					$$tag = ''; # $$tag stands for $strike or $spoiler
				}
				$treeChar = false;
			} else if ($tokenLen == 3) {
				if ($strike) {
					# Reached closing marker for both strike and spoiler.
					# Closing strike marker:
					for ($i = 0; $i < 2; ++$i) {
						$shiftedToken = array_shift($tokenStack);
						$tag = strlen($shiftedToken) == 2 ? "del" : "s";
						$span = array_shift($textStack);
						$span = $this->runSpanGamut($span);
						$span = "<$tag>$span</$tag>";
						$textStack[0] .= $this->hashPart($span);
						$$tag = ''; # $$tag stands for $strike or $spoiler
					}
				} else {
					# Reached opening three-char strike/spoiler marker. Push on token 
					# stack; will be handled by the special condition above.
					$strike = $token{0};
					$spoiler = "$strike$strike";
					array_unshift($tokenStack, $token);
					array_unshift($textStack, '');
					$treeChar = true;
				}
			} else if ($tokenLen == 2) {
				if ($spoiler) {
					# Unwind any dangling strike/spoiler marker:
					if (strlen($tokenStack[0]) == 1) {
						$textStack[1] .= array_shift($tokenStack);
						$textStack[0] .= array_shift($textStack);
					}
					# Closing spoiler marker:
					array_shift($tokenStack);
					$span = array_shift($textStack);
					$span = $this->runSpanGamut($span);
					$span = "<del>$span</del>";
					$textStack[0] .= $this->hashPart($span);
					$spoiler = '';
				} else {
					array_unshift($tokenStack, $token);
					array_unshift($textStack, '');
					$spoiler = $token;
				}
			} else {
				# Here $tokenLen == 1
				if ($strike) { 
					if (strlen($tokenStack[0]) == 1) {
						# Closing strike marker:
						array_shift($tokenStack);
						$span = array_shift($textStack);
						$span = $this->runSpanGamut($span);
						$span = "<s>$span</s>";
						$textStack[0] .= $this->hashPart($span);
						$strike = '';
					} else {
						$textStack[0] .= $token;
					}
				} else {
					array_unshift($tokenStack, $token);
					array_unshift($textStack, '');
					$strike = $token;
				}
			}
		}
		return $textStack[0];
	}


	public function doBlockQuotes($text)
	{
		$text = preg_replace_callback('/
			  (								# Wrap whole match in $1
				(?>
				  ^[ ]*>[ ]?			# ">" at the start of a line
					.+\n					# rest of the first line
				  (.+\n)*					# subsequent consecutive lines
				  \n*						# blanks
				)+
			  )
			/xm',
			array(&$this, 'doBlockQuotesCallback'), $text);

		return $text;
	}

	private function doBlockQuotesCallback($matches)
	{
		$bq = $matches[1];
		# trim one level of quoting - trim whitespace-only lines
		$bq = preg_replace('/^[ ]*>[ ]?|^[ ]+$/m', '', $bq);
		$bq = $this->runBlockGamut($bq);		# recurse

		$bq = preg_replace('/^/m', "  ", $bq);
		# These leading spaces cause problem with <pre> content, 
		# so we need to fix that:
		$bq = preg_replace_callback('{(\s*<pre>.+?</pre>)}sx', 
			array(&$this, 'doBlockQuotesCallback2'), $bq);

		return "\n". $this->hashBlock("<blockquote>\n$bq\n</blockquote>")."\n\n";
	}

	private function doBlockQuotesCallback2($matches)
	{
		$pre = $matches[1];
		$pre = preg_replace('/^  /m', '', $pre);
		return $pre;
	}


	/*
	 * Params:
	 * $text - string to process with html <p> tags
	 */
	public function formParagraphs($text)
	{
		# Strip leading and trailing lines:
		$text = preg_replace('/\A\n+|\n+\z/', '', $text);
		
		$grafs = preg_split('/\n{2,}/', $text, -1, PREG_SPLIT_NO_EMPTY);

		# Wrap <p> tags and unhashify HTML blocks
		foreach ($grafs as $key => $value) {
			$value = trim($this->runSpanGamut($value));
			
			# Check if this should be enclosed in a paragraph.
			# Clean tag hashes & block tag hashes are left alone.
			$isP = !preg_match('/^B\x1A[0-9]+B|^C\x1A[0-9]+C$/', $value);
			
			if ($isP) {
				$value = "<p>$value</p>";
			}
			$grafs[$key] = $value;
		}
		
		# Join grafs in one text, then unhash HTML tags. 
		$text = implode("\n\n", $grafs);
		
		# Finish by removing any tag hashes still present in $text.
		$text = $this->unhash($text);
		
		return $text;
	}


	/*
	 * Encode text for a double-quoted HTML attribute. This function
	 * is *not* suitable for attributes enclosed in single quotes.
	 */
	public function encodeAttribute($text)
	{
		$text = $this->encodeAmpsAndAngles($text);
		$text = str_replace('"', '&quot;', $text);
		return $text;
	}
	
	
	/*
	 * Smart processing for ampersands and angle brackets that need to 
	 * be encoded. Valid character entities are left alone unless the
	 * no-entities mode is set.
	 */
	public function encodeAmpsAndAngles($text)
	{
		if ($this->noEntities) {
			$text = str_replace('&', '&amp;', $text);
		} else {
			# Ampersand-encoding based entirely on Nat Irons's Amputator
			# MT plugin: <http://bumppo.net/projects/amputator/>
			$text = preg_replace('/&(?!#?[xX]?(?:[0-9a-fA-F]+|\w+);)/', 
								'&amp;', $text);;
		}
		# Encode remaining <'s
		$text = str_replace('<', '&lt;', $text);

		return $text;
	}


	public function doAutoLinks($text)
	{
		$text = preg_replace_callback('{<((https?|ftp|dict):[^\'">\s]+)>}i', 
			array(&$this, 'doAutoLinksUrlCallback'), $text);

		# Email addresses: <address@domain.foo>
		$text = preg_replace_callback('{
			<
			(?:mailto:)?
			(
				(?:
					[-!#$%&\'*+/=?^_`.{|}~\w\x80-\xFF]+
				|
					".*?"
				)
				\@
				(?:
					[-a-z0-9\x80-\xFF]+(\.[-a-z0-9\x80-\xFF]+)*\.[a-z]+
				|
					\[[\d.a-fA-F:]+\]	# IPv4 & IPv6
				)
			)
			>
			}xi',
			array(&$this, 'doAutoLinksEmailCallback'), $text);

		return $text;
	}

	private function doAutoLinksUrlCallback($matches)
	{
		$url = $this->encodeAttribute($matches[1]);
		$link = "<a href=\"$url\">$url</a>";
		return $this->hashPart($link);
	}

	private function doAutoLinksEmailCallback($matches)
	{
		$address = $matches[1];
		$link = $this->encodeEmailAddress($address);
		return $this->hashPart($link);
	}


	public function encodeEmailAddress($addr)
	{
	#
	#	Input: an email address, e.g. "foo@example.com"
	#
	#	Output: the email address as a mailto link, with each character
	#		of the address encoded as either a decimal or hex entity, in
	#		the hopes of foiling most address harvesting spam bots. E.g.:
	#
	#	  <p><a href="&#109;&#x61;&#105;&#x6c;&#116;&#x6f;&#58;&#x66;o&#111;
	#        &#x40;&#101;&#x78;&#97;&#x6d;&#112;&#x6c;&#101;&#46;&#x63;&#111;
	#        &#x6d;">&#x66;o&#111;&#x40;&#101;&#x78;&#97;&#x6d;&#112;&#x6c;
	#        &#101;&#46;&#x63;&#111;&#x6d;</a></p>
	#
	#	Based by a filter by Matthew Wickline, posted to BBEdit-Talk.
	#   With some optimizations by Milian Wolff.
	#
		$addr = "mailto:" . $addr;
		$chars = preg_split('/(?<!^)(?!$)/', $addr);
		$seed = (int)abs(crc32($addr) / strlen($addr)); # Deterministic seed.
		
		foreach ($chars as $key => $char) {
			$ord = ord($char);
			# Ignore non-ascii chars.
			if ($ord < 128) {
				$r = ($seed * (1 + $key)) % 100; # Pseudo-random function.
				# roughly 10% raw, 45% hex, 45% dec
				# '@' *must* be encoded. I insist.
				if ($r > 90 && $char != '@') /* do nothing */;
				else if ($r < 45) $chars[$key] = '&#x'.dechex($ord).';';
				else              $chars[$key] = '&#'.$ord.';';
			}
		}
		
		$addr = implode('', $chars);
		$text = implode('', array_slice($chars, 7)); # text without `mailto:`
		$addr = "<a href=\"$addr\">$text</a>";

		return $addr;
	}


	/*
	 * Take the string $str and parse it into tokens, hashing embeded HTML,
	 * escaped characters and handling code spans.
	 */
	public function parseSpan($str)
	{
		$output = '';
		
		$spanRe = '{
				(
					\\\\'.$this->escapeCharsRe.'
				|
					(?<![`\\\\])
					`+						# code span marker
			'.( $this->noMarkup ? '' : '
				|
					<!--    .*?     -->		# comment
				|
					<\?.*?\?> | <%.*?%>		# processing instruction
				|
					<[/!$]?[-a-zA-Z0-9:_]+	# regular tags
					(?>
						\s
						(?>[^"\'>]+|"[^"]*"|\'[^\']*\')*
					)?
					>
			').'
				)
				}xs';

		while (1) {
			#
			# Each loop iteration seach for either the next tag, the next 
			# openning code span marker, or the next escaped character. 
			# Each token is then passed to handleSpanToken.
			#
			$parts = preg_split($spanRe, $str, 2, PREG_SPLIT_DELIM_CAPTURE);
			
			# Create token from text preceding tag.
			if ($parts[0] != "") {
				$output .= $parts[0];
			}
			
			# Check if we reach the end.
			if (isset($parts[1])) {
				$output .= $this->handleSpanToken($parts[1], $parts[2]);
				$str = $parts[2];
			}
			else {
				break;
			}
		}
		
		return $output;
	}
	
	
	/*
	 * Handle $token provided by parseSpan by determining its nature and 
	 * returning the corresponding value that should replace it.
	 */
	public function handleSpanToken($token, &$str)
	{
		switch ($token{0}) {
			case "\\":
				return $this->hashPart("&#". ord($token{1}). ";");
			case "`":
				# Search for end marker in remaining text.
				if (preg_match('/^(.*?[^`])'.preg_quote($token).'(?!`)(.*)$/sm', 
					$str, $matches))
				{
					$str = $matches[2];
					$codespan = $this->makeCodeSpan($matches[1]);
					return $this->hashPart($codespan);
				}
				return $token; // return as text since no ending marker found.
			default:
				return $this->hashPart($token);
		}
	}


	/*
	 * Remove one level of line-leading tabs or spaces
	 */
	public function outdent($text)
	{
		return preg_replace('/^(\t|[ ]{1,'.$this->tabWidth.'})/m', '', $text);
	}


	/*
	 * String length function for detab. `initDetab` will create a function to 
	 * hanlde UTF-8 if the default function does not exist.
	 */
	protected $utf8Strlen = 'mb_strlen';
	
	public function detab($text)
	{
	#
	# Replace tabs with the appropriate amount of space.
	#
		# For each line we separate the line in blocks delemited by
		# tab characters. Then we reconstruct every line by adding the 
		# appropriate number of space between each blocks.
		
		$text = preg_replace_callback('/^.*\t.*$/m',
			array(&$this, 'detabCallback'), $text);

		return $text;
	}
	private function detabCallback($matches)
	{
		$line = $matches[0];
		$strlen = $this->utf8Strlen; # strlen function for UTF-8.
		
		# Split in blocks.
		$blocks = explode("\t", $line);
		# Add each blocks to the line.
		$line = $blocks[0];
		unset($blocks[0]); # Do not add first block twice.
		foreach ($blocks as $block) {
			# Calculate amount of space, insert spaces, insert block.
			$amount = $this->tabWidth - 
				$strlen($line, 'UTF-8') % $this->tabWidth;
			$line .= str_repeat(" ", $amount) . $block;
		}
		return $line;
	}

	/*
	 * Check for the availability of the function in the `utf8Strlen` property
	 * (initially `mbStrlen`). If the function is not available, create a 
	 * function that will loosely count the number of UTF-8 characters with a
	 * regular expression.
	 */
	private function initDetab()
	{
		if (function_exists($this->utf8Strlen)) return;
		$this->utf8Strlen = createFunction('$text', 'return preg_matchAll(
			"/[\\\\x00-\\\\xBF]|[\\\\xC0-\\\\xFF][\\\\x80-\\\\xBF]*/", 
			$text, $m);');
	}


	/*
	 * Swap back in all the tags hashed by _HashHTMLBlocks.
	 */
	public function unhash($text)
	{
		return preg_replace_callback('/(.)\x1A[0-9]+\1/', 
			array(&$this, 'unhashCallback'), $text);
	}

	private function unhashCallback($matches)
	{
		return $this->htmlHashes[$matches[0]];
	}

}


#
# Markdown Extra Parser Class
#

class MarkdownExtra extends Markdown {

	# Prefix for footnote ids.
	public $fnIdPrefix = "";
	
	# Optional title attribute for footnote links and backlinks.
	public $fnLinkTitle = '';
	public $fnBacklinkTitle = '';
	
	# Optional class attribute for footnote links and backlinks.
	public $fnLinkClass = '';
	public $fnBacklinkClass = '';
	
	# Predefined abbreviations.
	public $predefAbbr = array();


	public function __construct()
	{
		# Add extra escapable characters before parent constructor 
		# initialize the table.
		$this->escapeChars .= ':|';
		
		# Insert extra document, block, and span transformations. 
		# Parent constructor will do the sorting.
		$this->documentGamut += array(
			'doFencedCodeBlocks' => 5,
			'stripFootnotes'     => 15,
			'stripAbbreviations' => 25,
			'appendFootnotes'    => 50,
		);
		$this->blockGamut += array(
			'doFencedCodeBlocks' => 5,
			'doTables'           => 15,
			'doDefLists'         => 45,
		);
		$this->spanGamut += array(
			'doFootnotes'        => 5,
			'doAbbreviations'    => 70,
		);
		
		parent::__construct();
	}
	
	
	# Extra variables used during extra transformations.
	protected $footnotes = array();
	protected $footnotesOrdered = array();
	protected $abbrDesciptions = array();
	protected $abbrWordRe = '';
	
	# Give the current footnote number.
	protected $footnoteCounter = 1;
	
	
	/*
	 * Setting up Extra-specific variables.
	 */
	public function setup()
	{
		parent::setup();
		
		$this->footnotes = array();
		$this->footnotesOrdered = array();
		$this->abbrDesciptions = array();
		$this->abbrWordRe = '';
		$this->footnoteCounter = 1;
		
		foreach ($this->predefAbbr as $abbrWord => $abbrDesc) {
			if ($this->abbrWordRe)
				$this->abbrWordRe .= '|';
			$this->abbrWordRe .= preg_quote($abbrWord);
			$this->abbrDesciptions[$abbrWord] = trim($abbrDesc);
		}
	}
	
	/*
	 * Clearing Extra-specific variables.
	 */
	public function teardown()
	{
		$this->footnotes = array();
		$this->footnotesOrdered = array();
		$this->abbrDesciptions = array();
		$this->abbrWordRe = '';
		
		parent::teardown();
	}
	
	
	### HTML Block Parser ###
	
	# Tags that are always treated as block tags:
	protected $blockTagsRe = 'p|div|h[1-6]|blockquote|pre|table|dl|ol|ul|address|form|fieldset|iframe|hr|legend';
	
	# Tags treated as block tags only if the opening tag is alone on it's line:
	protected $contextBlockTagsRe = 'script|noscript|math|ins|del';
	
	# Tags where markdown="1" default to span mode:
	protected $containSpanTagsRe = 'p|h[1-6]|li|dd|dt|td|th|legend|address';
	
	# Tags which must not have their contents modified, no matter where 
	# they appear:
	protected $cleanTagsRe = 'script|math';
	
	# Tags that do not need to be closed.
	protected $autoCloseTagsRe = 'hr|img';
	

	public function hashHTMLBlocks($text)
	{
		if ($this->noMarkup) return $text;

		$lessThanTab = $this->tabWidth - 1;

		#
		# Hashify HTML Blocks and "clean tags".
		#
		# We only want to do this for block-level HTML tags, such as headers,
		# lists, and tables. That's because we still want to wrap <p>s around
		# "paragraphs" that are wrapped in non-block-level tags, such as anchors,
		# phrase emphasis, and spans. The list of tags we're looking for is
		# hard-coded.
		#
		# This works by calling _HashHTMLBlocks_InMarkdown, which then calls
		# _HashHTMLBlocks_InHTML when it encounter block tags. When the markdown="1" 
		# attribute is found whitin a tag, _HashHTMLBlocks_InHTML calls back
		#  _HashHTMLBlocks_InMarkdown to handle the Markdown syntax within the tag.
		# These two functions are calling each other. It's recursive!
		#

		#
		# Call the HTML-in-Markdown hasher.
		#
		list($text, ) = $this->hashHTMLBlocksInMarkdown($text);
		
		return $text;
	}
	private function hashHTMLBlocksInMarkdown($text, $indent = 0, 
										$enclosingTagRe = '', $span = false)
	{
	#
	# Parse markdown text, calling _HashHTMLBlocks_InHTML for block tags.
	#
	# *   $indent is the number of space to be ignored when checking for code 
	#     blocks. This is important because if we don't take the indent into 
	#     account, something like this (which looks right) won't work as expected:
	#
	#     <div>
	#         <div markdown="1">
	#         Hello World.  <-- Is this a Markdown code block or text?
	#         </div>  <-- Is this a Markdown code block or a real tag?
	#     <div>
	#
	#     If you don't like this, just don't indent the tag on which
	#     you apply the markdown="1" attribute.
	#
	# *   If $enclosingTagRe is not empty, stops at the first unmatched closing 
	#     tag with that name. Nested tags supported.
	#
	# *   If $span is true, text inside must treated as span. So any double 
	#     newline will be replaced by a single newline so that it does not create 
	#     paragraphs.
	#
	# Returns an array of that form: ( processed text , remaining text )
	#
		if ($text === '') return array('', '');

		# Regex to check for the presense of newlines around a block tag.
		$newlineBeforeRe = '/(?:^\n?|\n\n)*$/';
		$newlineAfterRe = 
			'{
				^						# Start of text following the tag.
				(?>[ ]*<!--.*?-->)?		# Optional comment.
				[ ]*\n					# Must be followed by newline.
			}xs';
		
		# Regex to match any tag.
		$blockTagRe =
			'{
				(					# $2: Capture hole tag.
					</?					# Any opening or closing tag.
						(?>				# Tag name.
							'.$this->blockTagsRe.'			|
							'.$this->contextBlockTagsRe.'	|
							'.$this->cleanTagsRe.'        	|
							(?!\s)'.$enclosingTagRe.'
						)
						(?:
							(?=[\s"\'/a-zA-Z0-9])	# Allowed characters after tag name.
							(?>
								".*?"		|	# Double quotes (can contain `>`)
								\'.*?\'   	|	# Single quotes (can contain `>`)
								.+?				# Anything but quotes and `>`.
							)*?
						)?
					>					# End of tag.
				|
					<!--    .*?     -->	# HTML Comment
				|
					<\?.*?\?> | <%.*?%>	# Processing instruction
				|
					<!\[CDATA\[.*?\]\]>	# CData Block
				|
					# Code span marker
					`+
				'. ( !$span ? ' # If not in span.
				|
					# Indented code block
					(?: ^[ ]*\n | ^ | \n[ ]*\n )
					[ ]{'.($indent+4).'}[^\n]* \n
					(?>
						(?: [ ]{'.($indent+4).'}[^\n]* | [ ]* ) \n
					)*
				|
					# Fenced code block marker
					(?> ^ | \n )
					[ ]{0,'.($indent).'}~~~+[ ]*\n
				' : '' ). ' # End (if not is span).
				)
			}xs';

		
		$depth = 0;		# Current depth inside the tag tree.
		$parsed = "";	# Parsed text that will be returned.

		#
		# Loop through every tag until we find the closing tag of the parent
		# or loop until reaching the end of text if no parent tag specified.
		#
		do {
			#
			# Split the text using the first $tagMatch pattern found.
			# Text before  pattern will be first in the array, text after
			# pattern will be at the end, and between will be any catches made 
			# by the pattern.
			#
			$parts = preg_split($blockTagRe, $text, 2, 
								PREG_SPLIT_DELIM_CAPTURE);
			
			# If in Markdown span mode, add a empty-string span-level hash 
			# after each newline to prevent triggering any block element.
			if ($span) {
				$void = $this->hashPart("", ':');
				$newline = "$void\n";
				$parts[0] = $void . str_replace("\n", $newline, $parts[0]) . $void;
			}
			
			$parsed .= $parts[0]; # Text before current tag.
			
			# If end of $text has been reached. Stop loop.
			if (count($parts) < 3) {
				$text = "";
				break;
			}
			
			$tag  = $parts[1]; # Tag to handle.
			$text = $parts[2]; # Remaining text after current tag.
			$tagRe = preg_quote($tag); # For use in a regular expression.
			
			#
			# Check for: Code span marker
			#
			if ($tag{0} == "`") {
				# Find corresponding end marker.
				$tagRe = preg_quote($tag);
				if (preg_match('{^(?>.+?|\n(?!\n))*?(?<!`)'.$tagRe.'(?!`)}',
					$text, $matches))
				{
					# End marker found: pass text unchanged until marker.
					$parsed .= $tag . $matches[0];
					$text = substr($text, strlen($matches[0]));
				}
				else {
					# Unmatched marker: just skip it.
					$parsed .= $tag;
				}

			} else if (preg_match('{^\n?[ ]{0,'.($indent+3).'}~}', $tag)) {
				# Check for: Fenced code block marker.

				# Fenced code block marker: find matching end marker.
				$tagRe = preg_quote(trim($tag));
				if (preg_match('{^(?>.*\n)+?[ ]{0,'.($indent).'}'.$tagRe.'[ ]*\n}', $text, 
					$matches)) 
				{
					# End marker found: pass text unchanged until marker.
					$parsed .= $tag . $matches[0];
					$text = substr($text, strlen($matches[0]));
				} else {
					# No end marker: just skip it.
					$parsed .= $tag;
				}
			} else if ($tag{0} == "\n" || $tag{0} == " ") {
				# Check for: Indented code block.

				# Indented code block: pass it unchanged, will be handled 
				# later.
				$parsed .= $tag;
			} else if (preg_match('{^<(?:'.$this->blockTagsRe.')\b}', $tag) ||
				# Check for: Opening Block level tag or
				#            Opening Context Block tag (like ins and del) 
				#               used as a block tag (tag is alone on it's line).
				(	preg_match('{^<(?:'.$this->contextBlockTagsRe.')\b}', $tag) &&
					preg_match($newlineBeforeRe, $parsed) &&
					preg_match($newlineAfterRe, $text)	)
				)
			{
				# Need to parse tag and following text using the HTML parser.
				list($blockText, $text) = 
					$this->hashHTMLBlocksInHTML($tag . $text, "hashBlock", true);
				
				# Make sure it stays outside of any paragraph by adding newlines.
				$parsed .= "\n\n$blockText\n\n";
			} else if (preg_match('{^<(?:'.$this->cleanTagsRe.')\b}', $tag) ||
				$tag{1} == '!' || $tag{1} == '?')
			{
				#
				# Check for: Clean tag (like script, math)
				#            HTML Comments, processing instructions.
				#
				# Need to parse tag and following text using the HTML parser.
				# (don't check for markdown attribute)
				list($blockText, $text) = 
					$this->hashHTMLBlocksInHTML($tag . $text, "hashClean", false);
				
				$parsed .= $blockText;
			} else if ($enclosingTagRe !== '' &&
				# Same name as enclosing tag.
				preg_match('{^</?(?:'.$enclosingTagRe.')\b}', $tag))
			{
				#
				# Check for: Tag with same name as enclosing tag.
				#

				#
				# Increase/decrease nested tag count.
				#
				if ($tag{1} == '/')						$depth--;
				else if ($tag{strlen($tag)-2} != '/')	$depth++;

				if ($depth < 0) {
					#
					# Going out of parent element. Clean up and break so we
					# return to the calling function.
					#
					$text = $tag . $text;
					break;
				}
				
				$parsed .= $tag;
			}
			else {
				$parsed .= $tag;
			}
		} while ($depth >= 0);
		
		return array($parsed, $text);
	}

	/*
	 *Parse HTML, calling _HashHTMLBlocks_InMarkdown for block tags.
	 *
	 * Calls $hashMethod to convert any blocks.
	 * Stops when the first opening tag closes.
	 * $mdAttr indicate if the use of the `markdown="1"` attribute is allowed.
	 * (it is not inside clean tags)
	 *
	 * Returns an array of that form: ( processed text , remaining text )
	 */
	private function hashHTMLBlocksInHTML($text, $hashMethod, $mdAttr)
	{
		if ($text === '') return array('', '');
		
		# Regex to match `markdown` attribute inside of a tag.
		$markdownAttrRe = '
			{
				\s*			# Eat whitespace before the `markdown` attribute
				markdown
				\s*=\s*
				(?>
					(["\'])		# $1: quote delimiter		
					(.*?)		# $2: attribute value
					\1			# matching delimiter	
				|
					([^\s>]*)	# $3: unquoted attribute value
				)
				()				# $4: make $3 always defined (avoid warnings)
			}xs';
		
		# Regex to match any tag.
		$tagRe = '{
				(					# $2: Capture hole tag.
					</?					# Any opening or closing tag.
						[\w:$]+			# Tag name.
						(?:
							(?=[\s"\'/a-zA-Z0-9])	# Allowed characters after tag name.
							(?>
								".*?"		|	# Double quotes (can contain `>`)
								\'.*?\'   	|	# Single quotes (can contain `>`)
								.+?				# Anything but quotes and `>`.
							)*?
						)?
					>					# End of tag.
				|
					<!--    .*?     -->	# HTML Comment
				|
					<\?.*?\?> | <%.*?%>	# Processing instruction
				|
					<!\[CDATA\[.*?\]\]>	# CData Block
				)
			}xs';
		
		$originalText = $text;		# Save original text in case of faliure.
		
		$depth		= 0;	# Current depth inside the tag tree.
		$blockText	= "";	# Temporary text holder for current text.
		$parsed		= "";	# Parsed text that will be returned.

		#
		# Get the name of the starting tag.
		# (This pattern makes $baseTagNameRe safe without quoting.)
		#
		if (preg_match('/^<([\w:$]*)\b/', $text, $matches))
			$baseTagNameRe = $matches[1];

		#
		# Loop through every tag until we find the corresponding closing tag.
		#
		do {
			#
			# Split the text using the first $tagMatch pattern found.
			# Text before  pattern will be first in the array, text after
			# pattern will be at the end, and between will be any catches made 
			# by the pattern.
			#
			$parts = preg_split($tagRe, $text, 2, PREG_SPLIT_DELIM_CAPTURE);
			
			if (count($parts) < 3) {
				#
				# End of $text reached with unbalenced tag(s).
				# In that case, we return original text unchanged and pass the
				# first character as filtered to prevent an infinite loop in the 
				# parent function.
				#
				return array($originalText{0}, substr($originalText, 1));
			}
			
			$blockText .= $parts[0]; # Text before current tag.
			$tag         = $parts[1]; # Tag to handle.
			$text        = $parts[2]; # Remaining text after current tag.
			
			#
			# Check for: Auto-close tag (like <hr/>)
			#			 Comments and Processing Instructions.
			#
			if (preg_match('{^</?(?:'.$this->autoCloseTagsRe.')\b}', $tag) ||
				$tag{1} == '!' || $tag{1} == '?')
			{
				# Just add the tag to the block as if it was text.
				$blockText .= $tag;
			}
			else {
				#
				# Increase/decrease nested tag count. Only do so if
				# the tag's name match base tag's.
				#
				if (preg_match('{^</?'.$baseTagNameRe.'\b}', $tag)) {
					if ($tag{1} == '/')						$depth--;
					else if ($tag{strlen($tag)-2} != '/')	$depth++;
				}
				
				#
				# Check for `markdown="1"` attribute and handle it.
				#
				if ($mdAttr && 
					preg_match($markdownAttrRe, $tag, $attrM) &&
					preg_match('/^1|block|span$/', $attrM[2] . $attrM[3]))
				{
					# Remove `markdown` attribute from opening tag.
					$tag = preg_replace($markdownAttrRe, '', $tag);
					
					# Check if text inside this tag must be parsed in span mode.
					$this->mode = $attrM[2] . $attrM[3];
					$spanMode = $this->mode == 'span' || $this->mode != 'block' &&
						preg_match('{^<(?:'.$this->containSpanTagsRe.')\b}', $tag);
					
					# Calculate indent before tag.
					if (preg_match('/(?:^|\n)( *?)(?! ).*?$/', $blockText, $matches)) {
						$strlen = $this->utf8Strlen;
						$indent = $strlen($matches[1], 'UTF-8');
					} else {
						$indent = 0;
					}
					
					# End preceding block with this tag.
					$blockText .= $tag;
					$parsed .= $this->$hashMethod($blockText);
					
					# Get enclosing tag name for the ParseMarkdown function.
					# (This pattern makes $tagNameRe safe without quoting.)
					preg_match('/^<([\w:$]*)\b/', $tag, $matches);
					$tagNameRe = $matches[1];
					
					# Parse the content using the HTML-in-Markdown parser.
					list ($blockText, $text)
						= $this->hashHTMLBlocksInMarkdown($text, $indent, 
							$tagNameRe, $spanMode);
					
					# Outdent markdown text.
					if ($indent > 0) {
						$blockText = preg_replace("/^[ ]{1,$indent}/m", "", 
													$blockText);
					}
					
					# Append tag content to parsed text.
					if (!$spanMode)	$parsed .= "\n\n$blockText\n\n";
					else				$parsed .= "$blockText";
					
					# Start over a new block.
					$blockText = "";
				}
				else $blockText .= $tag;
			}
			
		} while ($depth > 0);
		
		#
		# Hash last block text that wasn't processed inside the loop.
		#
		$parsed .= $this->$hashMethod($blockText);
		
		return array($parsed, $text);
	}


	/*
	 * Called whenever a tag must be hashed when a function insert a "clean" tag
	 * in $text, it pass through this function and is automaticaly escaped, 
	 * blocking invalid nested overlap.
	 */
	public function hashClean($text)
	{
		return $this->hashPart($text, 'C');
	}


	/*
	 * Redefined to add id attribute support.
	 */
	public function doHeaders($text)
	{
		# Setext-style headers:
		#	  Header 1  {#header1}
		#	  ========
		#  
		#	  Header 2  {#header2}
		#	  --------
		#
		$text = preg_replace_callback(
			'{
				(^.+?)								# $1: Header text
				(?:[ ]+\{\#([-_:a-zA-Z0-9]+)\})?	# $2: Id attribute
				[ ]*\n(=+|-+)[ ]*\n+				# $3: Header footer
			}mx',
			array(&$this, 'doHeadersCallbackSetext'), $text);

		# atx-style headers:
		#	# Header 1        {#header1}
		#	## Header 2       {#header2}
		#	## Header 2 with closing hashes ##  {#header3}
		#	...
		#	###### Header 6   {#header2}
		#
		$text = preg_replace_callback('{
				^(\#{1,6})	# $1 = string of #\'s
				[ ]*
				(.+?)		# $2 = Header text
				[ ]*
				\#*			# optional closing #\'s (not counted)
				(?:[ ]+\{\#([-_:a-zA-Z0-9]+)\})? # id attribute
				[ ]*
				\n+
			}xm',
			array(&$this, 'doHeadersCallbackAtx'), $text);

		return $text;
	}

	private function doHeadersAttr($attr)
	{
		if (empty($attr))  return "";
		return " id=\"$attr\"";
	}

	private function doHeadersCallbackSetext($matches)
	{
		if ($matches[3] == '-' && preg_match('{^- }', $matches[1]))
			return $matches[0];
		$level = $matches[3]{0} == '=' ? 1 : 2;
		$attr  = $this->doHeadersAttr($id =& $matches[2]);
		$block = "<h$level$attr>".$this->runSpanGamut($matches[1])."</h$level>";
		return "\n" . $this->hashBlock($block) . "\n\n";
	}

	private function doHeadersCallbackAtx($matches)
	{
		$level = strlen($matches[1]);
		$attr  = $this->doHeadersAttr($id =& $matches[3]);
		$block = "<h$level$attr>".$this->runSpanGamut($matches[2])."</h$level>";
		return "\n" . $this->hashBlock($block) . "\n\n";
	}


	/*
	 * Form HTML tables.
	 */
	public function doTables($text)
	{
		$lessThanTab = $this->tabWidth - 1;
		#
		# Find tables with leading pipe.
		#
		#	| Header 1 | Header 2
		#	| -------- | --------
		#	| Cell 1   | Cell 2
		#	| Cell 3   | Cell 4
		#
		$text = preg_replace_callback('
			{
				^							# Start of a line
				[ ]{0,'.$lessThanTab.'}	# Allowed whitespace.
				[|]							# Optional leading pipe (present)
				(.+) \n						# $1: Header row (at least one pipe)
				
				[ ]{0,'.$lessThanTab.'}	# Allowed whitespace.
				[|] ([ ]*[-:]+[-| :]*) \n	# $2: Header underline
				
				(							# $3: Cells
					(?>
						[ ]*				# Allowed whitespace.
						[|] .* \n			# Row content.
					)*
				)
				(?=\n|\Z)					# Stop at final double newline.
			}xm',
			array(&$this, 'doTableLeadingPipeCallback'), $text);
		
		#
		# Find tables without leading pipe.
		#
		#	Header 1 | Header 2
		#	-------- | --------
		#	Cell 1   | Cell 2
		#	Cell 3   | Cell 4
		#
		$text = preg_replace_callback('
			{
				^							# Start of a line
				[ ]{0,'.$lessThanTab.'}	# Allowed whitespace.
				(\S.*[|].*) \n				# $1: Header row (at least one pipe)
				
				[ ]{0,'.$lessThanTab.'}	# Allowed whitespace.
				([-:]+[ ]*[|][-| :]*) \n	# $2: Header underline
				
				(							# $3: Cells
					(?>
						.* [|] .* \n		# Row content
					)*
				)
				(?=\n|\Z)					# Stop at final double newline.
			}xm',
			array(&$this, 'doTableCallback'), $text);

		return $text;
	}

	private function doTableLeadingPipeCallback($matches)
	{
		$head		= $matches[1];
		$underline	= $matches[2];
		$content	= $matches[3];
		
		# Remove leading pipe for each row.
		$content	= preg_replace('/^ *[|]/m', '', $content);
		
		return $this->doTableCallback(array($matches[0], $head, $underline, $content));
	}

	private function doTableCallback($matches)
	{
		$head		= $matches[1];
		$underline	= $matches[2];
		$content	= $matches[3];

		# Remove any tailing pipes for each line.
		$head		= preg_replace('/[|] *$/m', '', $head);
		$underline	= preg_replace('/[|] *$/m', '', $underline);
		$content	= preg_replace('/[|] *$/m', '', $content);
		
		# Reading alignement from header underline.
		$separators	= preg_split('/ *[|] */', $underline);
		foreach ($separators as $n => $s) {
			if (preg_match('/^ *-+: *$/', $s))		$attr[$n] = ' align="right"';
			else if (preg_match('/^ *:-+: *$/', $s))$attr[$n] = ' align="center"';
			else if (preg_match('/^ *:-+ *$/', $s))	$attr[$n] = ' align="left"';
			else									$attr[$n] = '';
		}
		
		# Parsing span elements, including code spans, character escapes, 
		# and inline HTML tags, so that pipes inside those gets ignored.
		$head		= $this->parseSpan($head);
		$headers	= preg_split('/ *[|] */', $head);
		$colCount	= count($headers);
		
		# Write column headers.
		$text = "<table>\n";
		$text .= "<thead>\n";
		$text .= "<tr>\n";
		foreach ($headers as $n => $header)
			$text .= "  <th$attr[$n]>".$this->runSpanGamut(trim($header))."</th>\n";
		$text .= "</tr>\n";
		$text .= "</thead>\n";
		
		# Split content by row.
		$rows = explode("\n", trim($content, "\n"));
		
		$text .= "<tbody>\n";
		foreach ($rows as $row) {
			# Parsing span elements, including code spans, character escapes, 
			# and inline HTML tags, so that pipes inside those gets ignored.
			$row = $this->parseSpan($row);
			
			# Split row by cell.
			$rowCells = preg_split('/ *[|] */', $row, $colCount);
			$rowCells = array_pad($rowCells, $colCount, '');
			
			$text .= "<tr>\n";
			foreach ($rowCells as $n => $cell)
				$text .= "  <td$attr[$n]>".$this->runSpanGamut(trim($cell))."</td>\n";
			$text .= "</tr>\n";
		}
		$text .= "</tbody>\n";
		$text .= "</table>";
		
		return $this->hashBlock($text) . "\n";
	}

	
	/*
	 * Form HTML definition lists.
	 */
	public function doDefLists($text)
	{
		$lessThanTab = $this->tabWidth - 1;

		# Re-usable pattern to match any entire dl list:
		$wholeListRe = '(?>
			(								# $1 = whole list
			  (								# $2
				[ ]{0,'.$lessThanTab.'}
				((?>.*\S.*\n)+)				# $3 = defined term
				\n?
				[ ]{0,'.$lessThanTab.'}:[ ]+ # colon starting definition
			  )
			  (?s:.+?)
			  (								# $4
				  \z
				|
				  \n{2,}
				  (?=\S)
				  (?!						# Negative lookahead for another term
					[ ]{0,'.$lessThanTab.'}
					(?: \S.*\n )+?			# defined term
					\n?
					[ ]{0,'.$lessThanTab.'}:[ ]+ # colon starting definition
				  )
				  (?!						# Negative lookahead for another definition
					[ ]{0,'.$lessThanTab.'}:[ ]+ # colon starting definition
				  )
			  )
			)
		)'; // mx

		$text = preg_replace_callback('{
				(?>\A\n?|(?<=\n\n))
				'.$wholeListRe.'
			}mx',
			array(&$this, 'doDefListsCallback'), $text);

		return $text;
	}

	private function doDefListsCallback($matches)
	{
		# Re-usable patterns to match list item bullets and number markers:
		$list = $matches[1];
		
		# Turn double returns into triple returns, so that we can make a
		# paragraph for the last item in a list, if necessary:
		$result = trim($this->processDefListItems($list));
		$result = "<dl>\n" . $result . "\n</dl>";
		return $this->hashBlock($result) . "\n\n";
	}


	/*
	 * Process the contents of a single definition list, splitting it
	 * into individual term and definition list items.
	 */
	public function processDefListItems($listStr)
	{
		$lessThanTab = $this->tabWidth - 1;
		
		# trim trailing blank lines:
		$listStr = preg_replace("/\n{2,}\\z/", "\n", $listStr);

		# Process definition terms.
		$listStr = preg_replace_callback('{
			(?>\A\n?|\n\n+)					# leading line
			(								# definition terms = $1
				[ ]{0,'.$lessThanTab.'}	# leading whitespace
				(?![:][ ]|[ ])				# negative lookahead for a definition 
											#   mark (colon) or more whitespace.
				(?> \S.* \n)+?				# actual term (not whitespace).	
			)			
			(?=\n?[ ]{0,3}:[ ])				# lookahead for following line feed 
											#   with a definition mark.
			}xm',
			array(&$this, 'processDefListItemsCallbackDt'), $listStr);

		# Process actual definitions.
		$listStr = preg_replace_callback('{
			\n(\n+)?						# leading line = $1
			(								# marker space = $2
				[ ]{0,'.$lessThanTab.'}	# whitespace before colon
				[:][ ]+						# definition mark (colon)
			)
			((?s:.+?))						# definition text = $3
			(?= \n+ 						# stop at next definition mark,
				(?:							# next term or end of text
					[ ]{0,'.$lessThanTab.'} [:][ ]	|
					<dt> | \z
				)						
			)					
			}xm',
			array(&$this, 'processDefListItemsCallbackDd'), $listStr);

		return $listStr;
	}

	private function processDefListItemsCallbackDt($matches)
	{
		$terms = explode("\n", trim($matches[1]));
		$text = '';
		foreach ($terms as $term) {
			$term = $this->runSpanGamut(trim($term));
			$text .= "\n<dt>" . $term . "</dt>";
		}
		return $text . "\n";
	}

	private function processDefListItemsCallbackDd($matches)
	{
		$leadingLine	= $matches[1];
		$markerSpace	= $matches[2];
		$def			= $matches[3];

		if ($leadingLine || preg_match('/\n{2,}/', $def)) {
			# Replace marker with the appropriate whitespace indentation
			$def = str_repeat(' ', strlen($markerSpace)) . $def;
			$def = $this->runBlockGamut($this->outdent($def . "\n\n"));
			$def = "\n". $def ."\n";
		}
		else {
			$def = rtrim($def);
			$def = $this->runSpanGamut($this->outdent($def));
		}

		return "\n<dd>" . $def . "</dd>\n";
	}


	/*
	 * Adding the fenced code block syntax to regular Markdown:
	 *
	 * ~~~
	 * Code block
	 * ~~~
	 */
	public function doFencedCodeBlocks($text)
	{
		$lessThanTab = $this->tabWidth;
		
		$text = preg_replace_callback('{
				(?:\n|\A)
				# 1: Opening marker
				(
					~{3,} # Marker: three tilde or more.
				)
				[ ]* \n # Whitespace and newline following marker.
				
				# 2: Content
				(
					(?>
						(?!\1 [ ]* \n)	# Not a closing marker.
						.*\n+
					)+
				)
				
				# Closing marker.
				\1 [ ]* \n
			}xm',
			array(&$this, 'doFencedCodeBlocksCallback'), $text);

		return $text;
	}

	private function doFencedCodeBlocksCallback($matches)
	{
		$codeblock = $matches[2];
		$codeblock = htmlspecialchars($codeblock, ENT_NOQUOTES);
		$codeblock = preg_replace_callback('/^\n+/',
			array(&$this, 'doFencedCodeBlocksNewlines'), $codeblock);
		$codeblock = "<pre><code>$codeblock</code></pre>";
		return "\n\n".$this->hashBlock($codeblock)."\n\n";
	}

	private function doFencedCodeBlocksNewlines($matches)
	{
		return str_repeat("<br$this->emptyElementSuffix", 
			strlen($matches[0]));
	}


	/*
	 * Redefining emphasis markers so that emphasis by underscore does not
	 * work in the middle of a word.
	 */
	protected $emRelist = array(
		''  => '(?:(?<!\*)\*(?!\*)|(?<![a-zA-Z0-9_])_(?!_))(?=\S|$)(?![\.,:;]\s)',
		'*' => '(?<=\S|^)(?<!\*)\*(?!\*)',
		'_' => '(?<=\S|^)(?<!_)_(?![a-zA-Z0-9_])',
	);
	protected $strongRelist = array(
		''   => '(?:(?<!\*)\*\*(?!\*)|(?<![a-zA-Z0-9_])__(?!_))(?=\S|$)(?![\.,:;]\s)',
		'**' => '(?<=\S|^)(?<!\*)\*\*(?!\*)',
		'__' => '(?<=\S|^)(?<!_)__(?![a-zA-Z0-9_])',
	);
	protected $emStrongRelist = array(
		''    => '(?:(?<!\*)\*\*\*(?!\*)|(?<![a-zA-Z0-9_])___(?!_))(?=\S|$)(?![\.,:;]\s)',
		'***' => '(?<=\S|^)(?<!\*)\*\*\*(?!\*)',
		'___' => '(?<=\S|^)(?<!_)___(?![a-zA-Z0-9_])',
	);
	
	
	### Footnotes
	
	/*
	 * Strips link definitions from text, stores the URLs and titles in
	 * hash references.
	 */
	public function stripFootnotes($text)
	{
		$lessThanTab = $this->tabWidth - 1;

		# Link defs are in the form: [^id]: url "optional title"
		$text = preg_replace_callback('{
			^[ ]{0,'.$lessThanTab.'}\[\^(.+?)\][ ]?:	# noteId = $1
			  [ ]*
			  \n?					# maybe *one* newline
			(						# text = $2 (no blank lines allowed)
				(?:					
					.+				# actual text
				|
					\n				# newlines but 
					(?!\[\^.+?\]:\s)# negative lookahead for footnote marker.
					(?!\n+[ ]{0,3}\S)# ensure line is not blank and followed 
									# by non-indented content
				)*
			)		
			}xm',
			array(&$this, 'stripFootnotesCallback'),
			$text);
		return $text;
	}

	private function stripFootnotesCallback($matches)
	{
		$noteId = $this->fnIdPrefix . $matches[1];
		$this->footnotes[$noteId] = $this->outdent($matches[2]);
		return ''; # String that will replace the block
	}

	
	/*
	 * Replace footnote references in $text [^id] with a special text-token 
	 * which will be replaced by the actual footnote marker in appendFootnotes.
	 */
	public function doFootnotes($text)
	{
		if (!$this->inAnchor) {
			$text = preg_replace('{\[\^(.+?)\]}', "F\x1Afn:\\1\x1A:", $text);
		}
		return $text;
	}

	
	
	/*
	 * Append footnote list to text.
	 */
	public function appendFootnotes($text)
	{
		$text = preg_replace_callback('{F\x1Afn:(.*?)\x1A:}', 
			array(&$this, 'appendFootnotesCallback'), $text);
	
		if (!empty($this->footnotesOrdered)) {
			$text .= "\n\n";
			$text .= "<div class=\"footnotes\">\n";
			$text .= "<hr". $this->emptyElementSuffix ."\n";
			$text .= "<ol>\n\n";
			
			$attr = " rev=\"footnote\"";
			if ($this->fnBacklinkClass != "") {
				$class = $this->fnBacklinkClass;
				$class = $this->encodeAttribute($class);
				$attr .= " class=\"$class\"";
			}
			if ($this->fnBacklinkTitle != "") {
				$title = $this->fnBacklinkTitle;
				$title = $this->encodeAttribute($title);
				$attr .= " title=\"$title\"";
			}
			$num = 0;
			
			while (!empty($this->footnotesOrdered)) {
				$footnote = reset($this->footnotesOrdered);
				$noteId = key($this->footnotesOrdered);
				unset($this->footnotesOrdered[$noteId]);
				
				$footnote .= "\n"; # Need to append newline before parsing.
				$footnote = $this->runBlockGamut("$footnote\n");				
				$footnote = preg_replace_callback('{F\x1Afn:(.*?)\x1A:}', 
					array(&$this, 'appendFootnotesCallback'), $footnote);
				
				$attr = str_replace("%%", ++$num, $attr);
				$noteId = $this->encodeAttribute($noteId);
				
				# Add backlink to last paragraph; create new paragraph if needed.
				$backlink = "<a href=\"#fnref:$noteId\"$attr>&#8617;</a>";
				if (preg_match('{</p>$}', $footnote)) {
					$footnote = substr($footnote, 0, -4) . "&#160;$backlink</p>";
				} else {
					$footnote .= "\n\n<p>$backlink</p>";
				}
				
				$text .= "<li id=\"fn:$noteId\">\n";
				$text .= $footnote . "\n";
				$text .= "</li>\n\n";
			}
			
			$text .= "</ol>\n";
			$text .= "</div>";
		}
		return $text;
	}

	private function appendFootnotesCallback($matches)
	{
		$nodeId = $this->fnIdPrefix . $matches[1];
		
		# Create footnote marker only if it has a corresponding footnote *and*
		# the footnote hasn't been used by another marker.
		if (isset($this->footnotes[$nodeId])) {
			# Transfert footnote content to the ordered list.
			$this->footnotesOrdered[$nodeId] = $this->footnotes[$nodeId];
			unset($this->footnotes[$nodeId]);
			
			$num = $this->footnoteCounter++;
			$attr = " rel=\"footnote\"";
			if ($this->fnLinkClass != "") {
				$class = $this->fnLinkClass;
				$class = $this->encodeAttribute($class);
				$attr .= " class=\"$class\"";
			}
			if ($this->fnLinkTitle != "") {
				$title = $this->fnLinkTitle;
				$title = $this->encodeAttribute($title);
				$attr .= " title=\"$title\"";
			}
			
			$attr = str_replace("%%", $num, $attr);
			$nodeId = $this->encodeAttribute($nodeId);
			
			return
				"<sup id=\"fnref:$nodeId\">".
				"<a href=\"#fn:$nodeId\"$attr>$num</a>".
				"</sup>";
		}
		
		return "[^".$matches[1]."]";
	}
		
	
	### Abbreviations ###
	
	/*
	 * Strips abbreviations from text, stores titles in hash references.
	 */
	public function stripAbbreviations($text)
	{
		$lessThanTab = $this->tabWidth - 1;

		# Link defs are in the form: [id]*: url "optional title"
		$text = preg_replace_callback('{
			^[ ]{0,'.$lessThanTab.'}\*\[(.+?)\][ ]?:	# abbrId = $1
			(.*)					# text = $2 (no blank lines allowed)	
			}xm',
			array(&$this, 'stripAbbreviationsCallback'),
			$text);
		return $text;
	}

	private function stripAbbreviationsCallback($matches)
	{
		$abbrWord = $matches[1];
		$abbrDesc = $matches[2];
		if ($this->abbrWordRe)
			$this->abbrWordRe .= '|';
		$this->abbrWordRe .= preg_quote($abbrWord);
		$this->abbrDesciptions[$abbrWord] = trim($abbrDesc);
		return ''; # String that will replace the block
	}
	
	
	/*
	 * Find defined abbreviations in text and wrap them in <abbr> elements.
	 */
	public function doAbbreviations($text)
	{
		if ($this->abbrWordRe) {
			// cannot use the /x modifier because abbrWordRe may 
			// contain significant spaces:
			$text = preg_replace_callback('{'.
				'(?<![\w\x1A])'.
				'(?:'.$this->abbrWordRe.')'.
				'(?![\w\x1A])'.
				'}', 
				array(&$this, 'doAbbreviationsCallback'), $text);
		}
		return $text;
	}

	private function doAbbreviationsCallback($matches)
	{
		$abbr = $matches[0];
		if (isset($this->abbrDesciptions[$abbr])) {
			$desc = $this->abbrDesciptions[$abbr];
			if (empty($desc)) {
				return $this->hashPart("<abbr>$abbr</abbr>");
			} else {
				$desc = $this->encodeAttribute($desc);
				return $this->hashPart("<abbr title=\"$desc\">$abbr</abbr>");
			}
		} else {
			return $matches[0];
		}
	}

}
