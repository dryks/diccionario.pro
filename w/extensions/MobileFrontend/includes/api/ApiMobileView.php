<?php

/**
 * Extends Api of MediaWiki with actions for mobile devices. For further information see
 * https://www.mediawiki.org/wiki/Extension:MobileFrontend#API
 */
class ApiMobileView extends ApiBase {
	/**
	 * Increment this when changing the format of cached data
	 */
	const CACHE_VERSION = 9;

	/** @var boolean Saves whether redirects has to be followed or not */
	private $followRedirects;
	/** @var boolean Saves whether sections have the header name or not */
	private $noHeadings;
	/** @var boolean Saves whether the requested page is the main page */
	private $mainPage;
	/** @var boolean Saves whether the output is formatted or not */
	private $noTransform;
	/** @var boolean Saves whether page images should be added or not */
	private $usePageImages;
	/** @var string Saves in which language the content should be output */
	private $variant;
	/** @var Integer Saves at which character the section content start at */
	private $offset;
	/** @var Integer Saves value to specify the max length of a sections content */
	private $maxlen;
	/** @var file|boolean Saves a File Object, or false if no file exist */
	private $file;

	/**
	 * Run constructor of ApiBase
	 * @param ApiMain $main Instance of class ApiMain
	 * @param string $action Name of this module
	 */
	public function __construct( $main, $action ) {
		$this->usePageImages = defined( 'PAGE_IMAGES_INSTALLED' );
		parent::__construct( $main, $action );
	}

	/**
	 * Obtain the requested page properties.
	 * @param string $propNames requested list of pageprops separated by '|'. If '*'
	 *  all page props will be returned.
	 * @param array $data data available as returned by getData
	 * @return Array associative
	 */
	public function getMobileViewPageProps( $propNames, $data ) {
		if ( array_key_exists( 'pageprops', $data ) ) {
			if ( $propNames == '*' ) {
				$pageProps = $data['pageprops'];
			} else {
				$pageProps = array_intersect_key( $data['pageprops'],
					array_flip( explode( '|', $propNames ) ) );
			}
		} else {
			$pageProps = [];
		}
		return $pageProps;
	}

