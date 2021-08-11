<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @author Trevor Parscal
 * @author Roan Kattouw
 */

use MediaWiki\HookContainer\HookContainer;
use MediaWiki\MediaWikiServices;
use MediaWiki\ResourceLoader\HookRunner;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Wikimedia\RelPath;

/**
 * Abstraction for ResourceLoader modules, with name registration and maxage functionality.
 *
 * @see $wgResourceModules for the available options when registering a module.
 * @stable to extend
 * @ingroup ResourceLoader
 * @since 1.17
 */
abstract class ResourceLoaderModule implements LoggerAwareInterface {
	/** @var Config */
	protected $config;
	/** @var LoggerInterface */
	protected $logger;

	/**
	 * Script and style modules form a hierarchy of trustworthiness, with core modules
	 * like skins and jQuery as most trustworthy, and user scripts as least trustworthy. We can
	 * limit the types of scripts and styles we allow to load on, say, sensitive special
	 * pages like Special:UserLogin and Special:Preferences
	 * @var int
	 */
	protected $origin = self::ORIGIN_CORE_SITEWIDE;

	/** @var string|null Module name */
	protected $name = null;
	/** @var string[] What client platforms the module targets (e.g. desktop, mobile) */
	protected $targets = [ 'desktop' ];

	/** @var array Map of (variant => indirect file dependencies) */
	protected $fileDeps = [];
	/** @var array Map of (language => in-object cache for message blob) */
	protected $msgBlobs = [];
	/** @var array Map of (context hash => cached module version hash) */
	protected $versionHash = [];
	/** @var array Map of (context hash => cached module content) */
	protected $contents = [];

	/** @var HookRunner|null */
	private $hookRunner;

	/** @var callback Function of (module name, variant) to get indirect file dependencies */
	private $depLoadCallback;
	/** @var callback Function of (module name, variant) to get indirect file dependencies */
	private $depSaveCallback;

	/** @var string|bool Deprecation string or true if deprecated; false otherwise */
	protected $deprecated = false;

	/** @var string Scripts only */
	public const TYPE_SCRIPTS = 'scripts';
	/** @var string Styles only */
	public const TYPE_STYLES = 'styles';
	/** @var string Scripts and styles */
	public const TYPE_COMBINED = 'combined';

	/** @var string Module only has styles (loaded via <style> or <link rel=stylesheet>) */
	public const LOAD_STYLES = 'styles';
	/** @var string Module may have other resources (loaded via mw.loader from a script) */
	public const LOAD_GENERAL = 'general';

	/** @var int Sitewide core module like a skin file or jQuery component */
	public const ORIGIN_CORE_SITEWIDE = 1;
	/** @var int Per-user module generated by the software */
	public const ORIGIN_CORE_INDIVIDUAL = 2;
	/**
	 * Sitewide module generated from user-editable files, like MediaWiki:Common.js,
	 * or modules accessible to multiple users, such as those generated by the Gadgets extension.
	 * @var int
	 */
	public const ORIGIN_USER_SITEWIDE = 3;
	/** @var int Per-user module generated from user-editable files, like User:Me/vector.js */
	public const ORIGIN_USER_INDIVIDUAL = 4;
	/** @var int An access constant; make sure this is kept as the largest number in this group */
	public const ORIGIN_ALL = 10;

	/** @var int Cache version for user-script JS validation errors from validateScriptFile(). */
	private const USERJSPARSE_CACHE_VERSION = 2;

	/**
	 * Get this module's name. This is set when the module is registered
	 * with ResourceLoader::register()
	 *
	 * @return string|null Name (string) or null if no name was set
	 */
	public function getName() {
		return $this->name;
	}

	/**
	 * Set this module's name. This is called by ResourceLoader::register()
	 * when registering the module. Other code should not call this.
	 *
	 * @param string $name
	 */
	public function setName( $name ) {
		$this->name = $name;
	}

	/**
	 * Provide overrides for skinStyles to modules that support that.
	 *
	 * This MUST be called after self::setName().
	 *
	 * @since 1.37
	 * @see $wgResourceModuleSkinStyles
	 * @param array $moduleSkinStyles
	 */
	public function setSkinStylesOverride( array $moduleSkinStyles ): void {
		// Stub, only supported by FileModule currently.
	}

	/**
	 * Inject the functions that load/save the indirect file path dependency list from storage
	 *
	 * @param callable $loadCallback Function of (module name, variant)
	 * @param callable $saveCallback Function of (module name, variant, current paths, stored paths)
	 * @since 1.35
	 */
	public function setDependencyAccessCallbacks( callable $loadCallback, callable $saveCallback ) {
		$this->depLoadCallback = $loadCallback;
		$this->depSaveCallback = $saveCallback;
	}

	/**
	 * Get this module's origin. This is set when the module is registered
	 * with ResourceLoader::register()
	 *
	 * @return int ResourceLoaderModule class constant, the subclass default
	 *     if not set manually
	 */
	public function getOrigin() {
		return $this->origin;
	}

	/**
	 * @param ResourceLoaderContext $context
	 * @return bool
	 */
	public function getFlip( ResourceLoaderContext $context ) {
		return MediaWikiServices::getInstance()->getContentLanguage()->getDir() !==
			$context->getDirection();
	}

	/**
	 * Get JS representing deprecation information for the current module if available
	 *
	 * @param ResourceLoaderContext $context
	 * @return string JavaScript code
	 */
	public function getDeprecationInformation( ResourceLoaderContext $context ) {
		$deprecationInfo = $this->deprecated;
		if ( $deprecationInfo ) {
			$name = $this->getName();
			$warning = 'This page is using the deprecated ResourceLoader module "' . $name . '".';
			if ( is_string( $deprecationInfo ) ) {
				$warning .= "\n" . $deprecationInfo;
			}
			return 'mw.log.warn(' . $context->encodeJson( $warning ) . ');';
		} else {
			return '';
		}
	}

	/**
	 * Get all JS for this module for a given language and skin.
	 * Includes all relevant JS except loader scripts.
	 *
	 * For "plain" script modules, this should return a string with JS code. For multi-file modules
	 * where require() is used to load one file from another file, this should return an array
	 * structured as follows:
	 * [
	 *     'files' => [
	 *         'file1.js' => [ 'type' => 'script', 'content' => 'JS code' ],
	 *         'file2.js' => [ 'type' => 'script', 'content' => 'JS code' ],
	 *         'data.json' => [ 'type' => 'data', 'content' => array ]
	 *     ],
	 *     'main' => 'file1.js'
	 * ]
	 *
	 * @stable to override
	 * @param ResourceLoaderContext $context
	 * @return string|array JavaScript code (string), or multi-file structure described above (array)
	 */
	public function getScript( ResourceLoaderContext $context ) {
		// Stub, override expected
		return '';
	}

	/**
	 * Takes named templates by the module and returns an array mapping.
	 *
	 * @stable to override
	 * @return string[] Array of templates mapping template alias to content
	 */
	public function getTemplates() {
		// Stub, override expected.
		return [];
	}

	/**
	 * @return Config
	 * @since 1.24
	 */
	public function getConfig() {
		if ( $this->config === null ) {
			throw new RuntimeException( 'Config accessed before it is set' );
		}

		return $this->config;
	}

	/**
	 * @param Config $config
	 * @since 1.24
	 */
	public function setConfig( Config $config ) {
		$this->config = $config;
	}