	/**
	 * Execute the requested Api actions.
	 * @todo: Write some unit tests for API results
	 */
	public function execute() {
		// Logged-in users' parser options depend on preferences
		$this->getMain()->setCacheMode( 'anon-public-user-private' );

		// Don't strip srcset on renderings for mobileview api; the
		// app below it will decide how to use them.
		MobileContext::singleton()->setStripResponsiveImages( false );

		// Enough '*' keys in JSON!!!
		$isXml = $this->getMain()->isInternalMode()
			|| $this->getMain()->getPrinter()->getFormat() == 'XML';
		$textElement = $isXml ? '*' : 'text';
		$params = $this->extractRequestParams();

		$prop = array_flip( $params['prop'] );
		$sectionProp = array_flip( $params['sectionprop'] );
		$this->variant = $params['variant'];
		$this->followRedirects = $params['redirect'] == 'yes';
		$this->noHeadings = $params['noheadings'];
		$this->noTransform = $params['notransform'];
		$onlyRequestedSections = $params['onlyrequestedsections'];
		$this->offset = $params['offset'];
		$this->maxlen = $params['maxlen'];
		$resultObj = $this->getResult();
		$moduleName = $this->getModuleName();

		if ( $this->offset === 0 && $this->maxlen === 0 ) {
			// Disable text splitting
			$this->offset = -1;
		} elseif ( $this->maxlen === 0 ) {
			$this->maxlen = PHP_INT_MAX;
		}

		$title = $this->makeTitle( $params['page'] );
		RequestContext::getMain()->setTitle( $title );

		$namespace = $title->getNamespace();
		$this->addXAnalyticsItem( 'ns', (string)$namespace );

		// See whether the actual page (or if enabled, the redirect target) is the main page
		$this->mainPage = $this->isMainPage( $title );
		if ( $this->mainPage && $this->noHeadings ) {
			$this->noHeadings = false;
			$this->addWarning( 'apiwarn-mobilefrontend-ignoringnoheadings', 'ignoringnoheadings' );

		}
		if ( isset( $prop['normalizedtitle'] ) && $title->getPrefixedText() != $params['page'] ) {
			$resultObj->addValue( null, $moduleName,
				[ 'normalizedtitle' => $title->getPageLanguage()->convert( $title->getPrefixedText() ) ]
			);
		}

		if ( isset( $prop['namespace'] ) ) {
			$resultObj->addValue( null, $moduleName, [
				'ns' => $namespace,
			] );
		}
		$data = $this->getData( $title, $params['noimages'], $params['revision'] );
		$plainData = [ 'lastmodified', 'lastmodifiedby', 'revision',
			'languagecount', 'hasvariants', 'displaytitle', 'id', 'contentmodel' ];
		foreach ( $plainData as $name ) {
			// Bug 73109: #getData will return an empty array if the title redirects to
			// a page in a virtual namespace (NS_SPECIAL, NS_MEDIA), so make sure that
			// the requested data exists too.
			if ( isset( $prop[$name] ) && isset( $data[$name] ) ) {
				$resultObj->addValue( null, $moduleName,
					[ $name => $data[$name] ]
				);
			}
		}
		if ( isset( $data['id'] ) ) {
			$this->addXAnalyticsItem( 'page_id', (string)$data['id'] );
		}
		if ( isset( $prop['pageprops'] ) ) {
			$mvPageProps = $this->getMobileViewPageProps( $params['pageprops'], $data );
			ApiResult::setArrayType( $mvPageProps, 'assoc' );
			$resultObj->addValue( null, $moduleName,
				[ 'pageprops' => $mvPageProps ]
			);
		}

		if ( isset( $prop['description'] ) && isset( $data['pageprops']['wikibase_item'] ) ) {
			$desc = ExtMobileFrontend::getWikibaseDescription(
				$data['pageprops']['wikibase_item']
			);
			if ( $desc ) {
				$resultObj->addValue( null, $moduleName,
					[ 'description' => $desc ]
				);
			}
		}
		if ( $this->usePageImages ) {
			$this->addPageImage( $data, $params, $prop );
		}
		$result = [];
		$missingSections = [];
		if ( $this->mainPage ) {
			if ( $onlyRequestedSections ) {
				$requestedSections =
					self::getRequestedSectionIds( $params['sections'], $data, $missingSections );
			} else {
				$requestedSections = [ 0 ];
			}
			$resultObj->addValue( null, $moduleName,
				[ 'mainpage' => true ]
			);
		} elseif ( isset( $params['sections'] ) ) {
			$requestedSections = self::getRequestedSectionIds( $params['sections'],
				$data, $missingSections );
		} else {
			$requestedSections = [];
		}

		if ( isset( $data['sections'] ) ) {
			if ( isset( $prop['sections'] ) ) {
				$sectionCount = count( $data['sections'] );
				for ( $i = 0; $i <= $sectionCount; $i++ ) {
					if ( !isset( $requestedSections[$i] ) && $onlyRequestedSections ) {
						continue;
					}
					$section = [];
					if ( $i > 0 ) {
						$section = array_intersect_key( $data['sections'][$i - 1], $sectionProp );
					}
					$section['id'] = $i;
					if ( isset( $prop['text'] )
						&& isset( $requestedSections[$i] )
						&& isset( $data['text'][$i] )
					) {
						$section[$textElement] = $this->stringSplitter( $this->prepareSection( $data['text'][$i] ) );
						unset( $requestedSections[$i] );
					}
					if ( isset( $data['refsections'][$i] ) ) {
						$section['references'] = true;
					}
					$result[] = $section;
				}
				$missingSections = array_keys( $requestedSections );
			} else {
				foreach ( array_keys( $requestedSections ) as $index ) {
					$section = [ 'id' => $index ];
					if ( isset( $data['text'][$index] ) ) {
						$section[$textElement] =
							$this->stringSplitter( $this->prepareSection( $data['text'][$index] ) );
					} else {
						$missingSections[] = $index;
					}
					$result[] = $section;
				}
			}
			$resultObj->setIndexedTagName( $result, 'section' );
			$resultObj->addValue( null, $moduleName, [ 'sections' => $result ] );
		}

		if ( isset( $prop['protection'] ) ) {
			$this->addProtection( $title );
		}
		if ( isset( $prop['editable'] ) ) {
			$user = $this->getUser();
			if ( $user->isAnon() ) {
				// HACK: Anons receive cached information, so don't check blocked status for them
				// to avoid them receiving false positives. Currently there is no way to check
				// all permissions except blocked status from the Title class.
				$req = new FauxRequest();
				$req->setIP( '127.0.0.1' );
				$user = User::newFromSession( $req );
			}
			$editable = $title->quickUserCan( 'edit', $user );
			if ( $isXml ) {
				$editable = intval( $editable );
			}
			$resultObj->addValue( null, $moduleName,
				[
					'editable' => $editable,
					ApiResult::META_BC_BOOLS => [ 'editable' ],
				]
			);
		}
		// https://bugzilla.wikimedia.org/show_bug.cgi?id=51586
		// Inform ppl if the page is infested with LiquidThreads but that's the
		// only thing we support about it.
		if ( class_exists( \LqtDispatch::class ) && \LqtDispatch::isLqtPage( $title ) ) {
			$resultObj->addValue( null, $moduleName,
				[ 'liquidthreads' => true ]
			);
		}
		if ( count( $missingSections ) && isset( $prop['text'] ) ) {
			$this->addWarning( [
				'apiwarn-mobilefrontend-sectionsnotfound',
				Message::listParam( $missingSections ),
				count( $missingSections )
			], 'sectionsnotfound' );
		}
		if ( $this->maxlen < 0 ) {
			// There is more data available
			$resultObj->addValue( null, $moduleName,
				[ 'continue-offset' => $params['offset'] + $params['maxlen'] ]
			);
		}
	}

	/**
	 * Small wrapper around XAnalytics extension
	 *
	 * @see \XAnalytics::addItem
	 * @param string $name
	 * @param string $value
	 */
	private function addXAnalyticsItem( $name, $value ) {
		if ( is_callable( [ \XAnalytics::class, 'addItem' ] ) ) {
			\XAnalytics::addItem( $name, $value );
		}
	}

	/**
	 * Creates and validates a title
	 * @param string $name Title content
	 * @return Title
	 */
	protected function makeTitle( $name ) {
		global $wgContLang;
		$title = Title::newFromText( $name );
		if ( !$title ) {
			$this->dieWithError( [ 'apierror-invalidtitle', wfEscapeWikiText( $name ) ] );
		}
		$unconvertedTitle = $title->getPrefixedText();
		$wgContLang->findVariantLink( $name, $title );
		if ( $unconvertedTitle !== $title->getPrefixedText() ) {
			$values = [ 'from' => $unconvertedTitle, 'to' => $title->getPrefixedText() ];
			$this->getResult()->addValue( 'mobileview', 'converted', $values );
		}
		if ( $title->inNamespace( NS_FILE ) ) {
			$this->file = $this->findFile( $title );
		}
		if ( !$title->exists() && !$this->file ) {
			$this->dieWithError( [ 'apierror-missingtitle' ] );
		}
		return $title;
	}

	/**
	 * Wrapper that returns a page image for a given title
	 *
	 * @param Title $title Page title
	 * @return bool|File
	 */
	protected function getPageImage( Title $title ) {
		return PageImages::getPageImage( $title );
	}

	/**
	 * Wrapper for wfFindFile
	 *
	 * @param Title|string $title Page title
	 * @param array $options Options for wfFindFile (see RepoGroup::findFile)
	 * @return bool|File
	 */
	protected function findFile( $title, $options = [] ) {
		return wfFindFile( $title, $options );
	}