	/**
	 * @since 1.27
	 * @param LoggerInterface $logger
	 */
	public function setLogger( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * @since 1.27
	 * @return LoggerInterface
	 */
	protected function getLogger() {
		if ( !$this->logger ) {
			$this->logger = new NullLogger();
		}
		return $this->logger;
	}

	/**
	 * @internal For use only by ResourceLoader::getModule
	 * @param HookContainer $hookContainer
	 */
	public function setHookContainer( HookContainer $hookContainer ): void {
		$this->hookRunner = new HookRunner( $hookContainer );
	}

	/**
	 * Get a HookRunner for running core hooks.
	 *
	 * @internal For use only within core ResourceLoaderModule subclasses. Hook interfaces may be removed
	 *   without notice.
	 * @return HookRunner
	 */
	protected function getHookRunner(): HookRunner {
		return $this->hookRunner;
	}

	/**
	 * Get the URL or URLs to load for this module's JS in debug mode.
	 * The default behavior is to return a load.php?only=scripts URL for
	 * the module, but file-based modules will want to override this to
	 * load the files directly.
	 *
	 * This function is called only when 1) we're in debug mode, 2) there
	 * is no only= parameter and 3) supportsURLLoading() returns true.
	 * #2 is important to prevent an infinite loop, therefore this function
	 * MUST return either an only= URL or a non-load.php URL.
	 *
	 * @stable to override
	 * @param ResourceLoaderContext $context
	 * @return string[]
	 */
	public function getScriptURLsForDebug( ResourceLoaderContext $context ) {
		$resourceLoader = $context->getResourceLoader();
		$derivative = new DerivativeResourceLoaderContext( $context );
		$derivative->setModules( [ $this->getName() ] );
		$derivative->setOnly( 'scripts' );
		$derivative->setDebug( true );

		$url = $resourceLoader->createLoaderURL(
			$this->getSource(),
			$derivative
		);

		return [ $url ];
	}

	/**
	 * Whether this module supports URL loading. If this function returns false,
	 * getScript() will be used even in cases (debug mode, no only param) where
	 * getScriptURLsForDebug() would normally be used instead.
	 *
	 * @stable to override
	 * @return bool
	 */
	public function supportsURLLoading() {
		return true;
	}

	/**
	 * Get all CSS for this module for a given skin.
	 *
	 * @stable to override
	 * @param ResourceLoaderContext $context
	 * @return array List of CSS strings or array of CSS strings keyed by media type.
	 *  like [ 'screen' => '.foo { width: 0 }' ];
	 *  or [ 'screen' => [ '.foo { width: 0 }' ] ];
	 */
	public function getStyles( ResourceLoaderContext $context ) {
		// Stub, override expected
		return [];
	}

	/**
	 * Get the URL or URLs to load for this module's CSS in debug mode.
	 * The default behavior is to return a load.php?only=styles URL for
	 * the module, but file-based modules will want to override this to
	 * load the files directly. See also getScriptURLsForDebug()
	 *
	 * @stable to override
	 * @param ResourceLoaderContext $context
	 * @return array [ mediaType => [ URL1, URL2, ... ], ... ]
	 */
	public function getStyleURLsForDebug( ResourceLoaderContext $context ) {
		$resourceLoader = $context->getResourceLoader();
		$derivative = new DerivativeResourceLoaderContext( $context );
		$derivative->setModules( [ $this->getName() ] );
		$derivative->setOnly( 'styles' );
		$derivative->setDebug( true );

		$url = $resourceLoader->createLoaderURL(
			$this->getSource(),
			$derivative
		);

		return [ 'all' => [ $url ] ];
	}

	/**
	 * Get the messages needed for this module.
	 *
	 * To get a JSON blob with messages, use MessageBlobStore::get()
	 *
	 * @stable to override
	 * @return string[] List of message keys. Keys may occur more than once
	 */
	public function getMessages() {
		// Stub, override expected
		return [];
	}

	/**
	 * Get the group this module is in.
	 *
	 * @stable to override
	 * @return string Group name
	 */
	public function getGroup() {
		// Stub, override expected
		return null;
	}

	/**
	 * Get the source of this module. Should only be overridden for foreign modules.
	 *
	 * @stable to override
	 * @return string Source name, 'local' for local modules
	 */
	public function getSource() {
		// Stub, override expected
		return 'local';
	}

	/**
	 * Get a list of modules this module depends on.
	 *
	 * Dependency information is taken into account when loading a module
	 * on the client side.
	 *
	 * Note: It is expected that $context will be made non-optional in the near
	 * future.
	 *
	 * @stable to override
	 * @param ResourceLoaderContext|null $context
	 * @return string[] List of module names as strings
	 */
	public function getDependencies( ResourceLoaderContext $context = null ) {
		// Stub, override expected
		return [];
	}

	/**
	 * Get target(s) for the module, eg ['desktop'] or ['desktop', 'mobile']
	 *
	 * @stable to override
	 * @return string[]
	 */
	public function getTargets() {
		return $this->targets;
	}

	/**
	 * Get the module's load type.
	 *
	 * @stable to override
	 * @since 1.28
	 * @return string ResourceLoaderModule LOAD_* constant
	 */
	public function getType() {
		return self::LOAD_GENERAL;
	}

	/**
	 * Get the skip function.
	 *
	 * Modules that provide fallback functionality can provide a "skip function". This
	 * function, if provided, will be passed along to the module registry on the client.
	 * When this module is loaded (either directly or as a dependency of another module),
	 * then this function is executed first. If the function returns true, the module will
	 * instantly be considered "ready" without requesting the associated module resources.
	 *
	 * The value returned here must be valid javascript for execution in a private function.
	 * It must not contain the "function () {" and "}" wrapper though.
	 *
	 * @stable to override
	 * @return string|null A JavaScript function body returning a boolean value, or null
	 */
	public function getSkipFunction() {
		return null;
	}

	/**
	 * Whether the module requires ES6 support in the client.
	 *
	 * If the client does not support ES6, attempting to load a module that requires ES6 will
	 * result in an error.
	 *
	 * @stable to override
	 * @since 1.36
	 * @return bool
	 */
	public function requiresES6() {
		return false;
	}

	/**
	 * Get the indirect dependencies for this module persuant to the skin/language context
	 *
	 * These are only image files referenced by the module's stylesheet
	 *
	 * If niether setFileDependencies() nor setDependencyLoadCallback() was called, this
	 * will simply return a placeholder with an empty file list
	 *
	 * @see ResourceLoader::setFileDependencies()
	 * @see ResourceLoader::saveFileDependencies()
	 *
	 * @param ResourceLoaderContext $context
	 * @return string[] List of absolute file paths
	 * @throws RuntimeException When setFileDependencies() has not yet been called
	 */
	protected function getFileDependencies( ResourceLoaderContext $context ) {
		$variant = self::getVary( $context );

		if ( !isset( $this->fileDeps[$variant] ) ) {
			if ( $this->depLoadCallback ) {
				$this->fileDeps[$variant] =
					call_user_func( $this->depLoadCallback, $this->getName(), $variant );
			} else {
				$this->getLogger()->info( __METHOD__ . ": no callback registered" );
				$this->fileDeps[$variant] = [];
			}
		}

		return $this->fileDeps[$variant];
	}

	/**
	 * Set the indirect dependencies for this module persuant to the skin/language context
	 *
	 * These are only image files referenced by the module's stylesheet
	 *
	 * @see ResourceLoader::getFileDependencies()
	 * @see ResourceLoader::saveFileDependencies()
	 *
	 * @param ResourceLoaderContext $context
	 * @param string[] $paths List of absolute file paths
	 */
	public function setFileDependencies( ResourceLoaderContext $context, array $paths ) {
		$variant = self::getVary( $context );
		$this->fileDeps[$variant] = $paths;
	}

	/**
	 * Save the indirect dependencies for this module persuant to the skin/language context
	 *
	 * @param ResourceLoaderContext $context
	 * @param string[] $curFileRefs List of newly computed indirect file dependencies
	 * @since 1.27
	 */
	protected function saveFileDependencies( ResourceLoaderContext $context, array $curFileRefs ) {
		if ( !$this->depSaveCallback ) {
			$this->getLogger()->info( __METHOD__ . ": no callback registered" );

			return;
		}

		try {
			// Pitfalls and performance considerations:
			// 1. Don't keep updating the tracked paths due to duplicates or sorting.
			// 2. Use relative paths to avoid ghost entries when $IP changes. (T111481)
			// 3. Don't needlessly replace tracked paths with the same value
			//    just because $IP changed (e.g. when upgrading a wiki).
			// 4. Don't create an endless replace loop on every request for this
			//    module when '../' is used anywhere. Even though both are expanded
			//    (one expanded by getFileDependencies from the DB, the other is
			//    still raw as originally read by RL), the latter has not
			//    been normalized yet.
			call_user_func(
				$this->depSaveCallback,
				$this->getName(),
				self::getVary( $context ),
				self::getRelativePaths( $curFileRefs ),
				self::getRelativePaths( $this->getFileDependencies( $context ) )
			);
		} catch ( Exception $e ) {
			$this->getLogger()->warning(
				__METHOD__ . ": failed to update dependencies: {$e->getMessage()}",
				[ 'exception' => $e ]
			);
		}
	}

	/**
	 * Make file paths relative to MediaWiki directory.
	 *
	 * This is used to make file paths safe for storing in a database without the paths
	 * becoming stale or incorrect when MediaWiki is moved or upgraded (T111481).
	 *
	 * @since 1.27
	 * @param array $filePaths
	 * @return array
	 */
	public static function getRelativePaths( array $filePaths ) {
		global $IP;
		return array_map( static function ( $path ) use ( $IP ) {
			return RelPath::getRelativePath( $path, $IP );
		}, $filePaths );
	}

	/**
	 * Expand directories relative to $IP.
	 *
	 * @since 1.27
	 * @param array $filePaths
	 * @return array
	 */
	public static function expandRelativePaths( array $filePaths ) {
		global $IP;
		return array_map( static function ( $path ) use ( $IP ) {
			return RelPath::joinPath( $IP, $path );
		}, $filePaths );
	}

	/**
	 * Get the hash of the message blob.
	 *
	 * @stable to override
	 * @since 1.27
	 * @param ResourceLoaderContext $context
	 * @return string|null JSON blob or null if module has no messages
	 */
	protected function getMessageBlob( ResourceLoaderContext $context ) {
		if ( !$this->getMessages() ) {
			// Don't bother consulting MessageBlobStore
			return null;
		}
		// Message blobs may only vary language, not by context keys
		$lang = $context->getLanguage();
		if ( !isset( $this->msgBlobs[$lang] ) ) {
			$this->getLogger()->warning( 'Message blob for {module} should have been preloaded', [
				'module' => $this->getName(),
			] );
			$store = $context->getResourceLoader()->getMessageBlobStore();
			$this->msgBlobs[$lang] = $store->getBlob( $this, $lang );
		}
		return $this->msgBlobs[$lang];
	}

	/**
	 * Set in-object cache for message blobs.
	 *
	 * Used to allow fetching of message blobs in batches. See ResourceLoader::preloadModuleInfo().
	 *
	 * @since 1.27
	 * @param string|null $blob JSON blob or null
	 * @param string $lang Language code
	 */
	public function setMessageBlob( $blob, $lang ) {
		$this->msgBlobs[$lang] = $blob;
	}

	/**
	 * Get headers to send as part of a module web response.
	 *
	 * It is not supported to send headers through this method that are
	 * required to be unique or otherwise sent once in an HTTP response
	 * because clients may make batch requests for multiple modules (as
	 * is the default behaviour for ResourceLoader clients).
	 *
	 * For exclusive or aggregated headers, see ResourceLoader::sendResponseHeaders().
	 *
	 * @since 1.30
	 * @param ResourceLoaderContext $context
	 * @return string[] Array of HTTP response headers
	 */
	final public function getHeaders( ResourceLoaderContext $context ) {
		$headers = [];

		$formattedLinks = [];
		foreach ( $this->getPreloadLinks( $context ) as $url => $attribs ) {
			$link = "<{$url}>;rel=preload";
			foreach ( $attribs as $key => $val ) {
				$link .= ";{$key}={$val}";
			}
			$formattedLinks[] = $link;
		}
		if ( $formattedLinks ) {
			$headers[] = 'Link: ' . implode( ',', $formattedLinks );
		}

		return $headers;
	}

	/**
	 * Get a list of resources that web browsers may preload.
	 *
	 * Behaviour of rel=preload link is specified at <https://www.w3.org/TR/preload/>.
	 *
	 * Use case for ResourceLoader originally part of T164299.
	 *
	 * @par Example
	 * @code
	 *     protected function getPreloadLinks() {
	 *         return [
	 *             'https://example.org/script.js' => [ 'as' => 'script' ],
	 *             'https://example.org/image.png' => [ 'as' => 'image' ],
	 *         ];
	 *     }
	 * @endcode
	 *
	 * @par Example using HiDPI image variants
	 * @code
	 *     protected function getPreloadLinks() {
	 *         return [
	 *             'https://example.org/logo.png' => [
	 *                 'as' => 'image',
	 *                 'media' => 'not all and (min-resolution: 2dppx)',
	 *             ],
	 *             'https://example.org/logo@2x.png' => [
	 *                 'as' => 'image',
	 *                 'media' => '(min-resolution: 2dppx)',
	 *             ],
	 *         ];
	 *     }
	 * @endcode
	 *
	 * @see ResourceLoaderModule::getHeaders
	 *
	 * @stable to override
	 * @since 1.30
	 * @param ResourceLoaderContext $context
	 * @return array Keyed by url, values must be an array containing
	 *  at least an 'as' key. Optionally a 'media' key as well.
	 */
	protected function getPreloadLinks( ResourceLoaderContext $context ) {
		return [];
	}

	/**
	 * Get module-specific LESS variables, if any.
	 *
	 * @stable to override
	 * @since 1.27
	 * @param ResourceLoaderContext $context
	 * @return array Module-specific LESS variables.
	 */
	protected function getLessVars( ResourceLoaderContext $context ) {
		return [];
	}

	/**
	 * Get an array of this module's resources. Ready for serving to the web.
	 *
	 * @since 1.26
	 * @param ResourceLoaderContext $context
	 * @return array
	 */
	public function getModuleContent( ResourceLoaderContext $context ) {
		$contextHash = $context->getHash();
		// Cache this expensive operation. This calls builds the scripts, styles, and messages
		// content which typically involves filesystem and/or database access.
		if ( !array_key_exists( $contextHash, $this->contents ) ) {
			$this->contents[$contextHash] = $this->buildContent( $context );
		}
		return $this->contents[$contextHash];
	}

	/**
	 * Bundle all resources attached to this module into an array.
	 *
	 * @since 1.26
	 * @param ResourceLoaderContext $context
	 * @return array
	 */
	final protected function buildContent( ResourceLoaderContext $context ) {
		$stats = MediaWikiServices::getInstance()->getStatsdDataFactory();
		$statStart = microtime( true );

		// This MUST build both scripts and styles, regardless of whether $context->getOnly()
		// is 'scripts' or 'styles' because the result is used by getVersionHash which
		// must be consistent regardless of the 'only' filter on the current request.
		// Also, when introducing new module content resources (e.g. templates, headers),
		// these should only be included in the array when they are non-empty so that
		// existing modules not using them do not get their cache invalidated.
		$content = [];

		// Scripts
		// If we are in debug mode, we'll want to return an array of URLs if possible
		// However, we can't do this if the module doesn't support it.
		// We also can't do this if there is an only= parameter, because we have to give
		// the module a way to return a load.php URL without causing an infinite loop
		if ( $context->getDebug() && !$context->getOnly() && $this->supportsURLLoading() ) {
			$scripts = $this->getScriptURLsForDebug( $context );
		} else {
			$scripts = $this->getScript( $context );
			// Make the script safe to concatenate by making sure there is at least one
			// trailing new line at the end of the content. Previously, this looked for
			// a semi-colon instead, but that breaks concatenation if the semicolon
			// is inside a comment like "// foo();". Instead, simply use a
			// line break as separator which matches JavaScript native logic for implicitly
			// ending statements even if a semi-colon is missing.
			// Bugs: T29054, T162719.
			if ( is_string( $scripts )
				&& strlen( $scripts )
				&& substr( $scripts, -1 ) !== "\n"
			) {
				$scripts .= "\n";
			}
		}
		$content['scripts'] = $scripts;

		$styles = [];
		// Don't create empty stylesheets like [ '' => '' ] for modules
		// that don't *have* any stylesheets (T40024).
		$stylePairs = $this->getStyles( $context );
		if ( count( $stylePairs ) ) {
			// If we are in debug mode without &only= set, we'll want to return an array of URLs
			// See comment near shouldIncludeScripts() for more details
			if ( $context->getDebug() && !$context->getOnly() && $this->supportsURLLoading() ) {
				$styles = [
					'url' => $this->getStyleURLsForDebug( $context )
				];
			} else {
				// Minify CSS before embedding in mw.loader.implement call
				// (unless in debug mode)
				if ( !$context->getDebug() ) {
					foreach ( $stylePairs as $media => $style ) {
						// Can be either a string or an array of strings.
						if ( is_array( $style ) ) {
							$stylePairs[$media] = [];
							foreach ( $style as $cssText ) {
								if ( is_string( $cssText ) ) {
									$stylePairs[$media][] =
										ResourceLoader::filter( 'minify-css', $cssText );
								}
							}
						} elseif ( is_string( $style ) ) {
							$stylePairs[$media] = ResourceLoader::filter( 'minify-css', $style );
						}
					}
				}
				// Wrap styles into @media groups as needed and flatten into a numerical array
				$styles = [
					'css' => ResourceLoader::makeCombinedStyles( $stylePairs )
				];
			}
		}
		$content['styles'] = $styles;

		// Messages
		$blob = $this->getMessageBlob( $context );
		if ( $blob ) {
			$content['messagesBlob'] = $blob;
		}

		$templates = $this->getTemplates();
		if ( $templates ) {
			$content['templates'] = $templates;
		}

		$headers = $this->getHeaders( $context );
		if ( $headers ) {
			$content['headers'] = $headers;
		}

		$statTiming = microtime( true ) - $statStart;
		$statName = strtr( $this->getName(), '.', '_' );
		$stats->timing( "resourceloader_build.all", 1000 * $statTiming );
		$stats->timing( "resourceloader_build.$statName", 1000 * $statTiming );

		return $content;
	}

	/**
	 * Get a string identifying the current version of this module in a given context.
	 *
	 * Whenever anything happens that changes the module's response (e.g. scripts, styles, and
	 * messages) this value must change. This value is used to store module responses in cache.
	 * (Both client-side and server-side.)
	 *
	 * It is not recommended to override this directly. Use getDefinitionSummary() instead.
	 * If overridden, one must call the parent getVersionHash(), append data and re-hash.
	 *
	 * This method should be quick because it is frequently run by ResourceLoaderStartUpModule to
	 * propagate changes to the client and effectively invalidate cache.
	 *
	 * @since 1.26
	 * @param ResourceLoaderContext $context
	 * @return string Hash (should use ResourceLoader::makeHash)
	 */
	public function getVersionHash( ResourceLoaderContext $context ) {
		// Cache this somewhat expensive operation. Especially because some classes
		// (e.g. startup module) iterate more than once over all modules to get versions.
		$contextHash = $context->getHash();
		if ( !array_key_exists( $contextHash, $this->versionHash ) ) {
			if ( $this->enableModuleContentVersion() ) {
				// Detect changes directly by hashing the module contents.
				$str = json_encode( $this->getModuleContent( $context ) );
			} else {
				// Infer changes based on definition and other metrics
				$summary = $this->getDefinitionSummary( $context );
				if ( !isset( $summary['_class'] ) ) {
					throw new LogicException( 'getDefinitionSummary must call parent method' );
				}
				$str = json_encode( $summary );
			}

			$this->versionHash[$contextHash] = ResourceLoader::makeHash( $str );
		}
		return $this->versionHash[$contextHash];
	}

	/**
	 * Whether to generate version hash based on module content.
	 *
	 * If a module requires database or file system access to build the module
	 * content, consider disabling this in favour of manually tracking relevant
	 * aspects in getDefinitionSummary(). See getVersionHash() for how this is used.
	 *
	 * @stable to override
	 * @return bool
	 */
	public function enableModuleContentVersion() {
		return false;
	}

	/**
	 * Get the definition summary for this module.
	 *
	 * This is the method subclasses are recommended to use to track data that
	 * should influence the module's version hash.
	 *
	 * Subclasses must call the parent getDefinitionSummary() and add to the
	 * returned array. It is recommended that each subclass appends its own array,
	 * to prevent clashes or accidental overwrites of array keys from the parent
	 * class. This gives each subclass a clean scope.
	 *
	 * @code
	 *     $summary = parent::getDefinitionSummary( $context );
	 *     $summary[] = [
	 *         'foo' => 123,
	 *         'bar' => 'quux',
	 *     ];
	 *     return $summary;
	 * @endcode
	 *
	 * Return an array that contains all significant properties that define the
	 * module. The returned data should be deterministic and only change when
	 * the generated module response would change. Prefer content hashes over
	 * modified timestamps because timestamps may change for unrelated reasons
	 * and are not deterministic (T102578). For example, because timestamps are
	 * not stored in Git, each branch checkout would cause all files to appear as
	 * new. Timestamps also tend to not match between servers causing additional
	 * ever-lasting churning of the version hash.
	 *
	 * Be careful not to normalise the data too much in an effort to be deterministic.
	 * For example, if a module concatenates files together (order is significant),
	 * then the definition summary could be a list of file names, and a list of
	 * file hashes. These lists should not be sorted as that would mean the cache
	 * is not invalidated when the order changes (T39812).
	 *
	 * This data structure must exclusively contain primitive "scalar" values,
	 * as it will be serialised using `json_encode`.
	 *
	 * @stable to override
	 * @since 1.23
	 * @param ResourceLoaderContext $context
	 * @return array|null
	 */
	public function getDefinitionSummary( ResourceLoaderContext $context ) {
		return [
			'_class' => static::class,
			// Make sure that when filter cache for minification is invalidated,
			// we also change the HTTP urls and mw.loader.store keys (T176884).
			'_cacheVersion' => ResourceLoader::CACHE_VERSION,
		];
	}

	/**
	 * Check whether this module is known to be empty. If a child class
	 * has an easy and cheap way to determine that this module is
	 * definitely going to be empty, it should override this method to
	 * return true in that case. Callers may optimize the request for this
	 * module away if this function returns true.
	 *
	 * @stable to override
	 * @param ResourceLoaderContext $context
	 * @return bool
	 */
	public function isKnownEmpty( ResourceLoaderContext $context ) {
		return false;
	}

	/**
	 * Check whether this module should be embeded rather than linked
	 *
	 * Modules returning true here will be embedded rather than loaded by
	 * ResourceLoaderClientHtml.
	 *
	 * @since 1.30
	 * @stable to override
	 * @param ResourceLoaderContext $context
	 * @return bool
	 */
	public function shouldEmbedModule( ResourceLoaderContext $context ) {
		return $this->getGroup() === 'private';
	}

	/**
	 * Validate a user-provided JavaScript blob.
	 *
	 * @param string $fileName
	 * @param string $contents JavaScript code
	 * @return string JavaScript code, either the original content or a replacement
	 *  that uses `mw.log.error()` to communicate a syntax error.
	 */
	protected function validateScriptFile( $fileName, $contents ) {
		$error = null;

		if ( $this->getConfig()->get( 'ResourceLoaderValidateJS' ) ) {
			$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
			// Cache potentially slow parsing of JavaScript code during the
			// critical path. This happens lazily when responding to requests
			// for modules=site, modules=user, and Gadgets.
			$error = $cache->getWithSetCallback(
				$cache->makeKey(
					'resourceloader-userjsparse',
					self::USERJSPARSE_CACHE_VERSION,
					md5( $contents ),
					$fileName
				),
				$cache::TTL_WEEK,
				static function () use ( $contents, $fileName ) {
					$parser = new JSParser();
					try {
						// Ignore compiler warnings (T77169)
						// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
						@$parser->parse( $contents, $fileName, 1 );
					} catch ( Exception $e ) {
						return $e->getMessage();
					}
					// Cache success as null
					return null;
				}
			);
		}

		if ( $error ) {
			// Send the error to the browser console client-side.
			// By returning this as replacement for the actual script,
			// we ensure user-provided scripts are safe to include in a batch
			// request, without risk of a syntax error in this blob breaking
			// the response itself.
			return 'mw.log.error(' .
				json_encode(
					'JavaScript parse error (scripts need to be valid ECMAScript 5): ' .
					$error
				) .
				');';
		} else {
			return $contents;
		}
	}

	/**
	 * Compute a non-cryptographic string hash of a file's contents.
	 * If the file does not exist or cannot be read, returns an empty string.
	 *
	 * @since 1.26 Uses MD4 instead of SHA1.
	 * @param string $filePath File path
	 * @return string Hash
	 */
	protected static function safeFileHash( $filePath ) {
		return FileContentsHasher::getFileContentsHash( $filePath );
	}

	/**
	 * Get vary string.
	 *
	 * @internal For internal use only.
	 * @param ResourceLoaderContext $context
	 * @return string
	 */
	public static function getVary( ResourceLoaderContext $context ) {
		return implode( '|', [
			$context->getSkin(),
			$context->getLanguage(),
		] );
	}
}