	/**
	 * Check if page is the main page after follow redirect when followRedirects is true.
	 *
	 * @param Title $title Title object to check
	 * @return bool
	 */
	protected function isMainPage( $title ) {
		if ( $title->isRedirect() && $this->followRedirects ) {
			$wikiPage = $this->makeWikiPage( $title );
			$target = $wikiPage->getRedirectTarget();
			if ( $target ) {
				return $target->isMainPage();
			}
		}
		return $title->isMainPage();
	}

	/**
	 * Splits a string (using $offset and $maxlen)
	 * @param string $text The text to split
	 * @return string
	 */
	private function stringSplitter( $text ) {
		if ( $this->offset < 0 ) {
			// NOOP - string splitting mode is off
			return $text;
		} elseif ( $this->maxlen < 0 ) {
			// Limit exceeded
			return '';
		}
		$textLen = mb_strlen( $text );
		$start = $this->offset;
		$len = $textLen - $start;
		if ( $len > 0 ) {
			// At least part of the $text should be included
			if ( $len > $this->maxlen ) {
				$len = $this->maxlen;
				$this->maxlen = -1;
			} else {
				$this->maxlen -= $len;
			}
			$this->offset = 0;
			return mb_substr( $text, $start, $len );
		}
		$this->offset -= $textLen;
		return '';
	}

	/**
	 * Delete headings from page html
	 * @param string $html Page content
	 * @return string
	 */
	private function prepareSection( $html ) {
		if ( $this->noHeadings ) {
			$html = preg_replace( '#<(h[1-6])\b.*?<\s*/\s*\\1>#', '', $html );
		}
		return trim( $html );
	}

	/**
	 * Parses requested sections string into a list of sections
	 * @param string $str String to parse
	 * @param array $data Processed parser output
	 * @param array &$missingSections Upon return, contains the list of sections that were
	 * requested but are not present in parser output (passed by reference)
	 * @return array
	 */
	public static function getRequestedSectionIds( $str, $data, &$missingSections ) {
		$str = trim( $str );
		if ( !isset( $data['sections'] ) ) {
			return [];
		}
		$sectionCount = count( $data['sections'] );
		if ( $str === 'all' ) {
			return range( 0, $sectionCount );
		}
		$sections = array_map( 'trim', explode( '|', $str ) );
		$ret = [];
		foreach ( $sections as $sec ) {
			if ( $sec === '' ) {
				continue;
			}
			if ( $sec === 'references' ) {
				$ret = array_merge( $ret, array_keys( $data['refsections'] ) );
				continue;
			}
			$val = intval( $sec );
			if ( strval( $val ) === $sec ) {
				if ( $val >= 0 && $val <= $sectionCount ) {
					$ret[] = $val;
					continue;
				}
			} else {
				$parts = explode( '-', $sec );
				if ( count( $parts ) === 2 ) {
					$from = intval( $parts[0] );
					if ( strval( $from ) === $parts[0] && $from >= 0 && $from <= $sectionCount ) {
						if ( $parts[1] === '' ) {
							$ret = array_merge( $ret, range( $from, $sectionCount ) );
							continue;
						}
						$to = intval( $parts[1] );
						if ( strval( $to ) === $parts[1] ) {
							$ret = array_merge( $ret, range( $from, $to ) );
							continue;
						}
					}
				}
			}
			$missingSections[] = $sec;
		}
		$ret = array_unique( $ret );
		sort( $ret );
		return array_flip( $ret );
	}

	/**
	 * Performs a page parse
	 * @param WikiPage $wikiPage
	 * @param ParserOptions $parserOptions
	 * @param null|int $oldid Revision ID to get the text from, passing null or 0 will
	 *   get the current revision (default value)
	 * @return ParserOutput|bool
	 */
	protected function getParserOutput(
		WikiPage $wikiPage,
		ParserOptions $parserOptions,
		$oldid = null
	) {
		$parserOutput = $wikiPage->getParserOutput( $parserOptions, $oldid );
		if ( $parserOutput && !defined( 'ParserOutput::SUPPORTS_STATELESS_TRANSFORMS' ) ) {
			$parserOutput->setTOCEnabled( false );
		}

		return $parserOutput;
	}

	/**
	 * Creates a WikiPage from title
	 * @param Title $title Page title
	 * @return WikiPage
	 */
	protected function makeWikiPage( Title $title ) {
		return WikiPage::factory( $title );
	}

	/**
	 * Call makeParserOptions on a WikiPage with the wrapper output class disabled.
	 * @param WikiPage $wikiPage to call makeParserOptions on.
	 * @return ParserOptions
	 */
	protected function makeParserOptions( WikiPage $wikiPage ) {
		$popt = $wikiPage->makeParserOptions( $this );
		return $popt;
	}

	/**
	 * Parses section data
	 * @param string $html representing the entire page
	 * @param Title $title Page title
	 * @param ParserOutput $parserOutput
	 * @param int $revId this is a temporary parameter to avoid debug log warnings.
	 *  Long term the call to wfDebugLog should be moved outside this method (optional)
	 * @return array structure representing the list of sections and their properties:
	 *  - refsections: [] where all keys are section ids of sections with refs
	 *    that contain references
	 *  - sections: [] a structured array of all the sections inside the page
	 *  - text: [] of the text of each individual section. length === same as sections
	 *      or of length 1 when there is a mismatch.
	 */
	protected function parseSectionsData( $html, Title $title,
		ParserOutput $parserOutput, $revId = null
	) {
		$data = [];
		$data['sections'] = $parserOutput->getSections();
		$sectionCount = count( $data['sections'] );
		for ( $i = 0; $i < $sectionCount; $i++ ) {
			$data['sections'][$i]['line'] =
				$title->getPageLanguage()->convert( $data['sections'][$i]['line'] );
		}
		$chunks = preg_split( '/<h(?=[1-6]\b)/i', $html );
		if ( count( $chunks ) != count( $data['sections'] ) + 1 ) {
			wfDebugLog( 'mobile', __METHOD__ . "(): mismatching number of " .
				"sections from parser and split on page {$title->getPrefixedText()}, oldid=$revId" );
			// We can't be sure about anything here, return all page HTML as one big section
			$chunks = [ $html ];
			$data['sections'] = [];
		}
		$data['text'] = [];
		$data['refsections'] = [];
		foreach ( $chunks as $chunk ) {
			if ( count( $data['text'] ) ) {
				$chunk = "<h$chunk";
			}
			if ( preg_match( '/<ol\b[^>]*?class="references"/', $chunk ) ) {
				$data['refsections'][count( $data['text'] )] = true;
			}
			$data['text'][] = $chunk;
		}
		return $data;
	}

	/**
	 * Get data of requested article.
	 * @param Title $title
	 * @param boolean $noImages
	 * @param null|int [$oldid] Revision ID to get the text from, passing null or 0 will
	 *   get the current revision (default value)
	 * @return array
	 */
	private function getData( Title $title, $noImages, $oldid = null ) {
		global $wgMemc;

		$mfConfig = MobileContext::singleton()->getMFConfig();
		$mfMinCachedPageSize = $mfConfig->get( 'MFMinCachedPageSize' );
		$mfSpecialCaseMainPage = $mfConfig->get( 'MFSpecialCaseMainPage' );

		$result = $this->getResult();
		$wikiPage = $this->makeWikiPage( $title );
		if ( $this->followRedirects && $wikiPage->isRedirect() ) {
			$newTitle = $wikiPage->getRedirectTarget();
			if ( $newTitle ) {
				$title = $newTitle;
				$textTitle = $title->getPrefixedText();
				if ( $title->hasFragment() ) {
					$textTitle .= $title->getFragmentForUrl();
				}
				$result->addValue( null, $this->getModuleName(),
					[ 'redirected' => $textTitle ]
				);
				if ( $title->getNamespace() < 0 ) {
					$result->addValue( null, $this->getModuleName(),
						[ 'viewable' => 'no' ]
					);
					return [];
				}
				$wikiPage = $this->makeWikiPage( $title );
			}
		}
		$latest = $wikiPage->getLatest();
		// Use page_touched so template updates invalidate cache
		$touched = $wikiPage->getTouched();
		$revId = $oldid ? $oldid : $title->getLatestRevID();
		if ( $this->file ) {
			$key = $wgMemc->makeKey(
				'mf',
				'mobileview',
				self::CACHE_VERSION,
				$noImages,
				$touched,
				$this->noTransform,
				$this->file->getSha1(),
				$this->variant
			);
			$cacheExpiry = 3600;
		} else {
			if ( !$latest ) {
				// https://bugzilla.wikimedia.org/show_bug.cgi?id=53378
				// Title::exists() above doesn't seem to always catch recently deleted pages
				$this->dieWithError( [ 'apierror-missingtitle' ] );
			}
			$parserOptions = $this->makeParserOptions( $wikiPage );
			$parserCache = \MediaWiki\MediaWikiServices::getInstance()->getParserCache();
			$parserCacheKey = $parserCache->getKey( $wikiPage, $parserOptions );
			$key = $wgMemc->makeKey(
				'mf',
				'mobileview',
				self::CACHE_VERSION,
				$noImages,
				$touched,
				$revId,
				$this->noTransform,
				$parserCacheKey
			);
		}
		$data = $wgMemc->get( $key );
		if ( $data ) {
			wfIncrStats( 'mobile.view.cache-hit' );
			return $data;
		}
		wfIncrStats( 'mobile.view.cache-miss' );
		if ( $this->file ) {
			$html = $this->getFilePage( $title );
		} else {
			$parserOutput = $this->getParserOutput( $wikiPage, $parserOptions, $oldid );
			if ( $parserOutput === false ) {
				$this->dieWithError( 'apierror-mobilefrontend-badidtitle', 'invalidparams' );
				return;
			}
			$html = $parserOutput->getText( [ 'allowTOC' => false, 'unwrap' => true,
				'deduplicateStyles' => false ] );
			$cacheExpiry = $parserOutput->getCacheExpiry();
		}

		if ( !$this->noTransform ) {
			$mf = new MobileFormatter( MobileFormatter::wrapHTML( $html ), $title );
			$mf->setRemoveMedia( $noImages );
			$mf->setIsMainPage( $this->mainPage && $mfSpecialCaseMainPage );
			$mf->filterContent();
			$html = $mf->getText();
		}

		if ( $this->mainPage || $this->file ) {
			$data = [
				'sections' => [],
				'text' => [ $html ],
				'refsections' => [],
			];
		} else {
			$data = $this->parseSectionsData( $html, $title, $parserOutput, $latest );
			if ( $this->usePageImages ) {
				$image = $this->getPageImage( $title );
				if ( $image ) {
					$data['image'] = $image->getTitle()->getText();
				}
			}
		}

		$data['lastmodified'] = wfTimestamp( TS_ISO_8601, $wikiPage->getTimestamp() );

		// Page id
		$data['id'] = $wikiPage->getId();
		$user = User::newFromId( $wikiPage->getUser() );
		if ( !$user->isAnon() ) {
			$data['lastmodifiedby'] = [
				'name' => $wikiPage->getUserText(),
				'gender' => $user->getOption( 'gender' ),
			];
		} else {
			$data['lastmodifiedby'] = null;
		}
		$data['revision'] = $revId;

		if ( isset( $parserOutput ) ) {
			$languages = $parserOutput->getLanguageLinks();
			$data['languagecount'] = count( $languages );
			$data['displaytitle'] = $parserOutput->getDisplayTitle();
			// @fixme: Does no work for some extension properties that get added in LinksUpdate
			$data['pageprops'] = $parserOutput->getProperties();
		} else {
			$data['languagecount'] = 0;
			$data['displaytitle'] = htmlspecialchars( $title->getPrefixedText() );
			$data['pageprops'] = [];
		}

		$data['contentmodel'] = $title->getContentModel();

		if ( $title->getPageLanguage()->hasVariants() ) {
			$data['hasvariants'] = true;
		}

		// Don't store small pages to decrease cache size requirements
		if ( strlen( $html ) >= $mfMinCachedPageSize ) {
			// store for the same time as original parser output
			$wgMemc->set( $key, $data, $cacheExpiry );
		}

		return $data;
	}

	/**
	 * Get a Filepage as parsed HTML
	 * @param Title $title
	 * @return string
	 */
	private function getFilePage( Title $title ) {
		// HACK: HACK: HACK:
		$context = new DerivativeContext( $this->getContext() );
		$context->setTitle( $title );
		$context->setOutput( new OutputPage( $context ) );

		$page = new ImagePage( $title );
		$page->setContext( $context );

		// T123821: Without setting the wiki page on the derivative context,
		// DerivativeContext#getWikiPage will (eventually) fall back to
		// RequestContext#getWikiPage. Here, the request context is distinct from the
		// derivative context and deliberately constructed with a bad title in the prelude
		// of api.php.
		$context->setWikiPage( $page->getPage() );

		$page->view();

		$html = $context->getOutput()->getHTML();

		return $html;
	}

	/**
	 * Adds Image information to Api result.
	 * @param array $data whatever getData() returned
	 * @param array $params parameters to this API module
	 * @param array $prop prop parameter value
	 */
	private function addPageImage( array $data, array $params, array $prop ) {
		if ( !isset( $prop['image'] ) && !isset( $prop['thumb'] ) ) {
			return;
		}
		if ( !isset( $data['image'] ) ) {
			return;
		}
		if ( isset( $params['thumbsize'] )
			&& ( isset( $params['thumbwidth'] ) || isset( $params['thumbheight'] ) )
		) {
			$this->dieWithError( 'apierror-mobilefrontend-toomanysizeparams', 'toomanysizeparams' );
		}

		$file = $this->findFile( $data['image'] );
		if ( !$file ) {
			return;
		}
		$result = $this->getResult();
		if ( isset( $prop['image'] ) ) {
			$result->addValue( null, $this->getModuleName(),
				[ 'image' =>
					[
						'file' => $data['image'],
						'width' => $file->getWidth(),
						'height' => $file->getHeight(),
					]
				]
			);
		}
		if ( isset( $prop['thumb'] ) ) {
			$resize = [];
			if ( isset( $params['thumbsize'] ) ) {
				$resize['width'] = $resize['height'] = $params['thumbsize'];
			}
			if ( isset( $params['thumbwidth'] ) ) {
				$resize['width'] = $params['thumbwidth'];
			}
			if ( isset( $params['thumbheight'] ) ) {
				$resize['height'] = $params['thumbheight'];
			}
			if ( isset( $resize['width'] ) && !isset( $resize['height'] ) ) {
				$resize['height'] = $this->isSVG( $file->getMimeType() )
					? $this->getScaledDimen( $file->getWidth(), $file->getHeight(), $resize['width'] )
					: $file->getHeight();
			}
			if ( !isset( $resize['width'] ) && isset( $resize['height'] ) ) {
				$resize['width'] = $this->isSVG( $file->getMimeType() )
					? $this->getScaledDimen( $file->getHeight(), $file->getWidth(), $resize['height'] )
					: $file->getWidth();
			}
			if ( !$resize ) {
				// Default
				$resize['width'] = $resize['height'] = 50;
			}
			$thumb = $file->transform( $resize );
			if ( !$thumb ) {
				return;
			}
			$result->addValue( null, $this->getModuleName(),
				[ 'thumb' =>
					[
						'url' => $thumb->getUrl(),
						'width' => $thumb->getWidth(),
						'height' => $thumb->getHeight(),
					]
				]
			);
		}
	}

	/**
	 * When only one dimension is given in a thumbnail request, scale the other proportionally
	 * with respect to the original file dimensions.
	 *
	 * @param int $srcX image width
	 * @param int $srcY image height
	 * @param int $dstX target image width
	 * @return int
	 */
	private function getScaledDimen( $srcX, $srcY, $dstX ) {
		return $srcX === 0 ? 0 : (int)round( $srcY * $dstX / $srcX );
	}

	/**
	 * Verify if mime type is SVG
	 * @param string $typeStr mime type
	 * @return bool
	 */
	private function isSVG( $typeStr ) {
		return strpos( $typeStr, 'image/svg' ) === 0;
	}

	/**
	 * Adds protection information to the Api result
	 * @param Title $title
	 */
	private function addProtection( Title $title ) {
		$result = $this->getResult();
		$protection = [];
		ApiResult::setArrayType( $protection, 'assoc' );
		foreach ( $title->getRestrictionTypes() as $type ) {
			$levels = $title->getRestrictions( $type );
			if ( $levels ) {
				$protection[$type] = $levels;
				ApiResult::setIndexedTagName( $protection[$type], 'level' );
			}
		}
		$result->addValue( null, $this->getModuleName(),
			[ 'protection' => $protection ]
		);
	}

	/**
	 * Get allowed Api parameters.
	 * @return array
	 */
	public function getAllowedParams() {
		$res = [
			'page' => [
				ApiBase::PARAM_REQUIRED => true,
			],
			'redirect' => [
				ApiBase::PARAM_TYPE => [ 'yes', 'no' ],
				ApiBase::PARAM_DFLT => 'yes',
			],
			'sections' => null,
			'prop' => [
				ApiBase::PARAM_DFLT => 'text|sections|normalizedtitle',
				ApiBase::PARAM_ISMULTI => true,
				ApiBase::PARAM_TYPE => [
					'id',
					'text',
					'sections',
					'normalizedtitle',
					'lastmodified',
					'lastmodifiedby',
					'revision',
					'protection',
					'editable',
					'languagecount',
					'hasvariants',
					'displaytitle',
					'pageprops',
					'description',
					'contentmodel',
					'namespace',
				]
			],
			'sectionprop' => [
				ApiBase::PARAM_TYPE => [
					'toclevel',
					'level',
					'line',
					'number',
					'index',
					'fromtitle',
					'anchor',
				],
				ApiBase::PARAM_ISMULTI => true,
				ApiBase::PARAM_DFLT => 'toclevel|line',
			],
			'pageprops' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_DFLT => 'notoc|noeditsection|wikibase_item'
			],
			'variant' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_DFLT => '',
			],
			'noimages' => false,
			'noheadings' => false,
			'notransform' => false,
			'onlyrequestedsections' => false,
			'offset' => [
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_MIN => 0,
				ApiBase::PARAM_DFLT => 0,
			],
			'maxlen' => [
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_MIN => 0,
				ApiBase::PARAM_DFLT => 0,
			],
			'revision' => [
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_MIN => 0,
				ApiBase::PARAM_DFLT => 0,
			],
		];
		if ( $this->usePageImages ) {
			$res['prop'][ApiBase::PARAM_TYPE][] = 'image';
			$res['prop'][ApiBase::PARAM_TYPE][] = 'thumb';
			$res['thumbsize'] = $res['thumbwidth'] = $res['thumbheight'] = [
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_MIN => 0,
			];
		}
		return $res;
	}

	/**
	 * Returns usage examples for this module.
	 * @see ApiBase::getExamplesMessages()
	 * @return array
	 */
	protected function getExamplesMessages() {
		return [
			'action=mobileview&page=Doom_metal&sections=0'
				=> 'apihelp-mobileview-example-1',
			'action=mobileview&page=Candlemass&sections=0|references'
				=> 'apihelp-mobileview-example-2',
			'action=mobileview&page=Candlemass&sections=1-|references'
				=> 'apihelp-mobileview-example-3',
		];
	}

	/**
	 * Returns the Help URL for this Api
	 * @return string
	 */
	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Extension:MobileFrontend#action.3Dmobileview';
	}
}
